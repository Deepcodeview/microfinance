<?php

declare(strict_types=1);

// Field controls end to end: GPS on collections, cash float -> field loan payout -> day-end handover,
// customer paying by UPI in the app, the demand vs collection / missed reports, and the app-version check.
// Run on the seeded database after the other suites.

use App\Core\Database;
use App\Services\Ledger;
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

function token(string $email, string $password = 'Admin@12345'): string
{
    return req('POST', '/auth/login', ['email' => $email, 'password' => $password])[1]['data']['token'] ?? '';
}

$key = fn () => ['Idempotency-Key: fd-' . bin2hex(random_bytes(8))];
$here = ['latitude' => 25.594095, 'longitude' => 85.137566, 'accuracy' => 18, 'captured_at' => gmdate('c')];
$admin = token('admin@finserve.local');
$manager = token('manager@finserve.local');
// agent2: no cash or handover from the earlier suites, so the amounts below are exact.
$agent = token('agent2@finserve.local');
$supervisor = token('supervisor@finserve.local');
$finance = token('finance@finserve.local');
$agentId = (int) Database::value("SELECT id FROM users WHERE email = 'agent2@finserve.local'");
$branchId = (int) Database::value("SELECT branch_id FROM users WHERE id = ?", [$agentId]);
$today = Settings::today();
Database::query("UPDATE settings SET setting_value = 'kbsave@upi' WHERE setting_key = 'upi_vpa'");
Database::query("DELETE FROM audit_logs WHERE action = 'customer.self_registered'");

// A customer who registered in the app, approved and handed to agent1, with an approved loan.
$reg = req('POST', '/public/register', ['full_name' => 'Kiran Field', 'phone' => '9600011122', 'password' => 'Kiran@2026', 'branch_id' => $branchId,
    'address_line' => '7 Canal Road', 'city' => 'Patna', 'state' => 'Bihar', 'pincode' => '800001'])[1]['data'];
$customer = $reg['token'];
$customerId = (int) $reg['user']['customer_id'];
req('POST', "/customers/{$customerId}/approve-registration", ['agent_user_id' => $agentId], $manager);
$daily = (int) Database::value("SELECT id FROM loan_products WHERE code = 'DCL-DAILY'");
[$s, $b] = req('POST', '/loans', ['customer_id' => $customerId, 'product_id' => $daily, 'principal' => '10000', 'tenure' => 50], $agent);
$loanId = (int) ($b['data']['id'] ?? 0);
$net = $b['data']['net_disbursement'] ?? '0';
req('POST', "/loans/{$loanId}/approve", [], $manager);

echo "Cash float and paying out a loan in the field\n";
[$s, $b] = req('POST', "/loans/{$loanId}/disburse-field", [], $agent);
ok('agent without cash cannot pay out -> 409 asking for a float', $s === 409 && str_contains($b['error']['message'] ?? '', 'cash float'), $b);
[$s] = req('POST', '/cash/floats', ['agent_user_id' => $agentId, 'amount' => '5000'], $agent);
ok('agent cannot give themselves a float -> 403', $s === 403);
$branchCashBefore = Ledger::balance(Ledger::BRANCH_CASH, $branchId);
[$s, $b] = req('POST', '/cash/floats', ['agent_user_id' => $agentId, 'amount' => '15000', 'note' => 'Morning float'], $manager);
ok('manager gives the agent a Rs 15,000 float', $s === 201 && $b['data']['type'] === 'float_issued', $b);
ok('branch cash goes down by the float', bccomp(bcsub($branchCashBefore, Ledger::balance(Ledger::BRANCH_CASH, $branchId), 2), '15000', 2) === 0);
[$s, $b] = req('POST', '/cash/floats', ['agent_user_id' => $agentId, 'amount' => '9000000'], $manager);
ok('a float larger than branch cash -> 409', $s === 409, $b);
[$s, $b] = req('POST', "/loans/{$loanId}/disburse-field", [], $agent);
ok('agent pays the loan out in the field', $s === 200 && $b['data']['status'] === 'active' && (int) $b['data']['disbursed_in_field'] === 1, $b);
[$s, $b] = req('GET', '/cash/my-summary', null, $agent);
$position = $b['data']['position'] ?? [];
ok('cash in hand = float − loan paid out', bccomp($position['in_hand'] ?? '0', bcsub('15000', $net, 2), 2) === 0 && count($b['data']['movements']) === 2, $position);
[$s] = req('POST', "/loans/{$loanId}/disburse-field", [], $agent);
ok('the same loan cannot be paid out twice -> 409', $s === 409);

echo "Location on collections\n";
[$s, $b] = req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '100', 'method' => 'cash', 'location' => $here], $agent, $key());
$paymentId = (int) ($b['data']['id'] ?? 0);
ok('collection stores where it was taken', $s === 201 && $b['data']['latitude'] === '25.594095' && (int) $b['data']['location_accuracy_m'] === 18, $b);
[$s, $b] = req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '100', 'method' => 'cash', 'allow_repeat' => true,
    'location' => ['latitude' => 200, 'longitude' => 85, 'captured_at' => gmdate('c')]], $agent, $key());
ok('impossible coordinates -> 422', $s === 422 && isset($b['error']['fields']['location']), $b);
Database::query("UPDATE settings SET setting_value = '1' WHERE setting_key = 'require_collection_location'");
[$s, $b] = req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '100', 'method' => 'cash', 'allow_repeat' => true], $agent, $key());
ok('when required, an agent collection without location -> 422', $s === 422 && isset($b['error']['fields']['location']), $b);
[$s] = req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '50', 'method' => 'cash', 'allow_repeat' => true], $manager, $key());
ok('office staff are not asked for location', $s === 201);
Database::query("UPDATE settings SET setting_value = '0' WHERE setting_key = 'require_collection_location'");
[$s, $b] = req('POST', '/visits', ['customer_id' => $customerId, 'loan_id' => $loanId, 'outcome' => 'paid', 'payment_id' => $paymentId, 'location' => $here], $agent);
ok('visits store location too', $s === 201 && $b['data']['latitude'] === '25.594095', $b);

echo "Customer pays by UPI in the app\n";
[$s, $b] = req('POST', '/my/payments/upi-intent', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '200'], $customer);
ok('customer gets a UPI payment link for their loan', $s === 200 && str_starts_with($b['data']['uri'] ?? '', 'upi://pay?'), $b);
[$s, $b] = req('POST', '/my/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '200', 'method' => 'cash', 'reference' => '123456789012', 'allow_repeat' => true], $customer, $key());
$selfPaid = (int) ($b['data']['id'] ?? 0);
ok('customer payment is UPI and waits for finance', $s === 201 && $b['data']['method'] === 'upi' && $b['data']['status'] === 'pending', $b);
[$s] = req('POST', '/my/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '200', 'allow_repeat' => true], $customer, $key());
ok('needs the UTR or a screenshot -> 422', $s === 422);
$otherLoan = (int) Database::value("SELECT id FROM loans WHERE customer_id <> ? AND status = 'active' LIMIT 1", [$customerId]);
[$s] = req('POST', '/my/payments', ['account_type' => 'loan', 'account_id' => $otherLoan, 'amount' => '200', 'reference' => '123456789013', 'allow_repeat' => true], $customer, $key());
ok("cannot pay into someone else's loan -> 422", $s === 422);
[$s] = req('POST', '/payments', ['account_type' => 'loan', 'account_id' => $loanId, 'amount' => '200', 'method' => 'cash'], $customer, $key());
ok('customer cannot use the staff collection endpoint -> 403', $s === 403);
[$s, $b] = req('GET', '/notifications', null, $finance);
ok('finance is told to confirm it', in_array('Customer UPI payment to confirm', array_column($b['data']['items'] ?? [], 'title'), true), $b);
[$s, $b] = req('POST', "/payments/{$selfPaid}/confirm", [], $finance);
ok('finance confirms -> receipt', $s === 200 && $b['data']['status'] === 'confirmed' && $b['data']['receipt_number'] !== null, $b);

echo "Day-end handover includes the float\n";
[, $b] = req('GET', '/cash/my-summary', null, $agent);
$expected = $b['data']['expected_cash'];
ok('expected cash = float − payout + cash collected', bccomp($expected, bcadd(bcsub('15000', $net, 2), '100', 2), 2) === 0, $b['data']['position'] ?? $b);
[$s, $b] = req('POST', '/cash-handovers', ['declared_cash' => $expected], $agent);
$handoverId = (int) ($b['data']['id'] ?? 0);
ok('agent hands over: float and payout are part of it', $s === 201 && bccomp($b['data']['expected_cash'], $expected, 2) === 0 && count($b['data']['movements']) === 2, $b);
[$s, $b] = req('POST', "/cash-handovers/{$handoverId}/verify", ['verified_cash' => $expected], $supervisor);
ok('supervisor counts it -> verified', $s === 200 && $b['data']['status'] === 'verified', $b);
[, $b] = req('GET', '/cash/my-summary', null, $agent);
ok('nothing left in hand after the handover', bccomp($b['data']['expected_cash'], '0', 2) === 0, $b['data']);
[, $b] = req('GET', '/reports/trial-balance', null, $finance);
ok('books still balance', ($b['data']['summary']['balanced'] ?? false) === true, $b['data']['summary'] ?? $b);

echo "Reports\n";
[$s, $b] = req('GET', "/reports/demand-collection?date={$today}", null, $manager);
$row = array_values(array_filter($b['data']['rows'] ?? [], fn ($r) => $r['agent'] === 'Sita Agent'))[0] ?? null;
ok('demand vs collection lists the agent with collections', $s === 200 && $row !== null && bccomp($row['loan_collected'], '0', 2) > 0, $b);
ok('summary has collection efficiency', isset($b['data']['summary']['collection_efficiency_percent']), $b['data']['summary'] ?? $b);
[$s, $b] = req('GET', "/reports/missed-collections?date={$today}", null, $manager);
ok('missed collections report runs', $s === 200 && isset($b['data']['summary']['loans_missed']), $b);
[$s, $b] = req('GET', '/reports/loan-portfolio', null, $manager);
ok('portfolio shows PAR 1 / 30 / 60 / 90', $s === 200 && isset($b['data']['summary']['par_1_percent'], $b['data']['summary']['par_90_percent']), $b['data']['summary'] ?? $b);
[$s, $b] = req('GET', '/reports/agent-cash', null, $manager);
ok('agent cash report shows float and payouts', $s === 200 && isset($b['data']['rows'][0]['float_received'], $b['data']['rows'][0]['loans_paid_out']), $b);

echo "KYC document upload\n";
/** Multipart upload like the app sends; $bytes = file contents. */
function upload(string $token, int $customerId, string $type, string $name, string $bytes): array
{
    global $base;
    $path = tempnam(sys_get_temp_dir(), 'kyc');
    file_put_contents($path, $bytes);
    $ch = curl_init("{$base}/customers/{$customerId}/documents");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => ['doc_type' => $type, 'file' => new CURLFile($path, 'application/octet-stream', $name)],
    ]);
    $text = (string) curl_exec($ch);
    unlink($path);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($text, true) ?? $text];
}
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('k', 2000);
[$s, $b] = upload($agent, $customerId, 'photo', 'photo.jpg', $jpeg);
ok('agent uploads a JPEG photo', $s === 201, $b);
[$s, $b] = upload($agent, $customerId, 'aadhaar', 'aadhaar.pdf', "%PDF-1.4\n" . str_repeat('p', 500));
ok('a PDF is accepted', $s === 201, $b);
[$s, $b] = upload($agent, $customerId, 'pan', 'pan.jpg', 'just text pretending to be a photo');
ok('a non-image with a .jpg name -> 422', $s === 422 && str_contains($b['error']['fields']['file'] ?? '', 'JPG, PNG or PDF'), $b);
[$s, $b] = upload($agent, $customerId, 'pan', 'pan.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('x', (int) (2.5 * 1048576)));
ok('over the host upload limit -> 422 naming the limit', $s === 422 && str_contains($b['error']['fields']['file'] ?? '', 'too large for the server'), $b);
[$s, $b] = upload($agent, $customerId, 'pan', 'pan.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('x', 9 * 1048576));
ok('over the post limit -> 422 (not "choose a type")', $s === 422 && str_contains($b['error']['fields']['file'] ?? '', 'too large for the server'), $b);
[$s, $b] = req('GET', "/customers/{$customerId}/documents", null, $agent);
ok('both documents are listed', $s === 200 && count($b['data']) === 2, $b);

echo "Customer map\n";
$pin = Database::one('SELECT latitude, longitude, location_set_by FROM customers WHERE id = ?', [$customerId]);
ok("the first collection with GPS pins the customer's location", $pin['latitude'] === '25.594095' && (int) $pin['location_set_by'] === $agentId, $pin);
[$s, $b] = req('POST', "/customers/{$customerId}/location", ['location' => ['latitude' => 25.6, 'longitude' => 85.14, 'accuracy' => 9, 'captured_at' => gmdate('c')]], $agent);
ok('agent moves the pin to where they stand', $s === 200 && $b['data']['latitude'] === '25.600000', $b);
[$s] = req('POST', "/customers/{$customerId}/location", [], $agent);
ok('no GPS reading -> 422', $s === 422);
[$s] = req('POST', "/customers/{$customerId}/location", ['location' => $here], $customer);
ok('a customer cannot move pins -> 403', $s === 403);
[$s, $b] = req('GET', '/collections/map', null, $agent);
$mine = array_values(array_filter($b['data']['customers'] ?? [], fn ($c) => (int) $c['id'] === $customerId))[0] ?? null;
ok("agent's map shows the pinned customer with today's status", $s === 200 && $mine !== null && in_array($mine['status'], ['overdue', 'due', 'collected', 'none'], true), $b);
[$s, $b] = req('GET', '/collections/map', null, $customer);
ok('customers have no map -> 403', $s === 403);

echo "App version check\n";
[$s, $b] = req('GET', '/public/app-version');
ok('public app-version endpoint answers', $s === 200 && array_key_exists('agent', $b['data']), $b);

echo $failures === 0 ? "All field checks passed.\n" : "{$failures} field check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
