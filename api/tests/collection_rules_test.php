<?php

declare(strict_types=1);

// Collection rhythm: recurring savings are collected once per period (daily = once a day, weekly = once
// every 7 days from opening, monthly = once a month), for the installment or a catch-up multiple; a second
// loan payment on the same day needs the collector's confirmation. Run on the seeded database after the others.

use App\Core\Database;
use App\Services\LoanService;
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

/** Records a UPI payment confirmed on the spot (admin may confirm), with a fresh UTR and idempotency key. */
function pay(string $token, string $type, int $id, string $amount, array $extra = []): array
{
    return req('POST', '/payments', ['account_type' => $type, 'account_id' => $id, 'amount' => $amount, 'method' => 'upi',
        'reference' => (string) random_int(100000000000, 999999999999)] + $extra, $token, ['Idempotency-Key: cr-' . bin2hex(random_bytes(8))]);
}

$admin = req('POST', '/auth/login', ['email' => 'admin@finserve.local', 'password' => 'Admin@12345'])[1]['data']['token'];
$today = Settings::today();
$daysAgo = fn (int $n) => (new DateTimeImmutable($today))->modify("-{$n} days")->format('Y-m-d');
Database::query("UPDATE settings SET setting_value = '1' WHERE setting_key = 'deposits_enabled'");
Database::query("UPDATE settings SET setting_value = 'kbsave@upi' WHERE setting_key = 'upi_vpa'");
$customerId = (int) Database::value("SELECT id FROM customers WHERE status = 'active' ORDER BY id LIMIT 1");
$products = array_column(req('GET', '/deposit-products', null, $admin)[1]['data'], null, 'code');
$open = function (string $code, string $amount) use ($admin, $customerId, $products): int {
    return (int) (req('POST', '/deposit-accounts', ['customer_id' => $customerId, 'product_id' => (int) $products[$code]['id'], 'amount' => $amount], $admin)[1]['data']['id'] ?? 0);
};
$row = function (int $depositId) use ($admin): ?array {
    $list = req('GET', '/collections/today', null, $admin)[1]['data']['deposits'] ?? [];
    return array_values(array_filter($list, fn ($d) => (int) $d['deposit_account_id'] === $depositId))[0] ?? null;
};

echo "Daily savings: once a day\n";
$daily = $open('RD-DAILY', '100');
$r = $row($daily);
ok('new daily saver is due today, 1 installment', $r !== null && $r['collected_on'] === null && $r['installments_due'] === 1 && $r['amount_due'] === '100.00', $r);
[$s, $b] = pay($admin, 'deposit', $daily, '150');
ok('an amount that is not the installment -> 422', $s === 422 && str_contains($b['error']['fields']['amount'] ?? '', '100.00'), $b);
[$s, $b] = pay($admin, 'deposit', $daily, '200');
ok('two installments when only one is due -> 422', $s === 422, $b);
[$s] = pay($admin, 'deposit', $daily, '100');
ok('collects today\'s 100', $s === 201);
[$s, $b] = pay($admin, 'deposit', $daily, '100');
ok('second collection the same day -> 409 ALREADY_COLLECTED', $s === 409 && ($b['error']['code'] ?? '') === 'ALREADY_COLLECTED', $b);
[$s, $b] = req('POST', '/payments/upi-intent', ['account_type' => 'deposit', 'account_id' => $daily, 'amount' => '100'], $admin);
ok('no UPI QR either once today is collected -> 409', $s === 409, $b);
$r = $row($daily);
ok('list shows it collected, next due tomorrow', $r['collected_on'] === $today && $r['next_due_date'] === $daysAgo(-1) && $r['installments_due'] === 0, $r);

echo "Missed days are caught up in one collection\n";
$missed = $open('RD-DAILY', '100');
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$daysAgo(3), $missed]);
$r = $row($missed);
ok('opened 3 days ago, nothing paid: 4 installments due (Rs 400)', $r['installments_due'] === 4 && $r['amount_due'] === '400.00', $r);
[$s] = pay($admin, 'deposit', $missed, '500');
ok('more than what is due -> 422', $s === 422);
[$s] = pay($admin, 'deposit', $missed, '400');
ok('collects all 4 missed installments at once', $s === 201);
[$s] = pay($admin, 'deposit', $missed, '100');
ok('then nothing more today -> 409', $s === 409);

echo "Paying part of the missed days, then the rest the same day\n";
$late = $open('RD-DAILY', '100');
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$daysAgo(3), $late]);
$r = $row($late);
ok('not paid for 3 days: 3 missed + today, unpaid since opening, Rs 400 due', $r['missed_installments'] === 3 && $r['installments_due'] === 4 && $r['unpaid_since'] === $daysAgo(3) && $r['amount_due'] === '400.00', $r);
[$s, $b] = pay($admin, 'deposit', $late, '100');
ok('Rs 100 today pays the oldest missed day', $s === 201 && ($b['data']['covers_from'] ?? '') === $daysAgo(3) && ($b['data']['covers_to'] ?? '') === $daysAgo(3) && (int) $b['data']['covers_count'] === 1, $b['data'] ?? $b);
$r = $row($late);
ok('still owes 3 (since 2 days ago) and is still on the list', $r['installments_due'] === 3 && $r['unpaid_since'] === $daysAgo(2) && $r['collected_on'] === null, $r);
[$s, $b] = pay($admin, 'deposit', $late, '300');
ok('the rest can be collected the same day, covering 2 days ago to today', $s === 201 && $b['data']['covers_from'] === $daysAgo(2) && $b['data']['covers_to'] === $today && (int) $b['data']['covers_count'] === 3, $b['data'] ?? $b);
[$s, $b] = pay($admin, 'deposit', $late, '100');
ok('paid up: nothing more today -> 409 naming tomorrow', $s === 409 && str_contains($b['error']['message'], $daysAgo(-1)), $b);
$detail = req('GET', "/deposit-accounts/{$late}", null, $admin)[1]['data'] ?? [];
$cal = array_column($detail['calendar'] ?? [], 'state', 'date');
ok('calendar: the 3 missed days show as paid late, today as paid, tomorrow upcoming',
    ($cal[$daysAgo(3)] ?? '') === 'paid_late' && ($cal[$daysAgo(1)] ?? '') === 'paid_late' && ($cal[$today] ?? '') === 'paid' && ($cal[$daysAgo(-1)] ?? '') === 'upcoming', array_slice($detail['calendar'] ?? [], 0, 5));
ok('account shows it is paid up', ($detail['contribution']['installments_due'] ?? null) === 0 && ($detail['contribution']['last_paid_on'] ?? '') === $today, $detail['contribution'] ?? null);
$statement = req('GET', "/deposit-accounts/{$late}/statement", null, $admin)[1]['data']['lines'] ?? [];
ok('statement says which days each payment was for', str_contains(implode(' | ', array_column($statement, 'description')), '(3 installments)'), array_column($statement, 'description'));
$skip = $open('RD-DAILY', '100');
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$daysAgo(2), $skip]);
$cal = array_column(req('GET', "/deposit-accounts/{$skip}", null, $admin)[1]['data']['calendar'] ?? [], 'state', 'date');
ok('calendar: unpaid past days are missed, today is due', ($cal[$daysAgo(2)] ?? '') === 'missed' && ($cal[$daysAgo(1)] ?? '') === 'missed' && ($cal[$today] ?? '') === 'due', $cal[$today] ?? null);
$customerRow = array_values(array_filter(req('GET', "/customers/{$customerId}", null, $admin)[1]['data']['deposits'] ?? [], fn ($d) => (int) $d['id'] === $skip))[0] ?? [];
ok('customer page shows Rs 300 due since 2 days ago', ($customerRow['amount_due'] ?? '') === '300.00' && ($customerRow['unpaid_since'] ?? '') === $daysAgo(2), $customerRow);

echo "Weekly savings: once every 7 days from opening\n";
$weekly = $open('RD-WEEKLY', '250');
[$s] = pay($admin, 'deposit', $weekly, '250');
ok('collects this week', $s === 201);
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$daysAgo(3), $weekly]);
Database::query('UPDATE payments SET business_date = ? WHERE deposit_account_id = ?', [$daysAgo(3), $weekly]);
[$s, $b] = pay($admin, 'deposit', $weekly, '250');
ok('3 days later, same week -> 409 with next date', $s === 409 && str_contains($b['error']['message'], $daysAgo(-4)), $b);
$r = $row($weekly);
ok('list: collected this week, next collection in 4 days', $r['collected_on'] === $daysAgo(3) && $r['next_due_date'] === $daysAgo(-4), $r);
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$daysAgo(7), $weekly]);
Database::query('UPDATE payments SET business_date = ? WHERE deposit_account_id = ?', [$daysAgo(7), $weekly]);
$r = $row($weekly);
ok('7 days later the next week is due', $r['collected_on'] === null && $r['installments_due'] === 1, $r);
[$s] = pay($admin, 'deposit', $weekly, '250');
ok('collects the new week', $s === 201);

echo "Monthly savings: once a month from opening\n";
$monthly = $open('RD-MONTHLY', '500');
[$s] = pay($admin, 'deposit', $monthly, '500');
ok('collects this month', $s === 201);
[$s] = pay($admin, 'deposit', $monthly, '500');
ok('again in the same month -> 409', $s === 409);
$lastMonth = LoanService::addPeriods($today, 'monthly', -1);
Database::query('UPDATE deposit_accounts SET opened_on = ? WHERE id = ?', [$lastMonth, $monthly]);
Database::query('UPDATE payments SET business_date = ? WHERE deposit_account_id = ?', [$lastMonth, $monthly]);
[$s] = pay($admin, 'deposit', $monthly, '500');
ok('a month after opening the next installment is accepted', $s === 201);

echo "Loans: a second payment the same day needs confirmation\n";
$loan = Database::one(
    "SELECT l.id, l.installment_amount FROM loans l WHERE l.status = 'active'
        AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.loan_id = l.id AND p.business_date = ?) ORDER BY l.id LIMIT 1",
    [$today],
);
$amount = number_format(min(100.0, (float) $loan['installment_amount']), 2, '.', '');
[$s] = pay($admin, 'loan', (int) $loan['id'], $amount);
ok('first loan payment today', $s === 201);
[$s, $b] = pay($admin, 'loan', (int) $loan['id'], $amount);
ok('second one -> 409 REPEAT_TODAY naming the first', $s === 409 && ($b['error']['code'] ?? '') === 'REPEAT_TODAY' && str_contains($b['error']['message'], 'PAY'), $b);
[$s] = pay($admin, 'loan', (int) $loan['id'], $amount, ['allow_repeat' => true]);
ok('recorded when the collector confirms the customer paid again', $s === 201);

Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'deposits_enabled'");
echo $failures === 0 ? "All collection rule checks passed.\n" : "{$failures} collection rule check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
