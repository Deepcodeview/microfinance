<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Accounts brought in from before KBSAVE (the manual register): a loan that started on a past date with
 * part of it already repaid, or a savings account opened earlier with a balance. The schedule runs from
 * the real start date; what was already collected is applied to the installments in order; and the
 * books take the position as one opening-balance entry (equity 3100) — no cash moves today, no late
 * fees are back-charged, and past interest is not booked as today's income.
 * Every method runs inside the caller's transaction.
 */
final class Migration
{
    /** 2026-03-15, 15-03-2026, 15/03/2026 or 15.03.2026 → Y-m-d, or null if it is not a real date. */
    public static function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** "₹1,25,000.50" / "125000" → "125000.50", or null. Empty = "0.00" when $emptyIsZero. */
    public static function money(mixed $value, bool $emptyIsZero = false): ?string
    {
        $value = str_replace(['₹', 'Rs.', 'Rs', ',', ' '], '', trim((string) $value));
        if ($value === '') {
            return $emptyIsZero ? '0.00' : null;
        }
        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) ? Money::fmt($value) : null;
    }

    /** "12 months", "45 days", "1 year", "6m" → {value, unit}, or null. */
    public static function tenure(mixed $value): ?array
    {
        if (!preg_match('/^\s*(\d{1,4})\s*([a-z]+)\s*$/i', (string) $value, $m)) {
            return null;
        }
        $unit = match (strtolower($m[2])) {
            'd', 'day', 'days' => 'days',
            'w', 'wk', 'week', 'weeks' => 'weeks',
            'm', 'mon', 'month', 'months' => 'months',
            'y', 'yr', 'year', 'years' => 'years',
            default => null,
        };
        return $unit === null ? null : ['value' => (int) $m[1], 'unit' => $unit];
    }

    /**
     * An existing loan: $principal lent on $start over $tenure installments of the plan's frequency, of which
     * $collected has been repaid so far. Plan limits and tenure choices are not enforced — old loans were
     * made before the current plans — but rate, frequency and method come from the plan.
     *
     * @return array<string, mixed> what the loan looks like now
     */
    public static function loan(array $customer, array $product, string $principal, int $tenure, string $start, string $collected, ?string $note, int $userId, Request $request): array
    {
        $today = Settings::today();
        if ($start > $today) {
            throw HttpException::validation(['start_date' => 'The start date cannot be in the future.']);
        }
        $terms = json_decode((string) Database::value('SELECT terms_json FROM loan_product_versions WHERE id = ?', [$product['current_version_id']]), true);
        $preview = LoanTerms::preview($terms, $principal, $tenure);
        $payable = Money::add($principal, $preview['total_interest']);
        if (Money::cmp($collected, $payable) > 0) {
            throw HttpException::validation(['amount_received' => 'More than the whole loan with interest (Rs ' . Money::fmt($payable) . ').']);
        }

        $id = Database::insert('loans', [
            'loan_number' => Database::nextNumber('loan'),
            'customer_id' => $customer['id'],
            'branch_id' => $customer['branch_id'],
            'product_id' => $product['id'],
            'product_version_id' => $product['current_version_id'],
            'principal' => $principal,
            'tenure' => $tenure,
            'installment_amount' => $preview['installment_amount'],
            'total_interest' => $preview['total_interest'],
            'processing_fee' => $preview['processing_fee'],
            'tax_on_fee' => $preview['tax_on_processing_fee'],
            'net_disbursement' => $preview['net_disbursement'],
            'purpose' => $note,
            'status' => 'active',
            'applied_by' => $userId,
            'decided_by' => $userId,
            'decided_at' => gmdate('Y-m-d H:i:s'),
            'disbursed_by' => $userId,
            'disbursed_at' => gmdate('Y-m-d H:i:s'),
            'disbursement_date' => $start,
            'migrated_at' => gmdate('Y-m-d H:i:s'),
            'opening_collected' => $collected,
        ]);
        $firstDue = LoanService::createSchedule($id, $terms, $principal, $tenure, $start);
        Database::query('UPDATE loans SET first_due_date = ? WHERE id = ?', [$firstDue, $id]);

        // What was collected pays the installments in order: each one's interest, then its principal.
        $left = $collected;
        $rows = Database::all('SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY number', [$id]);
        foreach ($rows as &$row) {
            $row['interest_paid'] = Money::min($left, $row['interest_due']);
            $left = Money::sub($left, $row['interest_paid']);
            $row['principal_paid'] = Money::min($left, $row['principal_due']);
            $left = Money::sub($left, $row['principal_paid']);
            $full = Money::cmp($row['interest_paid'], $row['interest_due']) === 0 && Money::cmp($row['principal_paid'], $row['principal_due']) === 0;
            $row['status'] = $full ? 'paid' : (Money::isPositive(Money::add($row['interest_paid'], $row['principal_paid'])) ? 'partial' : 'pending');
            // Installments already late when the loan is brought in are not charged a late fee for the past.
            Database::query(
                'UPDATE loan_installments SET interest_paid = ?, principal_paid = ?, status = ?, paid_at = ?, late_fee_applied_at = ? WHERE id = ?',
                [$row['interest_paid'], $row['principal_paid'], $row['status'], $full ? gmdate('Y-m-d H:i:s') : null, $row['due_date'] < $today ? gmdate('Y-m-d H:i:s') : null, $row['id']],
            );
        }
        unset($row);

        $loan = Database::one('SELECT * FROM loans WHERE id = ?', [$id]);
        $allPaid = !array_filter($rows, fn ($r) => $r['status'] !== 'paid');
        if ($allPaid) {
            Database::query("UPDATE loans SET status = 'closed', closed_at = UTC_TIMESTAMP(), closure_type = 'repaid' WHERE id = ?", [$id]);
            $loan['status'] = 'closed';
        }
        LoanAccounting::classify($loan, $today, $userId);
        $loan = Database::one('SELECT * FROM loans WHERE id = ?', [$id]);
        $npa = LoanAccounting::isNpa($loan);

        // Interest of installments already due was earned before KBSAVE (an NPA only counts what was received).
        $receivable = $advance = $principalOut = $overdue = '0';
        foreach ($rows as $row) {
            $accrued = '0';
            if ($row['due_date'] <= $today) {
                $accrued = $npa ? Money::min($row['interest_paid'], $row['interest_due']) : $row['interest_due'];
                Database::query('UPDATE loan_installments SET interest_accrued = ?, accrued_at = ? WHERE id = ?', [$accrued, $npa ? null : gmdate('Y-m-d H:i:s'), $row['id']]);
            }
            $receivable = Money::add($receivable, Money::max0(Money::sub($accrued, $row['interest_paid'])));
            $advance = Money::add($advance, Money::max0(Money::sub($row['interest_paid'], $accrued)));
            $principalOut = Money::add($principalOut, Money::sub($row['principal_due'], $row['principal_paid']));
            if ($row['due_date'] < $today) {
                $overdue = Money::add($overdue, Money::sub(Money::add($row['principal_due'], $row['interest_due']), Money::add($row['principal_paid'], $row['interest_paid'])));
            }
        }
        $net = Money::sub(Money::add($principalOut, $receivable), $advance);
        $lines = array_values(array_filter([
            [Ledger::LOAN_RECEIVABLE, $principalOut, '0'],
            [Ledger::INTEREST_RECEIVABLE, $receivable, '0'],
            [Ledger::ADVANCE_INTEREST, '0', $advance],
            Money::cmp($net, '0') >= 0 ? [Ledger::OPENING_BALANCES, '0', $net] : [Ledger::OPENING_BALANCES, Money::sub('0', $net), '0'],
        ], fn ($l) => Money::isPositive($l[1]) || Money::isPositive($l[2])));
        if ($lines !== []) {
            Ledger::post($today, (int) $customer['branch_id'], 'loan_migration', $id, "Opening balance of {$loan['loan_number']} (brought in, started {$start})", $lines, $userId);
        }
        Audit::log($request, $userId, 'loan.migrated', 'loan', $id, null, compact('principal', 'tenure', 'start', 'collected') + ['customer_id' => (int) $customer['id']]);

        $next = Database::value("SELECT MIN(due_date) FROM loan_installments WHERE loan_id = ? AND status <> 'paid' AND due_date >= ?", [$id, $today]);
        return [
            'loan_id' => $id,
            'loan_number' => $loan['loan_number'],
            'status' => $loan['status'],
            'asset_class' => $loan['asset_class'],
            'days_late' => (int) $loan['dpd'],
            'installment_amount' => Money::fmt($preview['installment_amount']),
            'installments_paid' => count(array_filter($rows, fn ($r) => $r['status'] === 'paid')) . ' of ' . count($rows),
            'principal_outstanding' => Money::fmt($principalOut),
            'overdue_amount' => Money::fmt($overdue),
            'next_due_date' => $next,
        ];
    }

    /**
     * An existing savings account opened on $opened with $balance saved so far (and $interest accrued so far).
     * Its maturity runs from the real opening date; the balance counts as installments already collected.
     *
     * @return array<string, mixed>
     */
    public static function deposit(array $customer, array $product, string $amount, ?array $tenure, string $opened, ?string $balance, string $interest, int $userId, Request $request): array
    {
        $today = Settings::today();
        if ($opened > $today) {
            throw HttpException::validation(['opened_on' => 'The opening date cannot be in the future.']);
        }
        $terms = json_decode((string) Database::value('SELECT terms_json FROM deposit_product_versions WHERE id = ?', [$product['current_version_id']]), true);
        $tenure = DepositTerms::chooseTenure($terms, $tenure['value'] ?? null, $tenure['unit'] ?? null);
        $fixed = $terms['scheme'] === 'fixed';
        $balance ??= $fixed ? $amount : '0.00';
        $maturity = Tenure::endDate($opened, $tenure);
        $lastAccrual = min((new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'), (new \DateTimeImmutable($maturity))->modify('-1 day')->format('Y-m-d'));

        $id = Database::insert('deposit_accounts', [
            'deposit_number' => Database::nextNumber('deposit'),
            'customer_id' => $customer['id'],
            'branch_id' => $customer['branch_id'],
            'product_id' => $product['id'],
            'product_version_id' => $product['current_version_id'],
            'scheme' => $terms['scheme'],
            'installment_amount' => $fixed ? null : $amount,
            'deposit_amount' => $fixed ? $amount : null,
            'opened_on' => $opened,
            'maturity_date' => $maturity,
            'tenure_value' => $tenure['value'],
            'tenure_unit' => $tenure['unit'],
            'status' => $maturity <= $today ? 'matured' : 'active',
            'principal_balance' => $balance,
            'accrued_interest' => $interest,
            'last_accrual_date' => $lastAccrual,
            'opened_by' => $userId,
            'migrated_at' => gmdate('Y-m-d H:i:s'),
            'opening_balance' => $balance,
        ]);
        $total = Money::add($balance, $interest);
        if (Money::isPositive($total)) {
            $number = Database::value('SELECT deposit_number FROM deposit_accounts WHERE id = ?', [$id]);
            Ledger::post($today, (int) $customer['branch_id'], 'deposit_migration', $id, "Opening balance of {$number} (brought in, opened {$opened})", array_values(array_filter([
                [Ledger::OPENING_BALANCES, $total, '0'],
                [Ledger::CUSTOMER_DEPOSITS, '0', $balance],
                [Ledger::DEPOSIT_INTEREST_PAYABLE, '0', $interest],
            ], fn ($l) => Money::isPositive($l[1]) || Money::isPositive($l[2]))), $userId);
        }
        Audit::log($request, $userId, 'deposit.migrated', 'deposit_account', $id, null, compact('amount', 'opened', 'balance', 'interest') + ['customer_id' => (int) $customer['id'], 'tenure' => Tenure::label($tenure)]);

        $account = Database::one('SELECT * FROM deposit_accounts WHERE id = ?', [$id]);
        $due = !$fixed && $account['status'] === 'active' ? DepositService::contributionStatus($account, $today)['installments_due'] : 0;
        return [
            'deposit_account_id' => $id,
            'deposit_number' => $account['deposit_number'],
            'status' => $account['status'],
            'maturity_date' => $maturity,
            'balance' => Money::fmt($balance),
            'interest' => Money::fmt($interest),
            'installments_due_now' => $due,
        ];
    }
}
