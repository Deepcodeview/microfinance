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
use App\Services\Audit;
use App\Services\CustomerScope;
use App\Services\DepositService;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\Origination;
use App\Services\Settings;
use App\Services\Tenure;

/** Deposit accounts and payouts. Every write is behind the compliance gate (Settings::requireDeposits). */
final class DepositController
{
    public function index(Request $request): Response
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $where = [$scope];
        Paginator::filter($request, 'status', 'd.status', $where, $params, ['active', 'matured', 'pending_closure', 'closed']);
        Paginator::filter($request, 'customer_id', 'd.customer_id', $where, $params);
        Paginator::filter($request, 'scheme', 'd.scheme', $where, $params, ['recurring', 'fixed']);
        $search = trim((string) ($request->query['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(d.deposit_number LIKE ? OR c.full_name LIKE ? OR c.customer_number LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return Paginator::run(
            $request,
            'd.id, d.deposit_number, d.scheme, d.status, d.installment_amount, d.deposit_amount, d.opened_on, d.maturity_date,
             d.principal_balance, d.accrued_interest, c.id AS customer_id, c.full_name AS customer_name, c.customer_number, p.name AS product_name',
            'deposit_accounts d JOIN customers c ON c.id = d.customer_id JOIN deposit_products p ON p.id = d.product_id',
            implode(' AND ', $where),
            $params,
            ['opened_on' => 'd.id', 'maturity_date' => 'd.maturity_date', 'balance' => 'd.principal_balance'],
            'opened_on',
        );
    }

    public function show(Request $request): Response
    {
        $account = $this->findInScope($request->intParam('id'));
        $account['terms'] = DepositService::terms($account);
        $account['contributions'] = Database::all(
            "SELECT p.id, p.payment_number, p.amount, p.method, p.status, p.business_date, p.covers_from, p.covers_to, p.covers_count, u.name AS collected_by_name, r.receipt_number
               FROM payments p JOIN users u ON u.id = p.collected_by LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.deposit_account_id = ? ORDER BY p.id DESC",
            [$account['id']],
        );
        if ($account['scheme'] === 'recurring') {
            $account['contribution'] = DepositService::contributionStatus($account, Settings::today());
            $account['calendar'] = DepositService::calendar($account, Settings::today());
        }
        $account['accruals'] = Database::all('SELECT from_date, to_date, amount FROM deposit_accruals WHERE deposit_account_id = ? ORDER BY id DESC LIMIT 50', [$account['id']]);
        $account['payouts'] = Database::all(
            'SELECT o.*, ur.name AS requested_by_name, ud.name AS decided_by_name, up.name AS paid_by_name
               FROM payout_requests o JOIN users ur ON ur.id = o.requested_by
               LEFT JOIN users ud ON ud.id = o.decided_by LEFT JOIN users up ON up.id = o.paid_by
              WHERE o.deposit_account_id = ? ORDER BY o.id DESC',
            [$account['id']],
        );
        try {
            $account['payout_quote'] = in_array($account['status'], ['active', 'matured'], true)
                ? DepositService::payoutQuote($account, Settings::today()) : null;
            $account['payout_blocked_reason'] = null;
        } catch (HttpException $e) {
            $account['payout_quote'] = null;
            $account['payout_blocked_reason'] = $e->getMessage();
        }
        $account['deposits_enabled'] = Settings::depositsEnabled();
        return Response::json($account);
    }

    /** Statement: contributions, reversals, interest credited and payout, with running balance. */
    public function statement(Request $request): Response
    {
        $account = $this->findInScope($request->intParam('id'));
        $lines = [];
        foreach (Database::all(
            "SELECT p.payment_number, p.amount, p.method, p.status, p.business_date, p.covers_from, p.covers_to, p.covers_count, p.collected_at, p.reversed_at, DATE(p.reversed_at) AS reversed_date, r.receipt_number
               FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.deposit_account_id = ? AND p.status IN ('confirmed','reversed') ORDER BY p.id",
            [$account['id']],
        ) as $p) {
            $covers = DepositService::coversLabel($p['covers_from'], $p['covers_to'], $p['covers_count'] === null ? null : (int) $p['covers_count']);
            $lines[] = ['date' => $p['business_date'], 'description' => 'Contribution — ' . str_replace('_', ' ', $p['method']) . ($covers !== '' ? " {$covers}" : ''), 'reference' => $p['receipt_number'] ?? $p['payment_number'],
                'credit' => Money::fmt($p['amount']), 'debit' => '0.00', 'sort' => $p['collected_at'] . 'a'];
            if ($p['status'] === 'reversed') {
                $lines[] = ['date' => $p['reversed_date'], 'description' => "Reversal of {$p['payment_number']}", 'reference' => $p['payment_number'],
                    'credit' => '0.00', 'debit' => Money::fmt($p['amount']), 'sort' => $p['reversed_at'] . 'b'];
            }
        }
        foreach (Database::all("SELECT payout_number, net_amount, interest_amount, penalty_amount, paid_at, DATE(paid_at) AS paid_date FROM payout_requests WHERE deposit_account_id = ? AND status = 'paid'", [$account['id']]) as $o) {
            $credited = Money::sub($o['interest_amount'], $o['penalty_amount']);
            $lines[] = ['date' => $o['paid_date'], 'description' => 'Interest credited', 'reference' => $o['payout_number'], 'credit' => Money::fmt($credited), 'debit' => '0.00', 'sort' => $o['paid_at'] . 'c'];
            $lines[] = ['date' => $o['paid_date'], 'description' => 'Closure payout', 'reference' => $o['payout_number'], 'credit' => '0.00', 'debit' => Money::fmt($o['net_amount']), 'sort' => $o['paid_at'] . 'd'];
        }
        usort($lines, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
        $balance = '0';
        foreach ($lines as &$line) {
            $balance = Money::sub(Money::add($balance, $line['credit']), $line['debit']);
            $line['balance'] = Money::fmt($balance);
            unset($line['sort']);
        }
        unset($line);
        return Response::json([
            'account' => array_intersect_key($account, array_flip(['id', 'deposit_number', 'customer_name', 'customer_number', 'branch_name', 'product_name', 'scheme', 'opened_on', 'maturity_date', 'status', 'principal_balance', 'accrued_interest'])),
            'lines' => $lines,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function store(Request $request): Response
    {
        Settings::requireDeposits();
        $user = Auth::user();
        $v = new Validator($request->all());
        $customerId = $v->int('customer_id', 1, PHP_INT_MAX);
        $productId = $v->int('product_id', 1, PHP_INT_MAX);
        $amount = $v->decimal('amount', '1', '100000000', 2);
        $tenureValue = $v->int('tenure_value', 1, 3650, false);
        $tenureUnit = $v->in('tenure_unit', Tenure::UNITS, false);
        $v->validate();

        [$scope, $params] = CustomerScope::where($user);
        $customer = Database::one("SELECT c.* FROM customers c WHERE c.id = ? AND {$scope}", array_merge([$customerId], $params))
            ?? throw HttpException::validation(['customer_id' => 'Customer not found.']);
        if ($customer['status'] !== 'active') {
            throw HttpException::validation(['customer_id' => 'Customer is not active.']);
        }
        $checked = Origination::checkDeposit($productId, $amount, $tenureValue, $tenureUnit);
        $id = Database::transaction(fn () => Origination::openDeposit($customer, $checked, $amount, $user->id, $request));
        $request->params['id'] = (string) $id;
        return new Response(201, $this->show($request)->body);
    }

    public function requestPayout(Request $request): Response
    {
        Settings::requireDeposits();
        $user = Auth::user();
        $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $note = $v->string('note', false, 500);
        $v->validate();

        $payoutId = Database::transaction(function () use ($request, $user, $note) {
            $account = DepositService::lock($request->intParam('id'));
            $today = Settings::today();
            DepositService::accrue($account, (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'), $user->id);
            $quote = DepositService::payoutQuote($account, $today);
            if (!Money::isPositive($quote['net_amount'])) {
                throw HttpException::conflict('There is nothing to pay out on this account.');
            }
            $id = Database::insert('payout_requests', [
                'payout_number' => Database::nextNumber('payout'),
                'deposit_account_id' => $account['id'],
                'branch_id' => $account['branch_id'],
                'previous_status' => $account['status'],
                'request_note' => $note,
                'requested_by' => $user->id,
            ] + $quote);
            // Freezes accrual and further contributions while the request is open.
            Database::query("UPDATE deposit_accounts SET status = 'pending_closure' WHERE id = ?", [$account['id']]);
            Audit::log($request, $user->id, 'payout.requested', 'payout_request', $id, null, $quote, $note);
            Notifier::role('branch_manager', (int) $account['branch_id'], 'payout.requested', 'Payout awaiting approval',
                "{$account['deposit_number']}: Rs {$quote['net_amount']}", '/payouts?status=requested', $user->id);
            return $id;
        });
        return Response::json($this->payout($payoutId), 201);
    }

    public function payouts(Request $request): Response
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $where = [$scope];
        Paginator::filter($request, 'status', 'o.status', $where, $params, ['requested', 'approved', 'rejected', 'paid']);
        return Paginator::run(
            $request,
            'o.*, d.deposit_number, c.full_name AS customer_name, ur.name AS requested_by_name',
            'payout_requests o JOIN deposit_accounts d ON d.id = o.deposit_account_id JOIN customers c ON c.id = d.customer_id JOIN users ur ON ur.id = o.requested_by',
            implode(' AND ', $where),
            $params,
            ['requested_at' => 'o.id'],
            'requested_at',
        );
    }

    public function decidePayout(Request $request): Response
    {
        Settings::requireDeposits();
        $user = Auth::user();
        $v = new Validator($request->all());
        $decision = $v->in('decision', ['approved', 'rejected']);
        $reason = $v->string('reason', $decision === 'rejected', 500, 3);
        $v->validate();

        $id = $request->intParam('id');
        Database::transaction(function () use ($id, $user, $decision, $reason, $request) {
            $payout = $this->lockPayoutInScope($id);
            if ($payout['status'] !== 'requested') {
                throw HttpException::conflict("Payout is already {$payout['status']}.");
            }
            if ((int) $payout['requested_by'] === $user->id && !$user->maySelfApprove()) {
                throw HttpException::forbidden('You cannot approve or reject a payout you requested. Ask another approver, or a Super Admin can allow single-owner approval in Settings → Security.');
            }
            Database::query('UPDATE payout_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ? WHERE id = ?',
                [$decision, $user->id, $reason, $id]);
            if ($decision === 'rejected') {
                Database::query('UPDATE deposit_accounts SET status = ? WHERE id = ?', [$payout['previous_status'], $payout['deposit_account_id']]);
            }
            Notifier::user((int) $payout['requested_by'], 'payout.' . $decision, "Payout {$payout['payout_number']} {$decision}", $reason ?? 'No note added.', "/deposits/{$payout['deposit_account_id']}");
            Notifier::customer((int) Database::value('SELECT customer_id FROM deposit_accounts WHERE id = ?', [$payout['deposit_account_id']]),
                'payout.' . $decision, $decision === 'approved' ? 'Payout approved' : 'Payout request update',
                $decision === 'approved' ? 'Your payout of Rs ' . Money::fmt($payout['net_amount']) . ' is approved and will be paid shortly.' : "Your payout request was not approved: {$reason}",
                "/deposits/{$payout['deposit_account_id']}");
            Audit::log($request, $user->id, 'payout.' . $decision, 'payout_request', $id, ['status' => 'requested'], ['status' => $decision], $reason);
        });
        return Response::json($this->payout($id));
    }

    public function payPayout(Request $request): Response
    {
        Settings::requireDeposits();
        $user = Auth::user();
        $v = new Validator($request->all());
        $method = $v->in('method', PaymentController::ACCEPTED);
        $reference = $v->string('reference', $method !== null && $method !== 'cash', 100);
        $v->validate();

        $id = $request->intParam('id');
        Database::transaction(function () use ($id, $user, $method, $reference, $request) {
            $payout = $this->lockPayoutInScope($id);
            if ($payout['status'] !== 'approved') {
                throw HttpException::conflict('Only approved payouts can be paid.');
            }
            $account = DepositService::lock((int) $payout['deposit_account_id']);
            Ledger::assertFunds($method, Money::fmt($payout['net_amount']), (int) $payout['branch_id']);
            $accrued = $account['accrued_interest'];
            // Interest expense absorbs the penalty and any paise rounding between accrued (4 dp) and paid (2 dp).
            $adjustment = Money::add(Money::sub($accrued, $payout['interest_amount']), $payout['penalty_amount']);
            $lines = [
                [Ledger::CUSTOMER_DEPOSITS, Money::fmt($payout['principal_amount']), '0'],
                [Ledger::DEPOSIT_INTEREST_PAYABLE, $accrued, '0'],
                [Ledger::paymentAccount($method), '0', Money::fmt($payout['net_amount'])],
                Money::cmp($adjustment, '0') >= 0
                    ? [Ledger::DEPOSIT_INTEREST_EXPENSE, '0', $adjustment]
                    : [Ledger::DEPOSIT_INTEREST_EXPENSE, ltrim($adjustment, '-'), '0'],
            ];
            $entryId = Ledger::post(Settings::today(), (int) $payout['branch_id'], 'deposit_payout', $id,
                "Payout {$payout['payout_number']} for {$account['deposit_number']}", $lines, $user->id);

            Database::query(
                "UPDATE payout_requests SET status = 'paid', paid_by = ?, paid_at = UTC_TIMESTAMP(), payment_method = ?, payment_reference = ?, journal_entry_id = ? WHERE id = ?",
                [$user->id, $method, $reference, $entryId, $id],
            );
            Database::query(
                "UPDATE deposit_accounts SET status = 'closed', closed_at = UTC_TIMESTAMP(), principal_balance = 0, accrued_interest = 0,
                        interest_paid = interest_paid + ? WHERE id = ?",
                [Money::sub($payout['interest_amount'], $payout['penalty_amount']), $account['id']],
            );
            Notifier::customer((int) $account['customer_id'], 'payout.paid', 'Payout paid',
                'Rs ' . Money::fmt($payout['net_amount']) . " has been paid for {$account['deposit_number']}. The account is now closed.", "/deposits/{$account['id']}");
            Audit::log($request, $user->id, 'payout.paid', 'payout_request', $id, ['status' => 'approved'],
                ['status' => 'paid', 'method' => $method, 'reference' => $reference, 'net' => $payout['net_amount']]);
        });
        return Response::json($this->payout($id));
    }

    private function payout(int $id): array
    {
        return Database::one(
            'SELECT o.*, d.deposit_number, c.full_name AS customer_name FROM payout_requests o
               JOIN deposit_accounts d ON d.id = o.deposit_account_id JOIN customers c ON c.id = d.customer_id WHERE o.id = ?',
            [$id],
        );
    }

    private function lockPayoutInScope(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one(
            "SELECT o.* FROM payout_requests o JOIN deposit_accounts d ON d.id = o.deposit_account_id JOIN customers c ON c.id = d.customer_id
              WHERE o.id = ? AND {$scope} FOR UPDATE",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Payout not found.');
    }

    private function findInScope(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one(
            "SELECT d.*, c.full_name AS customer_name, c.customer_number, b.name AS branch_name, p.name AS product_name, p.code AS product_code,
                    v.version AS product_version, u.name AS opened_by_name
               FROM deposit_accounts d
               JOIN customers c ON c.id = d.customer_id
               JOIN branches b ON b.id = d.branch_id
               JOIN deposit_products p ON p.id = d.product_id
               JOIN deposit_product_versions v ON v.id = d.product_version_id
               JOIN users u ON u.id = d.opened_by
              WHERE d.id = ? AND {$scope}",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Deposit account not found.');
    }
}
