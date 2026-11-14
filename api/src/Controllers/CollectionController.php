<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\CustomerLocation;
use App\Services\CustomerScope;
use App\Services\DepositService;
use App\Services\GeoPoint;
use App\Services\Money;
use App\Services\Settings;

final class CollectionController
{
    /** The field agent's worklist: loan dues up to today, recurring deposits expecting a contribution, and today's visits. */
    public function today(Request $request): Response
    {
        $user = Auth::user();
        $date = Settings::today();
        [$scope, $params] = CustomerScope::where($user);

        $loans = Database::all(
            "SELECT l.id AS loan_id, l.loan_number, l.installment_amount, c.id AS customer_id, c.full_name, c.customer_number, c.phone,
                    c.address_line, c.city,
                    SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid) AS amount_due,
                    MIN(i.due_date) AS oldest_due_date,
                    SUM(i.due_date < ?) AS overdue_installments,
                    (SELECT MAX(p.business_date) FROM payments p WHERE p.loan_id = l.id AND p.status IN ('pending','confirmed')) AS last_payment_date,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.loan_id = l.id AND p.status IN ('pending','confirmed') AND p.business_date = ?) AS paid_today
               FROM loans l
               JOIN customers c ON c.id = l.customer_id
               JOIN loan_installments i ON i.loan_id = l.id AND i.status <> 'paid' AND i.due_date <= ?
              WHERE l.status = 'active' AND {$scope}
              GROUP BY l.id
              ORDER BY oldest_due_date, c.full_name",
            array_merge([$date, $date, $date], $params),
        );
        foreach ($loans as &$loan) {
            $loan['days_past_due'] = (int) (new \DateTimeImmutable($loan['oldest_due_date']))->diff(new \DateTimeImmutable($date))->days;
            $loan['amount_due'] = Money::fmt($loan['amount_due']);
        }
        unset($loan);

        $deposits = [];
        if (Settings::depositsEnabled()) {
            // Recurring savers are collected once per period counted from opening (every day, every 7 days,
            // every month). Those already collected this period stay listed, marked with the next due date.
            $accounts = Database::all(
                "SELECT d.*, c.full_name, c.customer_number, c.phone
                   FROM deposit_accounts d JOIN customers c ON c.id = d.customer_id
                  WHERE d.status = 'active' AND d.scheme = 'recurring' AND d.maturity_date > ? AND d.opened_on <= ? AND {$scope}
                  ORDER BY c.full_name",
                array_merge([$date, $date], $params),
            );
            foreach ($accounts as $account) {
                $status = DepositService::contributionStatus($account, $date);
                $deposits[] = [
                    'deposit_account_id' => $account['id'],
                    'deposit_number' => $account['deposit_number'],
                    'installment_amount' => $account['installment_amount'],
                    'customer_id' => $account['customer_id'],
                    'full_name' => $account['full_name'],
                    'customer_number' => $account['customer_number'],
                    'phone' => $account['phone'],
                    'frequency' => $status['period']['frequency'],
                    'collected_on' => $status['collected_on'],
                    'installments_due' => $status['installments_due'],
                    'missed_installments' => $status['missed_installments'],
                    'unpaid_since' => $status['unpaid_since'],
                    'last_paid_on' => $status['last_paid_on'],
                    'amount_due' => $status['amount_due'],
                    'next_due_date' => $status['next_due_date'],
                    'paid_this_period' => Money::fmt((string) Database::value(
                        "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deposit_account_id = ? AND status IN ('pending','confirmed') AND business_date >= ? AND business_date < ?",
                        [$account['id'], $status['period']['start'], $status['period']['end']],
                    )),
                ];
            }
            usort($deposits, fn ($a, $b) => [$a['collected_on'] !== null, $a['full_name']] <=> [$b['collected_on'] !== null, $b['full_name']]);
        }

        $visits = Database::all(
            'SELECT v.id, v.customer_id, v.loan_id, v.outcome, v.promise_date, v.notes, v.created_at, c.full_name
               FROM collection_visits v JOIN customers c ON c.id = v.customer_id
              WHERE v.agent_id = ? AND v.visit_date = ? ORDER BY v.id DESC',
            [$user->id, $date],
        );

        $collected = Database::all(
            "SELECT method, COUNT(*) AS count, SUM(amount) AS total FROM payments
              WHERE collected_by = ? AND business_date = ? AND status IN ('pending','confirmed') GROUP BY method",
            [$user->id, $date],
        );

        return Response::json([
            'business_date' => $date,
            'loans' => $loans,
            'deposits' => $deposits,
            'visits' => $visits,
            'collected_today' => $collected,
            'totals' => [
                'due' => Money::fmt(array_reduce($loans, fn ($s, $l) => Money::add($s, $l['amount_due']), '0')),
                'collected' => Money::fmt(array_reduce($collected, fn ($s, $c) => Money::add($s, $c['total']), '0')),
            ],
        ]);
    }

    /**
     * The collection map: customers in the user's scope with a pinned location and where they stand today
     * (overdue / due / collected / nothing due), plus who still has no pin.
     */
    public function map(Request $request): Response
    {
        $date = Settings::today();
        [$scope, $params] = CustomerScope::where(Auth::user());
        $rows = Database::all(
            "SELECT c.id, c.full_name, c.customer_number, c.phone, c.address_line, c.city, c.latitude, c.longitude,
                    (SELECT COALESCE(SUM(i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid), 0)
                       FROM loan_installments i JOIN loans l ON l.id = i.loan_id
                      WHERE l.customer_id = c.id AND l.status = 'active' AND i.status <> 'paid' AND i.due_date <= ?) AS due_amount,
                    (SELECT COUNT(*) FROM loan_installments i JOIN loans l ON l.id = i.loan_id
                      WHERE l.customer_id = c.id AND l.status = 'active' AND i.status <> 'paid' AND i.due_date < ?) AS overdue_installments,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                      WHERE p.customer_id = c.id AND p.business_date = ? AND p.status IN ('pending','confirmed')) AS collected_today
               FROM customers c
              WHERE c.status = 'active' AND {$scope}
              ORDER BY c.full_name LIMIT 1000",
            array_merge([$date, $date, $date], $params),
        );
        $savings = Settings::depositsEnabled();
        $located = [];
        $unlocated = [];
        foreach ($rows as $row) {
            $savingsDue = 0;
            if ($savings) {
                foreach (Database::all("SELECT * FROM deposit_accounts WHERE customer_id = ? AND status = 'active' AND scheme = 'recurring' AND maturity_date > ?", [$row['id'], $date]) as $account) {
                    $savingsDue += DepositService::contributionStatus($account, $date)['installments_due'] > 0 ? 1 : 0;
                }
            }
            $row['savings_due'] = $savingsDue;
            $row['status'] = match (true) {
                (int) $row['overdue_installments'] > 0 => 'overdue',
                Money::isPositive($row['due_amount']) || $savingsDue > 0 => 'due',
                Money::isPositive($row['collected_today']) => 'collected',
                default => 'none',
            };
            $row['due_amount'] = Money::fmt($row['due_amount']);
            $row['collected_today'] = Money::fmt($row['collected_today']);
            if ($row['latitude'] === null) {
                $unlocated[] = array_intersect_key($row, array_flip(['id', 'full_name', 'customer_number', 'city', 'status']));
            } else {
                $located[] = $row;
            }
        }
        return Response::json(['business_date' => $date, 'customers' => $located, 'unlocated' => $unlocated]);
    }

    public function storeVisit(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $customerId = $v->int('customer_id', 1, PHP_INT_MAX);
        $loanId = $v->int('loan_id', 1, PHP_INT_MAX, false);
        $outcome = $v->in('outcome', ['paid', 'partial', 'promised', 'not_available', 'refused', 'other']);
        $promiseDate = $v->date('promise_date', $outcome === 'promised');
        $notes = $v->string('notes', false, 500);
        $paymentId = $v->int('payment_id', 1, PHP_INT_MAX, false);
        $v->validate();
        $location = GeoPoint::fromInput($request->input('location'));

        $date = Settings::today();
        if ($promiseDate !== null && $promiseDate < $date) {
            throw HttpException::validation(['promise_date' => 'Promise date cannot be in the past.']);
        }
        [$scope, $params] = CustomerScope::where($user);
        if (Database::value("SELECT c.id FROM customers c WHERE c.id = ? AND {$scope}", array_merge([$customerId], $params)) === null) {
            throw HttpException::validation(['customer_id' => 'Customer not found.']);
        }
        if ($loanId !== null && Database::value('SELECT id FROM loans WHERE id = ? AND customer_id = ?', [$loanId, $customerId]) === null) {
            throw HttpException::validation(['loan_id' => 'Loan does not belong to this customer.']);
        }
        if ($paymentId !== null && Database::value('SELECT id FROM payments WHERE id = ? AND customer_id = ? AND collected_by = ?', [$paymentId, $customerId, $user->id]) === null) {
            throw HttpException::validation(['payment_id' => 'Payment not found.']);
        }

        $id = Database::transaction(function () use ($customerId, $user, $loanId, $date, $outcome, $promiseDate, $notes, $paymentId, $location, $request) {
            $id = Database::insert('collection_visits', [
                'customer_id' => $customerId,
                'agent_id' => $user->id,
                'loan_id' => $loanId,
                'visit_date' => $date,
                'outcome' => $outcome,
                'promise_date' => $promiseDate,
                'notes' => $notes,
                'payment_id' => $paymentId,
            ] + array_intersect_key($location ?? [], array_flip(['latitude', 'longitude', 'location_accuracy_m'])));
            if ($location !== null) {
                CustomerLocation::pinIfMissing($customerId, $location, $user->id);
            }
            Audit::log($request, $user->id, 'visit.recorded', 'customer', $customerId, null, compact('outcome', 'promiseDate', 'loanId'));
            return $id;
        });
        return Response::json(Database::one('SELECT * FROM collection_visits WHERE id = ?', [$id]), 201);
    }
}
