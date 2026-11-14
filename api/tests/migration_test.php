<?php

declare(strict_types=1);

// Bringing in accounts from the old manual register: loans with a past start date and money already
// collected, savings with a past opening date and a balance. Preview changes nothing; import keeps the
// books balanced and every subledger in agreement with the GL. Run on the seeded database after the others.

use App\Core\Database;
use App\Services\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://localhost/api/v1';
$failures = 0;

function req(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers[] = 'Content-Type: application/json';
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $text = (string) curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($text, true) ?? $text];
}

function ok(string $label, bool $pass, mixed $detail = null): void
{
    global $failures;
    echo ($pass ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$pass) {
        $failures++;
        echo '       ' . substr(json_encode($detail), 0, 500) . "\n";
    }
}

function token(string $email): string
{
    return req('POST', '/auth/login', ['email' => $email, 'password' => 'Admin@12345'])[1]['data']['token'] ?? '';
}

$admin = token('admin@finserve.local');
$manager = token('manager@finserve.local');
$finance = token('finance@finserve.local');
$agent = token('agent1@finserve.local');
$today = Settings::today();
$ago = fn (int $days) => (new DateTimeImmutable($today))->modify("-{$days} days")->format('d-m-Y');
$import = fn (string $kind, array $rows, bool $commit, string $token) => req('POST', '/migration/import', ['kind' => $kind, 'commit' => $commit, 'rows' => $rows], $token);
$controls = function (string $when) use ($finance): void {
    [, $b] = req('GET', '/accounting/controls', null, $finance);
    $bad = array_values(array_filter($b['data']['checks'] ?? [], fn ($c) => !$c['ok']));
    ok("ledger agrees with every loan and savings account {$when}", ($b['data']['checks'] ?? []) !== [] && $bad === [], $bad ?: $b);
    [, $t] = req('GET', '/reports/trial-balance', null, $finance);
    ok("books balance {$when}", ($t['data']['summary']['balanced'] ?? false) === true, $t['data']['summary'] ?? $t);
};
Database::query("UPDATE settings SET setting_value = '1' WHERE setting_key = 'deposits_enabled'");
$person = fn (string $phone, array $extra = []) => $extra + ['name' => 'Ramesh Old', 'mobile' => $phone, 'address' => 'Old Market Road', 'city' => 'Patna', 'state' => 'Bihar', 'pincode' => '800002'];

echo "Who may import\n";
[$s] = $import('loans', [$person('9500000001') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => $ago(30), 'amount_received' => '4000']], false, $manager);
ok('branch manager cannot import -> 403', $s === 403);

echo "Old loan: preview, then import\n";
$row = $person('9500000001', ['agent_email' => 'agent1@finserve.local']) + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '₹10,000', 'installments' => 50, 'start_date' => $ago(30), 'amount_received' => '4,000', 'note' => 'Register page 12'];
$loansBefore = (int) Database::value('SELECT COUNT(*) FROM loans');
[$s, $b] = $import('loans', [$row], false, $admin);
$preview = $b['data']['rows'][0] ?? [];
ok('preview shows the loan as it stands today', $s === 200 && $preview['ok'] === true && $preview['customer_is_new'] === true && str_ends_with($preview['installments_paid'], 'of 50'), $b);
ok('preview saves nothing', (int) Database::value('SELECT COUNT(*) FROM loans') === $loansBefore && Database::value("SELECT id FROM customers WHERE phone = '9500000001'") === null);
[$s, $b] = $import('loans', [$row], true, $admin);
$r = $b['data']['rows'][0] ?? [];
$loanId = (int) ($r['loan_id'] ?? 0);
ok('import creates the customer and the loan', $s === 200 && $b['data']['ok'] === 1 && $loanId > 0, $b);
$loan = Database::one('SELECT * FROM loans WHERE id = ?', [$loanId]);
ok('loan started on the old date, marked as brought in', $loan['status'] === 'active' && $loan['disbursement_date'] === (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d') && $loan['migrated_at'] !== null && $loan['opening_collected'] === '4000.0000', $loan);
$paid = Database::one('SELECT SUM(principal_paid + interest_paid) AS paid, SUM(late_fee_due) AS fees FROM loan_installments WHERE loan_id = ?', [$loanId]);
ok('Rs 4,000 applied to the installments, no late fees back-charged', bccomp($paid['paid'], '4000', 2) === 0 && bccomp($paid['fees'], '0', 2) === 0, $paid);
ok('no cash payment is invented', (int) Database::value('SELECT COUNT(*) FROM payments WHERE loan_id = ?', [$loanId]) === 0);
[, $b] = req('GET', '/collections/today', null, $agent);
ok("it appears on the assigned agent's list", in_array($loanId, array_map('intval', array_column($b['data']['loans'] ?? [], 'loan_id')), true) || $r['overdue_amount'] === '0.00', $r);
[$s, $b] = req('GET', "/loans/{$loanId}/statement", null, $admin);
$descriptions = array_column($b['data']['lines'] ?? [], 'description');
ok('statement shows the old loan and what was collected before', $s === 200 && in_array('Loan given (before KBSAVE)', $descriptions, true) && in_array('Collected before KBSAVE (brought in)', $descriptions, true), $b);
$controls('after a loan is brought in');

echo "Same customer again, other cases\n";
[$s, $b] = $import('loans', [['mobile' => '+91 95000 00001', 'plan_code' => 'PL-MONTHLY', 'loan_amount' => '20000', 'installments' => 12, 'start_date' => $ago(400), 'amount_received' => '21945.97']], true, $admin);
$r = $b['data']['rows'][0] ?? [];
ok('matched by mobile in another format; fully repaid loan comes in closed', $r['ok'] === true && $r['customer_is_new'] === false && $r['status'] === 'closed', $b);
[$s, $b] = $import('loans', [$person('9500000002') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => $ago(200), 'amount_received' => '500']], true, $admin);
$r = $b['data']['rows'][0] ?? [];
ok('a long-unpaid loan comes in as an NPA', $r['ok'] === true && $r['asset_class'] === 'substandard' && $r['days_late'] > 90, $b);
$controls('after an NPA is brought in');

echo "Rows with mistakes\n";
[$s, $b] = $import('loans', [
    $person('9500000003') + ['plan_code' => 'NOPE', 'loan_amount' => '1000', 'installments' => 10, 'start_date' => $ago(10), 'amount_received' => '0'],
    $person('9500000004') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => '31-02-2026', 'amount_received' => '0'],
    $person('9500000005') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => $ago(10), 'amount_received' => '99999'],
    $person('9500000006') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => (new DateTimeImmutable($today))->modify('+5 days')->format('d-m-Y'), 'amount_received' => '0'],
    ['mobile' => '9500000007', 'plan_code' => 'DCL-DAILY', 'loan_amount' => '10000', 'installments' => 50, 'start_date' => $ago(10), 'amount_received' => '0'],
    $person('9500000008') + ['plan_code' => 'DCL-DAILY', 'loan_amount' => '8000', 'installments' => 50, 'start_date' => $ago(5), 'amount_received' => '0'],
], true, $admin);
$rows = $b['data']['rows'] ?? [];
$err = fn (int $i, string $field) => isset($rows[$i]['errors'][$field]);
ok('unknown plan code', $err(0, 'plan_code'), $rows[0] ?? null);
ok('impossible date', $err(1, 'start_date'), $rows[1] ?? null);
ok('more received than the loan with interest', $err(2, 'amount_received'), $rows[2] ?? null);
ok('start date in the future', $err(3, 'start_date'), $rows[3] ?? null);
ok('new customer without name / address', $err(4, 'name') && $err(4, 'pincode'), $rows[4] ?? null);
ok('the good row is still imported', $b['data']['ok'] === 1 && $b['data']['failed'] === 5 && $rows[5]['ok'] === true, $b);

echo "Old savings accounts\n";
[$s, $b] = $import('savings', [$person('9500000011') + ['plan_code' => 'RD-DAILY', 'amount' => '100', 'tenure' => '1 year', 'opened_on' => $ago(10), 'balance' => '1000']], true, $admin);
$r = $b['data']['rows'][0] ?? [];
$depositId = (int) ($r['deposit_account_id'] ?? 0);
ok('daily savings opened 10 days ago with Rs 1,000: one installment due today', $r['ok'] === true && $r['installments_due_now'] === 1 && $r['balance'] === '1000.00', $b);
$pay = fn () => req('POST', '/payments', ['account_type' => 'deposit', 'account_id' => $depositId, 'amount' => '100', 'method' => 'cash'], $admin, ['Idempotency-Key: mg-' . bin2hex(random_bytes(8))]);
[$s] = $pay();
ok("today's Rs 100 can be collected", $s === 201);
[$s] = $pay();
ok('but only once today -> 409', $s === 409);
[$s, $b] = $import('savings', [$person('9500000012') + ['plan_code' => 'FD-12M', 'amount' => '5000', 'tenure' => '45 days', 'opened_on' => $ago(60), 'interest' => '43']], true, $admin);
$r = $b['data']['rows'][0] ?? [];
ok('a 45-day FD opened 60 days ago comes in matured with its balance', $r['ok'] === true && $r['status'] === 'matured' && $r['balance'] === '5000.00', $b);
[$s, $b] = $import('savings', [$person('9500000013') + ['plan_code' => 'FD-12M', 'amount' => '5000', 'tenure' => '7 weeks', 'opened_on' => $ago(10)]], false, $admin);
ok('a tenure the plan does not offer -> row error', ($b['data']['rows'][0]['ok'] ?? true) === false && isset($b['data']['rows'][0]['errors']['tenure']), $b);
$controls('after savings are brought in');

Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'deposits_enabled'");
echo $failures === 0 ? "All migration checks passed.\n" : "{$failures} migration check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
