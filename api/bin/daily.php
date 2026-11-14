<?php

declare(strict_types=1);

// Daily jobs. Schedule once a day shortly after midnight business time, e.g. cPanel cron:
//   15 0 * * *  php /home/USER/finserve/api/bin/daily.php >> /home/USER/logs/finserve-daily.log 2>&1
//
// 1. Applies late fees to overdue loan installments.
// 2. Accrues deposit interest up to yesterday (one journal entry per branch).
// 3. Marks deposits matured on/after their maturity date.
// Safe to re-run: every step is idempotent. A lock prevents overlapping runs.

use App\Core\Database;
use App\Services\DepositService;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\Money;
use App\Services\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

if ((int) Database::value("SELECT GET_LOCK('finserve_daily', 0)") !== 1) {
    fwrite(STDERR, "Another daily run is in progress.\n");
    exit(1);
}

$today = Settings::today();
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$started = microtime(true);
echo '[' . gmdate('c') . "] Daily run for business date {$today}\n";

// Loans: late fees, interest recognition (accrual), IRACP classification (NPA reversal inside), then provisioning.
$loanIds = array_column(Database::all("SELECT id FROM loans WHERE status IN ('active','closed','written_off')"), 'id');
$classes = [];
foreach ($loanIds as $id) {
    Database::transaction(function () use ($id, $today, &$classes) {
        $lock = fn () => Database::one('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$id]);
        LoanService::applyLateFees($lock(), $today);
        \App\Services\LoanAccounting::accrue($lock(), $today, null);
        $result = \App\Services\LoanAccounting::classify($lock(), $today, null);
        $classes[$result['asset_class']] = ($classes[$result['asset_class']] ?? 0) + 1;
    });
}
echo '  loans processed: ' . count($loanIds) . ' ' . json_encode($classes) . "\n";
$provision = Database::transaction(fn () => \App\Services\LoanAccounting::provision($today, null));
echo "  provision required {$provision['required']}, adjustment {$provision['adjustment']}\n";

$branches = array_column(Database::all("SELECT DISTINCT branch_id FROM deposit_accounts WHERE status IN ('active')"), 'branch_id');
foreach ($branches as $branchId) {
    Database::transaction(function () use ($branchId, $yesterday, $today) {
        $total = '0';
        $ids = array_column(Database::all("SELECT id FROM deposit_accounts WHERE status = 'active' AND branch_id = ?", [$branchId]), 'id');
        foreach ($ids as $id) {
            $account = DepositService::lock((int) $id);
            $total = Money::add($total, DepositService::accrue($account, $yesterday, null, false));
        }
        if (Money::isPositive($total)) {
            $entryId = Ledger::post($today, (int) $branchId, 'deposit_accrual_batch', null, "Daily deposit interest accrual to {$yesterday}", [
                [Ledger::DEPOSIT_INTEREST_EXPENSE, $total, '0'],
                [Ledger::DEPOSIT_INTEREST_PAYABLE, '0', $total],
            ], null);
            Database::query(
                'UPDATE deposit_accruals a JOIN deposit_accounts d ON d.id = a.deposit_account_id
                    SET a.journal_entry_id = ? WHERE a.journal_entry_id IS NULL AND d.branch_id = ?',
                [$entryId, $branchId],
            );
        }
        echo "  branch {$branchId}: accrued {$total} across " . count($ids) . " deposit accounts\n";
    });
}

// Reminders: installments due on the next business day, and a nudge once an installment is 3 days overdue.
$nextDay = \App\Services\Calendar::nextBusinessDay((new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
$reminders = 0;
foreach (Database::all(
    "SELECT i.id, i.due_date, l.id AS loan_id, l.loan_number, l.customer_id,
            (i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid) AS amount
       FROM loan_installments i JOIN loans l ON l.id = i.loan_id
      WHERE l.status = 'active' AND i.status <> 'paid' AND (i.due_date = ? OR i.due_date = DATE_SUB(?, INTERVAL 3 DAY))",
    [$nextDay, $today],
) as $row) {
    $overdue = $row['due_date'] < $today;
    \App\Services\Notifier::customer((int) $row['customer_id'], $overdue ? 'loan.overdue' : 'loan.due_reminder',
        $overdue ? 'Installment overdue' : 'Installment due soon',
        ($overdue ? 'Your installment of Rs ' : 'Reminder: Rs ') . Money::fmt($row['amount']) . " for {$row['loan_number']} " . ($overdue ? "was due on {$row['due_date']}. Please pay to avoid further charges." : "is due on {$row['due_date']}."),
        "/loans/{$row['loan_id']}", ($overdue ? 'overdue:' : 'due:') . $row['id'] . ':' . $row['due_date']);
    $reminders++;
}
echo "  {$reminders} payment reminder(s) queued\n";

$matured = Database::query("UPDATE deposit_accounts SET status = 'matured' WHERE status = 'active' AND maturity_date <= ?", [$today])->rowCount();
echo "  {$matured} deposit account(s) matured\n";

Database::value("SELECT RELEASE_LOCK('finserve_daily')");
printf("  done in %.1fs\n", microtime(true) - $started);
