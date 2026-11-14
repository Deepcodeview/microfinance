<?php

declare(strict_types=1);

// Core banking: accrual accounting, IRACP classification & NPA interest reversal, provisioning, write-off
// and recovery, maker-checker approvals, credit/cash limits, manual journals, UPI settlement, financial
// statements and day-end close. After every money event the GL must agree with every subledger.
// Run last (after finance, features, smoke) on a freshly seeded --demo database: it closes the books.

use App\Core\Database;
use App\Services\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://localhost/api/v1';
$failures = 0;

function req(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    // These suites test money logic, not the one-collection-a-day prompt (collection_rules_test.php covers it).
    if ($method === 'POST' && $path === '/payments' && is_array($body) && !isset($body['allow_repeat'])) {
        $body['allow_repeat'] = true;
    }
    global $base;
    $ch = curl_init($base . $path);
    $headers[] = 'Content-Type: application/json';
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $raw = (string) curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($raw, true) ?? $raw];
}

function ok(string $label, bool $pass, mixed $detail = null): void
{
    global $failures;
    echo ($pass ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$pass) {
        $failures++;
        echo '       ' . substr(json_encode($detail), 0, 700) . "\n";
    }
}

function token(string $email): string
{
    return req('POST', '/auth/login', ['email' => $email, 'password' => 'Admin@12345'])[1]['data']['token'] ?? '';
}

function gl(string $code): string
{
    return (string) Database::value(
        "SELECT COALESCE(SUM(CASE WHEN a.normal_balance = 'debit' THEN l.debit - l.credit ELSE l.credit - l.debit END), 0)
           FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE a.code = ?",
        [$code],
    );
}

function controlsPass(string $token, string $when): void
{
    [, $b] = req('GET', '/accounting/controls', null, $token);
    $bad = array_values(array_filter($b['data']['checks'] ?? [], fn ($c) => !$c['ok']));
    ok("GL agrees with every subledger {$when}", ($b['data']['checks'] ?? []) !== [] && $bad === [], $bad ?: $b);
}

function daily(): void
{
    exec('php ' . dirname(__DIR__) . '/bin/daily.php 2>&1', $out, $code);
    if ($code !== 0) {
        echo implode("\n", $out) . "\n";
    }
}

function pay(string $token, int $loanId, string $amount, string $method = 'cash', ?string $ref = null): array
{
    return req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => $amount, 'method' => $method, 'reference' => $ref],
        $token, ['Idempotency-Key: core-' . bin2hex(random_bytes(10))]);
}

$admin = token('admin@finserve.local');
$manager = token('manager@finserve.local');
$officer = token('officer@finserve.local');
$finance = token('finance@finserve.local');
$agent1 = token('agent1@finserve.local');
$auditor = token('auditor@finserve.local');
$today = Settings::today();
$ago = fn (int $d) => (new DateTimeImmutable($today))->modify("-{$d} days")->format('Y-m-d');
$products = array_column(req('GET', '/loan-products', null, $admin)[1]['data'], null, 'code');
$agentId = (int) Database::value("SELECT id FROM users WHERE email = 'agent1@finserve.local'");

function newCustomer(string $token, int $agentId, string $name): int
{
    [, $b] = req('POST', '/customers', ['full_name' => $name, 'phone' => '97' . random_int(10000000, 99999999), 'address_line' => '1 Main Road',
        'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001', 'agent_user_id' => $agentId], $token);
    return (int) $b['data']['id'];
}

function newLoan(int $customerId, string $productId, string $principal, int $tenure): int
{
    global $officer, $manager, $admin, $finance;
    [, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => (int) $productId, 'principal' => $principal, 'tenure' => $tenure], $officer);
    $id = (int) ($b['data']['id'] ?? 0);
    [$s1, $b1] = req('POST', "/loans/{$id}/approve", ['reason' => 'Verified documents'], (float) $principal > 200000 ? $admin : $manager);
    [$s2, $b2] = req('POST', "/loans/{$id}/disburse", ['method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT)], $finance);
    if ($id === 0 || $s1 !== 200 || $s2 !== 200) {
        ok("set up loan for customer {$customerId}", false, [$s1, $b1["error"] ?? null, $s2, $b2["error"] ?? null]);
    }
    return $id;
}

controlsPass($finance, 'at start');

echo "Accrual accounting\n";
$c1 = newCustomer($manager, $agentId, 'Accrual Test');
$loan = newLoan($c1, $products['PL-MONTHLY']['id'], '120000', 12);
$i1 = Database::one('SELECT * FROM loan_installments WHERE loan_id = ? AND number = 1', [$loan]);
Database::query('UPDATE loan_installments SET due_date = ? WHERE id = ?', [$ago(1), $i1['id']]);
$receivableBefore = gl('1110');
daily();
$i1 = Database::one('SELECT * FROM loan_installments WHERE id = ?', [$i1['id']]);
ok('interest recognised on due date (accrual)', $i1['interest_accrued'] === $i1['interest_due'] && $i1['accrued_at'] !== null, $i1);
ok('Interest receivable rose by that interest', bccomp(bcsub(gl('1110'), $receivableBefore, 4), $i1['interest_due'], 2) === 0, [gl('1110'), $receivableBefore]);
controlsPass($finance, 'after accrual');

[$s, $b] = pay($agent1, $loan, bcadd($i1['interest_due'], '0', 2));
$alloc = $b['data']['allocations'][0] ?? [];
ok('payment of accrued interest clears the receivable', $s === 201 && $alloc['component'] === 'interest', $b);
ok('…credited to 1110, not income again', bccomp(gl('1110'), $receivableBefore, 2) === 0, gl('1110'));
$advanceBefore = gl('2300');
$i2 = Database::one('SELECT * FROM loan_installments WHERE loan_id = ? AND number = 2', [$loan]);
pay($agent1, $loan, bcadd($i1['principal_due'], $i2['interest_due'], 2));
ok('interest paid before its due date sits in Advance interest (liability)', bccomp(bcsub(gl('2300'), $advanceBefore, 4), $i2['interest_due'], 2) === 0, [gl('2300'), $advanceBefore]);
Database::query('UPDATE loan_installments SET due_date = ? WHERE id = ?', [$today, $i2['id']]);
daily();
ok('on the due date the advance is released to income', bccomp(gl('2300'), $advanceBefore, 2) === 0, gl('2300'));
controlsPass($finance, 'after advance + accrual');

echo "Asset classification, NPA and provisioning\n";
$c2 = newCustomer($manager, $agentId, 'Npa Test');
$npa = newLoan($c2, $products['PL-MONTHLY']['id'], '60000', 12);
foreach ([1 => 45, 2 => 30, 3 => 15, 4 => 1] as $n => $days) {
    Database::query('UPDATE loan_installments SET due_date = ? WHERE loan_id = ? AND number = ?', [$ago($days), $npa, $n]);
}
daily();
$row = Database::one('SELECT asset_class, dpd FROM loans WHERE id = ?', [$npa]);
ok('45 days past due -> SMA-1, interest still accrued', $row['asset_class'] === 'sma_1' && (int) Database::value('SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND accrued_at IS NOT NULL', [$npa]) >= 1, $row);
$accruedOnNpa = (string) Database::value('SELECT SUM(interest_accrued - interest_paid) FROM loan_installments WHERE loan_id = ?', [$npa]);
Database::query('UPDATE loan_installments SET due_date = ? WHERE loan_id = ? AND number = 1', [$ago(100), $npa]);
$incomeBefore = gl('4000');
daily();
$row = Database::one('SELECT asset_class, dpd, npa_since FROM loans WHERE id = ?', [$npa]);
ok('100 days past due -> NPA (substandard)', $row['asset_class'] === 'substandard' && (int) $row['dpd'] === 100 && $row['npa_since'] !== null, $row);
ok('unrealised interest reversed out of income', (string) Database::value('SELECT SUM(GREATEST(interest_accrued - interest_paid, 0)) FROM loan_installments WHERE loan_id = ?', [$npa]) === '0.0000'
    && bccomp(gl('4000'), $incomeBefore, 2) < 0, [$accruedOnNpa, gl('4000'), $incomeBefore]);
$run = Database::one('SELECT * FROM provision_runs ORDER BY id DESC LIMIT 1');
$npaPrincipal = (string) Database::value('SELECT SUM(principal_due - principal_paid) FROM loan_installments WHERE loan_id = ?', [$npa]);
ok('provision includes 15% of the NPA principal', bccomp($run['required_amount'], bcmul($npaPrincipal, '0.15', 2), 2) > 0 && bccomp(gl('1190'), $run['required_amount'], 2) === 0, [$run, gl('1190')]);
controlsPass($finance, 'after NPA slip and provisioning');

$incomeBefore = gl('4000');
[$s, $b] = pay($agent1, $npa, '3000');
$interestPaid = array_sum(array_map(fn ($a) => $a['component'] === 'interest' ? (float) $a['amount'] : 0, $b['data']['allocations'] ?? []));
ok('NPA: late fees first, then interest recognised as income only on receipt', $s === 201 && $interestPaid > 0
    && abs((float) bcsub(gl('4000'), $incomeBefore, 2) - $interestPaid) < 0.01, [$b['data']['allocations'] ?? $b, gl('4000'), $incomeBefore]);
controlsPass($finance, 'after NPA cash-basis receipt');
[$s, $b] = req('POST', '/loans', ['customer_id' => $c2, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '20000', 'tenure' => 6], $officer);
ok('no new loan for a customer with an NPA', $s === 422 && str_contains($b['error']['fields']['customer_id'] ?? '', 'non-performing'), $b);

echo "Write-off (maker-checker) and recovery\n";
[$s, $b] = req('POST', "/loans/{$loan}/write-off", ['reason' => 'Borrower absconded, legal notice returned'], $finance);
ok('performing loan cannot be written off -> 409', $s === 409, $b);
[$s, $b] = req('POST', "/loans/{$npa}/write-off", ['reason' => 'Borrower absconded, legal notice returned'], $finance);
ok('write-off request queued for approval', $s === 202, $b);
$wo = $b['data']['approval_request_id'] ?? 0;
[$s] = req('POST', "/approvals/{$wo}/decide", ['decision' => 'approved'], $finance);
ok('requester cannot approve -> 403', $s === 403);
[$s] = req('POST', "/approvals/{$wo}/decide", ['decision' => 'approved'], $manager);
ok('branch manager lacks write-off authority -> 403', $s === 403);
$provisionBefore = gl('1190');
[$s, $b] = req('POST', "/approvals/{$wo}/decide", ['decision' => 'approved'], $admin);
$loanRow = Database::one('SELECT status, written_off_amount FROM loans WHERE id = ?', [$npa]);
ok('admin approves -> loan written off against provision', $s === 200 && $loanRow['status'] === 'written_off' && bccomp(gl('1190'), $provisionBefore, 2) < 0, [$b, $loanRow]);
controlsPass($finance, 'after write-off');
$recoveryBefore = gl('4040');
[$s, $b] = pay($agent1, $npa, '1000');
ok('recovery on a written-off loan goes to recovery income', $s === 201 && bccomp(bcsub(gl('4040'), $recoveryBefore, 2), '1000', 2) === 0, [$b, gl('4040')]);
controlsPass($finance, 'after recovery');

echo "Limits\n";
$c3 = newCustomer($manager, $agentId, 'Limit Test');
[, $b] = req('POST', '/loans', ['customer_id' => $c3, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '300000', 'tenure' => 24], $officer);
$big = (int) $b['data']['id'];
[$s, $b] = req('POST', "/loans/{$big}/approve", [], $manager);
ok('manager cannot approve above Rs 2,00,000 delegated limit -> 403', $s === 403 && str_contains($b['error']['message'], 'approval limit'), $b);
[$s] = req('POST', "/loans/{$big}/approve", [], $admin);
ok('admin can approve it', $s === 200);
[$s, $b] = req('POST', "/loans/{$big}/disburse", ['method' => 'cash'], $finance);
ok('treasury: cannot disburse more cash than the branch vault holds -> 409', $s === 409 && $b['error']['code'] === 'INSUFFICIENT_FUNDS', $b);
[$s] = req('POST', "/loans/{$big}/disburse", ['method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT)], $finance);
ok('…but can disburse from the funded bank account', $s === 200);
[$s, $b] = req('POST', '/loans', ['customer_id' => $c3, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '480000', 'tenure' => 24], $officer);
ok('Rs 3L approved + Rs 4.8L new is within the Rs 10L exposure cap', $s === 201, $b);
[$s, $b] = req('POST', '/loans', ['customer_id' => $c3, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '400000', 'tenure' => 24], $officer);
ok('another Rs 4L would breach the exposure cap -> 422', $s === 422 && str_contains($b['error']['fields']['principal'] ?? '', 'per-customer limit'), $b);
req('PATCH', '/settings', ['agent_cash_limit' => '2000'], $admin);
[$s, $b] = pay($agent1, $loan, '5000');
ok('agent cash-in-hand limit blocks collection -> 422', $s === 422 && str_contains($b['error']['fields']['amount'] ?? '', 'limit'), $b);
req('PATCH', '/settings', ['agent_cash_limit' => '100000'], $admin);

echo "Manual journal (maker-checker)\n";
[$s, $b] = req('POST', '/accounting/journals', ['description' => 'Office rent October', 'reason' => 'Rent invoice #44',
    'lines' => [['account_code' => '1100', 'debit' => '100', 'credit' => '0'], ['account_code' => '1020', 'debit' => '0', 'credit' => '100']]], $finance);
ok('control accounts cannot be posted manually -> 422', $s === 422, $b);
[$s, $b] = req('POST', '/accounting/journals', ['description' => 'Office rent October', 'reason' => 'Rent invoice #44',
    'lines' => [['account_code' => '5500', 'debit' => '15000', 'credit' => '0'], ['account_code' => '1020', 'debit' => '0', 'credit' => '14000']]], $finance);
ok('unbalanced journal rejected -> 422', $s === 422, $b);
[$s, $b] = req('POST', '/accounting/journals', ['description' => 'Office rent October', 'reason' => 'Rent invoice #44',
    'lines' => [['account_code' => '5500', 'debit' => '15000', 'credit' => '0'], ['account_code' => '1020', 'debit' => '0', 'credit' => '15000']]], $finance);
ok('journal queued for approval, nothing posted yet', $s === 202 && bccomp(gl('5500'), '0', 2) === 0, $b);
req('POST', "/approvals/{$b['data']['approval_request_id']}/decide", ['decision' => 'approved'], $admin);
ok('approved journal posted', bccomp(gl('5500'), '15000', 2) === 0, gl('5500'));

echo "UPI settlement reconciliation\n";
$ref = (string) random_int(100000000000, 999999999999);
[, $b] = pay($finance, $loan, '2000', 'upi', $ref);
$upiPayment = (int) $b['data']['id'];
[, $b] = req('GET', '/accounting/settlements/unsettled', null, $finance);
ok('confirmed UPI shows as unsettled', in_array((string) $upiPayment, array_column($b['data'], 'id'), true), $b);
[$s, $b] = req('POST', '/accounting/settlements', ['payment_ids' => [$upiPayment], 'bank_reference' => 'NEFT-SETL-1', 'settlement_date' => $today, 'fees' => '10', 'amount_credited' => '1995'], $finance);
ok('bank credit not matching payments minus fees -> 422', $s === 422, $b);
[$s, $b] = req('POST', '/accounting/settlements', ['payment_ids' => [$upiPayment], 'bank_reference' => 'NEFT-SETL-1', 'settlement_date' => $today, 'fees' => '10', 'amount_credited' => '1990'], $finance);
ok('settlement moves clearing to bank, fee expensed', $s === 201 && bccomp(gl('5400'), '10', 2) === 0, $b);
[$s, $b] = req('POST', "/payments/{$upiPayment}/reverse", ['reason' => 'Customer dispute raised'], $finance);
ok('settled UPI payment cannot be reversed -> 409', $s === 409, $b);
controlsPass($finance, 'after settlement');

echo "Financial statements\n";
[$s, $b] = req('GET', '/accounting/balance-sheet', null, $auditor);
ok('balance sheet balances (assets = liabilities + equity)', $s === 200 && $b['data']['balanced'] === true, $b['data'] ?? $b);
[$s, $p] = req('GET', '/accounting/income-statement?date_from=2000-01-01', null, $auditor);
ok('net profit = income − expenses and equals retained earnings', $s === 200 && $p['data']['net_profit'] === $b['data']['retained_earnings'], [$p['data']['net_profit'] ?? null, $b['data']['retained_earnings'] ?? null]);
[$s, $b] = req('GET', '/accounting/accounts/1100/ledger?date_from=2000-01-01', null, $auditor);
ok('account ledger closing balance equals GL', $s === 200 && bccomp($b['data']['closing_balance'], gl('1100'), 2) === 0, $b['data']['closing_balance'] ?? $b);

echo "Day-end close\n";
[, $b] = pay($agent1, $loan, '700', 'upi', (string) random_int(100000000000, 999999999999));
$pendingId = (int) ($b['data']['id'] ?? 0);
[$s, $b] = req('POST', '/accounting/day-close', ['business_date' => $today], $finance);
ok('close blocked while a digital payment awaits confirmation', $s === 422 && $b['error']['code'] === 'DAY_CLOSE_BLOCKED', $b);
req('POST', "/payments/{$pendingId}/confirm", [], $finance);
[$s, $b] = req('POST', '/accounting/day-close', ['business_date' => $today], $finance);
ok('day closes once checks pass', $s === 200 && $b['data']['closed_through'] === $today, $b);
[$s, $b] = pay($agent1, $loan, '100');
ok('no posting into a closed day -> 409 PERIOD_CLOSED', $s === 409 && $b['error']['code'] === 'PERIOD_CLOSED', $b);

// Test cleanup: reopen so the demo stays usable (production has no reopen — by design).
Database::query("UPDATE settings SET setting_value = '' WHERE setting_key = 'closed_through'");
Database::query('DELETE FROM day_closes');

echo $failures === 0 ? "\nAll core banking checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
