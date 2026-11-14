<?php

declare(strict_types=1);

// Tenure choices per plan: admins list several tenures (45 days, 3 months, 1 year) on loan and savings
// products; loans turn them into installment counts, savings use the chosen one for the maturity date.
// Run on a freshly seeded (--demo) database.

use App\Core\Database;
use App\Services\Settings;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://localhost/api/v1';
$failures = 0;

function req(string $method, string $path, mixed $body = null, ?string $token = null, array $extraHeaders = []): array
{
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

$admin = req('POST', '/auth/login', ['email' => 'admin@finserve.local', 'password' => 'Admin@12345'])[1]['data']['token'];
$manager = req('POST', '/auth/login', ['email' => 'manager@finserve.local', 'password' => 'Admin@12345'])[1]['data']['token'];
Database::query("UPDATE settings SET setting_value = '1' WHERE setting_key = 'deposits_enabled'");
$opt = fn (int $value, string $unit) => ['value' => $value, 'unit' => $unit];

echo "Loan plans with tenure choices\n";
$loans = array_column(req('GET', '/loan-products', null, $admin)[1]['data'], null, 'code');
$daily = $loans['DCL-DAILY']['terms'];
[$s, $b] = req('POST', '/loan-products', ['code' => 'DL-CHOICE', 'name' => 'Daily loan with choices', 'terms' => array_merge($daily, [
    'tenure_options' => [$opt(3, 'months'), $opt(45, 'days'), $opt(100, 'days')],
])], $admin);
$choice = $b['data']['terms'] ?? [];
ok('admin creates a daily loan with 45 days / 3 months / 100 days', $s === 201
    && array_column($choice['tenure_options'] ?? [], 'installments') === [45, 90, 100], $b);
ok('min / max tenure follow the choices', ($choice['min_tenure'] ?? 0) === 45 && ($choice['max_tenure'] ?? 0) === 100, $choice);
$choiceId = (int) ($b['data']['id'] ?? 0);
[$s, $b] = req('POST', '/loan-products', ['code' => 'WL-BAD', 'name' => 'Weekly bad', 'terms' => array_merge($loans['WL-WEEKLY']['terms'], [
    'tenure_options' => [$opt(45, 'days')],
])], $admin);
ok('45 days on a weekly loan is refused (not whole weeks)', $s === 422 && isset($b['error']['fields']['terms.tenure_options']), $b);
[$s, $b] = req('POST', '/loan-products', ['code' => 'WL-OK', 'name' => 'Weekly by months', 'terms' => array_merge($loans['WL-WEEKLY']['terms'], [
    'tenure_options' => [$opt(3, 'months'), $opt(6, 'months'), $opt(1, 'years')],
])], $admin);
ok('weekly loan: 3 months / 6 months / 1 year = 13 / 26 / 52 weeks', $s === 201 && array_column($b['data']['terms']['tenure_options'], 'installments') === [13, 26, 52], $b);
[$s, $b] = req('POST', '/loan-products', ['code' => 'ML-BAD', 'name' => 'Monthly bad', 'terms' => array_merge($loans['PL-MONTHLY']['terms'], [
    'tenure_options' => [$opt(10, 'days')],
])], $admin);
ok('days on a monthly loan are refused', $s === 422, $b);
[$s, $b] = req('POST', "/public/loan-products/{$choiceId}/preview", ['principal' => '10000', 'tenure' => 60]);
ok('a tenure that is not a choice -> 422 listing the choices', $s === 422 && str_contains($b['error']['fields']['tenure'] ?? '', '45 days, 3 months, 100 days'), $b);
[$s, $b] = req('POST', "/public/loan-products/{$choiceId}/preview", ['principal' => '10000', 'tenure' => 90]);
ok('3 months (90 installments) previews', $s === 200 && isset($b['data']['installment_amount']), $b);
$customerId = (int) Database::value("SELECT id FROM customers WHERE status = 'active' ORDER BY id LIMIT 1");
[$s, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => $choiceId, 'principal' => '10000', 'tenure' => 50], $manager);
ok('staff loan with a non-choice tenure -> 422', $s === 422 && isset($b['error']['fields']['tenure']), $b);
[$s, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => $choiceId, 'principal' => '10000', 'tenure' => 45], $manager);
ok('staff loan with 45 days', $s === 201 && (int) $b['data']['tenure'] === 45, $b);
$range = $loans['PL-MONTHLY']['terms'];
[$s] = req('POST', "/public/loan-products/{$loans['PL-MONTHLY']['id']}/preview", ['principal' => '20000', 'tenure' => (int) $range['min_tenure'] + 1]);
ok('plans without choices keep the min–max range', $s === 200);

echo "Savings plans with tenure choices\n";
$deposits = array_column(req('GET', '/deposit-products', null, $admin)[1]['data'], null, 'code');
$fd = $deposits['FD-12M'];
ok('seeded fixed deposit offers 45 days … 2 years', count($fd['terms']['tenure_options']) === 5 && $fd['terms']['tenure_options'][0] == $opt(45, 'days'), $fd['terms']);
[$s, $b] = req('POST', '/deposit-products', ['code' => 'RD-SHORT', 'name' => 'Too short', 'terms' => array_merge($deposits['RD-WEEKLY']['terms'], [
    'tenure_options' => [$opt(5, 'days')],
])], $admin);
ok('weekly savings for 5 days is refused (fewer than 2 installments)', $s === 422 && isset($b['error']['fields']['terms.tenure_options']), $b);
[$s, $b] = req('POST', '/deposit-products', ['code' => 'RD-NONE', 'name' => 'No tenure', 'terms' => array_merge($deposits['RD-WEEKLY']['terms'], ['tenure_options' => []])], $admin);
ok('savings plan needs at least one tenure', $s === 422 && isset($b['error']['fields']['terms.tenure_options']), $b);
$legacy = $deposits['RD-WEEKLY']['terms'];
unset($legacy['tenure_options']);
[$s, $b] = req('POST', '/deposit-products', ['code' => 'RD-LEGACY', 'name' => 'Old client', 'terms' => $legacy + ['tenure_months' => 9]], $admin);
ok('an old client sending tenure_months gets one 9-month choice', $s === 201 && $b['data']['terms']['tenure_options'] == [$opt(9, 'months')], $b);

[$s, $b] = req('POST', "/public/deposit-products/{$fd['id']}/preview", ['amount' => '10000', 'tenure_value' => 45, 'tenure_unit' => 'days']);
ok('FD calculator for 45 days', $s === 200 && $b['data']['tenure_days'] === 45 && $b['data']['installments'] === 1, $b);
[$s, $b] = req('POST', "/public/deposit-products/{$fd['id']}/preview", ['amount' => '10000', 'tenure_value' => 2, 'tenure_unit' => 'months']);
ok('FD calculator refuses a tenure the plan does not offer', $s === 422 && isset($b['error']['fields']['tenure']), $b);
$rdMonthly = $deposits['RD-MONTHLY'];
[$s, $b] = req('POST', "/public/deposit-products/{$rdMonthly['id']}/preview", ['amount' => '500', 'tenure_value' => 2, 'tenure_unit' => 'years']);
ok('monthly savings for 2 years = 24 installments', $s === 200 && $b['data']['installments'] === 24, $b);

$today = Settings::today();
[$s, $b] = req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $fd['id'], 'amount' => '10000', 'tenure_value' => 45, 'tenure_unit' => 'days'], $manager);
$expected = (new DateTimeImmutable($today))->modify('+45 days')->format('Y-m-d');
ok('staff opens a 45-day FD: matures in 45 days', $s === 201 && $b['data']['maturity_date'] === $expected && (int) $b['data']['tenure_value'] === 45 && $b['data']['tenure_unit'] === 'days', $b);
[$s, $b] = req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $fd['id'], 'amount' => '10000'], $manager);
ok('no tenure given -> the plan\'s first choice', $s === 201 && $b['data']['tenure_unit'] === 'days', $b);
[$s] = req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $fd['id'], 'amount' => '10000', 'tenure_value' => 7, 'tenure_unit' => 'weeks'], $manager);
ok('a tenure the plan does not offer -> 422', $s === 422);

echo "A different interest rate per tenure\n";
[$s, $b] = req('POST', '/deposit-products', ['code' => 'FD-RATES', 'name' => 'FD with tenure rates', 'terms' => array_merge($fd['terms'], [
    'annual_interest_rate' => '7.0000',
    'tenure_options' => [$opt(3, 'months') + ['rate' => '5'], $opt(1, 'years') + ['rate' => '7.5'], $opt(2, 'years')],
])], $admin);
$fdRates = (int) ($b['data']['id'] ?? 0);
ok('savings plan keeps a rate per tenure', $s === 201 && ($b['data']['terms']['tenure_options'][0]['rate'] ?? null) === '5.0000' && !isset($b['data']['terms']['tenure_options'][2]['rate']), $b);
$quote = fn (int $v, string $u) => req('POST', "/public/deposit-products/{$fdRates}/preview", ['amount' => '10000', 'tenure_value' => $v, 'tenure_unit' => $u])[1]['data'] ?? [];
$q3 = $quote(3, 'months'); $q12 = $quote(1, 'years'); $q24 = $quote(2, 'years');
ok('3 months uses 5%, 1 year 7.5%, 2 years the plan\'s 7%', ($q3['annual_interest_rate'] ?? '') === '5.0000' && ($q12['annual_interest_rate'] ?? '') === '7.5000' && ($q24['annual_interest_rate'] ?? '') === '7.0000', [$q3, $q12, $q24]);
ok('1 year at 7.5% earns Rs 750 on Rs 10,000', ($q12['interest_earned'] ?? '') === '750.00', $q12);
[$s] = req('POST', '/deposit-products', ['code' => 'FD-BADRATE', 'name' => 'Bad rate', 'terms' => array_merge($fd['terms'], ['tenure_options' => [$opt(1, 'years') + ['rate' => '40']]])], $admin);
ok('a savings rate above 15% -> 422', $s === 422);
[$s, $b] = req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => $fdRates, 'amount' => '10000', 'tenure_value' => 3, 'tenure_unit' => 'months'], $manager);
ok("the account carries its tenure's rate", $s === 201 && ($b['data']['terms']['annual_interest_rate'] ?? '') === '5.0000', $b['data']['terms'] ?? $b);
[$s, $b] = req('POST', '/loan-products', ['code' => 'DL-RATES', 'name' => 'Daily loan with tenure rates', 'terms' => array_merge($daily, [
    'annual_interest_rate' => '24.0000',
    'tenure_options' => [$opt(50, 'days') + ['rate' => '20'], $opt(100, 'days')],
])], $admin);
$dlRates = (int) ($b['data']['id'] ?? 0);
$lq = fn (int $n) => req('POST', "/public/loan-products/{$dlRates}/preview", ['principal' => '10000', 'tenure' => $n])[1]['data'] ?? [];
$l50 = $lq(50); $l100 = $lq(100);
ok('loan: 50 days at 20%, 100 days at the plan\'s 24%', ($l50['annual_interest_rate'] ?? '') === '20.0000' && ($l100['annual_interest_rate'] ?? '') === '24.0000', [$l50['annual_interest_rate'] ?? null, $l100['annual_interest_rate'] ?? null]);
[$s, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => $dlRates, 'principal' => '10000', 'tenure' => 50], $manager);
ok('the loan is priced at its tenure\'s rate', $s === 201 && $b['data']['total_interest'] === bcadd($l50['total_interest'], '0', 4) && ($b['data']['terms']['annual_interest_rate'] ?? '') === '20.0000', $b['data'] ?? $b);

echo "Flat return on the total deposited\n";
$rdDaily = $deposits['RD-DAILY'] ?? array_values(array_filter($deposits, fn ($p) => $p['terms']['scheme'] === 'recurring' && $p['terms']['contribution_frequency'] === 'daily'))[0];
[$s, $b] = req('POST', '/deposit-products', ['code' => 'RD-FLAT', 'name' => 'Daily savings, flat 8%', 'terms' => array_merge($rdDaily['terms'], [
    'interest_method' => 'flat', 'annual_interest_rate' => '8', 'min_installment' => '50',
    'tenure_options' => [$opt(180, 'days'), $opt(1, 'years') + ['rate' => '20']],
])], $admin);
$rdFlat = (int) ($b['data']['id'] ?? 0);
ok('flat savings plan saved (20% for 1 year is allowed for flat plans)', $s === 201 && $b['data']['terms']['interest_method'] === 'flat', $b);
$q = req('POST', "/public/deposit-products/{$rdFlat}/preview", ['amount' => '50', 'tenure_value' => 180, 'tenure_unit' => 'days'])[1]['data'] ?? [];
ok('Rs 50 a day for 180 days at 8% flat: 9,000 + 720 = 9,720', ($q['installments'] ?? 0) === 180 && $q['total_deposited'] === '9000.00' && $q['interest_earned'] === '720.00' && $q['maturity_value'] === '9720.00', $q);
$q = req('POST', "/public/deposit-products/{$rdFlat}/preview", ['amount' => '50', 'tenure_value' => 1, 'tenure_unit' => 'years'])[1]['data'] ?? [];
ok('1 year at its own 20% flat: 18,250 + 3,650', $q['total_deposited'] === '18250.00' && $q['interest_earned'] === '3650.00', $q);
[$s] = req('POST', '/deposit-products', ['code' => 'RD-FLAT-BAD', 'name' => 'Too high', 'terms' => array_merge($rdDaily['terms'], ['interest_method' => 'flat', 'annual_interest_rate' => '60'])], $admin);
ok('a flat rate above 50% -> 422', $s === 422);
[$s] = req('POST', '/deposit-products', ['code' => 'RD-DAY-BAD', 'name' => 'Too high', 'terms' => array_merge($rdDaily['terms'], ['interest_method' => 'daily', 'annual_interest_rate' => '20'])], $admin);
ok('a yearly rate above 15% is still refused -> 422', $s === 422);
$catalog = req('GET', '/public/catalog')[1]['data'] ?? [];
$listed = array_values(array_filter($catalog['deposit_products'] ?? $catalog['deposits'] ?? [], fn ($p) => (int) $p['id'] === $rdFlat))[0] ?? null;
ok('customer app catalog says the plan is flat', ($listed['terms']['interest_method'] ?? null) === 'flat', array_keys($catalog));

$fdFlat = (int) (req('POST', '/deposit-products', ['code' => 'FD-FLAT', 'name' => 'FD flat 8%', 'terms' => array_merge($fd['terms'], [
    'interest_method' => 'flat', 'annual_interest_rate' => '8', 'tenure_options' => [$opt(180, 'days')],
])], $admin)[1]['data']['id'] ?? 0);
$acct = (int) (req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => $fdFlat, 'amount' => '9000'], $manager)[1]['data']['id'] ?? 0);
[$s, $b] = req('POST', '/payments', ['account_type' => 'deposit', 'account_id' => $acct, 'amount' => '9000', 'method' => 'upi', 'reference' => (string) random_int(100000000000, 999999999999)],
    $admin, ['Idempotency-Key: flat-' . bin2hex(random_bytes(8))]);
ok('Rs 9,000 deposited into the flat FD', $s === 201, $b);
$account = \App\Services\DepositService::lock($acct);
$mid = (new DateTimeImmutable($today))->modify('+89 days')->format('Y-m-d');
Database::transaction(function () use (&$account, $mid) { \App\Services\DepositService::accrue($account, $mid, null); });
ok('halfway through, interest builds up day by day (about Rs 360)', abs((float) $account['accrued_interest'] - 360) < 1, $account['accrued_interest']);
$last = (new DateTimeImmutable($account['maturity_date']))->modify('-1 day')->format('Y-m-d');
Database::transaction(function () use (&$account, $last) { \App\Services\DepositService::accrue($account, $last, null); });
ok('at maturity the interest is exactly 8% of Rs 9,000 = Rs 720', $account['accrued_interest'] === '720.0000', $account['accrued_interest']);
$quote = \App\Services\DepositService::payoutQuote(array_merge($account, ['status' => 'matured']), $account['maturity_date']);
ok('maturity payout = Rs 9,720', $quote['net_amount'] === '9720.00', $quote);

echo "Customer app applications with a tenure choice\n";
// The self-service suite used up this test network's hourly registration limit.
Database::query("DELETE FROM audit_logs WHERE action = 'customer.self_registered'");
$phone = '97000' . substr((string) time(), -5);
$reg = req('POST', '/public/register', ['full_name' => 'Tenure Test', 'phone' => $phone, 'password' => 'Tenure@2026', 'address_line' => '1 Road', 'city' => 'Patna', 'state' => 'Bihar', 'pincode' => '800001'])[1]['data'];
[$s, $b] = req('POST', '/my/applications', ['type' => 'savings', 'product_id' => (int) $fd['id'], 'amount' => '5000', 'tenure' => 6, 'tenure_unit' => 'months'], $reg['token']);
$appId = (int) ($b['data']['id'] ?? 0);
ok('customer applies for a 6-month FD', $s === 201 && (int) $b['data']['tenure'] === 6 && $b['data']['tenure_unit'] === 'months', $b);
[$s] = req('POST', '/my/applications', ['type' => 'savings', 'product_id' => (int) $fd['id'], 'amount' => '5000', 'tenure' => 5, 'tenure_unit' => 'months'], $reg['token']);
ok('a tenure the plan does not offer -> 422', $s === 422);
req('POST', "/customers/{$reg['user']['customer_id']}/approve-registration", [], $admin);
[$s, $b] = req('POST', "/applications/{$appId}/accept", [], $admin);
$maturity = Database::value('SELECT maturity_date FROM deposit_accounts WHERE id = ?', [$b['data']['deposit_account_id'] ?? 0]);
ok('accepting opens the FD with the chosen 6 months', $s === 200 && $maturity === \App\Services\LoanService::addPeriods($today, 'monthly', 6), [$b, $maturity]);

Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'deposits_enabled'");
echo $failures === 0 ? "All tenure checks passed.\n" : "{$failures} tenure check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
