<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;

/**
 * Income recognition and asset classification for loans (modelled on RBI IRACP norms; the business's
 * accountant must confirm the policy).
 *
 * Per installment, `interest_accrued` is the interest recognised as income so far. The ledger then holds:
 *   1110 Interest receivable  = Σ max(0, interest_accrued − interest_paid)   (earned, not yet received)
 *   2300 Advance interest     = Σ max(0, interest_paid − interest_accrued)   (received before it was earned)
 * for every loan that is not written off. ControlsController checks both identities against the GL.
 *
 * Performing loans recognise an installment's interest on its due date. NPAs recognise interest only
 * when it is received, and any accrued-but-unpaid interest is reversed out of income when a loan slips.
 * All methods run inside the caller's transaction with the loan row locked.
 */
final class LoanAccounting
{
    public const NPA_CLASSES = ['substandard', 'doubtful', 'loss'];

    public static function isNpa(array $loan): bool
    {
        return in_array($loan['asset_class'], self::NPA_CLASSES, true);
    }

    /** Recognises interest on installments that have fallen due. Returns the amount recognised. */
    public static function accrue(array $loan, string $asOf, ?int $userId): string
    {
        if (!in_array($loan['status'], ['active', 'closed'], true)) {
            return '0';
        }
        $npa = self::isNpa($loan);
        $rows = Database::all(
            'SELECT id, number, interest_due, interest_waived, interest_paid, interest_accrued FROM loan_installments
              WHERE loan_id = ? AND due_date <= ? AND accrued_at IS NULL ORDER BY number FOR UPDATE',
            [$loan['id'], $asOf],
        );
        $fromAdvance = $toReceivable = '0';
        foreach ($rows as $row) {
            $earned = Money::sub($row['interest_due'], $row['interest_waived']);
            // An NPA only recognises what it has actually received.
            $target = $npa ? Money::min($earned, $row['interest_paid']) : $earned;
            $recognise = Money::sub($target, $row['interest_accrued']);
            if (Money::cmp($recognise, '0') <= 0) {
                if (!$npa) {
                    Database::query('UPDATE loan_installments SET accrued_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
                }
                continue;
            }
            $advance = Money::min($recognise, self::max0(Money::sub($row['interest_paid'], $row['interest_accrued'])));
            $fromAdvance = Money::add($fromAdvance, $advance);
            $toReceivable = Money::add($toReceivable, Money::sub($recognise, $advance));
            Database::query(
                'UPDATE loan_installments SET interest_accrued = ?' . ($npa ? '' : ', accrued_at = UTC_TIMESTAMP()') . ' WHERE id = ?',
                [$target, $row['id']],
            );
        }
        $total = Money::add($fromAdvance, $toReceivable);
        if (Money::isPositive($total)) {
            Ledger::post($asOf, (int) $loan['branch_id'], 'loan_interest_accrual', (int) $loan['id'], "Interest recognised on {$loan['loan_number']}", [
                [Ledger::ADVANCE_INTEREST, $fromAdvance, '0'],
                [Ledger::INTEREST_RECEIVABLE, $toReceivable, '0'],
                [Ledger::INTEREST_INCOME, '0', $total],
            ], $userId);
        }
        return $total;
    }

    /**
     * Days-past-due and IRACP class. NPAs are only upgraded once every overdue amount is cleared.
     * When a loan becomes (or stays) an NPA, unrealised interest is reversed.
     *
     * @return array{asset_class: string, dpd: int, changed: bool}
     */
    public static function classify(array $loan, string $asOf, ?int $userId): array
    {
        if ($loan['status'] !== 'active') {
            $class = $loan['status'] === 'written_off' ? $loan['asset_class'] : 'standard';
            Database::query('UPDATE loans SET asset_class = ?, dpd = 0, npa_since = IF(? = \'standard\', NULL, npa_since), classified_at = UTC_TIMESTAMP() WHERE id = ?', [$class, $class, $loan['id']]);
            return ['asset_class' => $class, 'dpd' => 0, 'changed' => $class !== $loan['asset_class']];
        }

        $oldest = Database::value(
            "SELECT MIN(due_date) FROM loan_installments
              WHERE loan_id = ? AND status <> 'paid' AND due_date < ?
                AND (principal_due - principal_paid + interest_due - interest_paid - interest_waived + late_fee_due - late_fee_paid) > 0",
            [$loan['id'], $asOf],
        );
        $dpd = $oldest === null ? 0 : (int) (new DateTimeImmutable($oldest))->diff(new DateTimeImmutable($asOf))->days;
        $before = $loan['asset_class'];
        $wasNpa = self::isNpa($loan);
        $npaSince = $loan['npa_since'];

        if ($wasNpa && $dpd > 0) {
            $months = $npaSince === null ? 0 : self::monthsBetween($npaSince, $asOf);
            $class = $months >= 36 ? 'loss' : ($months >= 12 ? 'doubtful' : 'substandard');
        } elseif ($dpd > 90) {
            $class = 'substandard';
            $npaSince = (new DateTimeImmutable($oldest))->modify('+90 days')->format('Y-m-d');
        } else {
            $class = match (true) {
                $dpd === 0 => 'standard',
                $dpd <= 30 => 'sma_0',
                $dpd <= 60 => 'sma_1',
                default => 'sma_2',
            };
            $npaSince = null;
        }

        Database::query('UPDATE loans SET asset_class = ?, dpd = ?, npa_since = ?, classified_at = UTC_TIMESTAMP() WHERE id = ?', [$class, $dpd, $npaSince, $loan['id']]);
        $loan['asset_class'] = $class;
        if (self::isNpa($loan)) {
            self::reverseUnrealised($loan, $asOf, $userId);
            if (!$wasNpa) {
                Notifier::role('branch_manager', (int) $loan['branch_id'], 'loan.npa', "{$loan['loan_number']} is now an NPA",
                    "{$dpd} days past due. Interest is now recognised only on receipt.", "/loans/{$loan['id']}");
            }
        }
        return ['asset_class' => $class, 'dpd' => $dpd, 'changed' => $class !== $before];
    }

    /** NPA rule: income must not include interest that was accrued but never received. */
    public static function reverseUnrealised(array $loan, string $asOf, ?int $userId): string
    {
        $rows = Database::all(
            'SELECT id, interest_paid, interest_accrued FROM loan_installments WHERE loan_id = ? AND interest_accrued > interest_paid FOR UPDATE',
            [$loan['id']],
        );
        $total = '0';
        foreach ($rows as $row) {
            $total = Money::add($total, Money::sub($row['interest_accrued'], $row['interest_paid']));
            Database::query('UPDATE loan_installments SET interest_accrued = interest_paid, accrued_at = NULL WHERE id = ?', [$row['id']]);
        }
        if (Money::isPositive($total)) {
            Ledger::post($asOf, (int) $loan['branch_id'], 'loan_npa_interest_reversal', (int) $loan['id'],
                "Unrealised interest reversed on NPA {$loan['loan_number']}", [
                    [Ledger::INTEREST_INCOME, $total, '0'],
                    [Ledger::INTEREST_RECEIVABLE, '0', $total],
                ], $userId);
        }
        return $total;
    }

    /** Required provision vs held; posts the difference. @return array<string, mixed> */
    public static function provision(string $asOf, ?int $userId): array
    {
        $rates = [
            'standard' => Settings::get('provision_rate_standard'),
            'substandard' => Settings::get('provision_rate_substandard'),
            'doubtful' => Settings::get('provision_rate_doubtful'),
            'loss' => Settings::get('provision_rate_loss'),
        ];
        $byClass = Database::all(
            "SELECT l.asset_class, COUNT(DISTINCT l.id) AS loans, COALESCE(SUM(i.principal_due - i.principal_paid), 0) AS principal
               FROM loans l JOIN loan_installments i ON i.loan_id = l.id
              WHERE l.status = 'active' GROUP BY l.asset_class",
        );
        $required = '0';
        $detail = [];
        foreach ($byClass as $row) {
            $bucket = in_array($row['asset_class'], self::NPA_CLASSES, true) ? $row['asset_class'] : 'standard';
            $amount = Money::percentOf($row['principal'], $rates[$bucket]);
            $required = Money::add($required, $amount);
            $detail[] = ['asset_class' => $row['asset_class'], 'loans' => (int) $row['loans'], 'principal' => Money::fmt($row['principal']), 'rate' => $rates[$bucket], 'provision' => $amount];
        }
        $held = self::provisionHeld();
        $adjustment = Money::sub($required, $held);
        $entryId = null;
        if (!Money::isZero($adjustment)) {
            $abs = ltrim($adjustment, '-');
            $lines = Money::cmp($adjustment, '0') > 0
                ? [[Ledger::PROVISION_EXPENSE, $abs, '0'], [Ledger::LOAN_LOSS_PROVISION, '0', $abs]]
                : [[Ledger::LOAN_LOSS_PROVISION, $abs, '0'], [Ledger::PROVISION_EXPENSE, '0', $abs]];
            $branch = (int) Database::value("SELECT id FROM branches WHERE code = 'HO'") ?: 1;
            $entryId = Ledger::post($asOf, $branch, 'loan_loss_provision', null, 'Loan loss provision true-up', $lines, $userId);
        }
        Database::insert('provision_runs', [
            'run_date' => $asOf,
            'required_amount' => $required,
            'held_before' => $held,
            'adjustment' => $adjustment,
            'detail_json' => json_encode($detail),
            'journal_entry_id' => $entryId,
            'created_by' => $userId,
        ]);
        return ['required' => Money::fmt($required), 'held_before' => Money::fmt($held), 'adjustment' => Money::fmt($adjustment), 'detail' => $detail];
    }

    public static function provisionHeld(): string
    {
        return (string) Database::value(
            "SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE a.code = ?",
            [Ledger::LOAN_LOSS_PROVISION],
        );
    }

    /** Technical write-off of an NPA: principal leaves the books against the provision; the customer still owes it. */
    public static function writeOff(array $loan, string $asOf, int $userId): array
    {
        if ($loan['status'] !== 'active' || !self::isNpa($loan)) {
            throw HttpException::conflict('Only active NPA loans (substandard, doubtful or loss) can be written off.');
        }
        self::reverseUnrealised($loan, $asOf, $userId);
        $principal = (string) Database::value('SELECT COALESCE(SUM(principal_due - principal_paid), 0) FROM loan_installments WHERE loan_id = ?', [$loan['id']]);
        $advance = (string) Database::value('SELECT COALESCE(SUM(GREATEST(interest_paid - interest_accrued, 0)), 0) FROM loan_installments WHERE loan_id = ?', [$loan['id']]);
        $fromProvision = Money::min(self::max0(self::provisionHeld()), $principal);
        $lines = [
            [Ledger::LOAN_LOSS_PROVISION, $fromProvision, '0'],
            [Ledger::LOAN_WRITE_OFF_EXPENSE, Money::sub($principal, $fromProvision), '0'],
            [Ledger::LOAN_RECEIVABLE, '0', $principal],
            // Interest already received in advance is now realised.
            [Ledger::ADVANCE_INTEREST, $advance, '0'],
            [Ledger::INTEREST_INCOME, '0', $advance],
        ];
        $entryId = Ledger::post($asOf, (int) $loan['branch_id'], 'loan_write_off', (int) $loan['id'], "Write-off of {$loan['loan_number']}", $lines, $userId);
        Database::query('UPDATE loan_installments SET interest_accrued = GREATEST(interest_accrued, interest_paid) WHERE loan_id = ?', [$loan['id']]);
        Database::query("UPDATE loans SET status = 'written_off', written_off_at = UTC_TIMESTAMP(), written_off_amount = ? WHERE id = ?", [$principal, $loan['id']]);
        return ['principal_written_off' => Money::fmt($principal), 'from_provision' => Money::fmt($fromProvision), 'journal_entry_id' => $entryId];
    }

    private static function max0(string $v): string
    {
        return Money::cmp($v, '0') > 0 ? $v : '0';
    }

    private static function monthsBetween(string $from, string $to): int
    {
        $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));
        return $d->y * 12 + $d->m;
    }
}
