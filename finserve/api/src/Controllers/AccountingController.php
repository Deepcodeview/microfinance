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
use App\Services\Approvals;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\LoanAccounting;
use App\Services\Money;
use App\Services\Settings;

/** General ledger views, financial statements, reconciliation, provisioning, controls and day-end close. */
final class AccountingController
{
    /** Where the business's money sits: the bank account and each branch's cash vault. */
    public function funds(Request $request): Response
    {
        return Response::json($this->fundsPosition());
    }

    /** @return array<string, mixed> */
    private function fundsPosition(): array
    {
        $branches = Database::all('SELECT id, code, name FROM branches ORDER BY id');
        foreach ($branches as &$b) {
            $b['cash'] = Money::fmt(Ledger::balance(Ledger::BRANCH_CASH, (int) $b['id']));
        }
        unset($b);
        return [
            'bank' => Money::fmt(Ledger::balance(Ledger::BANK)),
            'capital' => Money::fmt(Ledger::balance(Ledger::CAPITAL)),
            'branches' => $branches,
        ];
    }

    /**
     * Money in and between the business's own accounts, posted straight to the ledger:
     *  capital       owner puts money in (into the bank or a branch's cash)
     *  bank_to_cash  cash withdrawn from the bank for a branch (e.g. to pay loans in cash)
     *  cash_to_bank  branch cash deposited into the bank
     */
    public function moveFunds(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $type = $v->in('type', ['capital', 'bank_to_cash', 'cash_to_bank']);
        $amount = $v->decimal('amount', '1', '1000000000', 2);
        $branchId = $v->int('branch_id', 1, PHP_INT_MAX, $type !== 'capital' || $request->input('into') === 'cash');
        $into = $type === 'capital' ? ($v->in('into', ['bank', 'cash'], false) ?? 'bank') : null;
        $reference = $v->string('reference', false, 100);
        $note = $v->string('note', false, 500);
        $v->validate();

        $headOffice = (int) Database::value("SELECT id FROM branches WHERE code = 'HO'");
        $branchId ??= $headOffice ?: (int) Database::value('SELECT MIN(id) FROM branches');
        if (Database::value('SELECT id FROM branches WHERE id = ?', [$branchId]) === null) {
            throw HttpException::validation(['branch_id' => 'Branch not found.']);
        }
        $amount = Money::fmt($amount);
        [$lines, $description] = match ($type) {
            'capital' => [[[$into === 'cash' ? Ledger::BRANCH_CASH : Ledger::BANK, $amount, '0'], [Ledger::CAPITAL, '0', $amount]],
                "Capital introduced into " . ($into === 'cash' ? 'branch cash' : 'bank')],
            'bank_to_cash' => [[[Ledger::BRANCH_CASH, $amount, '0'], [Ledger::BANK, '0', $amount]], 'Cash withdrawn from bank to branch'],
            'cash_to_bank' => [[[Ledger::BANK, $amount, '0'], [Ledger::BRANCH_CASH, '0', $amount]], 'Branch cash deposited into bank'],
        };

        $entryId = Database::transaction(function () use ($type, $amount, $branchId, $lines, $description, $reference, $note, $user, $request) {
            if ($type === 'bank_to_cash') {
                Ledger::assertFunds('bank_transfer', $amount, $branchId);
            } elseif ($type === 'cash_to_bank') {
                Ledger::assertFunds('cash', $amount, $branchId);
            }
            $label = $description . ($reference ? " (ref {$reference})" : '');
            $id = Ledger::post(Settings::today(), $branchId, 'funds_' . $type, null, $label, $lines, $user->id);
            Audit::log($request, $user->id, 'funds.' . $type, 'journal_entry', $id, null, compact('amount', 'branchId', 'reference'), $note);
            return $id;
        });

        return Response::json(['journal_entry_id' => $entryId] + $this->fundsPosition(), 201);
    }

    public function accounts(Request $request): Response
    {
        $asOf = $this->date($request, 'as_of', Settings::today());
        return Response::json(['as_of' => $asOf, 'accounts' => $this->balances($asOf)]);
    }

    public function ledger(Request $request): Response
    {
        $code = $request->param('code');
        $account = Database::one('SELECT id, code, name, type, normal_balance FROM accounting_accounts WHERE code = ?', [$code])
            ?? throw HttpException::notFound('Account not found.');
        $to = $this->date($request, 'date_to', Settings::today());
        $from = $this->date($request, 'date_from', (new \DateTimeImmutable($to))->modify('-30 days')->format('Y-m-d'));
        $sign = $account['normal_balance'] === 'debit' ? 1 : -1;

        $opening = (string) Database::value(
            'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
              WHERE l.account_id = ? AND e.entry_date < ?',
            [$account['id'], $from],
        );
        $rows = Database::all(
            'SELECT e.entry_number, e.entry_date, e.source_type, e.source_id, e.description, l.debit, l.credit, u.name AS posted_by
               FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id LEFT JOIN users u ON u.id = e.posted_by
              WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id LIMIT 2000',
            [$account['id'], $from, $to],
        );
        $running = $opening;
        foreach ($rows as &$row) {
            $running = Money::add($running, Money::sub($row['debit'], $row['credit']));
            $row['balance'] = Money::fmt($sign > 0 ? $running : bcmul($running, '-1', 4));
        }
        unset($row);
        return Response::json([
            'account' => $account,
            'date_from' => $from,
            'date_to' => $to,
            'opening_balance' => Money::fmt($sign > 0 ? $opening : bcmul($opening, '-1', 4)),
            'closing_balance' => Money::fmt($sign > 0 ? $running : bcmul($running, '-1', 4)),
            'lines' => $rows,
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        $to = $this->date($request, 'date_to', Settings::today());
        $from = $this->date($request, 'date_from', substr($to, 0, 8) . '01');
        $rows = Database::all(
            "SELECT a.code, a.name, a.type, COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END), 0) AS amount
               FROM accounting_accounts a
               LEFT JOIN journal_lines l ON l.account_id = a.id
                     AND l.journal_entry_id IN (SELECT id FROM journal_entries WHERE entry_date BETWEEN ? AND ?)
              WHERE a.type IN ('income','expense') GROUP BY a.id ORDER BY a.code",
            [$from, $to],
        );
        $income = array_values(array_filter($rows, fn ($r) => $r['type'] === 'income'));
        $expense = array_values(array_filter($rows, fn ($r) => $r['type'] === 'expense'));
        $totalIncome = array_reduce($income, fn ($s, $r) => Money::add($s, $r['amount']), '0');
        $totalExpense = array_reduce($expense, fn ($s, $r) => Money::add($s, $r['amount']), '0');
        return Response::json([
            'date_from' => $from,
            'date_to' => $to,
            'income' => array_map(fn ($r) => ['amount' => Money::fmt($r['amount'])] + $r, $income),
            'expenses' => array_map(fn ($r) => ['amount' => Money::fmt($r['amount'])] + $r, $expense),
            'total_income' => Money::fmt($totalIncome),
            'total_expenses' => Money::fmt($totalExpense),
            'net_profit' => Money::fmt(Money::sub($totalIncome, $totalExpense)),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asOf = $this->date($request, 'as_of', Settings::today());
        $balances = $this->balances($asOf);
        $section = fn (string $type) => array_values(array_filter($balances, fn ($b) => $b['type'] === $type && !Money::isZero($b['balance'])));
        // Contra-assets (normal credit, e.g. loan-loss provision) reduce total assets.
        $assets = array_map(fn ($b) => $b + ['signed' => $b['normal_balance'] === 'credit' ? bcmul($b['balance'], '-1', 2) : $b['balance']], $section('asset'));
        $liabilities = $section('liability');
        $equity = $section('equity');
        $earnings = '0';
        foreach ($balances as $b) {
            if ($b['type'] === 'income') {
                $earnings = Money::add($earnings, $b['balance']);
            } elseif ($b['type'] === 'expense') {
                $earnings = Money::sub($earnings, $b['balance']);
            }
        }
        $totalAssets = array_reduce($assets, fn ($s, $b) => Money::add($s, $b['signed']), '0');
        $totalLiabilities = array_reduce($liabilities, fn ($s, $b) => Money::add($s, $b['balance']), '0');
        $totalEquity = Money::add(array_reduce($equity, fn ($s, $b) => Money::add($s, $b['balance']), '0'), $earnings);
        return Response::json([
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained_earnings' => Money::fmt($earnings),
            'total_assets' => Money::fmt($totalAssets),
            'total_liabilities' => Money::fmt($totalLiabilities),
            'total_equity' => Money::fmt($totalEquity),
            'balanced' => Money::cmp($totalAssets, Money::add($totalLiabilities, $totalEquity)) === 0,
        ]);
    }

    /** Manual journal → approval request (posted only when a second person approves). */
    public function requestJournal(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $description = $v->string('description', true, 255, 5);
        $reason = $v->string('reason', true, 500, 5);
        $branchId = $v->int('branch_id', 1, PHP_INT_MAX, false);
        $v->validate();
        $lines = Approvals::validateJournalLines($request->input('lines'));
        $branchId ??= $user->branchId ?? (int) Database::value("SELECT id FROM branches WHERE code = 'HO'");
        $total = array_reduce($lines, fn ($s, $l) => Money::add($s, $l['debit']), '0');

        $id = Database::transaction(function () use ($user, $branchId, $total, $description, $lines, $reason, $request) {
            $id = Approvals::request($user, 'manual_journal', null, null, $branchId, $total, "Manual journal: {$description}",
                ['branch_id' => $branchId, 'description' => $description, 'lines' => $lines], $reason);
            Audit::log($request, $user->id, 'journal.requested', 'approval_request', $id, null, ['lines' => $lines], $reason);
            return $id;
        });
        return Response::json(['approval_request_id' => $id, 'status' => 'pending'], 202);
    }

    public function unsettled(Request $request): Response
    {
        return Response::json(Database::all(
            "SELECT p.id, p.payment_number, p.amount, p.reference, p.business_date, c.full_name AS customer_name
               FROM payments p JOIN customers c ON c.id = p.customer_id
              WHERE p.method = 'upi' AND p.status = 'confirmed' AND p.settlement_id IS NULL ORDER BY p.business_date, p.id",
        ));
    }

    public function settlements(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        return Paginator::run($request, 's.*, u.name AS created_by_name', 'settlement_batches s JOIN users u ON u.id = s.created_by',
            implode(' AND ', $where), $params, ['created_at' => 's.id'], 'created_at');
    }

    /** UPI provider settlement: matched payments move from clearing to bank; provider fees are expensed. */
    public function settle(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $reference = $v->string('bank_reference', true, 100, 3);
        $date = $v->date('settlement_date');
        $fees = $v->decimal('fees', '0', '10000000', 2, false) ?? '0.00';
        $credited = $v->decimal('amount_credited', '0.01', '100000000', 2);
        $v->validate();
        $ids = $request->input('payment_ids');
        if (!is_array($ids) || $ids === [] || array_filter($ids, fn ($i) => !is_int($i)) !== []) {
            throw HttpException::validation(['payment_ids' => 'Select the payments included in this settlement.']);
        }

        $batchId = Database::transaction(function () use ($ids, $reference, $date, $fees, $credited, $user, $request) {
            if (Database::value('SELECT id FROM settlement_batches WHERE bank_reference = ?', [$reference]) !== null) {
                throw HttpException::validation(['bank_reference' => 'This bank reference has already been reconciled.']);
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $payments = Database::all("SELECT id, amount, branch_id FROM payments WHERE id IN ({$in}) AND method = 'upi' AND status = 'confirmed' AND settlement_id IS NULL FOR UPDATE", $ids);
            if (count($payments) !== count(array_unique($ids))) {
                throw HttpException::validation(['payment_ids' => 'Some payments are not confirmed, unsettled UPI payments.']);
            }
            $gross = array_reduce($payments, fn ($s, $p) => Money::add($s, $p['amount']), '0');
            $net = Money::sub($gross, $fees);
            if (Money::cmp($net, $credited) !== 0) {
                throw HttpException::validation(['amount_credited' => 'Bank credit ' . Money::fmt($credited) . ' does not match payments ' . Money::fmt($gross) . ' minus fees ' . Money::fmt($fees) . ' = ' . Money::fmt($net) . '.']);
            }
            $entryId = Ledger::post(Settings::today(), (int) $payments[0]['branch_id'], 'upi_settlement', null, "UPI settlement {$reference}", [
                [Ledger::BANK, Money::fmt($net), '0'],
                [Ledger::PROVIDER_CHARGES, Money::fmt($fees), '0'],
                [Ledger::PROVIDER_CLEARING, '0', Money::fmt($gross)],
            ], $user->id);
            $batchId = Database::insert('settlement_batches', [
                'batch_number' => Database::nextNumber('settlement'),
                'method' => 'upi',
                'bank_reference' => $reference,
                'settlement_date' => $date,
                'gross_amount' => $gross,
                'fees' => $fees,
                'net_amount' => $net,
                'payment_count' => count($payments),
                'journal_entry_id' => $entryId,
                'created_by' => $user->id,
            ]);
            Database::query("UPDATE payments SET settlement_id = ? WHERE id IN ({$in})", array_merge([$batchId], $ids));
            Audit::log($request, $user->id, 'settlement.created', 'settlement_batch', $batchId, null, ['gross' => Money::fmt($gross), 'fees' => $fees, 'reference' => $reference]);
            return $batchId;
        });
        return Response::json(Database::one('SELECT * FROM settlement_batches WHERE id = ?', [$batchId]), 201);
    }

    public function provisions(Request $request): Response
    {
        return Response::json([
            'held' => Money::fmt(LoanAccounting::provisionHeld()),
            'runs' => array_map(fn ($r) => ['detail' => json_decode($r['detail_json'], true)] + array_diff_key($r, ['detail_json' => 1]),
                Database::all('SELECT * FROM provision_runs ORDER BY id DESC LIMIT 20')),
            'rates' => [
                'standard' => Settings::get('provision_rate_standard'),
                'substandard' => Settings::get('provision_rate_substandard'),
                'doubtful' => Settings::get('provision_rate_doubtful'),
                'loss' => Settings::get('provision_rate_loss'),
            ],
        ]);
    }

    public function runProvisions(Request $request): Response
    {
        $user = Auth::user();
        $result = Database::transaction(function () use ($user, $request) {
            $result = LoanAccounting::provision(Settings::today(), $user->id);
            Audit::log($request, $user->id, 'provision.run', 'provision', null, null, $result);
            return $result;
        });
        return Response::json($result);
    }

    /** GL vs subledger proof. Every control account must equal the sum of the records it summarises. */
    public function controls(Request $request): Response
    {
        return Response::json(['checks' => self::runControls(), 'as_of' => Settings::today()]);
    }

    public static function runControls(): array
    {
        $gl = fn (string $code) => (string) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN a.normal_balance = 'debit' THEN l.debit - l.credit ELSE l.credit - l.debit END), 0)
               FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE a.code = ?",
            [$code],
        );
        $sub = fn (string $sql) => (string) (Database::value($sql) ?? '0');
        $checks = [
            ['Loan principal', Ledger::LOAN_RECEIVABLE, $sub("SELECT COALESCE(SUM(i.principal_due - i.principal_paid), 0) FROM loan_installments i JOIN loans l ON l.id = i.loan_id WHERE l.status IN ('active','closed')")],
            ['Interest receivable', Ledger::INTEREST_RECEIVABLE, $sub("SELECT COALESCE(SUM(GREATEST(i.interest_accrued - i.interest_paid, 0)), 0) FROM loan_installments i JOIN loans l ON l.id = i.loan_id WHERE l.status IN ('active','closed')")],
            ['Interest received in advance', Ledger::ADVANCE_INTEREST, $sub("SELECT COALESCE(SUM(GREATEST(i.interest_paid - i.interest_accrued, 0)), 0) FROM loan_installments i JOIN loans l ON l.id = i.loan_id WHERE l.status IN ('active','closed')")],
            ['Customer deposits', Ledger::CUSTOMER_DEPOSITS, $sub('SELECT COALESCE(SUM(principal_balance), 0) FROM deposit_accounts')],
            ['Deposit interest payable', Ledger::DEPOSIT_INTEREST_PAYABLE, $sub('SELECT COALESCE(SUM(accrued_interest), 0) FROM deposit_accounts')],
            ['Agent cash in hand', Ledger::AGENT_CASH, $sub(
                "SELECT (SELECT COALESCE(SUM(p.amount), 0) FROM payments p LEFT JOIN cash_handovers h ON h.id = p.handover_id
                          WHERE p.method = 'cash' AND p.status = 'confirmed' AND (p.handover_id IS NULL OR h.status = 'submitted'))
                      + (SELECT COALESCE(SUM(-difference), 0) FROM cash_handovers WHERE status = 'discrepancy' AND difference < 0)",
            )],
            ['UPI clearing (unsettled)', Ledger::PROVIDER_CLEARING, $sub("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE method = 'upi' AND status = 'confirmed' AND settlement_id IS NULL")],
        ];
        $out = [];
        foreach ($checks as [$name, $code, $subledger]) {
            $ledger = $gl($code);
            $diff = Money::sub($ledger, $subledger);
            $out[] = ['check' => $name, 'account' => $code, 'ledger' => Money::fmt($ledger), 'subledger' => Money::fmt($subledger),
                'difference' => Money::fmt($diff), 'ok' => Money::cmp(Money::round(ltrim($diff, '-'), 2), '0.01') < 0];
        }
        $unbalanced = (int) Database::value('SELECT COUNT(*) FROM (SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING SUM(debit) <> SUM(credit)) x');
        $out[] = ['check' => 'Unbalanced journal entries', 'account' => null, 'ledger' => (string) $unbalanced, 'subledger' => '0', 'difference' => (string) $unbalanced, 'ok' => $unbalanced === 0];
        $orphans = (int) Database::value("SELECT COUNT(*) FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.status IN ('confirmed','reversed') AND (p.journal_entry_id IS NULL OR r.id IS NULL)");
        $out[] = ['check' => 'Confirmed payments missing journal or receipt', 'account' => null, 'ledger' => (string) $orphans, 'subledger' => '0', 'difference' => (string) $orphans, 'ok' => $orphans === 0];
        return $out;
    }

    public function dayCloseStatus(Request $request): Response
    {
        $date = $this->date($request, 'business_date', Settings::today());
        return Response::json([
            'closed_through' => Settings::closedThrough(),
            'business_date' => $date,
            'checks' => $this->closeChecks($date),
            'history' => Database::all('SELECT d.business_date, d.closed_at, u.name AS closed_by_name FROM day_closes d JOIN users u ON u.id = d.closed_by ORDER BY d.business_date DESC LIMIT 15'),
        ]);
    }

    /** Day-end close: locks the books through the date once every blocking check passes. */
    public function closeDay(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $date = $v->date('business_date');
        $v->validate();
        $today = Settings::today();
        if ($date > $today) {
            throw HttpException::validation(['business_date' => 'You cannot close a future date.']);
        }
        $closed = Settings::closedThrough();
        if ($closed !== null && $date <= $closed) {
            throw HttpException::conflict("The books are already closed through {$closed}.");
        }
        $checks = $this->closeChecks($date);
        $failing = array_filter($checks, fn ($c) => !$c['ok'] && $c['blocking']);
        if ($failing !== []) {
            return new Response(422, ['error' => [
                'code' => 'DAY_CLOSE_BLOCKED', 'message' => 'Resolve the failing checks before closing.', 'request_id' => $request->id,
            ], 'data' => ['checks' => $checks]]);
        }
        Database::transaction(function () use ($date, $user, $checks, $request) {
            Database::insert('day_closes', ['business_date' => $date, 'closed_by' => $user->id, 'checks_json' => json_encode($checks)]);
            Settings::set('closed_through', $date, $user->id);
            Audit::log($request, $user->id, 'day.closed', 'day_close', null, null, ['business_date' => $date]);
        });
        return $this->dayCloseStatus($request);
    }

    private function closeChecks(string $date): array
    {
        $tb = Database::one('SELECT COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id WHERE e.entry_date <= ?', [$date]);
        $handovers = (int) Database::value("SELECT COUNT(*) FROM cash_handovers WHERE status = 'submitted' AND business_date <= ?", [$date]);
        $pending = (int) Database::value("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND business_date <= ?", [$date]);
        $unaccrued = (int) Database::value(
            "SELECT COUNT(*) FROM loan_installments i JOIN loans l ON l.id = i.loan_id
              WHERE l.status IN ('active','closed') AND l.asset_class NOT IN ('substandard','doubtful','loss') AND i.due_date <= ? AND i.accrued_at IS NULL",
            [$date],
        );
        $cashInHand = (string) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE method = 'cash' AND status = 'confirmed' AND handover_id IS NULL AND business_date <= ?", [$date]);
        $controls = self::runControls();
        $controlFailures = array_values(array_filter($controls, fn ($c) => !$c['ok']));
        return [
            ['check' => 'Trial balance balances', 'ok' => Money::cmp($tb['d'], $tb['c']) === 0, 'blocking' => true, 'detail' => 'Dr ' . Money::fmt($tb['d']) . ' / Cr ' . Money::fmt($tb['c'])],
            ['check' => 'GL agrees with subledgers', 'ok' => $controlFailures === [], 'blocking' => true, 'detail' => $controlFailures === [] ? 'All controls pass' : implode(', ', array_column($controlFailures, 'check'))],
            ['check' => 'Interest recognised for all due installments', 'ok' => $unaccrued === 0, 'blocking' => true, 'detail' => $unaccrued === 0 ? 'Up to date' : "{$unaccrued} installment(s) not accrued — run the daily job"],
            ['check' => 'No cash handovers awaiting verification', 'ok' => $handovers === 0, 'blocking' => true, 'detail' => "{$handovers} pending"],
            ['check' => 'No digital payments awaiting confirmation', 'ok' => $pending === 0, 'blocking' => true, 'detail' => "{$pending} pending"],
            ['check' => 'All collected cash handed over', 'ok' => Money::isZero($cashInHand), 'blocking' => false, 'detail' => 'Rs ' . Money::fmt($cashInHand) . ' still with collectors'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function balances(string $asOf): array
    {
        return array_map(fn ($r) => ['balance' => Money::fmt($r['balance'])] + $r, Database::all(
            "SELECT a.code, a.name, a.type, a.normal_balance,
                    COALESCE(SUM(CASE WHEN a.normal_balance = 'debit' THEN l.debit - l.credit ELSE l.credit - l.debit END), 0) AS balance,
                    COALESCE(SUM(l.debit), 0) AS total_debit, COALESCE(SUM(l.credit), 0) AS total_credit
               FROM accounting_accounts a
               LEFT JOIN journal_lines l ON l.account_id = a.id
                     AND l.journal_entry_id IN (SELECT id FROM journal_entries WHERE entry_date <= ?)
              GROUP BY a.id ORDER BY a.code",
            [$asOf],
        ));
    }

    private function date(Request $request, string $param, string $default): string
    {
        $value = $request->query[$param] ?? '';
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
    }
}
