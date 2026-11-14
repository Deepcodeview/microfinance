<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;

final class UserController
{
    public function index(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        Paginator::filter($request, 'status', 'u.status', $where, $params, ['active', 'disabled']);
        Paginator::filter($request, 'branch_id', 'u.branch_id', $where, $params);
        $role = $request->query['role'] ?? '';
        if (is_string($role) && preg_match('/^[a-z_]+$/', $role)) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.role_key = ?)';
            $params[] = $role;
        } else {
            // Customer portal logins are managed from the customer record.
            $where[] = "NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.role_key = 'customer')";
        }
        $search = trim((string) ($request->query['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like);
        }
        return Paginator::run(
            $request,
            "u.id, u.name, u.email, u.phone, u.status, u.branch_id, u.last_login_at, u.created_at, b.name AS branch_name,
             u.mfa_enabled_at IS NOT NULL AS mfa_enabled,
             (SELECT GROUP_CONCAT(r.role_key ORDER BY r.role_key) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles",
            'users u LEFT JOIN branches b ON b.id = u.branch_id',
            implode(' AND ', $where),
            $params,
            ['name' => 'u.name', 'created_at' => 'u.id', 'last_login_at' => 'u.last_login_at'],
            'name',
        );
    }

    public function roles(Request $request): Response
    {
        return Response::json(Database::all("SELECT role_key, name FROM roles WHERE role_key <> 'customer' ORDER BY id"));
    }

    public function store(Request $request): Response
    {
        $v = new Validator($request->all());
        $name = $v->string('name', true, 150, 2);
        $email = $v->email('email');
        $phone = $v->phone('phone', false);
        $password = $v->string('password', true, 200, 8);
        $branchId = $v->int('branch_id', 1, PHP_INT_MAX, false);
        $v->validate();
        $roles = $this->validateRoles($request->input('roles'));

        if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw HttpException::validation(['email' => 'A user with this email already exists.']);
        }
        $this->assertBranch($branchId, $roles);

        $actor = Auth::user();
        $id = Database::transaction(function () use ($name, $email, $phone, $password, $branchId, $roles, $actor, $request) {
            $id = Database::insert('users', [
                'name' => $name, 'email' => $email, 'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'branch_id' => $branchId,
            ]);
            $this->syncRoles($id, $roles);
            Audit::log($request, $actor->id, 'user.created', 'user', $id, null, compact('name', 'email', 'branchId', 'roles'));
            return $id;
        });
        return Response::json($this->find($id), 201);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        $before = $this->find($id);
        $actor = Auth::user();
        $input = $request->all();
        $v = new Validator($input);
        $data = [];
        if (array_key_exists('name', $input)) $data['name'] = $v->string('name', true, 150, 2);
        if (array_key_exists('phone', $input)) $data['phone'] = $v->phone('phone', false);
        if (array_key_exists('branch_id', $input)) $data['branch_id'] = $v->int('branch_id', 1, PHP_INT_MAX, false);
        if (array_key_exists('status', $input)) $data['status'] = $v->in('status', ['active', 'disabled']);
        $v->validate();
        $roles = array_key_exists('roles', $input) ? $this->validateRoles($input['roles']) : null;

        if ($id === $actor->id && (($data['status'] ?? 'active') === 'disabled' || ($roles !== null && $roles !== $before['roles']))) {
            throw HttpException::forbidden('You cannot disable yourself or change your own roles.');
        }
        if (in_array('super_admin', $before['roles'], true) && !in_array('super_admin', $actor->roles, true)) {
            throw HttpException::forbidden('Only a Super Admin can modify another Super Admin.');
        }
        $this->assertBranch(array_key_exists('branch_id', $data) ? $data['branch_id'] : ($before['branch_id'] === null ? null : (int) $before['branch_id']), $roles ?? $before['roles']);

        Database::transaction(function () use ($id, $data, $roles, $before, $actor, $request) {
            [$old, $new] = Audit::diff($before, $data);
            if ($new !== []) {
                $sets = implode(', ', array_map(fn ($c) => "`{$c}` = :{$c}", array_keys($new)));
                Database::query("UPDATE users SET {$sets} WHERE id = :id", $new + ['id' => $id]);
            }
            if ($roles !== null && $roles !== $before['roles']) {
                $this->syncRoles($id, $roles);
                $old['roles'] = $before['roles'];
                $new['roles'] = $roles;
            }
            if (($new['status'] ?? null) === 'disabled') {
                Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            }
            if ($new !== []) {
                Audit::log($request, $actor->id, 'user.updated', 'user', $id, $old, $new);
            }
        });
        return Response::json($this->find($id));
    }

    public function resetPassword(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->find($id);
        $v = new Validator($request->all());
        $password = $v->string('password', true, 200, 8);
        $v->validate();
        Database::transaction(function () use ($id, $password, $request) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            Audit::log($request, Auth::user()->id, 'user.password_reset', 'user', $id);
        });
        return Response::noContent();
    }

    /** For a user who lost their authenticator: they must re-enrol at next sign-in (if required). */
    public function resetMfa(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->find($id);
        Database::transaction(function () use ($id, $request) {
            Database::query('UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE id = ?', [$id]);
            Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            Audit::log($request, Auth::user()->id, 'user.mfa_reset', 'user', $id);
        });
        return Response::noContent();
    }

    /** Any signed-in user changing their own password. */
    public function changeOwnPassword(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $current = $v->string('current_password', true, 200);
        $new = $v->string('new_password', true, 200, 8);
        $v->validate();
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$user->id]);
        if (!password_verify($current, $hash)) {
            throw HttpException::validation(['current_password' => 'Current password is incorrect.']);
        }
        Database::transaction(function () use ($user, $new, $request) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user->id]);
            Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', [$user->id, $user->tokenId]);
            Audit::log($request, $user->id, 'user.password_changed', 'user', $user->id);
        });
        return Response::noContent();
    }

    /** @return list<string> */
    private function validateRoles(mixed $roles): array
    {
        $valid = array_column(Database::all("SELECT role_key FROM roles WHERE role_key <> 'customer'"), 'role_key');
        if (!is_array($roles) || $roles === [] || array_diff($roles, $valid) !== []) {
            throw HttpException::validation(['roles' => 'Choose at least one valid staff role.']);
        }
        if (in_array('super_admin', $roles, true) && !in_array('super_admin', Auth::user()->roles, true)) {
            throw HttpException::forbidden('Only a Super Admin can grant the Super Admin role.');
        }
        $roles = array_values(array_unique($roles));
        sort($roles);
        return $roles;
    }

    private function assertBranch(?int $branchId, array $roles): void
    {
        $needsBranch = array_diff($roles, ['super_admin', 'admin', 'finance', 'auditor']) !== [];
        if ($needsBranch && $branchId === null) {
            throw HttpException::validation(['branch_id' => 'Branch-level roles need a branch.']);
        }
        if ($branchId !== null && Database::value("SELECT id FROM branches WHERE id = ? AND status = 'active'", [$branchId]) === null) {
            throw HttpException::validation(['branch_id' => 'Branch not found or inactive.']);
        }
    }

    private function syncRoles(int $userId, array $roles): void
    {
        Database::query('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach ($roles as $role) {
            Database::query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_key = ?', [$userId, $role]);
        }
    }

    private function find(int $id): array
    {
        $user = Database::one(
            'SELECT u.id, u.name, u.email, u.phone, u.status, u.branch_id, u.last_login_at, u.created_at, b.name AS branch_name,
                    u.mfa_enabled_at IS NOT NULL AS mfa_enabled
               FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?',
            [$id],
        ) ?? throw HttpException::notFound('User not found.');
        $roles = array_column(Database::all('SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.role_key', [$id]), 'role_key');
        if (in_array('customer', $roles, true)) {
            throw HttpException::notFound('User not found.');
        }
        $user['roles'] = $roles;
        return $user;
    }
}
