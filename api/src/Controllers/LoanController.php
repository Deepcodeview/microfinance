<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AgentCash;
use App\Services\Approvals;
use App\Services\Audit;
use App\Services\CustomerScope;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\LoanTerms;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\Origination;
use App\Services\PaymentService;
use App\Services\Settings;

final class LoanController
{
    public const STATUSES = ['pending_approval', 'approved', 'rejected', 'active', 'closed', 'cancelled', 'written_off'];

    private const OUTSTANDING_SQL = '(SELECT COALESCE(SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid), 0)
           FROM loan_installments i WHERE i.loan_id = l.id)';

    private const OVERDUE_SQL = "(SELECT COALESCE(SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid), 0)
           FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?)";

    public function index(Request $request): Response
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $where = [$scope];
        Paginator::filter($request, 'status', 'l.status', $where, $params, self::STATUSES);
        Paginator::filter($request, 'customer_id', 'l.customer_id', $where, $params);
        Paginator::filter($request, 'product_id', 'l.product_id', $where, $params);
        Paginator::filter($request, 'branch_id', 'l.branch_id', $where, $params);
        Paginator::filter($request, 'asset_class', 'l.asset_class', $where, $params, ['standard', 'sma_0', 'sma_1', 'sma_2', 'substandard', 'doubtful', 'loss']);
        if (($request->query['npa'] ?? '') === '1') {
            $where[] = "l.asset_class IN ('substandard','doubtful','loss')";
        }
        $search = trim((string) ($request->query['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(l.loan_number LIKE ? OR c.full_name LIKE ? OR c.customer_number LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $today = Settings::today();
        if (($request->query['overdue'] ?? '') === '1') {
            $where[] = "EXISTS (SELECT 1 FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?)";
            $params[] = $today;
        }

        return Paginator::run(
            $request,
            'l.id, l.loan_number, l.status, l.asset_class, l.dpd, l.principal, l.net_disbursement, l.tenure, l.installment_amount, l.applied_at, l.disbursement_date,
             c.id AS customer_id, c.full_name AS customer_name, c.customer_number, p.name AS product_name,
             ' . self::OUTSTANDING_SQL . ' AS outstanding, ' . str_replace('?', $this->quoteDate($today), self::OVERDUE_SQL) . ' AS overdue',
            'loans l JOIN customers c ON c.id = l.customer_id JOIN loan_products p ON p.id = l.product_id',
            implode(' AND ', $where),
            $params,
            ['applied_at' => 'l.applied_at', 'loan_number' => 'l.loan_number', 'principal' => 'l.principal'],
            'applied_at',
        );
    }

    public function show(Request $request): Response
    {
        $loan = $this->findInScope($request->intParam('id'));
        $today = Settings::today();

        if ($loan['status'] === 'active') {
            Database::transaction(fn () => LoanService::applyLateFees(PaymentService::lockLoan((int) $loan['id']), $today));
        }

        $loan['terms'] = LoanService::terms($loan);
        $loan['installments'] = LoanService::installments((int) $loan['id']);
        $loan['position'] = in_array($loan['status'], ['active', 'closed', 'written_off'], true) ? LoanService::position((int) $loan['id'], $today) : null;
        $loan['settlement_quote'] = $loan['status'] === 'active' ? LoanService::settlementQuote($loan, $today) : null;
        $loan['payments'] = Database::all(
            "SELECT p.id, p.payment_number, p.amount, p.method, p.purpose, p.status, p.business_date, p.collected_at,
                    u.name AS collected_by_name, r.receipt_number
               FROM payments p JOIN users u ON u.id = p.collected_by LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.loan_id = ? ORDER BY p.id DESC",
            [$loan['id']],
        );
        $loan['preview'] = LoanTerms::preview($loan['terms'], Money::fmt($loan['principal']), (int) $loan['tenure']);
        unset($loan['preview']['schedule']);
        $loan['business_date'] = $today;
        return Response::json($loan);
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $customerId = $v->int('customer_id', 1, PHP_INT_MAX);
        $productId = $v->int('product_id', 1, PHP_INT_MAX);
        $principal = $v->decimal('principal', '1', '100000000', 2);
        $tenure = $v->int('tenure', 1, 1000);
        $purpose = $v->string('purpose', false, 255);
        $v->validate();

        $customer = $this->customerInScope($customerId);
        if ($customer['status'] !== 'active') {
            throw HttpException::validation(['customer_id' => 'Customer is not active.']);
        }
        $checked = Origination::checkLoan($customer, $productId, $principal, $tenure);
        $id = Database::transaction(fn () => Origination::createLoan($customer, $checked, $principal, $tenure, $purpose, $user->id, $request));

        $request->params['id'] = (string) $id;
        return new Response(201, $this->show($request)->body);
    }

    public function approve(Request $request): Response
    {
        return $this->decide($request, 'approved');
    }

    public function reject(Request $request): Response
    {
        return $this->decide($request, 'rejected');
    }

    public function disburse(Request $request): Response
    {
        $user = Auth::user();
        $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $method = $v->in('method', PaymentController::ACCEPTED);
        $reference = $v->string('reference', $method !== null && $method !== 'cash', 100);
        $v->validate();

        Database::transaction(function () use ($request, $user, $method, $reference) {
            $loan = PaymentService::lockLoan($request->intParam('id'));
            if ($loan['status'] === 'approved') {
                Ledger::assertFunds($method, Money::fmt($loan['net_disbursement']), (int) $loan['branch_id']);
            }
            $this->payOut($loan, $method, $reference, Ledger::paymentAccount($method), false, $request);
        });

        return $this->show($request);
    }

    /** An agent pays out an approved loan in cash in the field, from the cash they hold (float + collections). */
    public function disburseField(Request $request): Response
    {
        $user = Auth::user();
        $this->findInScope($request->intParam('id'));
        Database::transaction(function () use ($request, $user) {
            // One payout at a time per agent, so two loans cannot spend the same cash.
            Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            $loan = PaymentService::lockLoan($request->intParam('id'));
            $net = Money::fmt($loan['net_disbursement']);
            $inHand = AgentCash::position($user->id, Settings::today())['in_hand'];
            if (Money::cmp($net, $inHand) > 0) {
                throw new HttpException(409, 'INSUFFICIENT_FUNDS', "You hold Rs {$inHand} in cash; this loan pays out Rs {$net}. Ask the branch for a cash float first.");
            }
            $entryId = $this->payOut($loan, 'cash', null, Ledger::AGENT_CASH, true, $request);
            Database::insert('agent_cash_movements', [
                'movement_number' => Database::nextNumber('cash_movement'),
                'agent_id' => $user->id,
                'branch_id' => $loan['branch_id'],
                'type' => 'field_disbursement',
                'amount' => $net,
                'loan_id' => $loan['id'],
                'business_date' => Settings::today(),
                'journal_entry_id' => $entryId,
                'created_by' => $user->id,
            ]);
        });
        return $this->show($request);
    }

    /** Schedule, journal and status for a disbursement; $creditAccount is where the money left from. Returns the journal entry. */
    private function payOut(array $loan, string $method, ?string $reference, string $creditAccount, bool $field, Request $request): int
    {
        $user = Auth::user();
        if ($loan['status'] !== 'approved') {
            throw HttpException::conflict("Only approved loans can be disbursed (this one is {$loan['status']}).");
        }
        $date = Settings::today();
        $firstDue = LoanService::createSchedule((int) $loan['id'], LoanService::terms($loan), $loan['principal'], (int) $loan['tenure'], $date);
        $entryId = Ledger::post($date, (int) $loan['branch_id'], 'loan_disbursement', (int) $loan['id'], "Disbursement of {$loan['loan_number']}" . ($field ? ' (in the field)' : ''), [
            [Ledger::LOAN_RECEIVABLE, Money::fmt($loan['principal']), '0'],
            [$creditAccount, '0', Money::fmt($loan['net_disbursement'])],
            [Ledger::FEE_INCOME, '0', Money::fmt($loan['processing_fee'])],
            [Ledger::GST_PAYABLE, '0', Money::fmt($loan['tax_on_fee'])],
        ], $user->id);
        Database::query(
            "UPDATE loans SET status = 'active', disbursed_by = ?, disbursed_at = UTC_TIMESTAMP(), disbursement_date = ?,
                    disbursement_method = ?, disbursed_in_field = ?, disbursement_reference = ?, disbursement_entry_id = ?, first_due_date = ?
              WHERE id = ?",
            [$user->id, $date, $method, $field ? 1 : 0, $reference, $entryId, $firstDue, $loan['id']],
        );
        Notifier::customer((int) $loan['customer_id'], 'loan.disbursed', 'Loan disbursed',
            'Rs ' . Money::fmt($loan['net_disbursement']) . " has been paid out for loan {$loan['loan_number']}. First installment due {$firstDue}.", "/loans/{$loan['id']}");
        Audit::log($request, $user->id, $field ? 'loan.disbursed_in_field' : 'loan.disbursed', 'loan', (int) $loan['id'], null,
            ['method' => $method, 'reference' => $reference, 'net' => $loan['net_disbursement'], 'journal_entry_id' => $entryId]);
        return $entryId;
    }

    public function settlementQuote(Request $request): Response
    {
        $loan = $this->findInScope($request->intParam('id'));
        if ($loan['status'] !== 'active') {
            throw HttpException::conflict('Settlement quotes are only available for active loans.');
        }
        return Response::json(LoanService::settlementQuote($loan, Settings::today()));
    }

    /** Account statement: disbursement, every payment and reversal, with the running principal balance. */
    public function statement(Request $request): Response
    {
        $loan = $this->findInScope($request->intParam('id'));
        if ($loan['disbursement_date'] === null) {
            throw HttpException::conflict('A statement is available once the loan is disbursed.');
        }
        $migrated = $loan['migrated_at'] !== null;
        $lines = [[
            'date' => $loan['disbursement_date'],
            'description' => $migrated ? 'Loan given (before KBSAVE)' : 'Loan disbursed (' . str_replace('_', ' ', (string) $loan['disbursement_method']) . ')',
            'reference' => $loan['disbursement_reference'], 'debit' => Money::fmt($loan['principal']), 'credit' => '0.00',
            'principal' => '0.00', 'interest' => '0.00', 'charges' => '0.00', 'sort' => ($migrated ? '0000' : $loan['disbursed_at']) . '0',
        ]];
        if ($migrated && Money::isPositive((string) $loan['opening_collected'])) {
            // Same split as when it was brought in: each installment's interest, then its principal, in order.
            $left = $loan['opening_collected'];
            $principalPart = $interestPart = '0';
            foreach (Database::all('SELECT principal_due, interest_due FROM loan_installments WHERE loan_id = ? ORDER BY number', [$loan['id']]) as $i) {
                $interest = Money::min($left, $i['interest_due']);
                $left = Money::sub($left, $interest);
                $principal = Money::min($left, $i['principal_due']);
                $left = Money::sub($left, $principal);
                [$interestPart, $principalPart] = [Money::add($interestPart, $interest), Money::add($principalPart, $principal)];
            }
            $lines[] = ['date' => substr((string) $loan['migrated_at'], 0, 10), 'description' => 'Collected before KBSAVE (brought in)', 'reference' => null,
                'debit' => '0.00', 'credit' => Money::fmt($loan['opening_collected']), 'principal' => Money::fmt($principalPart),
                'interest' => Money::fmt($interestPart), 'charges' => '0.00', 'sort' => $loan['migrated_at'] . '0'];
        }
        $payments = Database::all(
            "SELECT p.id, p.payment_number, p.amount, p.method, p.purpose, p.status, p.business_date, p.collected_at, p.reversed_at,
                    DATE(p.reversed_at) AS reversed_date, r.receipt_number,
                    COALESCE(SUM(CASE WHEN a.component = 'principal' THEN a.amount END), 0) AS principal,
                    COALESCE(SUM(CASE WHEN a.component = 'interest' THEN a.amount END), 0) AS interest,
                    COALESCE(SUM(CASE WHEN a.component IN ('late_fee','fees','prepayment_charge') THEN a.amount END), 0) AS charges
               FROM payments p LEFT JOIN payment_allocations a ON a.payment_id = p.id LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.loan_id = ? AND p.status IN ('confirmed','reversed') GROUP BY p.id ORDER BY p.id",
            [$loan['id']],
        );
        foreach ($payments as $p) {
            $label = ($p['purpose'] === 'settlement' ? 'Settlement' : 'Repayment') . ' — ' . str_replace('_', ' ', $p['method']);
            $lines[] = ['date' => $p['business_date'], 'description' => $label, 'reference' => $p['receipt_number'] ?? $p['payment_number'],
                'debit' => '0.00', 'credit' => Money::fmt($p['amount']), 'principal' => Money::fmt($p['principal']),
                'interest' => Money::fmt($p['interest']), 'charges' => Money::fmt($p['charges']), 'sort' => $p['collected_at'] . '1'];
            if ($p['status'] === 'reversed') {
                $lines[] = ['date' => $p['reversed_date'], 'description' => "Reversal of {$p['payment_number']}", 'reference' => $p['payment_number'],
                    'debit' => Money::fmt($p['amount']), 'credit' => '0.00', 'principal' => '-' . Money::fmt($p['principal']),
                    'interest' => '-' . Money::fmt($p['interest']), 'charges' => '-' . Money::fmt($p['charges']), 'sort' => $p['reversed_at'] . '2'];
            }
        }
        usort($lines, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
        // Disbursement opens the principal balance; each repayment reduces it by its principal share,
        // and a reversal adds that share back (its principal is negative).
        $balance = Money::fmt($loan['principal']);
        foreach ($lines as $i => &$line) {
            if ($i > 0) {
                $balance = Money::sub($balance, $line['principal']);
            }
            $line['principal_balance'] = Money::fmt($balance);
            unset($line['sort']);
        }
        unset($line);

        return Response::json([
            'loan' => array_intersect_key($loan, array_flip(['id', 'loan_number', 'customer_name', 'customer_number', 'customer_phone', 'branch_name', 'product_name', 'principal', 'tenure', 'installment_amount', 'disbursement_date', 'status'])),
            'lines' => $lines,
            'position' => LoanService::position((int) $loan['id'], Settings::today()),
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Requests a write-off of an NPA (executed only after approval by an Admin / Super Admin). */
    public function requestWriteOff(Request $request): Response
    {
        $user = Auth::user();
        $loan = $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $reason = $v->string('reason', true, 500, 10);
        $v->validate();
        if ($loan['status'] !== 'active' || !in_array($loan['asset_class'], ['substandard', 'doubtful', 'loss'], true)) {
            throw HttpException::conflict('Only active NPA loans (substandard, doubtful or loss) can be written off.');
        }
        $principal = (string) Database::value('SELECT COALESCE(SUM(principal_due - principal_paid), 0) FROM loan_installments WHERE loan_id = ?', [$loan['id']]);
        $id = Database::transaction(function () use ($user, $loan, $principal, $reason, $request) {
            $id = Approvals::request($user, 'loan_write_off', 'loan', (int) $loan['id'], (int) $loan['branch_id'], $principal,
                "Write off {$loan['loan_number']} ({$loan['customer_name']}, principal Rs " . Money::fmt($principal) . ", {$loan['dpd']} DPD)", [], $reason);
            Audit::log($request, $user->id, 'loan.write_off_requested', 'loan', (int) $loan['id'], null, ['approval_request_id' => $id], $reason);
            return $id;
        });
        return Response::json(['approval_request_id' => $id, 'status' => 'pending'], 202);
    }

    private function decide(Request $request, string $decision): Response
    {
        $user = Auth::user();
        $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $reason = $v->string('reason', $decision === 'rejected', 500, 3);
        $v->validate();

        Database::transaction(function () use ($request, $user, $decision, $reason) {
            $loan = PaymentService::lockLoan($request->intParam('id'));
            if ($loan['status'] !== 'pending_approval') {
                throw HttpException::conflict("Loan is already {$loan['status']}.");
            }
            // Maker-checker: the person who applied cannot decide.
            if ((int) $loan['applied_by'] === $user->id && !$user->maySelfApprove()) {
                throw HttpException::forbidden('You cannot approve or reject a loan you applied for. Ask another approver, or a Super Admin can allow single-owner approval in Settings → Security.');
            }
            // Delegated authority: above the limit, only Admin / Super Admin may approve.
            $limit = Settings::get('loan_approval_limit');
            if ($decision === 'approved' && !$user->can('loans.approve_unlimited') && Money::cmp($loan['principal'], $limit) > 0) {
                throw HttpException::forbidden('This loan exceeds your approval limit of Rs ' . Money::fmt($limit) . '. An Admin must approve it.');
            }
            Database::query(
                'UPDATE loans SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ? WHERE id = ?',
                [$decision, $user->id, $reason, $loan['id']],
            );
            Audit::log($request, $user->id, 'loan.' . $decision, 'loan', (int) $loan['id'], ['status' => 'pending_approval'], ['status' => $decision], $reason);
            Notifier::user((int) $loan['applied_by'], 'loan.' . $decision, "Loan {$loan['loan_number']} {$decision}", $reason ?? 'No note added.', "/loans/{$loan['id']}");
            Notifier::customer((int) $loan['customer_id'], 'loan.' . $decision, $decision === 'approved' ? 'Loan approved' : 'Loan application update',
                $decision === 'approved' ? "Your loan {$loan['loan_number']} for Rs " . Money::fmt($loan['principal']) . ' is approved. Disbursement follows shortly.'
                    : "Your loan application {$loan['loan_number']} was not approved. Please contact your branch.", "/loans/{$loan['id']}");
            if ($decision === 'approved') {
                Notifier::role('finance', null, 'loan.ready_to_disburse', 'Loan ready to disburse', "{$loan['loan_number']} — Rs " . Money::fmt($loan['net_disbursement']), "/loans/{$loan['id']}");
            }
        });

        return $this->show($request);
    }

    private function findInScope(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one(
            "SELECT l.*, c.full_name AS customer_name, c.customer_number, c.phone AS customer_phone, b.name AS branch_name,
                    p.name AS product_name, p.code AS product_code, v.version AS product_version,
                    ua.name AS applied_by_name, ud.name AS decided_by_name, ub.name AS disbursed_by_name
               FROM loans l
               JOIN customers c ON c.id = l.customer_id
               JOIN branches b ON b.id = l.branch_id
               JOIN loan_products p ON p.id = l.product_id
               JOIN loan_product_versions v ON v.id = l.product_version_id
               JOIN users ua ON ua.id = l.applied_by
               LEFT JOIN users ud ON ud.id = l.decided_by
               LEFT JOIN users ub ON ub.id = l.disbursed_by
              WHERE l.id = ? AND {$scope}",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Loan not found.');
    }

    private function customerInScope(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one("SELECT c.* FROM customers c WHERE c.id = ? AND {$scope}", array_merge([$id], $params))
            ?? throw HttpException::validation(['customer_id' => 'Customer not found.']);
    }

    private function quoteDate(string $date): string
    {
        // $date comes from Settings::today() (Y-m-d), never from user input.
        return "'" . preg_replace('/[^0-9-]/', '', $date) . "'";
    }
}
