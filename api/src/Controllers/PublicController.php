<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\CustomerPhone;
use App\Services\DepositTerms;
use App\Services\LoanTerms;
use App\Services\Notifier;
use App\Services\Settings;
use App\Services\Tenure;

/**
 * Endpoints the customer app uses before anyone signs in: the plan catalogue, repayment / maturity
 * calculators and self-registration. Registration creates a login straight away, but the customer
 * stays "pending" (no loan or account can be opened) until staff approve them.
 */
final class PublicController
{
    private const REGISTRATIONS_PER_IP_PER_HOUR = 10;

    /** Only what a customer needs to compare plans; internal settings stay private. */
    private const LOAN_FIELDS = ['annual_interest_rate', 'interest_method', 'repayment_frequency', 'min_amount', 'max_amount', 'min_tenure', 'max_tenure', 'tenure_options',
        'processing_fee_percent', 'tax_on_fees_percent', 'late_fee_type', 'late_fee_value', 'grace_period_days', 'prepayment_allowed', 'prepayment_charge_percent'];
    private const DEPOSIT_FIELDS = ['scheme', 'interest_method', 'annual_interest_rate', 'contribution_frequency', 'min_installment', 'min_amount', 'max_amount', 'tenure_options',
        'premature_withdrawal_allowed', 'premature_penalty_percent', 'lock_in_days'];

    public function catalog(Request $request): Response
    {
        $depositsOn = Settings::depositsEnabled();
        return Response::json([
            'company_name' => Settings::get('company_name'),
            'deposits_enabled' => $depositsOn,
            'branches' => Database::all("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name"),
            'loan_products' => $this->products('loan_products', 'loan_product_versions', self::LOAN_FIELDS),
            'deposit_products' => $depositsOn ? $this->products('deposit_products', 'deposit_product_versions', self::DEPOSIT_FIELDS) : [],
        ]);
    }

    /**
     * Newest Android app versions, written by scripts/build-cpanel.sh. The apps compare it with their own
     * version and offer the download when a newer one is on the server.
     */
    public function appVersion(Request $request): Response
    {
        $file = dirname(__DIR__, 2) . '/app-versions.json';
        $versions = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return Response::json(is_array($versions) ? $versions : ['agent' => null, 'customer' => null]);
    }

    public function loanPreview(Request $request): Response
    {
        $terms = $this->activeTerms('loan_products', 'loan_product_versions', $request->intParam('id'));
        $v = new Validator($request->all());
        $principal = $v->decimal('principal', '1', '100000000', 2);
        $tenure = $v->int('tenure', 1, 1000);
        $v->validate();
        if (bccomp($principal, $terms['min_amount'], 4) < 0 || bccomp($principal, $terms['max_amount'], 4) > 0) {
            throw HttpException::validation(['principal' => 'Amount is outside this plan\'s minimum/maximum.']);
        }
        if (($tenureError = LoanTerms::tenureError($terms, $tenure)) !== null) {
            throw HttpException::validation(['tenure' => $tenureError]);
        }
        return Response::json(LoanTerms::preview($terms, $principal, $tenure));
    }

    public function depositPreview(Request $request): Response
    {
        Settings::requireDeposits();
        $terms = $this->activeTerms('deposit_products', 'deposit_product_versions', $request->intParam('id'));
        $v = new Validator($request->all());
        $amount = $v->decimal('amount', '1', '100000000', 2);
        $tenureValue = $v->int('tenure_value', 1, 3650, false);
        $tenureUnit = $v->in('tenure_unit', Tenure::UNITS, false);
        $v->validate();
        return Response::json(DepositTerms::preview($terms, $amount, DepositTerms::chooseTenure($terms, $tenureValue, $tenureUnit)));
    }

    public function register(Request $request): Response
    {
        $recent = (int) Database::value(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'customer.self_registered' AND ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR",
            [$request->ip],
        );
        if ($recent >= self::REGISTRATIONS_PER_IP_PER_HOUR) {
            throw HttpException::tooManyRequests('Too many registrations from this network. Please try again in an hour.');
        }

        $v = new Validator($request->all());
        $name = $v->string('full_name', true, 150, 2);
        $phone = $v->phone('phone');
        $email = $v->email('email', false);
        $password = $v->string('password', true, 200, 8);
        $address = $v->string('address_line', true, 255, 3);
        $city = $v->string('city', true, 100);
        $state = $v->string('state', true, 100);
        $pincode = $v->pattern('pincode', '/^\d{6}$/', 'Must be a 6-digit PIN code.', true, 6);
        $branchId = $v->int('branch_id', 1, PHP_INT_MAX, false);
        $dob = $v->date('date_of_birth', false);
        $gender = $v->in('gender', ['male', 'female', 'other'], false);
        $occupation = $v->string('occupation', false, 100);
        $v->validate();

        if (CustomerPhone::owner($phone) !== null) {
            throw HttpException::validation(['phone' => 'This mobile number is already registered. Please sign in instead.']);
        }
        if ($email !== null && Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw HttpException::validation(['email' => 'This email is already registered. Please sign in instead.']);
        }
        $branchId ??= (int) (Database::value("SELECT id FROM branches WHERE code = 'HO' AND status = 'active'")
            ?? Database::value("SELECT MIN(id) FROM branches WHERE status = 'active'"));
        if (Database::value("SELECT id FROM branches WHERE id = ? AND status = 'active'", [$branchId]) === null) {
            throw HttpException::validation(['branch_id' => 'Choose your nearest branch.']);
        }

        [$userId, $customerId] = Database::transaction(function () use ($name, $phone, $email, $password, $address, $city, $state, $pincode, $branchId, $dob, $gender, $occupation, $request) {
            $userId = Database::insert('users', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'branch_id' => $branchId,
            ]);
            Database::query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_key = 'customer'", [$userId]);
            $customerId = Database::insert('customers', [
                'customer_number' => Database::nextNumber('customer'),
                'user_id' => $userId,
                'branch_id' => $branchId,
                'full_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'date_of_birth' => $dob,
                'gender' => $gender,
                'address_line' => $address,
                'city' => $city,
                'state' => $state,
                'pincode' => $pincode,
                'occupation' => $occupation,
                'status' => 'pending',
                'source' => 'self',
                'created_by' => $userId,
            ]);
            Audit::log($request, $userId, 'customer.self_registered', 'customer', $customerId, null, ['phone' => $phone, 'branch_id' => $branchId]);
            $body = "{$name} ({$phone}) registered in the customer app and is waiting for approval.";
            foreach (['super_admin', 'admin'] as $role) {
                Notifier::role($role, null, 'customer.registered', 'New customer registration', $body, '/applications');
            }
            Notifier::role('branch_manager', $branchId, 'customer.registered', 'New customer registration', $body, '/applications');
            return [$userId, $customerId];
        });

        $session = (new AuthController())->startSession($userId, $request);
        return new Response(201, $session->body);
    }

    /** @return list<array<string, mixed>> */
    private function products(string $table, string $versionTable, array $fields): array
    {
        $rows = Database::all(
            "SELECT p.id, p.code, p.name, p.description, v.terms_json
               FROM {$table} p JOIN {$versionTable} v ON v.id = p.current_version_id
              WHERE p.status = 'active' ORDER BY p.name",
        );
        return array_map(function (array $r) use ($fields) {
            $terms = json_decode((string) $r['terms_json'], true);
            unset($r['terms_json']);
            return $r + ['terms' => array_intersect_key($terms, array_flip($fields))];
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function activeTerms(string $table, string $versionTable, int $id): array
    {
        $json = Database::value(
            "SELECT v.terms_json FROM {$table} p JOIN {$versionTable} v ON v.id = p.current_version_id WHERE p.id = ? AND p.status = 'active'",
            [$id],
        );
        if ($json === null) {
            throw HttpException::notFound('Plan not found.');
        }
        return json_decode((string) $json, true);
    }
}
