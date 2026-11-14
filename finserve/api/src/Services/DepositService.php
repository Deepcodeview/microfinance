<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;

/** Deposit interest accrual, maturity and payout quotes. Callers hold the transaction and the row lock. */
final class DepositService
{
    /** @return array<string, mixed> */
    public static function terms(array $account): array
    {
        $terms = json_decode((string) Database::value('SELECT terms_json FROM deposit_product_versions WHERE id = ?', [$account['product_version_id']]), true);
        // The account's own tenure may carry its own rate (e.g. 1 year at 7%, 3 months at 5%).
        $tenure = ($account['tenure_value'] ?? null) !== null ? Tenure::find(DepositTerms::tenureOptions($terms), (int) $account['tenure_value'], (string) $account['tenure_unit']) : null;
        return Tenure::withRate($terms, $tenure);
    }

    /**
     * The recurring contribution period containing $date, counted from the day the account was opened:
     * daily = that day, weekly = each 7 days from opening, monthly = each month from opening.
     * index 0 is the first period, so index + 1 installments are due by the end of this period.
     *
     * @return array{start: string, end: string, index: int, frequency: string}
     */
    public static function period(array $account, string $date): array
    {
        $frequency = self::terms($account)['contribution_frequency'] ?? 'daily';
        $opened = new DateTimeImmutable($account['opened_on']);
        $days = max(0, (int) $opened->diff(new DateTimeImmutable($date))->format('%r%a'));
        if ($frequency === 'monthly') {
            $index = 0;
            while (LoanService::addPeriods($account['opened_on'], 'monthly', $index + 1) <= $date) {
                $index++;
            }
            $start = LoanService::addPeriods($account['opened_on'], 'monthly', $index);
            $end = LoanService::addPeriods($account['opened_on'], 'monthly', $index + 1);
        } else {
            $step = $frequency === 'weekly' ? 7 : 1;
            $index = intdiv($days, $step);
            $start = $opened->modify('+' . ($index * $step) . ' days')->format('Y-m-d');
            $end = $opened->modify('+' . (($index + 1) * $step) . ' days')->format('Y-m-d');
        }
        return ['start' => $start, 'end' => $end, 'index' => $index, 'frequency' => $frequency];
    }

    /** First day of recurring period $index (0 = the period the account was opened in). */
    public static function periodStart(array $account, int $index, ?string $frequency = null): string
    {
        $frequency ??= self::terms($account)['contribution_frequency'] ?? 'daily';
        if ($frequency === 'monthly') {
            return LoanService::addPeriods($account['opened_on'], 'monthly', $index);
        }
        $step = $frequency === 'weekly' ? 7 : 1;
        return (new DateTimeImmutable($account['opened_on']))->modify('+' . ($index * $step) . ' days')->format('Y-m-d');
    }

    /** Whole installments paid so far: contributions not reversed, plus what was saved before KBSAVE. */
    private static function installmentsPaid(array $account): int
    {
        $paid = (string) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deposit_account_id = ? AND status IN ('pending','confirmed')", [$account['id']]);
        $paid = Money::add($paid, $account['opening_balance'] ?? '0');
        return Money::isPositive($account['installment_amount'] ?? '0') ? (int) bcdiv($paid, $account['installment_amount'], 0) : 0;
    }

    /**
     * Where a recurring account stands on $date. Installments are owed one per period from opening and
     * paid oldest first, so a customer who skipped days owes those days before today's.
     *
     * @return array{period: array, collected_on: ?string, installments_due: int, missed_installments: int,
     *   installments_paid: int, installments_expected: int, unpaid_since: ?string, last_paid_on: ?string,
     *   amount_due: string, next_due_date: string}
     */
    public static function contributionStatus(array $account, string $date): array
    {
        $period = self::period($account, $date);
        $paidInstallments = self::installmentsPaid($account);
        $expected = $period['index'] + 1;
        $due = max(0, $expected - $paidInstallments);
        $lastPaid = Database::value(
            "SELECT MAX(business_date) FROM payments WHERE deposit_account_id = ? AND status IN ('pending','confirmed')",
            [$account['id']],
        );
        $nextUnpaid = self::periodStart($account, $paidInstallments, $period['frequency']);
        return [
            'period' => $period,
            // Set once everything owed up to this period is paid: the day of the latest collection.
            'collected_on' => $due === 0 ? ($lastPaid ?: null) : null,
            'installments_due' => $due,
            'missed_installments' => max(0, $period['index'] - $paidInstallments),
            'installments_paid' => $paidInstallments,
            'installments_expected' => $expected,
            'unpaid_since' => $due > 0 ? $nextUnpaid : null,
            'last_paid_on' => $lastPaid ?: null,
            'amount_due' => Money::fmt(bcmul((string) ($account['installment_amount'] ?? '0'), (string) $due, 4)),
            'next_due_date' => $nextUnpaid,
        ];
    }

    /**
     * Recurring savings take the fixed installment, or a multiple of it to catch up missed periods, while
     * anything is owed up to the current period; once paid up, nothing more until the next period (no
     * advance). Returns which periods the amount pays for, oldest unpaid first.
     *
     * @return array{covers_from?: string, covers_to?: string, covers_count?: int}
     */
    public static function assertContribution(array $account, string $amount, string $date): array
    {
        if ($account['scheme'] !== 'recurring') {
            return [];
        }
        $status = self::contributionStatus($account, $date);
        $label = ['daily' => 'today', 'weekly' => 'this week', 'monthly' => 'this month'][$status['period']['frequency']] ?? 'this period';
        if ($status['installments_due'] === 0) {
            $last = $status['last_paid_on'] ? " (last collected on {$status['last_paid_on']})" : '';
            throw new HttpException(409, 'ALREADY_COLLECTED',
                "{$account['deposit_number']} is paid up to {$label}{$last}. The next collection is due from {$status['next_due_date']}.");
        }
        $installment = $account['installment_amount'];
        $multiple = bcdiv($amount, $installment, 4);
        $count = (int) $multiple;
        $max = $status['installments_due'];
        if (bccomp($multiple, (string) $count, 4) !== 0 || $count < 1 || $count > $max) {
            throw HttpException::validation(['amount' => $max === 1
                ? 'Collect the installment of Rs ' . Money::fmt($installment) . '.'
                : 'Collect Rs ' . Money::fmt($installment) . " or a multiple for missed installments (up to {$max} × Rs " . Money::fmt($installment) . ' = Rs ' . Money::fmt(bcmul($installment, (string) $max, 4)) . ').']);
        }
        $first = $status['installments_paid'];
        return [
            'covers_from' => self::periodStart($account, $first, $status['period']['frequency']),
            'covers_to' => self::periodStart($account, $first + $count - 1, $status['period']['frequency']),
            'covers_count' => $count,
        ];
    }

    /** "for 26 Oct 2026" or "for 26 Oct – 29 Oct 2026 (4 installments)"; empty when not recorded. */
    public static function coversLabel(?string $from, ?string $to, ?int $count): string
    {
        if ($from === null || $to === null || !$count) {
            return '';
        }
        $f = new DateTimeImmutable($from);
        $t = new DateTimeImmutable($to);
        return $count === 1 ? 'for ' . $f->format('j M Y') : 'for ' . $f->format('j M') . ' – ' . $t->format('j M Y') . " ({$count} installments)";
    }

    /**
     * Every installment of a recurring account from opening to maturity: paid (on time / late / brought in
     * from the old register), missed, due now or upcoming. Payments fill the oldest installments first.
     *
     * @return list<array{date: string, state: string, paid_on: ?string}>
     */
    public static function calendar(array $account, string $date): array
    {
        if ($account['scheme'] !== 'recurring' || !Money::isPositive($account['installment_amount'] ?? '0')) {
            return [];
        }
        $frequency = self::terms($account)['contribution_frequency'] ?? 'daily';
        $current = self::period($account, min($date, $account['maturity_date']))['index'];
        $paidOn = array_fill(0, (int) bcdiv($account['opening_balance'] ?? '0', $account['installment_amount'], 0), 'opening');
        foreach (Database::all(
            "SELECT amount, business_date FROM payments WHERE deposit_account_id = ? AND status IN ('pending','confirmed') ORDER BY business_date, id",
            [$account['id']],
        ) as $p) {
            for ($k = (int) bcdiv($p['amount'], $account['installment_amount'], 0); $k > 0; $k--) {
                $paidOn[] = $p['business_date'];
            }
        }
        $days = [];
        for ($i = 0; ($start = self::periodStart($account, $i, $frequency)) < $account['maturity_date'] && $i < 4000; $i++) {
            $on = $paidOn[$i] ?? null;
            $state = match (true) {
                $on === 'opening' => 'brought_in',
                $on !== null => $on >= self::periodStart($account, $i + 1, $frequency) ? 'paid_late' : 'paid',
                $i < $current && $account['status'] === 'active' => 'missed',
                $i < $current => 'unpaid',
                $i === $current && $date < $account['maturity_date'] => 'due',
                default => 'upcoming',
            };
            $days[] = ['date' => $start, 'state' => $state, 'paid_on' => $on === 'opening' ? null : $on];
        }
        return $days;
    }

    /**
     * Accrues simple interest on the current balance from the day after last_accrual_date through $upTo,
     * never past the day before maturity. Flat plans spread their tenure rate over the tenure's days the
     * same way, and on the last day top up so the total is exactly rate × the balance deposited. Returns the 4-dp amount accrued; posts its own journal entry
     * unless $postJournal is false (the daily job batches entries per branch).
     */
    public static function accrue(array &$account, string $upTo, ?int $userId, bool $postJournal = true): string
    {
        $lastDay = (new DateTimeImmutable($account['maturity_date']))->modify('-1 day')->format('Y-m-d');
        $end = min($upTo, $lastDay);
        $from = (new DateTimeImmutable($account['last_accrual_date']))->modify('+1 day')->format('Y-m-d');
        if ($end < $from) {
            return '0';
        }
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($end))->days + 1;
        $terms = self::terms($account);
        $rate = bcdiv($terms['annual_interest_rate'], '100', 10);
        if (DepositTerms::isFlat($terms)) {
            $tenureDays = max(1, (int) (new DateTimeImmutable($account['opened_on']))->diff(new DateTimeImmutable($account['maturity_date']))->days);
            $amount = $end === $lastDay
                ? bcsub(bcadd(bcmul($account['principal_balance'], $rate, 10), '0.00005', 4), $account['accrued_interest'], 4)
                : bcadd(bcdiv(bcmul(bcmul($account['principal_balance'], $rate, 10), (string) $days, 10), (string) $tenureDays, 10), '0.00005', 4);
            if (bccomp($amount, '0', 4) < 0) {
                $amount = '0';
            }
        } else {
            $amount = bcadd(bcdiv(bcmul(bcmul($account['principal_balance'], $rate, 10), (string) $days, 10), '365', 10), '0.00005', 4);
        }

        $entryId = null;
        if ($postJournal && Money::isPositive($amount)) {
            // Dated today (the posting date), covering interest through $end — never back into a closed day.
            $entryId = Ledger::post(Settings::today(), (int) $account['branch_id'], 'deposit_accrual', (int) $account['id'],
                "Interest accrual {$account['deposit_number']} {$from} to {$end}", [
                    [Ledger::DEPOSIT_INTEREST_EXPENSE, $amount, '0'],
                    [Ledger::DEPOSIT_INTEREST_PAYABLE, '0', $amount],
                ], $userId);
        }
        if (Money::isPositive($amount)) {
            Database::insert('deposit_accruals', [
                'deposit_account_id' => $account['id'],
                'from_date' => $from,
                'to_date' => $end,
                'amount' => $amount,
                'journal_entry_id' => $entryId,
            ]);
        }
        $account['accrued_interest'] = Money::add($account['accrued_interest'], $amount);
        $account['last_accrual_date'] = $end;
        Database::query('UPDATE deposit_accounts SET accrued_interest = ?, last_accrual_date = ? WHERE id = ?',
            [$account['accrued_interest'], $end, $account['id']]);
        return $amount;
    }

    /** Payout figures if the account were closed on $asOf. Accrue first. */
    public static function payoutQuote(array $account, string $asOf): array
    {
        $terms = self::terms($account);
        $accrued = $account['accrued_interest'];

        if ($account['status'] === 'matured') {
            $type = 'maturity';
            $penalty = '0';
        } elseif ($account['status'] === 'active') {
            if (!$terms['premature_withdrawal_allowed']) {
                throw HttpException::conflict('This product does not allow premature withdrawal.');
            }
            $held = (int) (new DateTimeImmutable($account['opened_on']))->diff(new DateTimeImmutable($asOf))->days;
            if ($held < (int) $terms['lock_in_days']) {
                throw HttpException::conflict("Premature withdrawal is allowed after the {$terms['lock_in_days']}-day lock-in ({$held} days so far).");
            }
            $type = 'premature';
            // Interest is recomputed at (rate - penalty): the penalty is that share of the accrued interest.
            $rate = $terms['annual_interest_rate'];
            $penalty = Money::isPositive($rate)
                ? Money::round(bcdiv(bcmul($accrued, Money::min($terms['premature_penalty_percent'], $rate), 10), $rate, 10))
                : '0.00';
        } else {
            throw HttpException::conflict("Account is {$account['status']}.");
        }

        $interest = Money::round($accrued);
        $penalty = Money::min($penalty, $interest);
        return [
            'payout_type' => $type,
            'principal_amount' => Money::fmt($account['principal_balance']),
            'interest_amount' => $interest,
            'penalty_amount' => Money::fmt($penalty),
            'net_amount' => Money::fmt(Money::sub(Money::add($account['principal_balance'], $interest), $penalty)),
        ];
    }

    public static function lock(int $id): array
    {
        return Database::one('SELECT * FROM deposit_accounts WHERE id = ? FOR UPDATE', [$id])
            ?? throw HttpException::notFound('Deposit account not found.');
    }
}
