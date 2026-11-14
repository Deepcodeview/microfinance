<?php

declare(strict_types=1);

// Customer app self-service: public catalogue and calculators, self-registration, mobile-number login,
// applications from the app and staff review (registration approval, accept / reject into the normal flow).
// Run on a freshly seeded (--demo) database after the other suites.

use App\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://localhost/api/v1';
$failures = 0;

function req(string $method, string $path, mixed $body = null, ?string $token = null): array
{
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

function token(string $email, string $password = 'Admin@12345'): string
{
    return req('POST', '/auth/login', ['email' => $email, 'password' => $password])[1]['data']['token'] ?? '';
}

function registration(string $phone, array $extra = []): array
{
    return $extra + [
        'full_name' => 'Meena Devi', 'phone' => $phone, 'password' => 'Meena@2026',
        'address_line' => '4 Station Road', 'city' => 'Patna', 'state' => 'Bihar', 'pincode' => '800001',
    ];
}

$admin = token('admin@finserve.local');
$manager = token('manager@finserve.local');
$agent1 = token('agent1@finserve.local');
$agent1Id = (int) Database::value("SELECT id FROM users WHERE email = 'agent1@finserve.local'");
Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'deposits_enabled'");

echo "Public catalogue and calculators (no login)\n";
[$s, $b] = req('GET', '/public/catalog');
$catalog = $b['data'] ?? [];
$loanPlan = $catalog['loan_products'][0] ?? null;
ok('catalogue is public and lists loan plans', $s === 200 && $loanPlan !== null && isset($loanPlan['terms']['annual_interest_rate']), $b);
ok('internal terms are not exposed', $loanPlan !== null && !isset($loanPlan['terms']['allocation_order']) && !isset($loanPlan['terms']['installment_rounding']), $loanPlan);
ok('no savings plans while deposits are off', ($catalog['deposits_enabled'] ?? null) === false && $catalog['deposit_products'] === [], $catalog);
$branchId = (int) array_column($catalog['branches'], 'id', 'name')['City Branch'];
$terms = $loanPlan['terms'];
$money = fn (string $v) => number_format((float) $v, 2, '.', '');
$minAmount = $money($terms['min_amount']);
[$s, $b] = req('POST', "/public/loan-products/{$loanPlan['id']}/preview", ['principal' => $minAmount, 'tenure' => $terms['min_tenure']]);
ok('loan calculator works without login', $s === 200 && isset($b['data']['installment_amount']), $b);
[$s] = req('POST', "/public/loan-products/{$loanPlan['id']}/preview", ['principal' => '1', 'tenure' => $terms['min_tenure']]);
ok('calculator enforces plan limits -> 422', $s === 422);
[$s] = req('POST', '/public/loan-products/999999/preview', ['principal' => '1000', 'tenure' => 10]);
ok('unknown plan -> 404', $s === 404);

echo "Self-registration\n";
[$s, $b] = req('POST', '/public/register', ['full_name' => 'X']);
ok('missing fields -> 422', $s === 422 && isset($b['error']['fields']['phone'], $b['error']['fields']['password']), $b);
[$s, $b] = req('POST', '/public/register', registration('9123400001', ['branch_id' => $branchId]));
$meena = $b['data']['token'] ?? '';
$meenaCustomerId = (int) ($b['data']['user']['customer_id'] ?? 0);
ok('registers without email and is signed in', $s === 201 && $meena !== '' && $meenaCustomerId > 0, $b);
$row = Database::one('SELECT c.status, c.source, u.email FROM customers c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$meenaCustomerId]);
ok('new customer is pending and self-registered', $row['status'] === 'pending' && $row['source'] === 'self' && $row['email'] === null, $row);
[$s, $b] = req('POST', '/public/register', registration('+91 91234 00001'));
ok('same mobile in another format -> 422', $s === 422 && isset($b['error']['fields']['phone']), $b);
[$s, $b] = req('GET', '/notifications', null, $manager);
ok('branch manager is told about the registration', in_array('New customer registration', array_column($b['data']['items'] ?? [], 'title'), true), $b);

echo "Mobile-number sign-in\n";
ok('signs in with the 10-digit mobile', token('9123400001', 'Meena@2026') !== '');
ok('signs in with +91 and spaces', token('+91 91234 00001', 'Meena@2026') !== '');
[$s, $b] = req('POST', '/auth/login', ['email' => '9123400001', 'password' => 'wrong-pass']);
ok('wrong password -> 401 mentions mobile number', $s === 401 && str_contains($b['error']['message'], 'mobile'), $b);
[$s, $b] = req('GET', "/customers/{$meenaCustomerId}", null, $meena);
ok('pending customer can see their own profile', $s === 200 && $b['data']['status'] === 'pending', $b);

echo "Applications from the app\n";
$apply = fn (array $body, string $token) => req('POST', '/my/applications', $body, $token);
[$s, $b] = $apply(['type' => 'loan', 'product_id' => (int) $loanPlan['id'], 'amount' => $minAmount, 'tenure' => (int) $terms['max_tenure'] + 1], $meena);
ok('tenure outside the plan -> 422', $s === 422 && isset($b['error']['fields']['tenure']), $b);
[$s, $b] = $apply(['type' => 'loan', 'product_id' => (int) $loanPlan['id'], 'amount' => $minAmount, 'tenure' => (int) $terms['min_tenure'], 'purpose' => 'Shop stock'], $meena);
$loanApp = (int) ($b['data']['id'] ?? 0);
ok('pending customer can apply for a loan', $s === 201 && $b['data']['status'] === 'submitted' && str_starts_with($b['data']['application_number'], 'APP'), $b);
$depositPlan = Database::one("SELECT id FROM deposit_products WHERE status = 'active' ORDER BY id LIMIT 1");
[$s] = $apply(['type' => 'savings', 'product_id' => (int) $depositPlan['id'], 'amount' => '100'], $meena);
ok('savings application blocked while deposits are off -> 403', $s === 403);
Database::query("UPDATE settings SET setting_value = '1' WHERE setting_key = 'deposits_enabled'");
$savingsPlan = req('GET', '/public/catalog')[1]['data']['deposit_products'];
$recurring = array_values(array_filter($savingsPlan, fn ($p) => $p['terms']['scheme'] === 'recurring'))[0];
[$s, $b] = $apply(['type' => 'savings', 'product_id' => (int) $recurring['id'], 'amount' => $money($recurring['terms']['min_installment'])], $meena);
$savingsApp = (int) ($b['data']['id'] ?? 0);
ok('savings application once deposits are on', $s === 201 && $b['data']['type'] === 'savings', $b);
[$s, $b] = req('GET', '/my/applications', null, $meena);
ok('customer lists own applications', $s === 200 && count($b['data']) === 2, $b);
[$s] = req('GET', '/applications', null, $meena);
ok('customer cannot open the staff review list -> 403', $s === 403);

echo "Staff review\n";
[$s, $b] = req('GET', '/applications?status=submitted', null, $agent1);
ok('unassigned agent does not see the applications', $s === 200 && !in_array($loanApp, array_map('intval', array_column($b['data'], 'id')), true), $b);
[$s] = req('POST', "/applications/{$loanApp}/accept", [], $agent1);
ok('unassigned agent cannot accept -> 404', $s === 404);
[$s, $b] = req('POST', "/applications/{$loanApp}/accept", [], $manager);
ok('accepting before the registration is approved -> 409', $s === 409 && str_contains($b['error']['message'], 'registration'), $b);
[$s] = req('POST', "/customers/{$meenaCustomerId}/approve-registration", [], $agent1);
ok('agent cannot approve registrations -> 403', $s === 403);
[$s, $b] = req('PATCH', "/customers/{$meenaCustomerId}", ['status' => 'active'], $manager);
ok('editing status cannot skip approval -> 422', $s === 422 && isset($b['error']['fields']['status']), $b);
$dash = req('GET', '/dashboard', null, $manager)[1]['data']['pending'] ?? [];
ok('dashboard counts registrations and applications', ($dash['registrations'] ?? 0) >= 1 && ($dash['applications'] ?? 0) >= 2, $dash);
[$s, $b] = req('POST', "/customers/{$meenaCustomerId}/approve-registration", ['agent_user_id' => $agent1Id], $manager);
ok('manager approves the registration and assigns an agent', $s === 200 && $b['data']['status'] === 'active', $b);
[$s, $b] = req('GET', '/applications?status=submitted', null, $agent1);
ok('assigned agent now sees the applications', in_array($loanApp, array_map('intval', array_column($b['data'], 'id')), true), $b);
[$s, $b] = req('POST', "/applications/{$loanApp}/accept", [], $agent1);
$loanId = (int) ($b['data']['loan_id'] ?? 0);
ok('agent accepts the loan application -> loan created', $s === 200 && $b['data']['status'] === 'accepted' && $loanId > 0, $b);
$loan = Database::one('SELECT status, applied_by FROM loans WHERE id = ?', [$loanId]);
ok('loan waits for approval, applied by the agent', $loan['status'] === 'pending_approval' && (int) $loan['applied_by'] === $agent1Id, $loan);
[$s, $b] = req('POST', "/loans/{$loanId}/approve", [], $manager);
ok('manager approves the loan (next step: disbursement)', $s === 200 && $b['data']['status'] === 'approved', $b);
[$s] = req('POST', "/applications/{$loanApp}/accept", [], $manager);
ok('accepting twice -> 409', $s === 409);
[$s, $b] = req('POST', "/applications/{$savingsApp}/accept", [], $agent1);
$depositId = (int) ($b['data']['deposit_account_id'] ?? 0);
ok('agent accepts savings -> account opened', $s === 200 && $depositId > 0 && Database::value('SELECT status FROM deposit_accounts WHERE id = ?', [$depositId]) === 'active', $b);
[$s, $b] = req('GET', '/notifications', null, $meena);
ok('customer is notified of approval and acceptance', count(array_intersect(['Registration approved', 'Application accepted'], array_column($b['data']['items'] ?? [], 'title'))) === 2, $b);

echo "Reject and cancel\n";
[$s, $b] = $apply(['type' => 'loan', 'product_id' => (int) $loanPlan['id'], 'amount' => $minAmount, 'tenure' => (int) $terms['min_tenure']], $meena);
$second = (int) $b['data']['id'];
[$s] = req('POST', "/applications/{$second}/reject", ['reason' => ''], $manager);
ok('reject needs a reason -> 422', $s === 422);
[$s, $b] = req('POST', "/applications/{$second}/reject", ['reason' => 'Existing loan still running'], $manager);
ok('manager rejects with a reason', $s === 200 && $b['data']['status'] === 'rejected' && $b['data']['decision_note'] === 'Existing loan still running', $b);
[$s, $b] = $apply(['type' => 'savings', 'product_id' => (int) $recurring['id'], 'amount' => $money($recurring['terms']['min_installment'])], $meena);
$third = (int) $b['data']['id'];
[$s, $b] = req('POST', "/my/applications/{$third}/cancel", [], $meena);
ok('customer cancels a waiting application', $s === 200 && $b['data']['status'] === 'cancelled', $b);
[$s] = req('POST', "/my/applications/{$third}/cancel", [], $meena);
ok('cancelling twice -> 409', $s === 409);
$other = req('POST', '/public/register', registration('9123400002'))[1]['data']['token'] ?? '';
[$s] = req('POST', "/my/applications/{$loanApp}/cancel", [], $other);
ok("cannot touch another customer's application -> 404", $s === 404);

echo "Registration rejected\n";
$otherId = (int) Database::value("SELECT id FROM customers WHERE phone = '9123400002'");
[$s, $b] = req('POST', "/customers/{$otherId}/reject-registration", ['reason' => 'Address outside our area'], $admin);
ok('admin rejects a registration', $s === 200 && $b['data']['status'] === 'blocked', $b);
[$s] = $apply(['type' => 'loan', 'product_id' => (int) $loanPlan['id'], 'amount' => $minAmount, 'tenure' => (int) $terms['min_tenure']], $other);
ok('rejected customer cannot apply -> 403', $s === 403);
[$s, $b] = req('GET', "/customers/{$otherId}", null, $other);
ok('rejected customer still sees the reason', $s === 200 && $b['data']['registration_note'] === 'Address outside our area', $b);

echo "Registration rate limit\n";
$statuses = [];
for ($i = 3; $i <= 12; $i++) {
    $statuses[] = req('POST', '/public/register', registration(sprintf('91234%05d', $i)))[0];
}
ok('more than 10 registrations an hour from one network -> 429', end($statuses) === 429 && in_array(201, $statuses, true), $statuses);

Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'deposits_enabled'");
echo $failures === 0 ? "All self-service checks passed.\n" : "{$failures} self-service check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
