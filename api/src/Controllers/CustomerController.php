<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\CustomerLocation;
use App\Services\CustomerScope;
use App\Services\DepositService;
use App\Services\GeoPoint;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\Settings;

final class CustomerController
{
    private const SORTABLE = ['created_at', 'full_name', 'customer_number'];

    private const EDITABLE = [
        'full_name', 'phone', 'alt_phone', 'email', 'date_of_birth', 'gender',
        'address_line', 'city', 'state', 'pincode', 'occupation', 'monthly_income', 'status',
    ];

    private const CURRENT_AGENT_SQL = '(SELECT u.name FROM agent_assignments aa JOIN users u ON u.id = aa.agent_user_id
         WHERE aa.customer_id = c.id AND aa.valid_from <= UTC_DATE()
           AND (aa.valid_to IS NULL OR aa.valid_to >= UTC_DATE())
         ORDER BY aa.id DESC LIMIT 1)';

    public function index(Request $request): Response
    {
        [$scopeSql, $params] = CustomerScope::where(Auth::user());
        $where = [$scopeSql];

        $search = trim((string) ($request->query['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(c.full_name LIKE ? OR c.phone LIKE ? OR c.customer_number LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $status = $request->query['status'] ?? '';
        if (in_array($status, ['pending', 'active', 'inactive', 'blocked'], true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $branchId = $request->query['branch_id'] ?? '';
        if (is_string($branchId) && ctype_digit($branchId)) {
            $where[] = 'c.branch_id = ?';
            $params[] = (int) $branchId;
        }

        $sort = in_array($request->query['sort'] ?? '', self::SORTABLE, true) ? $request->query['sort'] : 'created_at';
        $direction = ($request->query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM customers c WHERE {$whereSql}", $params);
        $rows = Database::all(
            "SELECT c.id, c.customer_number, c.full_name, c.phone, c.email, c.address_line, c.city, c.state, c.pincode, c.occupation,
                    c.status, c.source, c.registration_note, c.created_at,
                    b.name AS branch_name, " . self::CURRENT_AGENT_SQL . " AS agent_name
               FROM customers c
               JOIN branches b ON b.id = c.branch_id
              WHERE {$whereSql}
              ORDER BY c.{$sort} {$direction}, c.id {$direction}
              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params,
        );

        return Response::paginated($rows, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function show(Request $request): Response
    {
        $customer = $this->findInScope($request->intParam('id'));

        $customer['assignments'] = Database::all(
            'SELECT aa.id, aa.agent_user_id, u.name AS agent_name, aa.valid_from, aa.valid_to, ab.name AS assigned_by_name
               FROM agent_assignments aa
               JOIN users u ON u.id = aa.agent_user_id
               JOIN users ab ON ab.id = aa.assigned_by
              WHERE aa.customer_id = ?
              ORDER BY aa.id DESC',
            [$customer['id']],
        );
        $today = Settings::today();
        $unpaid = 'i.principal_due - i.principal_paid + i.interest_due - i.interest_paid - i.interest_waived + i.late_fee_due - i.late_fee_paid';
        // One row per account with what the customer, agent and branch need at a glance: how often it is paid, what is due next, what is late.
        $customer['loans'] = Database::all(
            "SELECT l.id, l.loan_number, l.status, l.principal, l.installment_amount, p.name AS product_name,
                    JSON_UNQUOTE(JSON_EXTRACT(v.terms_json, '$.repayment_frequency')) AS frequency,
                    (SELECT COALESCE(SUM({$unpaid}), 0) FROM loan_installments i WHERE i.loan_id = l.id) AS outstanding,
                    (SELECT COALESCE(SUM({$unpaid}), 0) FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date < ?) AS overdue,
                    (SELECT MIN(i.due_date) FROM loan_installments i WHERE i.loan_id = l.id AND i.status <> 'paid' AND i.due_date >= ?) AS next_due_date,
                    (SELECT COUNT(*) FROM loan_installments i WHERE i.loan_id = l.id) AS installments_total,
                    (SELECT COUNT(*) FROM loan_installments i WHERE i.loan_id = l.id AND i.status = 'paid') AS installments_paid
               FROM loans l JOIN loan_products p ON p.id = l.product_id JOIN loan_product_versions v ON v.id = l.product_version_id
              WHERE l.customer_id = ? ORDER BY l.id DESC",
            [$today, $today, $customer['id']],
        );
        foreach ($customer['loans'] as &$loan) {
            $loan['outstanding'] = Money::fmt($loan['outstanding']);
            $loan['overdue'] = Money::fmt($loan['overdue']);
        }
        unset($loan);
        $customer['deposits'] = Database::all(
            "SELECT d.id, d.deposit_number, d.status, d.scheme, d.installment_amount, d.deposit_amount, d.opened_on,
                    d.principal_balance, d.accrued_interest, d.maturity_date, d.tenure_value, d.tenure_unit, p.name AS product_name,
                    IF(d.scheme = 'fixed', NULL, JSON_UNQUOTE(JSON_EXTRACT(v.terms_json, '$.contribution_frequency'))) AS frequency
               FROM deposit_accounts d JOIN deposit_products p ON p.id = d.product_id JOIN deposit_product_versions v ON v.id = d.product_version_id
              WHERE d.customer_id = ? ORDER BY d.id DESC",
            [$customer['id']],
        );
        // Recurring savings: what is owed now, and since when (missed installments are paid oldest first).
        $today = Settings::today();
        foreach ($customer['deposits'] as &$deposit) {
            if ($deposit['scheme'] === 'recurring' && $deposit['status'] === 'active' && $deposit['maturity_date'] > $today) {
                $status = DepositService::contributionStatus(Database::one('SELECT * FROM deposit_accounts WHERE id = ?', [$deposit['id']]), $today);
                $deposit += array_intersect_key($status, array_flip(['installments_due', 'missed_installments', 'unpaid_since', 'last_paid_on', 'amount_due', 'next_due_date']));
            }
        }
        unset($deposit);

        return Response::json($customer);
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $data = $this->validate($request->all(), true);

        $v = new Validator($request->all());
        $branchId = $v->int('branch_id', 1, PHP_INT_MAX, $user->customerScope() === 'all');
        $agentId = $v->int('agent_user_id', 1, PHP_INT_MAX, false);
        $v->validate();

        // Branch-scoped staff can only create customers in their own branch.
        if ($user->customerScope() !== 'all') {
            if ($branchId !== null && $branchId !== $user->branchId) {
                throw HttpException::forbidden('You can only create customers in your own branch.');
            }
            $branchId = $user->branchId ?? throw HttpException::forbidden('Your account is not linked to a branch.');
        }
        if (Database::value("SELECT id FROM branches WHERE id = ? AND status = 'active'", [$branchId]) === null) {
            throw HttpException::validation(['branch_id' => 'Branch not found or inactive.']);
        }
        if ($user->customerScope() === 'assigned') {
            // A field agent onboarding a customer becomes their collection agent automatically.
            $agentId = $user->id;
        } elseif ($agentId !== null) {
            if (!$user->can('customers.assign')) {
                throw HttpException::forbidden('You do not have permission to assign agents.');
            }
            $this->assertAgent($agentId, $branchId);
        }

        $this->assertPhoneAvailable($data['phone']);

        $id = Database::transaction(function () use ($data, $branchId, $agentId, $user, $request) {
            $id = Database::insert('customers', $data + [
                'customer_number' => Database::nextNumber('customer'),
                'branch_id' => $branchId,
                'created_by' => $user->id,
            ]);
            Audit::log($request, $user->id, 'customer.created', 'customer', $id, null, $data + ['branch_id' => $branchId]);

            if ($agentId !== null) {
                $this->assign($request, $id, $agentId);
            }
            return $id;
        });

        $request->params['id'] = (string) $id;
        return new Response(201, $this->show($request)->body);
    }

    public function update(Request $request): Response
    {
        $before = $this->findInScope($request->intParam('id'));
        $data = $this->validate($request->all(), false);
        if ($data === []) {
            throw HttpException::validation([], 'No changes supplied.');
        }
        if (isset($data['phone']) && $data['phone'] !== $before['phone']) {
            $this->assertPhoneAvailable($data['phone'], (int) $before['id']);
        }
        if ($before['status'] === 'pending' && isset($data['status']) && $data['status'] !== 'pending') {
            throw HttpException::validation(['status' => 'This customer registered in the app — use Approve or Reject registration instead.']);
        }

        [$old, $new] = Audit::diff($before, $data);
        if ($new !== []) {
            Database::transaction(function () use ($new, $old, $before, $request) {
                $sets = implode(', ', array_map(fn ($c) => "`{$c}` = :{$c}", array_keys($new)));
                Database::query("UPDATE customers SET {$sets} WHERE id = :id", $new + ['id' => $before['id']]);
                Audit::log($request, Auth::user()->id, 'customer.updated', 'customer', (int) $before['id'], $old, $new, $request->input('reason'));
            });
        }

        return $this->show($request);
    }

    /** A customer who registered in the app becomes active; optionally hand them to a collection agent. */
    public function approveRegistration(Request $request): Response
    {
        $user = Auth::user();
        $customer = $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $agentId = $v->int('agent_user_id', 1, PHP_INT_MAX, false);
        $v->validate();
        if ($customer['status'] !== 'pending') {
            throw HttpException::conflict('This customer is not waiting for approval.');
        }
        if ($agentId !== null) {
            if (!$user->can('customers.assign')) {
                throw HttpException::forbidden('You do not have permission to assign agents.');
            }
            $this->assertAgent($agentId, (int) $customer['branch_id']);
        }

        Database::transaction(function () use ($customer, $agentId, $user, $request) {
            Database::query(
                "UPDATE customers SET status = 'active', registration_note = NULL, approved_by = ?, approved_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'",
                [$user->id, $customer['id']],
            );
            if ($agentId !== null) {
                $this->assign($request, (int) $customer['id'], $agentId);
            }
            Audit::log($request, $user->id, 'customer.registration_approved', 'customer', (int) $customer['id'], ['status' => 'pending'], ['status' => 'active', 'agent_user_id' => $agentId]);
            Notifier::customer((int) $customer['id'], 'customer.registration_approved', 'Registration approved',
                'Welcome! Your registration is approved. Your loan and savings applications can now be processed.', '/');
        });
        return $this->show($request);
    }

    public function rejectRegistration(Request $request): Response
    {
        $user = Auth::user();
        $customer = $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $reason = $v->string('reason', true, 255, 3);
        $v->validate();
        if ($customer['status'] !== 'pending') {
            throw HttpException::conflict('This customer is not waiting for approval.');
        }

        Database::transaction(function () use ($customer, $reason, $user, $request) {
            Database::query("UPDATE customers SET status = 'blocked', registration_note = ? WHERE id = ? AND status = 'pending'", [$reason, $customer['id']]);
            Database::query(
                "UPDATE customer_applications SET status = 'rejected', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = 'Registration not approved' WHERE customer_id = ? AND status = 'submitted'",
                [$user->id, $customer['id']],
            );
            Audit::log($request, $user->id, 'customer.registration_rejected', 'customer', (int) $customer['id'], ['status' => 'pending'], ['status' => 'blocked'], $reason);
            Notifier::customer((int) $customer['id'], 'customer.registration_rejected', 'Registration not approved',
                "Your registration was not approved: {$reason}. Please contact the branch.", '/');
        });
        return $this->show($request);
    }

    /** Pin the customer's location (the phone's GPS where the agent stands now) for the collection map. */
    public function setLocation(Request $request): Response
    {
        $user = Auth::user();
        $customer = $this->findInScope($request->intParam('id'));
        $point = GeoPoint::fromInput($request->input('location'))
            ?? throw HttpException::validation(['location' => 'Turn on location (GPS) and try again.']);
        Database::transaction(function () use ($customer, $point, $user, $request) {
            CustomerLocation::set((int) $customer['id'], $point, $user->id);
            Audit::log($request, $user->id, 'customer.location_set', 'customer', (int) $customer['id'],
                ['latitude' => $customer['latitude'], 'longitude' => $customer['longitude']], ['latitude' => $point['latitude'], 'longitude' => $point['longitude']]);
        });
        return $this->show($request);
    }

    public function assignAgent(Request $request): Response
    {
        $customer = $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $agentId = $v->int('agent_user_id', 1, PHP_INT_MAX);
        $v->validate();

        $this->assertAgent($agentId, (int) $customer['branch_id']);
        Database::transaction(fn () => $this->assign($request, (int) $customer['id'], $agentId));

        return $this->show($request);
    }

    /** Creates (or resets) the customer's self-service portal login. */
    public function portalAccess(Request $request): Response
    {
        $customer = $this->findInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $email = $v->email('email');
        $password = $v->string('password', true, 200, 8);
        $v->validate();

        Database::transaction(function () use ($customer, $email, $password, $request) {
            $existing = Database::one('SELECT id FROM users WHERE email = ?', [$email]);
            if ($customer['user_id'] === null) {
                if ($existing !== null) {
                    throw HttpException::validation(['email' => 'This email is already used by another login.']);
                }
                $userId = Database::insert('users', [
                    'name' => $customer['full_name'],
                    'email' => $email,
                    'phone' => $customer['phone'],
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'branch_id' => $customer['branch_id'],
                ]);
                Database::query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_key = 'customer'", [$userId]);
                Database::query('UPDATE customers SET user_id = ? WHERE id = ?', [$userId, $customer['id']]);
                $action = 'customer.portal_enabled';
            } else {
                $userId = (int) $customer['user_id'];
                if ($existing !== null && (int) $existing['id'] !== $userId) {
                    throw HttpException::validation(['email' => 'This email is already used by another login.']);
                }
                Database::query("UPDATE users SET email = ?, password_hash = ?, status = 'active' WHERE id = ?", [$email, password_hash($password, PASSWORD_DEFAULT), $userId]);
                Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
                $action = 'customer.portal_reset';
            }
            Audit::log($request, Auth::user()->id, $action, 'customer', (int) $customer['id'], null, ['email' => $email]);
        });

        return $this->show($request);
    }

    /** Agents a user may assign customers to (same branch unless the user has global scope). */
    public function agents(Request $request): Response
    {
        $user = Auth::user();
        $params = [];
        $branchFilter = '';
        if ($user->customerScope() !== 'all') {
            $branchFilter = 'AND u.branch_id = ?';
            $params[] = $user->branchId;
        }
        return Response::json(Database::all(
            "SELECT u.id, u.name, u.branch_id, b.name AS branch_name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.role_key = 'collection_agent'
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE u.status = 'active' {$branchFilter}
              ORDER BY u.name",
            $params,
        ));
    }

    /** Closes any open assignment and opens a new one from today. Caller must hold a transaction. */
    private function assign(Request $request, int $customerId, int $agentId): void
    {
        $current = Database::one(
            'SELECT id, agent_user_id FROM agent_assignments WHERE customer_id = ? AND valid_to IS NULL FOR UPDATE',
            [$customerId],
        );
        if ($current !== null && (int) $current['agent_user_id'] === $agentId) {
            return;
        }
        if ($current !== null) {
            Database::query('UPDATE agent_assignments SET valid_to = UTC_DATE() - INTERVAL 1 DAY WHERE id = ?', [$current['id']]);
        }
        Database::insert('agent_assignments', [
            'customer_id' => $customerId,
            'agent_user_id' => $agentId,
            'valid_from' => gmdate('Y-m-d'),
            'assigned_by' => Auth::user()->id,
        ]);
        Audit::log(
            $request,
            Auth::user()->id,
            'customer.agent_assigned',
            'customer',
            $customerId,
            $current === null ? null : ['agent_user_id' => (int) $current['agent_user_id']],
            ['agent_user_id' => $agentId],
        );
    }

    private function findInScope(int $id): array
    {
        [$scopeSql, $params] = CustomerScope::where(Auth::user());
        $customer = Database::one(
            "SELECT c.*, b.name AS branch_name, cb.name AS created_by_name, pu.email AS portal_email, pu.status AS portal_status
               FROM customers c
               JOIN branches b ON b.id = c.branch_id
               JOIN users cb ON cb.id = c.created_by
               LEFT JOIN users pu ON pu.id = c.user_id
              WHERE c.id = ? AND {$scopeSql}",
            array_merge([$id], $params),
        );
        // 404 rather than 403 so out-of-scope IDs are indistinguishable from missing ones.
        return $customer ?? throw HttpException::notFound('Customer not found.');
    }

    private function assertAgent(int $agentId, int $branchId): void
    {
        $agent = Database::one(
            "SELECT u.id, u.branch_id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.role_key = 'collection_agent'
              WHERE u.id = ? AND u.status = 'active'",
            [$agentId],
        );
        if ($agent === null) {
            throw HttpException::validation(['agent_user_id' => 'Agent not found or inactive.']);
        }
        if ((int) $agent['branch_id'] !== $branchId) {
            throw HttpException::validation(['agent_user_id' => "Agent must belong to the customer's branch."]);
        }
    }

    private function assertPhoneAvailable(string $phone, ?int $exceptId = null): void
    {
        $existing = Database::one('SELECT id, customer_number FROM customers WHERE phone = ? AND id <> ?', [$phone, $exceptId ?? 0]);
        if ($existing !== null) {
            throw HttpException::validation(['phone' => "This phone number is already registered ({$existing['customer_number']})."]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> Only the editable fields that were supplied (all required ones when creating).
     */
    private function validate(array $input, bool $creating): array
    {
        $v = new Validator($input);
        $has = fn (string $f) => $creating || array_key_exists($f, $input);

        $data = [];
        if ($has('full_name')) $data['full_name'] = $v->string('full_name', true, 150, 2);
        if ($has('phone')) $data['phone'] = $v->phone('phone');
        if ($has('alt_phone')) $data['alt_phone'] = $v->phone('alt_phone', false);
        if ($has('email')) $data['email'] = $v->email('email', false);
        if ($has('date_of_birth')) $data['date_of_birth'] = $v->date('date_of_birth', false);
        if ($has('gender')) $data['gender'] = $v->in('gender', ['male', 'female', 'other'], false);
        if ($has('address_line')) $data['address_line'] = $v->string('address_line', true, 255, 3);
        if ($has('city')) $data['city'] = $v->string('city', true, 100);
        if ($has('state')) $data['state'] = $v->string('state', true, 100);
        if ($has('pincode')) $data['pincode'] = $v->pattern('pincode', '/^\d{6}$/', 'Must be a 6-digit PIN code.', true, 6);
        if ($has('occupation')) $data['occupation'] = $v->string('occupation', false, 100);
        if ($has('monthly_income')) $data['monthly_income'] = $v->decimal('monthly_income', '0', '100000000', 4, false);
        if (!$creating && array_key_exists('status', $input)) {
            $data['status'] = $v->in('status', ['active', 'inactive', 'blocked']);
        }

        if (isset($data['date_of_birth']) && $data['date_of_birth'] > gmdate('Y-m-d', strtotime('-18 years'))) {
            $v->addError('date_of_birth', 'Customer must be at least 18 years old.');
        }
        $v->validate();

        return array_intersect_key($data, array_flip(self::EDITABLE));
    }
}
