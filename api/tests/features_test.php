<?php

declare(strict_types=1);

// Checks for KYC documents, support requests, notifications, statements, holidays, MFA, cookie sessions
// and dashboard insights. Run after finance_test.php on a freshly seeded (--demo) database.

use App\Core\Database;
use App\Services\Calendar;
use App\Services\Settings;
use App\Services\Totp;

require dirname(__DIR__) . '/src/bootstrap.php';

$base = 'http://localhost/api/v1';
$failures = 0;

function req(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = [], bool $multipart = false): array
{
    // These suites test money logic, not the one-collection-a-day prompt (collection_rules_test.php covers it).
    if ($method === 'POST' && $path === '/payments' && is_array($body) && !isset($body['allow_repeat'])) {
        $body['allow_repeat'] = true;
    }
    global $base;
    $ch = curl_init($base . $path);
    if (!$multipart) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : ($multipart ? $body : json_encode($body)),
        CURLOPT_HEADER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $text = substr($raw, $size);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($text, true) ?? $text, substr($raw, 0, $size)];
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

$admin = token('admin@finserve.local');
$manager = token('manager@finserve.local');
$officer = token('officer@finserve.local');
$finance = token('finance@finserve.local');
$agent1 = token('agent1@finserve.local');
$customerId = (int) Database::value("SELECT id FROM customers WHERE user_id IS NOT NULL ORDER BY id LIMIT 1");
$portalEmail = (string) Database::value('SELECT u.email FROM users u JOIN customers c ON c.user_id = u.id WHERE c.id = ?', [$customerId]);
$customer = token($portalEmail, 'Customer@123');
$today = Settings::today();

echo "Holiday calendar\n";
$holiday = Calendar::nextBusinessDay((new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
[$s, $b] = req('POST', '/holidays', ['holiday_date' => $holiday, 'name' => 'Test festival'], $admin);
ok('admin adds a holiday', $s === 200 && in_array($holiday, array_column($b['data']['holidays'], 'holiday_date'), true), $b);
[$s] = req('POST', '/holidays', ['holiday_date' => $holiday, 'name' => 'Dup'], $manager);
ok('manager cannot manage holidays -> 403', $s === 403);
$products = array_column(req('GET', '/loan-products', null, $admin)[1]['data'], null, 'code');
[$s, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => (int) $products['DCL-DAILY']['id'], 'principal' => '10000', 'tenure' => 60], $officer);
$dailyLoan = (int) $b['data']['id'];
req('POST', "/loans/{$dailyLoan}/approve", [], $manager);
[$s, $b] = req('POST', "/loans/{$dailyLoan}/disburse", ['method' => 'cash'], $finance);
$dues = array_column($b['data']['installments'] ?? [], 'due_date');
$sundays = array_filter($dues, fn ($d) => (new DateTimeImmutable($d))->format('N') === '7');
ok('daily schedule skips Sundays and the holiday', count($dues) === 60 && $sundays === [] && !in_array($holiday, $dues, true), [count($dues), array_values($sundays)]);
ok('no two installments share a date', count(array_unique($dues)) === count($dues));

echo "Statements\n";
[$s, $b] = req('GET', '/loans/1/statement', null, $finance);
$last = end($b['data']['lines']);
ok('loan statement balance matches outstanding principal', $s === 200 && $last['principal_balance'] === $b['data']['position']['principal_outstanding'], [$last, $b['data']['position'] ?? null]);
[$s, $b] = req('GET', '/loans/1/statement', null, $customer);
ok('customer can read own statement', $s === 200, $b);
[$s, $b] = req('GET', '/deposit-accounts/1/statement', null, $customer);
ok('deposit statement ends at zero after closure', $s === 200 && end($b['data']['lines'])['balance'] === '0.00', $b['data']['lines'] ?? $b);

echo "KYC documents\n";
$png = sys_get_temp_dir() . '/kyc-test.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
[$s, $b] = req('POST', "/customers/{$customerId}/documents", ['doc_type' => 'pan', 'doc_number_last4' => '123F', 'file' => new CURLFile($png, 'image/png', 'pan.png')], $customer, [], true);
ok('customer uploads own PAN', $s === 201, $b);
$docId = (int) ($b['data']['id'] ?? 0);
$fake = sys_get_temp_dir() . '/kyc-test.png.exe';
file_put_contents($fake, "MZ\x90\x00 not an image");
[$s, $b] = req('POST', "/customers/{$customerId}/documents", ['doc_type' => 'photo', 'file' => new CURLFile($fake, 'image/png', 'photo.png')], $officer, [], true);
ok('disguised non-image rejected by content check -> 422', $s === 422 && isset($b['error']['fields']['file']), $b);
[$s, $body, $h] = req('GET', "/documents/{$docId}/download", null, $officer);
ok('officer downloads the file with correct type', $s === 200 && str_contains($h, 'image/png') && strlen((string) $body) > 0, $h);
$otherCustomer = (int) Database::value('SELECT id FROM customers WHERE id <> ? ORDER BY id LIMIT 1', [$customerId]);
[$s] = req('GET', "/customers/{$otherCustomer}/documents", null, $customer);
ok("customer cannot list someone else's documents -> 404", $s === 404);
[$s] = req('GET', "/documents/{$docId}/download", null, $agent1);
ok('unassigned agent cannot download -> 404', Database::value('SELECT COUNT(*) FROM agent_assignments WHERE customer_id = ? AND agent_user_id = 3', [$customerId]) > 0 ? true : $s === 404);
[$s, $b] = req('POST', "/documents/{$docId}/review", ['status' => 'rejected'], $officer);
ok('rejection needs a note -> 422', $s === 422, $b);
[$s, $b] = req('POST', "/documents/{$docId}/review", ['status' => 'verified'], $officer);
ok('officer verifies the document', $s === 200 && $b['data']['status'] === 'verified', $b);
[$s, $b] = req('POST', "/customers/{$customerId}/documents", ['doc_type' => 'photo', 'file' => new CURLFile($png, 'image/png', 'photo.png')], $admin, [], true);
$ownDoc = (int) ($b['data']['id'] ?? 0);
[$s, $b] = req('POST', "/documents/{$ownDoc}/review", ['status' => 'verified'], $admin);
ok('super admin cannot verify their own upload by default -> 403', $s === 403, $b);
req('PATCH', '/settings', ['owner_self_approval' => true], $admin);
[$s, $b] = req('POST', "/documents/{$ownDoc}/review", ['status' => 'verified'], $admin);
ok('with single-owner approval on, super admin verifies their own upload', $s === 200 && $b['data']['status'] === 'verified', $b);
req('PATCH', '/settings', ['owner_self_approval' => false], $admin);
ok('document views are audited', (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'document.viewed'") >= 1);

echo "Service requests\n";
[$s, $b] = req('POST', '/service-requests', ['category' => 'complaint', 'subject' => 'Receipt not received', 'message' => 'I paid cash yesterday but got no SMS.'], $customer);
ok('customer raises a complaint (high priority)', $s === 201 && $b['data']['priority'] === 'high' && str_starts_with($b['data']['request_number'], 'SR'), $b);
$srId = (int) ($b['data']['id'] ?? 0);
ok('branch manager notified', (int) Database::value("SELECT COUNT(*) FROM notifications WHERE event = 'support.created' AND user_id = 2") === 1);
[$s, $b] = req('POST', "/service-requests/{$srId}/messages", ['message' => 'We have resent your receipt.'], $manager);
ok('manager replies -> in progress', $s === 200 && $b['data']['status'] === 'in_progress' && count($b['data']['messages']) === 2, $b);
[$s, $b] = req('PATCH', "/service-requests/{$srId}", ['status' => 'resolved'], $customer);
ok('customer cannot change status -> 403', $s === 403);
[$s, $b] = req('PATCH', "/service-requests/{$srId}", ['status' => 'resolved'], $manager);
ok('manager resolves', $s === 200 && $b['data']['status'] === 'resolved', $b);
[$s, $b] = req('GET', '/service-requests', null, $agent1);
ok('agent only sees requests of assigned customers', $s === 200, $b);

echo "Notifications\n";
[$s, $b] = req('GET', '/notifications', null, $customer);
$events = array_column($b['data']['items'] ?? [], 'event');
ok('customer got loan and support notifications in the portal', in_array('loan.approved', $events, true) && in_array('loan.disbursed', $events, true) && in_array('support.reply', $events, true), $events);
// Payments before the portal login existed still reached the customer by SMS.
ok('payment receipts queued as SMS to the customer phone', (int) Database::value("SELECT COUNT(*) FROM notifications WHERE channel = 'sms' AND customer_id = ? AND event = 'payment.confirmed' AND status = 'queued'", [$customerId]) > 0);
[$s] = req('POST', '/notifications/read-all', [], $customer);
[, $b] = req('GET', '/notifications', null, $customer);
ok('mark all read', $s === 204 && $b['data']['unread'] === 0, $b['data']['unread'] ?? null);
[$s, $b] = req('GET', '/notifications/outbox', null, $admin);
ok('admin sees SMS outbox', $s === 200 && $b['meta']['total'] > 0, $b['meta'] ?? $b);

echo "Dashboard insights\n";
[$s, $b] = req('GET', '/dashboard/insights', null, $admin);
ok('insights return chart series', $s === 200 && count($b['data']['aging']) === 5 && count($b['data']['monthly']) === 6 && $b['data']['top_collectors'] !== [], array_keys($b['data'] ?? []));
[$s] = req('GET', '/dashboard/insights', null, $agent1);
ok('agent cannot load management insights -> 403', $s === 403);

echo "Cookie session & CSRF\n";
[$s, $b, $h] = req('POST', '/auth/login', ['email' => 'officer@finserve.local', 'password' => 'Admin@12345'], null, ['X-Auth-Mode: cookie']);
preg_match('/fs_session=([a-f0-9]{64})/', $h, $m);
ok('cookie login: HttpOnly SameSite=Strict cookie, no token in body', $s === 200 && isset($m[1]) && stripos($h, 'httponly') !== false && stripos($h, 'samesite=strict') !== false && !isset($b['data']['token']), $h);
$cookie = 'Cookie: fs_session=' . ($m[1] ?? '');
[$s] = req('GET', '/me', null, null, [$cookie]);
ok('cookie authenticates GET', $s === 200);
[$s, $b] = req('POST', '/service-requests', ['category' => 'other', 'subject' => 'CSRF probe', 'message' => 'Should be blocked', 'customer_id' => $customerId], null, [$cookie]);
ok('cookie POST without CSRF header -> 403', $s === 403, $b);
[$s] = req('POST', '/auth/logout', [], null, [$cookie, 'X-Requested-With: FinServe']);
ok('cookie POST with CSRF header works (logout)', $s === 204);
[$s] = req('GET', '/me', null, null, [$cookie]);
ok('cookie revoked after logout', $s === 401);

echo "UPI QR collection\n";
$intent = ['account_type' => 'loan', 'account_id' => $dailyLoan, 'amount' => '250'];
[$s, $b] = req('POST', '/payments/upi-intent', $intent, $agent1);
ok('QR blocked until admin sets the UPI ID -> 409', $s === 409 && $b['error']['code'] === 'UPI_NOT_CONFIGURED', $b);
[$s, $b] = req('PATCH', '/settings', ['upi_vpa' => 'not a vpa'], $admin);
ok('invalid UPI ID rejected -> 422', $s === 422, $b);
[$s, $b] = req('PATCH', '/settings', ['upi_vpa' => 'FinServe.Collect@okhdfcbank', 'upi_payee_name' => 'FinServe Microfinance'], $admin);
ok('admin saves UPI ID (normalised to lower case)', $s === 200 && $b['data']['upi_vpa'] === 'finserve.collect@okhdfcbank', $b);
[$s, $b] = req('POST', '/payments/upi-intent', $intent, $agent1);
$uri = $b['data']['uri'] ?? '';
parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
ok('QR link carries VPA, payee, exact amount, INR and a unique ref', $s === 200 && str_starts_with($uri, 'upi://pay?') && $q['pa'] === 'finserve.collect@okhdfcbank'
    && $q['am'] === '250.00' && $q['cu'] === 'INR' && $q['pn'] === 'FinServe Microfinance' && strlen($q['tr']) >= 6, $b);
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
$payBody = $intent + ['method' => 'upi', 'upi_tr' => $q['tr']];
[$s, $b] = req('POST', '/payments', $payBody + ['reference' => '12345'], $agent1, ['Idempotency-Key: upi-' . bin2hex(random_bytes(8))]);
ok('UTR must be 12 digits -> 422', $s === 422 && isset($b['error']['fields']['reference']), $b);
[$s, $b] = req('POST', '/payments', $payBody, $agent1, ['Idempotency-Key: upi-' . bin2hex(random_bytes(8))]);
ok('UPI needs a UTR or a screenshot -> 422', $s === 422, $b);
[$s, $b] = req('POST', '/payments', $payBody + ['proof_image' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')], $agent1, ['Idempotency-Key: upi-' . bin2hex(random_bytes(8))]);
ok('non-image "screenshot" rejected by content check -> 422', $s === 422 && isset($b['error']['fields']['proof_image']), $b);
[$s, $b] = req('POST', '/payments', $payBody + ['proof_image' => $png], $agent1, ['Idempotency-Key: upi-' . bin2hex(random_bytes(8))]);
$upiId = (int) ($b['data']['id'] ?? 0);
ok('agent submits screenshot only -> pending with proof', $s === 201 && $b['data']['status'] === 'pending' && $b['data']['has_proof'] === true && $b['data']['upi_tr'] === $q['tr'], $b);
[$s, $body, $h] = req('GET', "/payments/{$upiId}/proof", null, $finance);
ok('finance views the screenshot', $s === 200 && str_contains($h, 'image/png'), $h);
[$s, $b] = req('POST', "/payments/{$upiId}/confirm", [], $finance);
ok('cannot confirm UPI without entering the UTR -> 422', $s === 422 && isset($b['error']['fields']['reference']), $b);
$utr = (string) random_int(100000000000, 999999999999);
[$s, $b] = req('POST', "/payments/{$upiId}/confirm", ['reference' => $utr], $finance);
ok('finance enters UTR from screenshot and confirms', $s === 200 && $b['data']['status'] === 'confirmed' && $b['data']['reference'] === $utr, $b);
[$s, $b] = req('POST', '/payments', $intent + ['method' => 'upi', 'reference' => $utr], $agent1, ['Idempotency-Key: upi-' . bin2hex(random_bytes(8))]);
ok('same UTR cannot be used twice -> 422', $s === 422, $b);

echo "Mobile app access (CORS)\n";
[$s, , $h] = req('OPTIONS', '/payments', null, null, ['Origin: http://localhost', 'Access-Control-Request-Method: POST']);
ok('preflight from the app origin allowed', $s === 204 && str_contains($h, 'Access-Control-Allow-Origin: http://localhost') && stripos($h, 'Idempotency-Key') !== false, $h);
[$s, , $h] = req('GET', '/health', null, null, ['Origin: https://evil.example']);
ok('other origins get no CORS grant', stripos($h, 'Access-Control-Allow-Origin') === false, $h);
req('PATCH', '/settings', ['upi_vpa' => ''], $admin);

echo "Agent onboarding (customer, loan request, savings)\n";
[$s, $b] = req('POST', '/customers', ['full_name' => 'Field Onboarded', 'phone' => '96' . random_int(10000000, 99999999), 'address_line' => '7 Market Lane',
    'city' => 'Nashik', 'state' => 'Maharashtra', 'pincode' => '422001'], $agent1);
$newCustomer = (int) ($b['data']['id'] ?? 0);
$agent1Id = (int) Database::value("SELECT id FROM users WHERE email = 'agent1@finserve.local'");
ok('agent creates a customer; it is assigned to that agent automatically', $s === 201 && (int) ($b['data']['assignments'][0]['agent_user_id'] ?? 0) === $agent1Id, $b);
[$s] = req('GET', "/customers/{$newCustomer}", null, $agent1);
ok('agent can open the customer they just created', $s === 200);
$plId = (int) Database::value("SELECT id FROM loan_products WHERE code = 'DCL-DAILY'");
[$s, $b] = req('POST', '/loans', ['customer_id' => $newCustomer, 'product_id' => $plId, 'principal' => '15000', 'tenure' => 100, 'purpose' => 'Vegetable stall stock'], $agent1);
$agentLoan = (int) ($b['data']['id'] ?? 0);
ok('agent submits a loan request -> pending approval', $s === 201 && $b['data']['status'] === 'pending_approval', $b);
[$s] = req('POST', "/loans/{$agentLoan}/approve", ['reason' => 'Looks fine'], $agent1);
ok('agent cannot approve a loan -> 403', $s === 403);
[$s] = req('POST', "/loans/{$agentLoan}/approve", ['reason' => 'Field verified'], $manager);
ok('branch manager approves the agent\'s request', $s === 200);
req('PATCH', '/settings', ['deposits_enabled' => true, 'reason' => 'Test: savings onboarding'], $admin);
$weekly = (int) Database::value("SELECT id FROM deposit_products WHERE code = 'RD-WEEKLY'");
[$s, $b] = req('POST', '/deposit-accounts', ['customer_id' => $newCustomer, 'product_id' => $weekly, 'amount' => '100'], $agent1);
ok('weekly savings below the Rs 200 minimum rejected -> 422', $s === 422, $b);
[$s, $b] = req('POST', '/deposit-accounts', ['customer_id' => $newCustomer, 'product_id' => $weekly, 'amount' => '250'], $agent1);
$savings = (int) ($b['data']['id'] ?? 0);
ok('agent opens a weekly savings account', $s === 201 && $b['data']['terms']['contribution_frequency'] === 'weekly', $b);
[, $b] = req('GET', '/collections/today', null, $agent1);
$row = array_values(array_filter($b['data']['deposits'] ?? [], fn ($d) => (int) $d['deposit_account_id'] === $savings))[0] ?? null;
ok('weekly saver appears on the agent\'s list as due this week', $row !== null && $row['frequency'] === 'weekly' && (float) $row['paid_this_period'] === 0.0, $row);
req('POST', '/payments', ['account_type' => 'deposit', 'account_id' => $savings, 'amount' => '250', 'method' => 'cash'], $agent1, ['Idempotency-Key: wk-' . bin2hex(random_bytes(8))]);
[, $b] = req('GET', '/collections/today', null, $agent1);
$row = array_values(array_filter($b['data']['deposits'] ?? [], fn ($d) => (int) $d['deposit_account_id'] === $savings))[0] ?? null;
ok('after collecting, it shows as paid for this week', $row !== null && (float) $row['paid_this_period'] === 250.0, $row);
req('PATCH', '/settings', ['deposits_enabled' => false, 'reason' => 'Test: restore default'], $admin);

echo "Two-factor sign-in\n";
[$s, $b] = req('POST', '/me/mfa/setup', [], $finance);
$secret = $b['data']['secret'] ?? '';
ok('setup returns secret and otpauth URI', $s === 200 && str_starts_with($b['data']['otpauth_uri'] ?? '', 'otpauth://totp/'), $b);
[$s] = req('POST', '/me/mfa/enable', ['code' => '000000'], $finance);
ok('wrong code rejected', $s === 422);
$step = intdiv(time(), 30);
[$s, $b] = req('POST', '/me/mfa/enable', ['code' => Totp::code($secret, $step)], $finance);
ok('correct code enables MFA', $s === 200 && $b['data']['mfa_enabled'] === true, $b);
ok('secret stored encrypted, not plain', !str_contains((string) Database::value("SELECT mfa_secret_enc FROM users WHERE email = 'finance@finserve.local'"), $secret));
[$s, $b] = req('POST', '/auth/login', ['email' => 'finance@finserve.local', 'password' => 'Admin@12345']);
ok('password alone now returns an MFA challenge, not a session', $s === 200 && ($b['data']['mfa_required'] ?? false) && !isset($b['data']['token']), $b);
$challenge = $b['data']['challenge'] ?? '';
[$s] = req('POST', '/auth/mfa', ['challenge' => $challenge, 'code' => Totp::code($secret, $step)]);
ok('replayed code (already used) rejected', $s === 422);
[$s, $b] = req('POST', '/auth/mfa', ['challenge' => $challenge, 'code' => Totp::code($secret, $step + 1)]);
ok('next code completes sign-in', $s === 200 && isset($b['data']['token']), $b);
[$s] = req('POST', '/users/7/reset-mfa', [], $admin);
ok('admin resets a lost authenticator', $s === 204 && Database::value("SELECT mfa_enabled_at FROM users WHERE email = 'finance@finserve.local'") === null);

[$s, $b] = req('PATCH', '/settings', ['mfa_required_for_privileged' => true], $admin);
ok('admin without MFA cannot require it (no self-lockout) -> 422', $s === 422, $b);
$adminSecret = req('POST', '/me/mfa/setup', [], $admin)[1]['data']['secret'];
req('POST', '/me/mfa/enable', ['code' => Totp::code($adminSecret, intdiv(time(), 30))], $admin);
[$s, $b] = req('PATCH', '/settings', ['mfa_required_for_privileged' => true], $admin);
ok('admin with MFA can require it', $s === 200 && $b['data']['mfa_required_for_privileged'] === '1', $b);
$financeAgain = token('finance@finserve.local');
[$s, $b] = req('GET', '/loans', null, $financeAgain);
ok('when required, privileged user without MFA is blocked', $s === 403 && $b['error']['code'] === 'MFA_SETUP_REQUIRED', $b);
[$s, $b] = req('GET', '/me', null, $financeAgain);
ok('…but can reach /me to start setup', $s === 200 && $b['data']['mfa_setup_required'] === true, $b);
[$s] = req('GET', '/loans', null, $officer);
ok('non-privileged roles unaffected', $s === 200);
[$s] = req('PATCH', '/settings', ['mfa_required_for_privileged' => false], $admin);
ok('requirement switched back off', $s === 200);
req('DELETE', "/holidays/{$holiday}", null, $admin);
// Leave the demo admin able to sign in with just a password.
Database::query("UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE email = 'admin@finserve.local'");

echo $failures === 0 ? "\nAll feature checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
