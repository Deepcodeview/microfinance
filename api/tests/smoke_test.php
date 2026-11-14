<?php

declare(strict_types=1);

// End-to-end API checks against a running, seeded (--demo) instance.
// Usage (inside the api container): php tests/smoke_test.php [base_url]
// Uses SEED_PASSWORD (default Admin@12345). Creates test customers with random phone numbers.

require dirname(__DIR__) . '/src/bootstrap.php';

$base = $argv[1] ?? 'http://localhost/api/v1';
$password = getenv('SEED_PASSWORD') ?: 'Admin@12345';
$failures = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    // These suites test money logic, not the one-collection-a-day prompt (collection_rules_test.php covers it).
    if ($method === 'POST' && $path === '/payments' && is_array($body) && !isset($body['allow_repeat'])) {
        $body['allow_repeat'] = true;
    }
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, json_decode((string) $raw, true)];
}

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '       ' . json_encode($detail) . "\n";
    }
}

function login(string $email): string
{
    global $password;
    [$status, $body] = call('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    check("login {$email}", $status === 200, $body);
    return $body['data']['token'] ?? '';
}

$phone = fn () => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$customerInput = fn () => [
    'full_name' => 'Test Customer',
    'phone' => $phone(),
    'address_line' => '12 MG Road',
    'city' => 'Pune',
    'state' => 'Maharashtra',
    'pincode' => '411001',
    'monthly_income' => '35000',
];

echo "Auth\n";
[$s, $b] = call('GET', '/me');
check('no token -> 401', $s === 401 && $b['error']['code'] === 'UNAUTHENTICATED', $b);
[$s, $b] = call('POST', '/auth/login', ['email' => 'admin@finserve.local', 'password' => 'wrong']);
check('wrong password -> 401', $s === 401, $b);
$admin = login('admin@finserve.local');
$manager = login('manager@finserve.local');
$agent1 = login('agent1@finserve.local');
$agent2 = login('agent2@finserve.local');
[$s, $b] = call('GET', '/me', null, $admin);
check('me returns permissions', $s === 200 && in_array('loan_products.manage', $b['data']['permissions'], true), $b);

echo "Customers & scope\n";
[$s, $b] = call('POST', '/customers', ['full_name' => 'X'], $manager);
check('missing fields -> 422 with field errors', $s === 422 && isset($b['error']['fields']['phone']), $b);

$agents = call('GET', '/agents', null, $manager)[1]['data'];
$agent1Id = $agents[array_search('Ravi Agent', array_column($agents, 'name'), true)]['id'];

[$s, $b] = call('POST', '/customers', $customerInput() + ['agent_user_id' => (int) $agent1Id], $manager);
check('manager creates customer in own branch', $s === 201 && str_starts_with($b['data']['customer_number'], 'CUS'), $b);
$customer = $b['data'];

[$s, $b] = call('POST', '/customers', ['phone' => $customer['phone']] + $customerInput(), $manager);
check('duplicate phone -> 422', $s === 422 && isset($b['error']['fields']['phone']), $b);

[$s, $b] = call('GET', "/customers/{$customer['id']}", null, $agent1);
check('assigned agent can view customer', $s === 200, $b);
[$s, $b] = call('GET', "/customers/{$customer['id']}", null, $agent2);
check('unassigned agent gets 404', $s === 404, $b);
[$s, $b] = call('GET', '/customers?per_page=100', null, $agent2);
check('unassigned agent list excludes customer', $s === 200 && !in_array($customer['id'], array_column($b['data'], 'id'), true), $b);
[$s, $b] = call('POST', '/customers', $customerInput(), $agent1);
check('agent onboards a customer, auto-assigned to themselves', $s === 201 && $b['data']['assignments'][0]['agent_name'] === 'Ravi Agent', $b);

[$s, $b] = call('PATCH', "/customers/{$customer['id']}", ['city' => 'Mumbai'], $manager);
check('manager updates customer', $s === 200 && $b['data']['city'] === 'Mumbai', $b);

[$s, $b] = call('GET', "/customers?q={$customer['customer_number']}", null, $admin);
check('admin search by customer number', $s === 200 && $b['meta']['total'] === 1, $b);

echo "Loan products\n";
[$s, $b] = call('GET', '/loan-products', null, $admin);
check('list products', $s === 200 && count($b['data']) >= 2, $b);
$personal = $b['data'][array_search('PL-MONTHLY', array_column($b['data'], 'code'), true)];

[$s, $b] = call('GET', '/loan-products', null, $agent1);
check('agent can view products (to take loan requests)', $s === 200, $b);
[$s, $b] = call('POST', '/loan-products', ['code' => 'AGENT-X', 'name' => 'Agent product', 'terms' => $b['data'][0]['terms'] ?? []], $agent1);
check('agent cannot create or change products -> 403', $s === 403, $b);

$terms = $personal['terms'];
[$s, $b] = call('POST', '/loan-products/preview', ['terms' => $terms, 'principal' => '100000', 'tenure' => 12], $admin);
// 1,00,000 at 18% reducing, 12 months: EMI 9167.98 -> rounded to rupee 9168.
check('EMI preview 100000 @18% x12 = 9168', $s === 200 && $b['data']['installment_amount'] === '9168.00', $b['data'] ?? $b);
$schedule = $b['data']['schedule'] ?? [];
check('schedule ends at zero balance', end($schedule)['balance'] === '0.00', end($schedule));
check('processing fee 2% + 18% GST', $b['data']['processing_fee'] === '2000.00' && $b['data']['tax_on_processing_fee'] === '360.00', $b['data']);

$flat = array_merge($terms, ['interest_method' => 'flat', 'annual_interest_rate' => '12.0000']);
[$s, $b] = call('POST', '/loan-products/preview', ['terms' => $flat, 'principal' => '120000', 'tenure' => 12], $admin);
check('flat 120000 @12% x12 -> interest 14400, EMI 11200', $b['data']['total_interest'] === '14400.00' && $b['data']['installment_amount'] === '11200.00', $b['data'] ?? $b);

$yearly = array_merge($terms, ['min_tenure' => 1, 'repayment_frequency' => 'yearly', 'annual_interest_rate' => '12.0000', 'processing_fee_percent' => '0.0000']);
[$s, $b] = call('POST', '/loan-products/preview', ['terms' => $yearly, 'principal' => '100000', 'tenure' => 2], $admin);
// 1,00,000 at 12% reducing, 2 yearly installments: EMI = 100000*0.12*1.2544/0.2544 = 59169.81 -> 59170.
check('yearly EMI 100000 @12% x2 years = 59170', $s === 200 && $b['data']['installment_amount'] === '59170.00' && end($b['data']['schedule'])['balance'] === '0.00', $b['data'] ?? $b);
[$s, $b] = call('POST', '/loan-products/preview', ['terms' => array_merge($yearly, ['repayment_frequency' => 'quarterly']), 'principal' => '100000', 'tenure' => 4], $admin);
check('quarterly preview has 4 installments', $s === 200 && count($b['data']['schedule'] ?? []) === 4, $b['data'] ?? $b);
check('yearly due dates step a year, clamped at month end', \App\Services\LoanService::addPeriods('2024-02-29', 'yearly', 1) === '2025-02-28'
    && \App\Services\LoanService::addPeriods('2026-01-31', 'quarterly', 1) === '2026-04-30', null);

[$s, $b] = call('POST', "/loan-products/{$personal['id']}/versions", ['terms' => array_merge($terms, ['annual_interest_rate' => 'abc']), 'change_reason' => 'Bad input'], $admin);
check('invalid rate -> 422 terms.annual_interest_rate', $s === 422 && isset($b['error']['fields']['terms.annual_interest_rate']), $b);

[$s, $b] = call('POST', "/loan-products/{$personal['id']}/versions", ['terms' => array_merge($terms, ['allocation_order' => ['principal', 'interest']]), 'change_reason' => 'Bad order'], $admin);
check('incomplete allocation order -> 422', $s === 422 && isset($b['error']['fields']['terms.allocation_order']), $b);

$newRate = $terms['annual_interest_rate'] === '17.5000' ? '18.0000' : '17.5000';
[$s, $b] = call('POST', "/loan-products/{$personal['id']}/versions", ['terms' => array_merge($terms, ['annual_interest_rate' => $newRate]), 'change_reason' => 'Rate revision (smoke test)'], $admin);
check('rate change creates new version', $s === 200 && $b['data']['version'] === (string) ((int) $personal['version'] + 1) && $b['data']['terms']['annual_interest_rate'] === $newRate, $b);
check('previous version preserved', count($b['data']['versions'] ?? []) >= 2, $b);

[$s, $b] = call('POST', "/loan-products/{$personal['id']}/versions", ['terms' => array_merge($terms, ['annual_interest_rate' => '20.0000']), 'change_reason' => 'Not allowed'], $manager);
check('manager cannot change terms -> 403', $s === 403, $b);

echo "Funds\n";
[$s, $f0] = call('GET', '/accounting/funds', null, $admin);
check('funds position readable', $s === 200 && isset($f0['data']['bank']), $f0);
[$s, $b] = call('POST', '/accounting/funds', ['type' => 'capital', 'amount' => '250000', 'reference' => 'OWNER-1'], $admin);
check('super admin adds capital into bank', $s === 201 && bccomp($b['data']['bank'], bcadd($f0['data']['bank'], '250000', 2), 2) === 0, $b);
[$s, $b] = call('POST', '/accounting/funds', ['type' => 'bank_to_cash', 'amount' => '999999999', 'branch_id' => (int) $customer['branch_id']], $admin);
check('cannot move more than the bank holds -> 409', $s === 409, $b);
[$s, $b] = call('POST', '/accounting/funds', ['type' => 'bank_to_cash', 'amount' => '5000', 'branch_id' => (int) $customer['branch_id']], $admin);
check('bank to branch cash moves money', $s === 201, $b);
[$s, $b] = call('POST', '/accounting/funds', ['type' => 'capital', 'amount' => '100'], $manager);
check('branch manager cannot add funds -> 403', $s === 403, $b);

echo "Single-owner approval\n";
[$s, $own] = call('POST', '/loans', ['customer_id' => (int) $customer['id'], 'product_id' => (int) $personal['id'], 'principal' => '20000', 'tenure' => 12], $admin);
check('super admin applies for a loan', $s === 201, $own);
[$s, $b] = call('POST', "/loans/{$own['data']['id']}/approve", ['decision' => 'approved'], $admin);
check('own loan blocked by default (maker-checker) -> 403', $s === 403 && str_contains($b['error']['message'], 'Settings'), $b);
call('PATCH', '/settings', ['owner_self_approval' => true], $admin);
[$s, $b] = call('GET', '/me', null, $admin);
check('me reports self-approval on', ($b['data']['settings']['self_approval'] ?? null) === true, $b['data']['settings'] ?? $b);
[$s, $b] = call('POST', "/loans/{$own['data']['id']}/approve", ['decision' => 'approved'], $admin);
check('single-owner mode lets super admin approve own loan', $s === 200, $b);
call('PATCH', '/settings', ['owner_self_approval' => false], $admin);

echo "Plan delete\n";
$code = 'DEL-' . random_int(1000, 9999);
[$s, $b] = call('POST', '/loan-products', ['code' => $code, 'name' => 'Created by mistake', 'terms' => $terms], $admin);
check('super admin creates a throwaway plan', $s === 201 && $b['data']['accounts_count'] === '0', $b);
$unused = $b['data']['id'] ?? 0;
[$s, $b] = call('DELETE', "/loan-products/{$unused}", null, $manager);
check('branch manager cannot delete plans -> 403', $s === 403, $b);
[$s] = call('DELETE', "/loan-products/{$unused}", null, $admin);
check('super admin deletes an unused plan -> 204', $s === 204);
[$s] = call('GET', "/loan-products/{$unused}", null, $admin);
check('deleted plan is gone -> 404', $s === 404);
[$s, $b] = call('DELETE', "/loan-products/{$personal['id']}", null, $admin);
check('plan with accounts cannot be deleted -> 409', $s === 409 && str_contains($b['error']['message'], 'Deactivate'), $b);

echo "Logout\n";
[$s] = call('POST', '/auth/logout', null, $agent2);
check('logout -> 204', $s === 204);
[$s] = call('GET', '/me', null, $agent2);
check('revoked token -> 401', $s === 401);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
