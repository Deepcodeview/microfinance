<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AgentCash;
use App\Services\Audit;
use App\Services\DepositService;
use App\Services\Money;
use App\Services\Settings;

/**
 * Operational and financial reports. Each returns {rows, summary}; ?format=csv downloads the rows.
 * Branch-scoped users always get their own branch only.
 */
final class ReportController
{
    private const REPORTS = [
        'demand-collection' => ['demandCollection', 'reports.view'],
        'missed-collections' => ['missedCollections', 'reports.view'],
        'daily-collection' => ['dailyCollection', 'reports.view'],
        'loan-portfolio' => ['loanPortfolio', 'reports.view'],
        'deposit-liability' => ['depositLiability', 'reports.view'],
        'agent-cash' => ['agentCash', 'reports.view'],
        'payouts' => ['payouts', 'reports.view'],
        'trial-balance' => ['trialBalance', 'accounting.view'],
        'journal' => ['journal', 'accounting.view'],
    ];

    public function show(Request $request): Response
    {
        $name = $request->param('name');
        [$method, $permission] = self::REPORTS[$name] ?? throw HttpException::notFound('Unknown report.');
        if (!Auth::user()->can($permission)) {
            throw HttpException::forbidden();
        }

        $result = $this->$method($request);
        if (($request->query['format'] ?? '') === 'csv') {
            Audit::log($request, Auth::user()->id, 'report.exported', 'report', null, null, ['report' => $name, 'filters' => $request->query]);
            return Response::csv($name . '-' . Settings::today() . '.csv', $result['rows']);
        }
        return Response::json($result);
    }

    /**
     * Per field agent for one day: what their customers owed (installments due that day, plus older
     * arrears) against what was collected, collection efficiency, missed loans, savings expected vs
     * collected, and collections recorded without a phone location. Customers count under their agent.
     */
    private function demandCollection(Request $request): array
    {
        $date = $this->date($request, 'date', Settings::today());
        [$branchSql, $params] = $this->branch('l.branch_id');
        $agentSql = 'LEFT JOIN agent_assignments aa ON aa.customer_id = c.id AND aa.valid_from <= ? AND (aa.valid_to IS NULL OR aa.valid_to >= ?)';
        $rows = [];
        // Returns the agent's row by reference so callers add to it in place.
        $row = function &(?string $agentId, string $agent) use (&$rows): array {
            $key = $agentId ?? 'none';
            $rows[$key] ??= ['agent' => $agent, 'loans_due' => 0, 'due_today_amount' => '0', 'arrears_amount' => '0', 'total_demand_amount' => '0',
                'loan_collected' => '0', 'efficiency_percent' => '—', 'loans_paid' => 0, 'loans_missed' => 0,
                'savings_expected' => '0', 'savings_collected' => '0', 'collections_without_location' => 0];
            return $rows[$key];
        };

        // Loans with something to collect on the day: an installment due that day, or unpaid older ones.
        $loans = Database::all(
            "SELECT l.id, aa.agent_user_id, COALESCE(a.name, 'No agent') AS agent,
                    SUM(CASE WHEN i.due_date = ? THEN i.principal_due + i.interest_due ELSE 0 END) AS due_today,
                    SUM(CASE WHEN i.due_date < ? AND i.status <> 'paid'
                             THEN i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid ELSE 0 END) AS arrears,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.loan_id = l.id AND p.business_date = ? AND p.status IN ('pending','confirmed')) AS collected
               FROM loans l JOIN customers c ON c.id = l.customer_id JOIN loan_installments i ON i.loan_id = l.id
               {$agentSql} LEFT JOIN users a ON a.id = aa.agent_user_id
              WHERE l.status IN ('active','closed') AND (i.due_date = ? OR (i.due_date < ? AND i.status <> 'paid')) AND {$branchSql}
              GROUP BY l.id, aa.agent_user_id, a.name",
            array_merge([$date, $date, $date, $date, $date, $date, $date], $params),
        );
        foreach ($loans as $loan) {
            $r = &$row($loan['agent_user_id'], $loan['agent']);
            $r['loans_due']++;
            $r['due_today_amount'] = Money::add($r['due_today_amount'], $loan['due_today']);
            $r['arrears_amount'] = Money::add($r['arrears_amount'], $loan['arrears']);
            $r['total_demand_amount'] = Money::add($r['total_demand_amount'], $loan['due_today'], $loan['arrears']);
            Money::isPositive($loan['collected']) ? $r['loans_paid']++ : $r['loans_missed']++;
            unset($r);
        }

        // Everything collected on the day from the agent's customers' loans — dues, arrears and advances.
        [$paidBranchSql, $paidParams] = $this->branch('p.branch_id');
        foreach (Database::all(
            "SELECT aa.agent_user_id, COALESCE(a.name, 'No agent') AS agent, SUM(p.amount) AS collected
               FROM payments p JOIN customers c ON c.id = p.customer_id
               {$agentSql} LEFT JOIN users a ON a.id = aa.agent_user_id
              WHERE p.business_date = ? AND p.account_type = 'loan' AND p.status IN ('pending','confirmed') AND {$paidBranchSql}
              GROUP BY aa.agent_user_id, a.name",
            array_merge([$date, $date, $date], $paidParams),
        ) as $paid) {
            $r = &$row($paid['agent_user_id'], $paid['agent']);
            $r['loan_collected'] = Money::add($r['loan_collected'], $paid['collected']);
            unset($r);
        }

        if (Settings::depositsEnabled()) {
            [$depositBranchSql, $depositParams] = $this->branch('d.branch_id');
            $accounts = Database::all(
                "SELECT d.*, aa.agent_user_id, COALESCE(a.name, 'No agent') AS agent
                   FROM deposit_accounts d JOIN customers c ON c.id = d.customer_id
                   {$agentSql} LEFT JOIN users a ON a.id = aa.agent_user_id
                  WHERE d.status = 'active' AND d.scheme = 'recurring' AND d.opened_on <= ? AND d.maturity_date > ? AND {$depositBranchSql}",
                array_merge([$date, $date, $date, $date], $depositParams),
            );
            foreach ($accounts as $account) {
                $status = DepositService::contributionStatus($account, $date);
                // Expected on this day = not collected earlier in its current period.
                if ($status['collected_on'] !== null && $status['collected_on'] < $date) {
                    continue;
                }
                $r = &$row($account['agent_user_id'], $account['agent']);
                $r['savings_expected'] = Money::add($r['savings_expected'], $account['installment_amount']);
                $r['savings_collected'] = Money::add($r['savings_collected'], (string) Database::value(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deposit_account_id = ? AND business_date = ? AND status IN ('pending','confirmed')",
                    [$account['id'], $date],
                ));
                unset($r);
            }
        }

        [$payBranchSql, $payParams] = $this->branch('p.branch_id');
        foreach (Database::all(
            "SELECT p.collected_by, u.name, COUNT(*) AS n FROM payments p JOIN users u ON u.id = p.collected_by
              WHERE p.business_date = ? AND p.latitude IS NULL AND p.status IN ('pending','confirmed')
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.role_key = 'collection_agent')
                AND {$payBranchSql}
              GROUP BY p.collected_by, u.name",
            array_merge([$date], $payParams),
        ) as $missing) {
            $r = &$row($missing['collected_by'], $missing['name']);
            $r['collections_without_location'] = (int) $missing['n'];
            unset($r);
        }

        $out = [];
        $totals = ['due' => '0', 'collected' => '0', 'arrears' => '0'];
        foreach ($rows as $r) {
            // Collection efficiency = collected ÷ total demand (due today + arrears).
            if (Money::isPositive($r['total_demand_amount'])) {
                $r['efficiency_percent'] = Money::round(bcdiv(bcmul($r['loan_collected'], '100', 10), $r['total_demand_amount'], 10));
            }
            $totals['due'] = Money::add($totals['due'], $r['total_demand_amount']);
            $totals['collected'] = Money::add($totals['collected'], $r['loan_collected']);
            $totals['arrears'] = Money::add($totals['arrears'], $r['arrears_amount']);
            $out[] = array_map(fn ($v) => is_string($v) && is_numeric($v) && str_contains($v, '.') ? Money::fmt($v) : $v, $r);
        }
        usort($out, fn ($a, $b) => strcmp($a['agent'], $b['agent']));
        return [
            'rows' => $out,
            'summary' => [
                'date' => $date,
                'total_demand_amount' => Money::fmt($totals['due']),
                'loan_collected' => Money::fmt($totals['collected']),
                'collection_efficiency_percent' => Money::isPositive($totals['due']) ? Money::round(bcdiv(bcmul($totals['collected'], '100', 10), $totals['due'], 10)) : '0.00',
                'arrears_amount' => Money::fmt($totals['arrears']),
                'loans_missed' => array_sum(array_column($out, 'loans_missed')),
            ],
        ];
    }

    /** Loans that had something due on the day and got no payment: who to follow up, with their agent. */
    private function missedCollections(Request $request): array
    {
        $date = $this->date($request, 'date', Settings::today());
        [$branchSql, $params] = $this->branch('l.branch_id');
        $rows = Database::all(
            "SELECT c.full_name AS customer, c.phone, l.loan_number, COALESCE(a.name, 'No agent') AS agent,
                    SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid) AS amount_due,
                    DATEDIFF(?, MIN(i.due_date)) AS days_late,
                    (SELECT v.outcome FROM collection_visits v WHERE v.customer_id = c.id AND v.visit_date = ? ORDER BY v.id DESC LIMIT 1) AS visit_outcome,
                    (SELECT MAX(p.business_date) FROM payments p WHERE p.loan_id = l.id AND p.status IN ('pending','confirmed')) AS last_paid_on
               FROM loans l JOIN customers c ON c.id = l.customer_id
               JOIN loan_installments i ON i.loan_id = l.id AND i.status <> 'paid' AND i.due_date <= ?
               LEFT JOIN agent_assignments aa ON aa.customer_id = c.id AND aa.valid_to IS NULL
               LEFT JOIN users a ON a.id = aa.agent_user_id
              WHERE l.status = 'active' AND {$branchSql}
                AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.loan_id = l.id AND p.business_date = ? AND p.status IN ('pending','confirmed'))
              GROUP BY l.id, c.id, a.name
              ORDER BY agent, days_late DESC",
            array_merge([$date, $date, $date, $date], $params),
        );
        foreach ($rows as &$r) {
            $r['amount_due'] = Money::fmt($r['amount_due']);
            $r['visit_outcome'] = $r['visit_outcome'] === null ? 'Not visited' : ucwords(str_replace('_', ' ', $r['visit_outcome']));
        }
        unset($r);
        return [
            'rows' => $rows,
            'summary' => [
                'date' => $date,
                'loans_missed' => count($rows),
                'amount_due' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['amount_due']), '0')),
                'not_visited' => count(array_filter($rows, fn ($r) => $r['visit_outcome'] === 'Not visited')),
            ],
        ];
    }

    private function dailyCollection(Request $request): array
    {
        $date = $this->date($request, 'date', Settings::today());
        [$branchSql, $params] = $this->branch('p.branch_id');
        $rows = Database::all(
            "SELECT u.name AS collector, b.name AS branch,
                    SUM(CASE WHEN p.status = 'confirmed' AND p.method = 'cash' THEN p.amount ELSE 0 END) AS cash,
                    SUM(CASE WHEN p.status = 'confirmed' AND p.method = 'upi' THEN p.amount ELSE 0 END) AS upi,
                    SUM(CASE WHEN p.status = 'confirmed' AND p.method IN ('bank_transfer','cheque') THEN p.amount ELSE 0 END) AS bank,
                    SUM(CASE WHEN p.status = 'confirmed' THEN p.amount ELSE 0 END) AS confirmed_total,
                    SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END) AS pending,
                    SUM(p.status = 'failed') AS failed_count,
                    SUM(p.status = 'reversed') AS reversed_count,
                    SUM(p.status = 'confirmed') AS receipts
               FROM payments p JOIN users u ON u.id = p.collected_by JOIN branches b ON b.id = p.branch_id
              WHERE p.business_date = ? AND {$branchSql}
              GROUP BY p.collected_by, p.branch_id ORDER BY confirmed_total DESC",
            array_merge([$date], $params),
        );
        [$branchSql2, $params2] = $this->branch('l.branch_id');
        $expected = (string) Database::value(
            "SELECT COALESCE(SUM(i.principal_due + i.interest_due), 0) FROM loan_installments i JOIN loans l ON l.id = i.loan_id
              WHERE i.due_date = ? AND l.status IN ('active','closed') AND {$branchSql2}",
            array_merge([$date], $params2),
        );
        $confirmed = array_reduce($rows, fn ($s, $r) => Money::add($s, $r['confirmed_total']), '0');
        return [
            'rows' => $rows,
            'summary' => [
                'date' => $date,
                'expected_due_today' => Money::fmt($expected),
                'confirmed' => Money::fmt($confirmed),
                'pending' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['pending']), '0')),
            ],
        ];
    }

    private function loanPortfolio(Request $request): array
    {
        $asOf = Settings::today();
        [$branchSql, $params] = $this->branch('l.branch_id');
        $loans = Database::all(
            "SELECT p.name AS product, b.name AS branch,
                    (SELECT COALESCE(SUM(i.principal_due - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS principal_outstanding,
                    (SELECT COALESCE(SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid), 0)
                       FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?) AS overdue_amount,
                    (SELECT DATEDIFF(?, MIN(i.due_date)) FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?) AS dpd
               FROM loans l JOIN loan_products p ON p.id = l.product_id JOIN branches b ON b.id = l.branch_id
              WHERE l.status = 'active' AND {$branchSql}",
            array_merge([$asOf, $asOf, $asOf], $params),
        );

        $buckets = ['current' => [0, 0], '1_30' => [1, 30], '31_60' => [31, 60], '61_90' => [61, 90], '90_plus' => [91, PHP_INT_MAX]];
        $groups = [];
        foreach ($loans as $loan) {
            $key = $loan['product'] . '|' . $loan['branch'];
            $groups[$key] ??= ['product' => $loan['product'], 'branch' => $loan['branch'], 'active_loans' => 0, 'principal_outstanding' => '0', 'overdue_amount' => '0']
                + array_fill_keys(array_map(fn ($b) => 'principal_' . $b, array_keys($buckets)), '0');
            $g = &$groups[$key];
            $g['active_loans']++;
            $g['principal_outstanding'] = Money::add($g['principal_outstanding'], $loan['principal_outstanding']);
            $g['overdue_amount'] = Money::add($g['overdue_amount'], $loan['overdue_amount']);
            $dpd = (int) ($loan['dpd'] ?? 0);
            foreach ($buckets as $name => [$lo, $hi]) {
                if ($dpd >= $lo && $dpd <= $hi) {
                    $g['principal_' . $name] = Money::add($g['principal_' . $name], $loan['principal_outstanding']);
                }
            }
            unset($g);
        }
        $rows = array_map(fn ($g) => array_map(fn ($v) => is_string($v) && is_numeric($v) ? Money::fmt($v) : $v, $g), array_values($groups));

        $total = array_reduce($rows, fn ($s, $r) => Money::add($s, $r['principal_outstanding']), '0');
        $par30 = array_reduce($rows, fn ($s, $r) => Money::add($s, $r['principal_31_60'], $r['principal_61_90'], $r['principal_90_plus']), '0');
        $par1 = Money::add($par30, array_reduce($rows, fn ($s, $r) => Money::add($s, $r['principal_1_30']), '0'));
        $par60 = array_reduce($rows, fn ($s, $r) => Money::add($s, $r['principal_61_90'], $r['principal_90_plus']), '0');
        $par90 = array_reduce($rows, fn ($s, $r) => Money::add($s, $r['principal_90_plus']), '0');
        $share = fn (string $part) => Money::isZero($total) ? '0.00' : Money::round(bcdiv(bcmul($part, '100', 10), $total, 10));
        return [
            'rows' => $rows,
            'summary' => [
                'as_of' => $asOf,
                'active_loans' => count($loans),
                'principal_outstanding' => Money::fmt($total),
                'overdue_amount' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['overdue_amount']), '0')),
                // Portfolio at risk > 30 days: share of principal in loans more than 30 days past due.
                'par_1_percent' => $share($par1),
                'par_30_percent' => $share($par30),
                'par_60_percent' => $share($par60),
                'par_90_percent' => $share($par90),
            ],
        ];
    }

    private function depositLiability(Request $request): array
    {
        $asOf = Settings::today();
        [$branchSql, $params] = $this->branch('d.branch_id');
        $rows = Database::all(
            "SELECT p.name AS product, d.scheme, COUNT(*) AS accounts,
                    SUM(d.principal_balance) AS principal_liability,
                    SUM(d.accrued_interest) AS accrued_interest_liability,
                    SUM(d.principal_balance + d.accrued_interest) AS total_liability,
                    SUM(CASE WHEN d.maturity_date <= DATE_ADD(?, INTERVAL 30 DAY) THEN d.principal_balance + d.accrued_interest ELSE 0 END) AS maturing_30_days,
                    SUM(CASE WHEN d.maturity_date <= DATE_ADD(?, INTERVAL 90 DAY) THEN d.principal_balance + d.accrued_interest ELSE 0 END) AS maturing_90_days
               FROM deposit_accounts d JOIN deposit_products p ON p.id = d.product_id
              WHERE d.status IN ('active','matured','pending_closure') AND {$branchSql}
              GROUP BY d.product_id, d.scheme ORDER BY p.name",
            array_merge([$asOf, $asOf], $params),
        );
        return [
            'rows' => $rows,
            'summary' => [
                'as_of' => $asOf,
                'total_liability' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['total_liability']), '0')),
                'deposits_enabled' => Settings::depositsEnabled(),
            ],
        ];
    }

    private function agentCash(Request $request): array
    {
        $from = $this->date($request, 'date_from', Settings::today());
        $to = $this->date($request, 'date_to', $from);
        [$branchSql, $params] = $this->branch('u.branch_id');
        $rows = Database::all(
            "SELECT u.id, u.name AS agent,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.collected_by = u.id AND p.method = 'cash' AND p.status = 'confirmed' AND p.business_date BETWEEN ? AND ?) AS cash_collected,
                    (SELECT COALESCE(SUM(h.expected_cash), 0) FROM cash_handovers h WHERE h.agent_id = u.id AND h.business_date BETWEEN ? AND ?) AS handed_over_expected,
                    (SELECT COALESCE(SUM(h.verified_cash), 0) FROM cash_handovers h WHERE h.agent_id = u.id AND h.business_date BETWEEN ? AND ?) AS verified,
                    (SELECT COALESCE(SUM(h.difference), 0) FROM cash_handovers h WHERE h.agent_id = u.id AND h.status = 'discrepancy') AS open_discrepancy,
                    (SELECT COALESCE(SUM(m.amount), 0) FROM agent_cash_movements m WHERE m.agent_id = u.id AND m.type = 'float_issued' AND m.business_date BETWEEN ? AND ?) AS float_received,
                    (SELECT COALESCE(SUM(m.amount), 0) FROM agent_cash_movements m WHERE m.agent_id = u.id AND m.type = 'field_disbursement' AND m.business_date BETWEEN ? AND ?) AS loans_paid_out
               FROM users u
              WHERE u.status = 'active' AND {$branchSql}
                AND (EXISTS (SELECT 1 FROM payments p WHERE p.collected_by = u.id AND p.method = 'cash')
                     OR EXISTS (SELECT 1 FROM agent_cash_movements m WHERE m.agent_id = u.id))
              ORDER BY u.name",
            array_merge([$from, $to, $from, $to, $from, $to, $from, $to, $from, $to], $params),
        );
        // Collections + float − field payouts not yet handed over (what the next handover expects).
        foreach ($rows as &$row) {
            $row['cash_in_hand_now'] = AgentCash::position((int) $row['id'], Settings::today())['in_hand'];
            unset($row['id']);
        }
        unset($row);
        return [
            'rows' => $rows,
            'summary' => [
                'date_from' => $from,
                'date_to' => $to,
                'cash_in_hand_now' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['cash_in_hand_now']), '0')),
                'open_discrepancy' => Money::fmt(array_reduce($rows, fn ($s, $r) => Money::add($s, $r['open_discrepancy']), '0')),
            ],
        ];
    }

    private function payouts(Request $request): array
    {
        [$branchSql, $params] = $this->branch('o.branch_id');
        $rows = Database::all(
            "SELECT o.status, COUNT(*) AS count, SUM(o.net_amount) AS net_amount, SUM(o.penalty_amount) AS penalties
               FROM payout_requests o WHERE {$branchSql} GROUP BY o.status ORDER BY FIELD(o.status, 'requested','approved','paid','rejected')",
            $params,
        );
        return ['rows' => $rows, 'summary' => []];
    }

    private function trialBalance(Request $request): array
    {
        $asOf = $this->date($request, 'as_of', Settings::today());
        [$branchSql, $params] = $this->branch('e.branch_id');
        $rows = Database::all(
            "SELECT a.code, a.name, a.type,
                    COALESCE(SUM(l.debit), 0) AS total_debit, COALESCE(SUM(l.credit), 0) AS total_credit
               FROM accounting_accounts a
               LEFT JOIN journal_lines l ON l.account_id = a.id
                     AND l.journal_entry_id IN (SELECT e.id FROM journal_entries e WHERE e.entry_date <= ? AND {$branchSql})
              GROUP BY a.id ORDER BY a.code",
            array_merge([$asOf], $params),
        );
        $debits = $credits = '0';
        foreach ($rows as &$row) {
            $net = Money::sub($row['total_debit'], $row['total_credit']);
            $row['debit_balance'] = Money::cmp($net, '0') > 0 ? Money::fmt($net) : '0.00';
            $row['credit_balance'] = Money::cmp($net, '0') < 0 ? Money::fmt(ltrim($net, '-')) : '0.00';
            $debits = Money::add($debits, $row['debit_balance']);
            $credits = Money::add($credits, $row['credit_balance']);
        }
        unset($row);
        return [
            'rows' => $rows,
            'summary' => [
                'as_of' => $asOf,
                'total_debit' => Money::fmt($debits),
                'total_credit' => Money::fmt($credits),
                'balanced' => Money::cmp($debits, $credits) === 0,
            ],
        ];
    }

    private function journal(Request $request): array
    {
        $from = $this->date($request, 'date_from', Settings::today());
        $to = $this->date($request, 'date_to', $from);
        [$branchSql, $params] = $this->branch('e.branch_id');
        $rows = Database::all(
            "SELECT e.entry_number, e.entry_date, e.source_type, e.description, a.code AS account_code, a.name AS account_name,
                    l.debit, l.credit, u.name AS posted_by
               FROM journal_entries e
               JOIN journal_lines l ON l.journal_entry_id = e.id
               JOIN accounting_accounts a ON a.id = l.account_id
               LEFT JOIN users u ON u.id = e.posted_by
              WHERE e.entry_date BETWEEN ? AND ? AND {$branchSql}
              ORDER BY e.id, l.id LIMIT 2000",
            array_merge([$from, $to], $params),
        );
        return ['rows' => $rows, 'summary' => ['date_from' => $from, 'date_to' => $to]];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function branch(string $column): array
    {
        $user = Auth::user();
        if ($user->customerScope() !== 'all') {
            return ["{$column} = ?", [$user->branchId ?? 0]];
        }
        return ['1 = 1', []];
    }

    private function date(Request $request, string $param, string $default): string
    {
        $value = $request->query[$param] ?? '';
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $default;
    }
}
