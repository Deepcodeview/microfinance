<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustomerScope;
use App\Services\Money;
use App\Services\Settings;

final class MetaController
{
    public function health(Request $request): Response
    {
        Database::value('SELECT 1');
        return Response::json(['status' => 'ok']);
    }

    public function branches(Request $request): Response
    {
        $user = Auth::user();
        if ($user->customerScope() === 'all') {
            return Response::json(Database::all("SELECT id, code, name FROM branches WHERE status = 'active' ORDER BY name"));
        }
        return Response::json(Database::all("SELECT id, code, name FROM branches WHERE id = ? AND status = 'active'", [$user->branchId]));
    }

    /** Chart data for the management dashboard, scoped like reports (all branches or own branch). */
    public function insights(Request $request): Response
    {
        $user = Auth::user();
        $today = Settings::today();
        [$scope, $params] = CustomerScope::where($user);

        $trend = Database::all(
            "SELECT p.business_date AS date,
                    SUM(CASE WHEN p.method = 'cash' THEN p.amount ELSE 0 END) AS cash,
                    SUM(CASE WHEN p.method <> 'cash' THEN p.amount ELSE 0 END) AS digital
               FROM payments p JOIN customers c ON c.id = p.customer_id
              WHERE p.status = 'confirmed' AND p.business_date > DATE_SUB(?, INTERVAL 30 DAY) AND {$scope}
              GROUP BY p.business_date ORDER BY p.business_date",
            array_merge([$today], $params),
        );

        $loans = Database::all(
            "SELECT l.id, l.status, p.name AS product, b.name AS branch,
                    (SELECT COALESCE(SUM(i.principal_due - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS principal_outstanding,
                    (SELECT DATEDIFF(?, MIN(i.due_date)) FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?) AS dpd
               FROM loans l JOIN customers c ON c.id = l.customer_id JOIN loan_products p ON p.id = l.product_id JOIN branches b ON b.id = l.branch_id
              WHERE {$scope}",
            array_merge([$today, $today], $params),
        );

        $buckets = ['Current' => [0, 0], '1–30 days' => [1, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], '90+ days' => [91, PHP_INT_MAX]];
        $aging = array_map(fn ($k) => ['bucket' => $k, 'loans' => 0, 'principal' => '0'], array_keys($buckets));
        $status = [];
        $products = [];
        $branches = [];
        foreach ($loans as $loan) {
            $status[$loan['status']] = ($status[$loan['status']] ?? 0) + 1;
            if ($loan['status'] !== 'active') {
                continue;
            }
            $dpd = (int) ($loan['dpd'] ?? 0);
            $i = 0;
            foreach ($buckets as [$lo, $hi]) {
                if ($dpd >= $lo && $dpd <= $hi) {
                    $aging[$i]['loans']++;
                    $aging[$i]['principal'] = Money::add($aging[$i]['principal'], $loan['principal_outstanding']);
                }
                $i++;
            }
            $products[$loan['product']] = Money::add($products[$loan['product']] ?? '0', $loan['principal_outstanding']);
            $branches[$loan['branch']]['outstanding'] = Money::add($branches[$loan['branch']]['outstanding'] ?? '0', $loan['principal_outstanding']);
            $branches[$loan['branch']]['active_loans'] = ($branches[$loan['branch']]['active_loans'] ?? 0) + 1;
        }
        foreach (Database::all(
            "SELECT b.name AS branch, SUM(p.amount) AS collected FROM payments p JOIN customers c ON c.id = p.customer_id JOIN branches b ON b.id = p.branch_id
              WHERE p.status = 'confirmed' AND p.business_date >= DATE_FORMAT(?, '%Y-%m-01') AND {$scope} GROUP BY b.id",
            array_merge([$today], $params),
        ) as $row) {
            $branches[$row['branch']]['collected_month'] = $row['collected'];
        }

        $collectors = Database::all(
            "SELECT u.name, COUNT(*) AS receipts, SUM(p.amount) AS total
               FROM payments p JOIN users u ON u.id = p.collected_by JOIN customers c ON c.id = p.customer_id
              WHERE p.status = 'confirmed' AND p.business_date >= DATE_FORMAT(?, '%Y-%m-01') AND {$scope}
              GROUP BY p.collected_by ORDER BY total DESC LIMIT 5",
            array_merge([$today], $params),
        );

        $monthly = Database::all(
            "SELECT m.month,
                    (SELECT COALESCE(SUM(l.principal), 0) FROM loans l JOIN customers c ON c.id = l.customer_id
                      WHERE DATE_FORMAT(l.disbursement_date, '%Y-%m') = m.month AND {$scope}) AS disbursed,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN customers c ON c.id = p.customer_id
                      WHERE p.status = 'confirmed' AND DATE_FORMAT(p.business_date, '%Y-%m') = m.month AND {$scope}) AS collected
               FROM (SELECT DATE_FORMAT(DATE_SUB(?, INTERVAL n MONTH), '%Y-%m') AS month
                       FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) x) m
              ORDER BY m.month",
            array_merge($params, $params, [$today]),
        );

        $assetClasses = Database::all(
            "SELECT l.asset_class, COUNT(DISTINCT l.id) AS loans, COALESCE(SUM(i.principal_due - i.principal_paid), 0) AS principal
               FROM loans l JOIN customers c ON c.id = l.customer_id JOIN loan_installments i ON i.loan_id = l.id
              WHERE l.status = 'active' AND {$scope} GROUP BY l.asset_class
              ORDER BY FIELD(l.asset_class, 'standard','sma_0','sma_1','sma_2','substandard','doubtful','loss')",
            $params,
        );
        $book = array_reduce($assetClasses, fn ($s, $r) => Money::add($s, $r['principal']), '0');
        $npa = array_reduce(array_filter($assetClasses, fn ($r) => in_array($r['asset_class'], ['substandard', 'doubtful', 'loss'], true)), fn ($s, $r) => Money::add($s, $r['principal']), '0');

        return Response::json([
            'asset_classes' => $assetClasses,
            'gross_npa' => Money::fmt($npa),
            'gross_npa_percent' => Money::isZero($book) ? '0.00' : Money::round(bcdiv(bcmul($npa, '100', 10), $book, 10)),
            'provision_held' => Money::fmt(\App\Services\LoanAccounting::provisionHeld()),
            'trend' => $trend,
            'aging' => array_map(fn ($a) => ['principal' => Money::fmt($a['principal'])] + $a, $aging),
            'loan_status' => array_map(fn ($k, $v) => ['status' => $k, 'count' => $v], array_keys($status), $status),
            'products' => array_map(fn ($k, $v) => ['product' => $k, 'outstanding' => Money::fmt($v)], array_keys($products), $products),
            'branches' => array_map(fn ($k, $v) => ['branch' => $k, 'outstanding' => Money::fmt($v['outstanding'] ?? '0'), 'active_loans' => $v['active_loans'] ?? 0, 'collected_month' => Money::fmt($v['collected_month'] ?? '0')], array_keys($branches), $branches),
            'top_collectors' => $collectors,
            'monthly' => $monthly,
        ]);
    }

    /** Role-scoped KPIs. Every figure is computed within the caller's customer scope. */
    public function dashboard(Request $request): Response
    {
        $user = Auth::user();
        $today = Settings::today();
        [$scope, $params] = CustomerScope::where($user);

        $customers = Database::one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(c.status = 'active'), 0) AS active,
                    COALESCE(SUM(c.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY), 0) AS new_last_30_days
               FROM customers c WHERE {$scope}",
            $params,
        );

        $loans = Database::one(
            "SELECT COUNT(*) AS active_loans,
                    COALESCE(SUM(i.principal_due - i.principal_paid), 0) AS principal_outstanding,
                    COALESCE(SUM(CASE WHEN i.due_date < ? AND i.status <> 'paid'
                        THEN i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid ELSE 0 END), 0) AS overdue_amount,
                    COUNT(DISTINCT CASE WHEN i.due_date < ? AND i.status <> 'paid' THEN l.id END) AS overdue_loans,
                    COALESCE(SUM(CASE WHEN i.due_date = ? THEN i.principal_due + i.interest_due ELSE 0 END), 0) AS due_today
               FROM loans l JOIN customers c ON c.id = l.customer_id JOIN loan_installments i ON i.loan_id = l.id
              WHERE l.status = 'active' AND {$scope}",
            array_merge([$today, $today, $today], $params),
        );
        $loans['active_loans'] = (int) Database::value("SELECT COUNT(*) FROM loans l JOIN customers c ON c.id = l.customer_id WHERE l.status = 'active' AND {$scope}", $params);

        $collections = Database::all(
            "SELECT p.method, SUM(p.amount) AS total, COUNT(*) AS count
               FROM payments p JOIN customers c ON c.id = p.customer_id
              WHERE p.business_date = ? AND p.status = 'confirmed' AND {$scope} GROUP BY p.method",
            array_merge([$today], $params),
        );
        $collectedToday = array_reduce($collections, fn ($s, $r) => Money::add($s, $r['total']), '0');

        $trend = Database::all(
            "SELECT p.business_date AS date, SUM(p.amount) AS total
               FROM payments p JOIN customers c ON c.id = p.customer_id
              WHERE p.status = 'confirmed' AND p.business_date > DATE_SUB(?, INTERVAL 14 DAY) AND {$scope}
              GROUP BY p.business_date ORDER BY p.business_date",
            array_merge([$today], $params),
        );

        $pending = [
            'loan_approvals' => $user->can('loans.approve')
                ? (int) Database::value("SELECT COUNT(*) FROM loans l JOIN customers c ON c.id = l.customer_id WHERE l.status = 'pending_approval' AND l.applied_by <> ? AND {$scope}", array_merge([$user->id], $params)) : 0,
            'disbursements' => $user->can('loans.disburse')
                ? (int) Database::value("SELECT COUNT(*) FROM loans l JOIN customers c ON c.id = l.customer_id WHERE l.status = 'approved' AND {$scope}", $params) : 0,
            'payment_confirmations' => $user->can('payments.confirm')
                ? (int) Database::value("SELECT COUNT(*) FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.status = 'pending' AND {$scope}", $params) : 0,
            'cash_verifications' => $user->can('cash.verify')
                ? (int) Database::value("SELECT COUNT(*) FROM cash_handovers h WHERE h.status = 'submitted'" . ($user->customerScope() === 'all' ? '' : ' AND h.branch_id = ?'), $user->customerScope() === 'all' ? [] : [$user->branchId]) : 0,
            'cash_discrepancies' => $user->can('cash.resolve')
                ? (int) Database::value("SELECT COUNT(*) FROM cash_handovers h WHERE h.status = 'discrepancy'" . ($user->customerScope() === 'all' ? '' : ' AND h.branch_id = ?'), $user->customerScope() === 'all' ? [] : [$user->branchId]) : 0,
            'payout_approvals' => $user->can('payouts.approve')
                ? (int) Database::value("SELECT COUNT(*) FROM payout_requests o JOIN deposit_accounts d ON d.id = o.deposit_account_id JOIN customers c ON c.id = d.customer_id WHERE o.status = 'requested' AND o.requested_by <> ? AND {$scope}", array_merge([$user->id], $params)) : 0,
            'approvals' => (int) Database::value(
                "SELECT COUNT(*) FROM approval_requests WHERE status = 'pending' AND requested_by <> ? AND type IN ('"
                . implode("','", array_keys(array_filter(\App\Services\Approvals::APPROVER_PERMISSION, fn ($p) => $user->can($p)))) . "', '')",
                [$user->id],
            ),
            'registrations' => $user->can('customers.approve')
                ? (int) Database::value("SELECT COUNT(*) FROM customers c WHERE c.status = 'pending' AND {$scope}", $params) : 0,
            'applications' => $user->can('applications.review')
                ? (int) Database::value("SELECT COUNT(*) FROM customer_applications a JOIN customers c ON c.id = a.customer_id WHERE a.status = 'submitted' AND {$scope}", $params) : 0,
            'payouts_to_pay' => $user->can('payouts.pay')
                ? (int) Database::value("SELECT COUNT(*) FROM payout_requests o JOIN deposit_accounts d ON d.id = o.deposit_account_id JOIN customers c ON c.id = d.customer_id WHERE o.status = 'approved' AND {$scope}", $params) : 0,
        ];

        $deposits = Settings::depositsEnabled() ? Database::one(
            "SELECT COUNT(*) AS accounts, COALESCE(SUM(d.principal_balance + d.accrued_interest), 0) AS liability
               FROM deposit_accounts d JOIN customers c ON c.id = d.customer_id WHERE d.status IN ('active','matured','pending_closure') AND {$scope}",
            $params,
        ) : null;

        $recent = Database::all(
            "SELECT p.id, p.payment_number, p.amount, p.method, p.status, p.collected_at, c.full_name AS customer_name,
                    COALESCE(l.loan_number, d.deposit_number) AS account_number
               FROM payments p JOIN customers c ON c.id = p.customer_id
               LEFT JOIN loans l ON l.id = p.loan_id LEFT JOIN deposit_accounts d ON d.id = p.deposit_account_id
              WHERE {$scope} ORDER BY p.id DESC LIMIT 8",
            $params,
        );

        $myCash = $user->can('payments.create') ? Money::fmt((string) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by = ? AND method = 'cash' AND status = 'confirmed' AND handover_id IS NULL",
            [$user->id],
        )) : null;

        return Response::json([
            'business_date' => $today,
            'customers' => array_map('intval', $customers),
            'loans' => [
                'active_loans' => $loans['active_loans'],
                'principal_outstanding' => Money::fmt($loans['principal_outstanding']),
                'overdue_amount' => Money::fmt($loans['overdue_amount']),
                'overdue_loans' => (int) $loans['overdue_loans'],
                'due_today' => Money::fmt($loans['due_today']),
            ],
            'collections_today' => ['total' => Money::fmt($collectedToday), 'by_method' => $collections],
            'collection_trend' => $trend,
            'pending' => $pending,
            'deposits' => $deposits,
            'my_cash_in_hand' => $myCash,
            'recent_payments' => $recent,
        ]);
    }
}
