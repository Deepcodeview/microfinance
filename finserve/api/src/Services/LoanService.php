<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;

/**
 * Loan schedule, late fees, payment allocation and settlement.
 * Callers hold the transaction and have locked the loan row (SELECT ... FOR UPDATE).
 */
final class LoanService
{
    /** @return array<string, mixed> The immutable product terms this loan was opened on (at its tenure's rate). */
    public static function terms(array $loan): array
    {
        $terms = json_decode((string) Database::value('SELECT terms_json FROM loan_product_versions WHERE id = ?', [$loan['product_version_id']]), true);
        return LoanTerms::forTenure($terms, (int) $loan['tenure']);
    }

    public static function addPeriods(string $start, string $frequency, int $n): string
    {
        $date = new DateTimeImmutable($start);
        // Quarterly and yearly schedules are whole months, so they share the month-end clamping.
        $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$frequency] ?? null;
        if ($months !== null) {
            $n *= $months;
            // Keep the same day of month, clamped to month end (31 Jan + 1 month = 28/29 Feb).
            $month = (int) $date->format('n') - 1 + $n;
            $year = (int) $date->format('Y') + intdiv($month, 12);
            $month = $month % 12 + 1;
            $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            return sprintf('%04d-%02d-%02d', $year, $month, min((int) $date->format('j'), $lastDay));
        }
        $days = ['daily' => 1, 'weekly' => 7, 'fortnightly' => 14][$frequency] * $n;
        return $date->modify("+{$days} days")->format('Y-m-d');
    }

    public static function createSchedule(int $loanId, array $terms, string $principal, int $tenure, string $disbursementDate): string
    {
        $preview = LoanTerms::preview($terms, Money::fmt($principal), $tenure);
        // Older product versions predate the holiday rule; they keep plain calendar dates.
        $adjust = ($terms['holiday_adjustment'] ?? 'none') === 'next_business_day';
        $dates = Calendar::dueDates($disbursementDate, $terms['repayment_frequency'], count($preview['schedule']), $adjust);
        $first = null;
        foreach ($preview['schedule'] as $row) {
            $due = $dates[$row['number'] - 1];
            $first ??= $due;
            Database::insert('loan_installments', [
                'loan_id' => $loanId,
                'number' => $row['number'],
                'due_date' => $due,
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
            ]);
        }
        return $first;
    }

    /** Charges a late fee once per installment that is unpaid past due date + grace period. */
    public static function applyLateFees(array $loan, string $asOf): void
    {
        if ($loan['status'] !== 'active') {
            return;
        }
        $terms = self::terms($loan);
        $rows = Database::all(
            "SELECT id, principal_due, principal_paid, interest_due, interest_paid, interest_waived
               FROM loan_installments
              WHERE loan_id = ? AND status <> 'paid' AND late_fee_applied_at IS NULL
                AND due_date < DATE_SUB(?, INTERVAL ? DAY)",
            [$loan['id'], $asOf, (int) $terms['grace_period_days']],
        );
        foreach ($rows as $row) {
            $fee = $terms['late_fee_type'] === 'fixed'
                ? Money::fmt($terms['late_fee_value'])
                : Money::percentOf(Money::add(self::principalOut($row), self::interestOut($row)), $terms['late_fee_value']);
            Database::query(
                'UPDATE loan_installments SET late_fee_due = ?, late_fee_applied_at = UTC_TIMESTAMP() WHERE id = ?',
                [$fee, $row['id']],
            );
        }
    }

    /** @return list<array<string, string>> */
    public static function installments(int $loanId, bool $lock = false): array
    {
        return Database::all(
            'SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY number' . ($lock ? ' FOR UPDATE' : ''),
            [$loanId],
        );
    }

    /** Outstanding position as of a date. @return array<string, mixed> */
    public static function position(int $loanId, string $asOf): array
    {
        $principal = $interest = $lateFees = $dueNow = $overdue = '0';
        $oldestOverdue = null;
        $next = null;
        foreach (self::installments($loanId) as $row) {
            if ($row['status'] === 'paid') {
                continue;
            }
            $out = Money::add(self::principalOut($row), self::interestOut($row), self::lateOut($row));
            $principal = Money::add($principal, self::principalOut($row));
            $interest = Money::add($interest, self::interestOut($row));
            $lateFees = Money::add($lateFees, self::lateOut($row));
            if ($row['due_date'] <= $asOf) {
                $dueNow = Money::add($dueNow, $out);
            }
            if ($row['due_date'] < $asOf) {
                $overdue = Money::add($overdue, $out);
                $oldestOverdue ??= $row['due_date'];
            }
            if ($next === null && $row['due_date'] >= $asOf) {
                $next = ['number' => (int) $row['number'], 'due_date' => $row['due_date'], 'amount' => Money::fmt($out)];
            }
        }
        $dpd = $oldestOverdue === null ? 0 : (int) (new DateTimeImmutable($oldestOverdue))->diff(new DateTimeImmutable($asOf))->days;

        return [
            'principal_outstanding' => Money::fmt($principal),
            'interest_outstanding' => Money::fmt($interest),
            'late_fees_outstanding' => Money::fmt($lateFees),
            'total_outstanding' => Money::fmt(Money::add($principal, $interest, $lateFees)),
            'due_now' => Money::fmt($dueNow),
            'overdue_amount' => Money::fmt($overdue),
            'days_past_due' => $dpd,
            'next_installment' => $next,
        ];
    }

    /** What it costs to close the loan today: dues so far + remaining principal + prepayment charge. */
    public static function settlementQuote(array $loan, string $asOf): array
    {
        $terms = self::terms($loan);
        $dues = $futurePrincipal = $futureInterest = '0';
        foreach (self::installments((int) $loan['id']) as $row) {
            if ($row['status'] === 'paid') {
                continue;
            }
            if ($row['due_date'] <= $asOf) {
                $dues = Money::add($dues, self::principalOut($row), self::interestOut($row), self::lateOut($row));
            } else {
                $futurePrincipal = Money::add($futurePrincipal, self::principalOut($row));
                $futureInterest = Money::add($futureInterest, self::interestOut($row));
                $dues = Money::add($dues, self::lateOut($row));
            }
        }
        $charge = Money::percentOf($futurePrincipal, $terms['prepayment_charge_percent']);
        return [
            'allowed' => (bool) $terms['prepayment_allowed'],
            'dues_to_date' => Money::fmt($dues),
            'future_principal' => Money::fmt($futurePrincipal),
            'prepayment_charge' => $charge,
            'interest_waived' => Money::fmt($futureInterest),
            'settlement_amount' => Money::fmt(Money::add($dues, $futurePrincipal, $charge)),
            'as_of' => $asOf,
        ];
    }

    /**
     * Allocates a confirmed payment across installments and persists it.
     * Due installments are paid in the product's allocation order (e.g. late fee → interest → principal);
     * any remainder is an advance against future installments in date order.
     *
     * Every allocation records the ledger account it credits:
     *   principal → 1100; late fee → 4020; prepayment charge → 4010;
     *   interest on a due installment → 1110 for the accrued part, 4000 for anything not yet recognised
     *     (NPA cash-basis — and the installment's interest_accrued rises to match);
     *   interest on a future installment → 2300 advance interest;
     *   anything on a written-off loan → 4040 recovery income.
     *
     * @return array{allocations: list<array{0: ?int, 1: string, 2: string, 3: ?string}>}
     */
    public static function allocate(array $loan, string $amount, string $asOf, bool $settlement): array
    {
        $terms = self::terms($loan);
        $rows = self::installments((int) $loan['id'], true);
        $remaining = $amount;
        $allocations = [];
        $writtenOff = $loan['status'] === 'written_off';

        $take = function (int $i, string $component) use (&$rows, &$remaining, &$allocations, $asOf, $writtenOff): void {
            [$field, $out] = match ($component) {
                'late_fee' => ['late_fee_paid', self::lateOut($rows[$i])],
                'interest' => ['interest_paid', self::interestOut($rows[$i])],
                'principal' => ['principal_paid', self::principalOut($rows[$i])],
            };
            $portion = Money::min($remaining, $out);
            if (!Money::isPositive($portion)) {
                return;
            }
            $id = (int) $rows[$i]['id'];
            if ($writtenOff) {
                $allocations[] = [$id, $component, $portion, Ledger::BAD_DEBT_RECOVERY];
            } elseif ($component === 'interest' && $rows[$i]['due_date'] > $asOf) {
                $allocations[] = [$id, 'interest', $portion, Ledger::ADVANCE_INTEREST];
            } elseif ($component === 'interest') {
                $accruedUnpaid = Money::sub($rows[$i]['interest_accrued'], $rows[$i]['interest_paid']);
                $fromReceivable = Money::cmp($accruedUnpaid, '0') > 0 ? Money::min($portion, $accruedUnpaid) : '0';
                $cashBasis = Money::sub($portion, $fromReceivable);
                if (Money::isPositive($fromReceivable)) {
                    $allocations[] = [$id, 'interest', $fromReceivable, Ledger::INTEREST_RECEIVABLE];
                }
                if (Money::isPositive($cashBasis)) {
                    $allocations[] = [$id, 'interest', $cashBasis, Ledger::INTEREST_INCOME];
                    $rows[$i]['interest_accrued'] = Money::add($rows[$i]['interest_accrued'], $cashBasis);
                }
            } else {
                $allocations[] = [$id, $component, $portion, $component === 'principal' ? Ledger::LOAN_RECEIVABLE : Ledger::LATE_FEE_INCOME];
            }
            $rows[$i][$field] = Money::add($rows[$i][$field], $portion);
            $remaining = Money::sub($remaining, $portion);
        };

        $due = array_keys(array_filter($rows, fn ($r) => $r['status'] !== 'paid' && $r['due_date'] <= $asOf));
        $future = array_keys(array_filter($rows, fn ($r) => $r['status'] !== 'paid' && $r['due_date'] > $asOf));

        foreach ($terms['allocation_order'] as $component) {
            if ($component === 'fees') {
                continue; // No recurring fees on installments; processing fee is taken at disbursement.
            }
            foreach ($due as $i) {
                $take($i, $component);
            }
        }

        if ($settlement) {
            $quote = self::settlementQuote($loan, $asOf);
            foreach ($future as $i) {
                $take($i, 'late_fee');
                $take($i, 'principal');
            }
            if (Money::isPositive($quote['prepayment_charge'])) {
                $allocations[] = [null, 'prepayment_charge', $quote['prepayment_charge'], $writtenOff ? Ledger::BAD_DEBT_RECOVERY : Ledger::FEE_INCOME];
                $remaining = Money::sub($remaining, $quote['prepayment_charge']);
            }
            foreach ($future as $i) {
                $waive = self::interestOut($rows[$i]);
                if (Money::isPositive($waive)) {
                    $rows[$i]['interest_waived'] = Money::add($rows[$i]['interest_waived'], $waive);
                    $allocations[] = [(int) $rows[$i]['id'], 'interest_waived', $waive, null];
                }
                // Interest this customer paid in advance on a now-cancelled installment is earned at closure.
                $advance = Money::sub($rows[$i]['interest_paid'], $rows[$i]['interest_accrued']);
                if (!$writtenOff && Money::cmp($advance, '0') > 0) {
                    $rows[$i]['interest_accrued'] = $rows[$i]['interest_paid'];
                    $allocations[] = [(int) $rows[$i]['id'], 'interest_recognized', $advance, Ledger::INTEREST_INCOME];
                }
            }
        } else {
            foreach ($future as $i) {
                $take($i, 'late_fee');
                $take($i, 'interest');
                $take($i, 'principal');
            }
        }

        if (!Money::isZero($remaining)) {
            throw new \LogicException("Allocation left {$remaining} unallocated on loan {$loan['loan_number']}.");
        }

        self::persist($rows);
        if (!$writtenOff) {
            self::refreshLoanStatus($loan, $settlement ? 'settled' : 'repaid');
        }
        return ['allocations' => $allocations];
    }

    /**
     * Journal lines for a confirmed loan payment, built from its allocations (one line per account).
     *
     * @param list<array{0: ?int, 1: string, 2: string, 3: ?string}> $allocations
     */
    public static function paymentLines(string $debitAccount, string $amount, array $allocations): array
    {
        $credits = [];
        $debits = [$debitAccount => $amount];
        foreach ($allocations as [, $component, $value, $gl]) {
            if ($component === 'interest_waived') {
                continue;
            }
            if ($component === 'interest_recognized') {
                $debits[Ledger::ADVANCE_INTEREST] = Money::add($debits[Ledger::ADVANCE_INTEREST] ?? '0', $value);
            }
            $credits[$gl] = Money::add($credits[$gl] ?? '0', $value);
        }
        $lines = [];
        foreach ($debits as $code => $v) {
            $lines[] = [(string) $code, $v, '0']; // numeric-string keys come back as ints
        }
        foreach ($credits as $code => $v) {
            $lines[] = [(string) $code, '0', $v];
        }
        return $lines;
    }

    /**
     * Mirror of a loan payment for reversal, routed by today's state: interest that went to "advance"
     * on an installment that has since fallen due (and been recognised) now comes back out of receivable.
     */
    public static function reversalLines(int $paymentId, string $creditAccount, string $amount, string $asOf): array
    {
        $debits = [];
        $credits = [$creditAccount => $amount];
        foreach (Database::all(
            'SELECT a.component, a.amount, a.gl_account, i.due_date FROM payment_allocations a
               LEFT JOIN loan_installments i ON i.id = a.installment_id WHERE a.payment_id = ?',
            [$paymentId],
        ) as $a) {
            if ($a['component'] === 'interest_waived') {
                continue;
            }
            if ($a['component'] === 'interest_recognized') {
                $credits[Ledger::ADVANCE_INTEREST] = Money::add($credits[Ledger::ADVANCE_INTEREST] ?? '0', $a['amount']);
                $debits[Ledger::INTEREST_INCOME] = Money::add($debits[Ledger::INTEREST_INCOME] ?? '0', $a['amount']);
                continue;
            }
            $gl = $a['gl_account'];
            if ($gl === Ledger::ADVANCE_INTEREST && $a['due_date'] !== null && $a['due_date'] <= $asOf) {
                $gl = Ledger::INTEREST_RECEIVABLE;
            }
            $debits[$gl] = Money::add($debits[$gl] ?? '0', $a['amount']);
        }
        $lines = [];
        foreach ($debits as $code => $v) {
            $lines[] = [(string) $code, $v, '0']; // numeric-string keys come back as ints
        }
        foreach ($credits as $code => $v) {
            $lines[] = [(string) $code, '0', $v];
        }
        return $lines;
    }

    /** Undo a payment's allocations (used by reversal). */
    public static function unallocate(array $loan, int $paymentId): void
    {
        $rows = self::installments((int) $loan['id'], true);
        $byId = [];
        foreach ($rows as $i => $row) {
            $byId[(int) $row['id']] = $i;
        }
        $fields = ['late_fee' => 'late_fee_paid', 'interest' => 'interest_paid', 'principal' => 'principal_paid', 'interest_waived' => 'interest_waived'];
        foreach (Database::all('SELECT installment_id, component, amount, gl_account FROM payment_allocations WHERE payment_id = ? AND installment_id IS NOT NULL', [$paymentId]) as $a) {
            $i = $byId[(int) $a['installment_id']];
            // Cash-basis interest and closure recognition are undone along with the cash.
            if (($a['component'] === 'interest' && $a['gl_account'] === Ledger::INTEREST_INCOME) || $a['component'] === 'interest_recognized') {
                $rows[$i]['interest_accrued'] = Money::sub($rows[$i]['interest_accrued'], $a['amount']);
            }
            if (isset($fields[$a['component']])) {
                $field = $fields[$a['component']];
                $rows[$i][$field] = Money::sub($rows[$i][$field], $a['amount']);
            }
        }
        self::persist($rows);
        if ($loan['status'] === 'closed') {
            Database::query("UPDATE loans SET status = 'active', closed_at = NULL, closure_type = NULL WHERE id = ?", [$loan['id']]);
        }
    }

    /** Max that can be accepted as an ordinary installment payment (everything still owed). */
    public static function maxPayable(int $loanId, string $asOf): string
    {
        return self::position($loanId, $asOf)['total_outstanding'];
    }

    public static function principalOut(array $row): string
    {
        return Money::sub($row['principal_due'], $row['principal_paid']);
    }

    public static function interestOut(array $row): string
    {
        return Money::sub(Money::sub($row['interest_due'], $row['interest_paid']), $row['interest_waived']);
    }

    public static function lateOut(array $row): string
    {
        return Money::sub($row['late_fee_due'], $row['late_fee_paid']);
    }

    private static function persist(array $rows): void
    {
        foreach ($rows as $row) {
            $out = Money::add(self::principalOut($row), self::interestOut($row), self::lateOut($row));
            $paidAny = Money::isPositive(Money::add($row['principal_paid'], $row['interest_paid'], $row['late_fee_paid']));
            $status = Money::isZero($out) ? 'paid' : ($paidAny ? 'partial' : 'pending');
            Database::query(
                'UPDATE loan_installments
                    SET principal_paid = ?, interest_paid = ?, late_fee_paid = ?, interest_waived = ?, interest_accrued = ?, status = ?,
                        paid_at = CASE WHEN ? = \'paid\' THEN COALESCE(paid_at, UTC_TIMESTAMP()) ELSE NULL END
                  WHERE id = ?',
                [$row['principal_paid'], $row['interest_paid'], $row['late_fee_paid'], $row['interest_waived'], $row['interest_accrued'], $status, $status, $row['id']],
            );
        }
    }

    private static function refreshLoanStatus(array $loan, string $closureType): void
    {
        $open = (int) Database::value("SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND status <> 'paid'", [$loan['id']]);
        if ($open === 0) {
            Database::query(
                "UPDATE loans SET status = 'closed', closed_at = UTC_TIMESTAMP(), closure_type = ? WHERE id = ?",
                [$closureType, $loan['id']],
            );
        }
    }

    public static function assertActive(array $loan): void
    {
        // Written-off loans still accept recoveries (the customer still owes the money).
        if (!in_array($loan['status'], ['active', 'written_off'], true)) {
            throw HttpException::conflict("Loan {$loan['loan_number']} is {$loan['status']}; payments are only accepted on active or written-off loans.");
        }
    }
}
