<?php

declare(strict_types=1);

// End-to-end money-flow checks against a running, freshly seeded (--demo) instance.
// Usage (inside the api container): php tests/finance_test.php
// Backdates a few rows directly in the database to simulate time passing (overdue dues, matured deposits).

use App\Core\Database;
use App\Services\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = $argv[1] ?? 'http://localhost/api/v1';
$password = getenv('SEED_PASSWORD') ?: 'Admin@12345';
$failures = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null, array $extraHeaders = []): array
{
    // These suites test money logic, not the one-collection-a-day prompt (collection_rules_test.php covers it).
    if ($method === 'POST' && $path === '/payments' && is_array($body) && !isset($body['allow_repeat'])) {
        $body['allow_repeat'] = true;
    }
    global $base;
    $ch = curl_init($base . $path);
    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        CURLOPT_HEADER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr($raw, 0, $headerSize);
    $text = substr($raw, $headerSize);
    $json = json_decode($text, true);
    return [$status, $json ?? $text, $rawHeaders];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '       ' . substr(json_encode($detail), 0, 600) . "\n";
    }
}

function login(string $email): string
{
    global $password;
    [, $b] = call('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    return $b['data']['token'] ?? '';
}

function pay(string $token, array $body, ?string $key = null): array
{
    return call('POST', '/payments', $body, $token, ['Idempotency-Key: ' . ($key ?? bin2hex(random_bytes(12)))]);
}

function trialBalanced(string $token): bool
{
    [, $b] = call('GET', '/reports/trial-balance', null, $token);
    return ($b['data']['summary']['balanced'] ?? false) === true;
}

function ledgerBalance(string $code): string
{
    return (string) Database::value(
        'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_lines l JOIN accounting_accounts a ON a.id = l.account_id WHERE a.code = ?',
        [$code],
    );
}

$today = Settings::today();
$daysAgo = fn (int $n) => (new DateTimeImmutable($today))->modify("-{$n} days")->format('Y-m-d');

$admin = login('admin@finserve.local');
$manager = login('manager@finserve.local');
$officer = login('officer@finserve.local');
$finance = login('finance@finserve.local');
$supervisor = login('supervisor@finserve.local');
$agent1 = login('agent1@finserve.local');
$agent2 = login('agent2@finserve.local');
$auditor = login('auditor@finserve.local');

echo "Setup\n";
$agents = call('GET', '/agents', null, $manager)[1]['data'];
$agent1Id = (int) $agents[array_search('Ravi Agent', array_column($agents, 'name'), true)]['id'];
[$s, $b] = call('POST', '/customers', [
    'full_name' => 'Meena Kumari', 'phone' => '98' . random_int(10000000, 99999999), 'address_line' => '4 Station Road',
    'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411002', 'agent_user_id' => $agent1Id,
], $manager);
check('customer created and assigned to agent1', $s === 201, $b);
$customerId = (int) $b['data']['id'];
$products = array_column(call('GET', '/loan-products', null, $admin)[1]['data'], null, 'code');

echo "Loan lifecycle\n";
// Expected figures come from the product's current terms (the smoke test may have revised the rate).
$plTerms = $products['PL-MONTHLY']['terms'];
$expectedEmi = call('POST', '/loan-products/preview', ['terms' => $plTerms, 'principal' => '100000', 'tenure' => 12], $admin)[1]['data']['installment_amount'];
$firstInterest = \App\Services\Money::round(bcdiv(bcmul('100000', $plTerms['annual_interest_rate'], 10), '1200', 10));
[$s, $b] = call('POST', '/loans', ['customer_id' => $customerId, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '100000', 'tenure' => 12, 'purpose' => 'Shop stock'], $officer);
check('officer applies for loan', $s === 201 && $b['data']['status'] === 'pending_approval' && $b['data']['installment_amount'] === bcadd($expectedEmi, '0', 4), $b['data']['installment_amount'] ?? $b);
$loanId = (int) $b['data']['id'];

[$s, $b] = call('POST', '/loans', ['customer_id' => $customerId, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '999', 'tenure' => 12], $officer);
check('amount below product minimum -> 422', $s === 422 && isset($b['error']['fields']['principal']), $b);

[$s, $b] = call('POST', "/loans/{$loanId}/approve", [], $officer);
check('officer cannot approve -> 403', $s === 403, $b);
[$s, $b] = call('POST', "/loans/{$loanId}/disburse", ['method' => 'upi', 'reference' => '100000000001'], $finance);
check('cannot disburse before approval -> 409', $s === 409, $b);
[$s, $b] = call('POST', "/loans/{$loanId}/approve", ['reason' => 'Income verified'], $manager);
check('manager approves', $s === 200 && $b['data']['status'] === 'approved', $b);

[$s, $b] = call('POST', '/loans', ['customer_id' => $customerId, 'product_id' => (int) $products['PL-MONTHLY']['id'], 'principal' => '20000', 'tenure' => 6], $manager);
[$s, $b] = call('POST', "/loans/{$b['data']['id']}/approve", [], $manager);
check('maker-checker: cannot approve own application -> 403', $s === 403, $b);

[$s, $b] = call('POST', "/loans/{$loanId}/disburse", ['method' => 'upi'], $finance);
check('non-cash disbursement needs reference -> 422', $s === 422, $b);
[$s, $b] = call('POST', "/loans/{$loanId}/disburse", ['method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT)], $finance);
check('finance disburses -> active with 12 installments', $s === 200 && $b['data']['status'] === 'active' && count($b['data']['installments']) === 12, $b['data']['status'] ?? $b);
check('net disbursed = 100000 - 2000 fee - 360 GST', ($b['data']['net_disbursement'] ?? '') === '97640.0000', $b['data']['net_disbursement'] ?? null);
check('trial balance balanced after disbursement', trialBalanced($finance));
check('loan receivable = 100000', bccomp(ledgerBalance('1100'), '100000', 2) === 0, ledgerBalance('1100'));

echo "Payments & idempotency\n";
$key = 'test-' . bin2hex(random_bytes(8));
$body = ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '5000', 'method' => 'cash'];
[$s, $b] = pay($agent1, $body, $key);
check('agent collects cash -> confirmed with receipt', $s === 201 && $b['data']['status'] === 'confirmed' && str_starts_with((string) $b['data']['receipt_number'], 'RCP'), $b);
$cashPaymentId = (int) $b['data']['id'];
$verification = $b['data']['verification_code'];
check("advance payment allocated interest first ({$firstInterest}) then principal",
    array_column($b['data']['allocations'], 'amount', 'component') == ['interest' => bcadd($firstInterest, '0', 4), 'principal' => bcsub('5000', $firstInterest, 4)], $b['data']['allocations']);

[$s, $b] = pay($agent1, $body, $key);
check('exact retry returns same payment (200)', $s === 200 && (int) $b['data']['id'] === $cashPaymentId && ($b['meta']['idempotent_replay'] ?? false), $b);
[$s, $b] = pay($agent1, ['amount' => '6000'] + $body, $key);
check('same key, different body -> 409', $s === 409, $b);
check('only one payment row for the key', (int) Database::value('SELECT COUNT(*) FROM payments WHERE idempotency_key = ?', [$key]) === 1);
[$s, $b] = call('POST', '/payments', $body, $agent1);
check('missing Idempotency-Key -> 400', $s === 400, $b);

[$s, $b] = pay($agent2, $body);
check('unassigned agent cannot pay into loan -> 422', $s === 422, $b);
[$s, $b] = pay($agent1, ['amount' => '500000'] + $body);
check('overpayment -> 422', $s === 422 && isset($b['error']['fields']['amount']), $b);

[$s, $b] = call('GET', "/receipts/verify/{$verification}");
check('public receipt verification works without login', $s === 200 && $b['data']['amount'] === '5000.0000' && $b['data']['customer'] === 'Meena K.', $b);

$upiRef = (string) random_int(100000000000, 999999999999); // 12-digit UTR
[$s, $b] = pay($agent1, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '1000', 'method' => 'upi', 'reference' => $upiRef]);
check('agent UPI payment is pending until confirmed', $s === 201 && $b['data']['status'] === 'pending' && $b['data']['receipt_number'] === null, $b);
$upiId = (int) $b['data']['id'];
[$s, $b] = pay($agent1, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '1000', 'method' => 'upi', 'reference' => $upiRef]);
check('duplicate UPI reference rejected -> 422', $s === 422 && isset($b['error']['fields']['reference']), $b);
[$s, $b] = call('POST', "/payments/{$upiId}/confirm", [], $agent1);
check('agent cannot confirm -> 403', $s === 403, $b);
[$s, $b] = call('POST', "/payments/{$upiId}/confirm", [], $finance);
check('finance confirms UPI -> receipt issued', $s === 200 && $b['data']['status'] === 'confirmed' && $b['data']['receipt_number'] !== null, $b);
check('trial balance balanced after payments', trialBalanced($finance));

echo "Overdue, late fee and collection list\n";
Database::query('UPDATE loan_installments SET due_date = ? WHERE loan_id = ? AND number = 1', [$daysAgo(10), $loanId]);
Database::query('UPDATE loan_installments SET due_date = ? WHERE loan_id = ? AND number = 2', [$today, $loanId]);
[$s, $b] = call('GET', "/loans/{$loanId}", null, $officer);
$inst1 = $b['data']['installments'][0];
check('late fee 500 applied to installment overdue past grace', $inst1['late_fee_due'] === '500.0000', $inst1);
check('position shows 10 days past due', $b['data']['position']['days_past_due'] === 10, $b['data']['position']);
[$s, $b] = call('GET', '/collections/today', null, $agent1);
$row = $b['data']['loans'][0] ?? null;
check('agent sees loan in today\'s collection list', $row !== null && (int) $row['loan_id'] === $loanId && $row['days_past_due'] === 10, $b['data'] ?? $b);
[$s, $b] = call('GET', '/collections/today', null, $agent2);
check('other agent does not', $s === 200 && $b['data']['loans'] === [], $b['data'] ?? $b);
$due = $row['amount_due'];
[$s, $b] = pay($agent1, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '600', 'method' => 'cash']);
$alloc = $b['data']['allocations'] ?? [];
check('allocation order: late fee (500) before interest', ($alloc[0]['component'] ?? '') === 'late_fee' && $alloc[0]['amount'] === '500.0000' && ($alloc[1]['component'] ?? '') === 'interest', $alloc);
[$s, $b] = call('POST', '/visits', ['customer_id' => $customerId, 'loan_id' => $loanId, 'outcome' => 'promised', 'promise_date' => $today, 'notes' => 'Will pay rest on Friday'], $agent1);
check('agent records visit outcome', $s === 201, $b);

echo "Settlement & reversal\n";
[$s, $q] = call('GET', "/loans/{$loanId}/settlement-quote", null, $finance);
check('settlement quote waives future interest', $s === 200 && bccomp($q['data']['interest_waived'], '0', 2) > 0, $q);
[$s, $b] = pay($finance, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '100', 'method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), 'purpose' => 'settlement']);
check('wrong settlement amount -> 422', $s === 422, $b);
[$s, $b] = pay($finance, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '100', 'method' => 'cheque', 'reference' => 'CHQ123456']);
check('only cash and UPI are accepted (cheque -> 422)', $s === 422 && isset($b['error']['fields']['method']), $b);
[$s, $b] = pay($finance, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => $q['data']['settlement_amount'], 'method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), 'purpose' => 'settlement']);
check('settlement payment confirmed', $s === 201 && $b['data']['status'] === 'confirmed', $b);
[$s, $b] = call('GET', "/loans/{$loanId}", null, $finance);
check('loan closed as settled, nothing outstanding', $b['data']['status'] === 'closed' && $b['data']['closure_type'] === 'settled' && $b['data']['position']['total_outstanding'] === '0.00', [$b['data']['status'], $b['data']['position'] ?? null]);
check('loan receivable back to 0', bccomp(ledgerBalance('1100'), '0', 2) === 0, ledgerBalance('1100'));
check('trial balance balanced after settlement', trialBalanced($finance));

[$s, $b] = call('POST', "/payments/{$upiId}/reverse", ['reason' => 'Bank reported chargeback'], $agent1);
check('agent cannot reverse -> 403', $s === 403, $b);
[$s, $b] = call('POST', "/payments/{$upiId}/reverse", ['reason' => 'Bank reported chargeback'], $finance);
check('finance requests reversal -> 202, payment unchanged until approved', $s === 202 && $b['data']['status'] === 'confirmed' && isset($b['data']['approval_request_id']), $b);
$approvalId = $b['data']['approval_request_id'] ?? 0;
[$s, $b] = call('POST', "/approvals/{$approvalId}/decide", ['decision' => 'approved'], $finance);
check('requester cannot approve own reversal -> 403', $s === 403, $b);
[$s, $b] = call('POST', "/approvals/{$approvalId}/decide", ['decision' => 'approved'], $admin);
check('admin approves -> reversal executes', $s === 200, $b);
[, $b] = call('GET', "/payments/{$upiId}", null, $finance);
check('payment reversed with linked entry', $b['data']['status'] === 'reversed' && $b['data']['reversal_entry_id'] !== null, $b['data']['status'] ?? $b);
[$s, $b] = call('GET', "/loans/{$loanId}", null, $finance);
check('reversal reopens loan with 1000 outstanding', $b['data']['status'] === 'active' && bccomp($b['data']['position']['total_outstanding'], '1000', 2) === 0, [$b['data']['status'], $b['data']['position'] ?? null]);
check('original payment preserved (not deleted)', Database::value('SELECT status FROM payments WHERE id = ?', [$upiId]) === 'reversed');
check('trial balance balanced after reversal', trialBalanced($finance));

echo "Cash handover\n";
[$s, $b] = call('GET', '/cash/my-summary', null, $agent1);
check('agent expected cash = 5600', $s === 200 && $b['data']['expected_cash'] === '5600.00', $b['data'] ?? $b);
[$s, $b] = call('POST', '/cash-handovers', ['declared_cash' => '5600'], $agent1);
check('agent submits handover', $s === 201 && $b['data']['status'] === 'submitted' && count($b['data']['payments']) === 2, $b);
$handoverId = (int) $b['data']['id'];
[$s, $b] = call('POST', '/cash-handovers', ['declared_cash' => '0'], $agent1);
check('second handover same day -> 409', $s === 409, $b);
[$s, $b] = call('POST', "/payments/{$cashPaymentId}/reverse", ['reason' => 'Test reversal during handover'], $finance);
check('cannot reverse cash inside unverified handover -> 409', $s === 409, $b);
[$s, $b] = call('POST', "/cash-handovers/{$handoverId}/verify", ['verified_cash' => '5600'], $agent1);
check('agent cannot verify own handover -> 403', $s === 403, $b);
[$s, $b] = call('POST', "/cash-handovers/{$handoverId}/verify", ['verified_cash' => '5500', 'note' => 'Short by 100'], $supervisor);
check('supervisor counts 5500 -> discrepancy -100', $s === 200 && $b['data']['status'] === 'discrepancy' && $b['data']['difference'] === '-100.0000', $b);
check('agent cash ledger still holds the 100 shortfall', bccomp(ledgerBalance('1010'), '100', 2) === 0, ledgerBalance('1010'));
[$s, $b] = call('POST', "/cash-handovers/{$handoverId}/resolve", ['resolution' => 'overage_income', 'note' => 'Wrong type'], $finance);
check('shortage cannot be taken to income -> 422', $s === 422, $b);
[$s, $b] = call('POST', "/cash-handovers/{$handoverId}/resolve", ['resolution' => 'recovered', 'note' => 'Agent paid the shortfall'], $finance);
check('finance resolves: recovered', $s === 200 && $b['data']['status'] === 'resolved', $b);
check('agent cash ledger cleared to 0', bccomp(ledgerBalance('1010'), '0', 2) === 0, ledgerBalance('1010'));
check('trial balance balanced after handover', trialBalanced($finance));

echo "Deposits (compliance gate)\n";
$depositProducts = array_column(call('GET', '/deposit-products', null, $admin)[1]['data'], null, 'code');
[$s, $b] = call('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $depositProducts['RD-DAILY']['id'], 'amount' => '100'], $officer);
check('deposits disabled -> 403 FEATURE_DISABLED', $s === 403 && $b['error']['code'] === 'FEATURE_DISABLED', $b);
[$s, $b] = call('PATCH', '/settings', ['deposits_enabled' => true, 'reason' => 'Test'], $admin);
check('enabling deposits requires a reason -> 422', $s === 422, $b);
[$s, $b] = call('PATCH', '/settings', ['deposits_enabled' => true], $manager);
check('only super admin can change settings -> 403', $s === 403, $b);
[$s, $b] = call('PATCH', '/settings', ['deposits_enabled' => true, 'reason' => 'Legal approval ref LEG-2026-14'], $admin);
check('super admin enables deposits', $s === 200 && $b['data']['deposits_enabled'] === '1', $b);

[$s, $b] = call('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $depositProducts['RD-DAILY']['id'], 'amount' => '100'], $officer);
check('officer opens recurring deposit', $s === 201 && $b['data']['status'] === 'active', $b);
$depositId = (int) $b['data']['id'];
// A saver 30 days in who has paid nothing: one collection may catch up all 30 daily installments.
Database::query('UPDATE deposit_accounts SET opened_on = ?, last_accrual_date = ? WHERE id = ?', [$daysAgo(29), $daysAgo(30), $depositId]);
[$s, $b] = pay($agent1, ['account_type' => 'deposit', 'account_id' => $depositId, 'amount' => '3000', 'method' => 'cash']);
check('agent collects 30 missed installments at once', $s === 201 && $b['data']['status'] === 'confirmed', $b);
check('customer deposit liability = 3000', bccomp(ledgerBalance('2000'), '-3000', 2) === 0, ledgerBalance('2000'));
[$s, $b] = call('POST', "/deposit-accounts/{$depositId}/payout-request", [], $officer);
check('premature payout blocked by 90-day lock-in -> 409', $s === 409, $b);

// Simulate the deposit having run its full year.
Database::query('UPDATE deposit_accounts SET opened_on = ?, maturity_date = ?, last_accrual_date = ? WHERE id = ?', [$daysAgo(400), $daysAgo(35), $daysAgo(366), $depositId]);
exec('php ' . dirname(__DIR__) . '/bin/daily.php', $out, $code);
check('daily job runs', $code === 0, $out);
[$s, $b] = call('GET', "/deposit-accounts/{$depositId}", null, $officer);
check('deposit matured with interest accrued', $b['data']['status'] === 'matured' && bccomp($b['data']['accrued_interest'], '0', 2) > 0, [$b['data']['status'] ?? null, $b['data']['accrued_interest'] ?? null]);
check('trial balance balanced after accrual', trialBalanced($finance));

[$s, $b] = call('POST', "/deposit-accounts/{$depositId}/payout-request", ['note' => 'Maturity'], $officer);
check('maturity payout requested', $s === 201 && $b['data']['payout_type'] === 'maturity' && $b['data']['status'] === 'requested', $b);
$payoutId = (int) $b['data']['id'];
[$s, $b] = call('POST', "/payout-requests/{$payoutId}/pay", ['method' => 'cash'], $finance);
check('cannot pay before approval -> 409', $s === 409, $b);
[$s, $b] = call('POST', "/payout-requests/{$payoutId}/decide", ['decision' => 'approved'], $manager);
check('manager approves payout', $s === 200 && $b['data']['status'] === 'approved', $b);
[$s, $b] = call('POST', "/payout-requests/{$payoutId}/pay", ['method' => 'upi', 'reference' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT)], $finance);
check('finance pays out', $s === 200 && $b['data']['status'] === 'paid', $b);
check('deposit liability and accrued interest cleared', bccomp(ledgerBalance('2000'), '0', 2) === 0 && bccomp(ledgerBalance('2010'), '0', 4) === 0, [ledgerBalance('2000'), ledgerBalance('2010')]);
check('trial balance balanced after payout', trialBalanced($finance));

echo "Customer portal\n";
$portalEmail = 'meena' . random_int(1000, 9999) . '@example.com';
[$s, $b] = call('POST', "/customers/{$customerId}/portal-access", ['email' => $portalEmail, 'password' => 'Customer@123'], $manager);
check('manager enables portal login', $s === 200 && $b['data']['portal_email'] === $portalEmail, $b);
[, $b] = call('POST', '/auth/login', ['email' => $portalEmail, 'password' => 'Customer@123']);
$customerToken = $b['data']['token'] ?? '';
check('customer logs in with own scope', ($b['data']['user']['customer_scope'] ?? '') === 'own' && (int) $b['data']['user']['customer_id'] === $customerId, $b);
[$s, $b] = call('GET', '/loans', null, $customerToken);
check('customer sees only own loans', $s === 200 && array_unique(array_column($b['data'], 'customer_id')) === [(string) $customerId], $b);
[$s, $b] = call('GET', '/customers', null, $customerToken);
check('customer list shows only self', $s === 200 && $b['meta']['total'] === 1, $b);
[$s, $b] = pay($customerToken, ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '10', 'method' => 'cash']);
check('customer cannot record payments -> 403', $s === 403, $b);

echo "Reports, audit, users\n";
[$s, $b] = call('GET', '/reports/loan-portfolio', null, $manager);
check('loan portfolio report', $s === 200 && isset($b['data']['summary']['par_30_percent']), $b);
[$s, $b, $h] = call('GET', '/reports/daily-collection?format=csv', null, $finance);
check('daily collection CSV export', $s === 200 && str_contains($h, 'text/csv') && str_starts_with((string) $b, 'collector,branch,cash'), substr((string) $b, 0, 100));
[$s, $b] = call('GET', '/reports/trial-balance', null, $manager);
check('branch manager cannot see trial balance -> 403', $s === 403, $b);
[$s, $b] = call('GET', '/audit-logs?action=payment', null, $auditor);
check('auditor reads audit trail', $s === 200 && $b['meta']['total'] > 0, $b['meta'] ?? $b);
[$s, $b] = call('GET', '/audit-logs', null, $agent1);
check('agent cannot read audit trail -> 403', $s === 403, $b);
[$s, $b] = call('POST', '/users', ['name' => 'New Agent', 'email' => 'new' . random_int(1000, 9999) . '@finserve.local', 'password' => 'Agent@1234', 'roles' => ['collection_agent'], 'branch_id' => (int) Database::value("SELECT id FROM branches WHERE code = 'BR01'")], $admin);
check('admin creates staff user', $s === 201 && $b['data']['roles'] === ['collection_agent'], $b);
[$s, $b] = call('POST', '/users', ['name' => 'X', 'email' => 'x@finserve.local', 'password' => 'Agent@1234', 'roles' => ['super_admin']], $manager);
check('manager cannot manage users -> 403', $s === 403, $b);

[$s, $b] = call('GET', '/dashboard', null, $finance);
check('dashboard returns KPIs', $s === 200 && isset($b['data']['loans']['principal_outstanding'], $b['data']['pending']), $b);

// Leave deposits disabled, as the compliance default requires.
call('PATCH', '/settings', ['deposits_enabled' => false, 'reason' => 'Restore default after test'], $admin);

echo $failures === 0 ? "\nAll finance checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
