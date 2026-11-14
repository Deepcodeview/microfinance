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
use App\Services\Settings;

/** Audit trail, system settings and branches. */
final class AdminController
{
    public function auditLogs(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        Paginator::filter($request, 'actor_id', 'a.actor_id', $where, $params);
        Paginator::filter($request, 'entity_id', 'a.entity_id', $where, $params);
        foreach (['action' => 'a.action', 'entity_type' => 'a.entity_type'] as $param => $column) {
            $value = $request->query[$param] ?? '';
            if (is_string($value) && preg_match('/^[a-z_.]+$/', $value)) {
                $where[] = "{$column} LIKE ?";
                $params[] = $value . '%';
            }
        }
        foreach (['date_from' => '>=', 'date_to' => '<'] as $param => $op) {
            $date = $request->query[$param] ?? '';
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = "a.created_at {$op} " . ($op === '<' ? 'DATE_ADD(?, INTERVAL 1 DAY)' : '?');
                $params[] = $date;
            }
        }
        // Reading the audit trail is itself a sensitive access.
        Audit::log($request, Auth::user()->id, 'audit.viewed', null, null, null, ['filters' => $request->query]);
        return Paginator::run(
            $request,
            'a.id, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.reason, a.ip, a.request_id, a.created_at, u.name AS actor_name',
            'audit_logs a LEFT JOIN users u ON u.id = a.actor_id',
            implode(' AND ', $where),
            $params,
            ['created_at' => 'a.id'],
            'created_at',
        );
    }

    public function settings(Request $request): Response
    {
        return Response::json(Settings::all() + ['business_date' => Settings::today()]);
    }

    public function updateSettings(Request $request): Response
    {
        $user = Auth::user();
        $input = $request->all();
        $v = new Validator($input);
        $changes = [];
        if (array_key_exists('company_name', $input)) $changes['company_name'] = $v->string('company_name', true, 100, 2);
        if (array_key_exists('business_timezone', $input)) {
            $tz = $v->string('business_timezone', true, 64);
            if ($tz !== null && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $v->addError('business_timezone', 'Unknown timezone.');
            }
            $changes['business_timezone'] = $tz;
        }
        // Credit/cash limits and provisioning rates: money or percentages as decimal strings.
        foreach ([
            'loan_approval_limit' => ['1', '1000000000'], 'max_customer_exposure' => ['1', '1000000000'], 'agent_cash_limit' => ['1', '100000000'],
            'provision_rate_standard' => ['0', '100'], 'provision_rate_substandard' => ['0', '100'],
            'provision_rate_doubtful' => ['0', '100'], 'provision_rate_loss' => ['0', '100'],
        ] as $key => [$min, $max]) {
            if (array_key_exists($key, $input)) {
                $value = $v->decimal($key, $min, $max, 2);
                $changes[$key] = $value;
            }
        }
        if (array_key_exists('weekly_off_days', $input)) {
            $days = $v->pattern('weekly_off_days', '/^([1-7](,[1-7])*)?$/', 'Use ISO weekday numbers, e.g. "7" for Sunday.', false, 13);
            $changes['weekly_off_days'] = $days ?? '';
        }
        if (array_key_exists('upi_vpa', $input)) {
            $vpa = $v->pattern('upi_vpa', '/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,64}$/', 'Enter a valid UPI ID, e.g. finserve@okhdfcbank.', false, 320);
            $changes['upi_vpa'] = $vpa === null ? '' : strtolower($vpa);
        }
        if (array_key_exists('upi_payee_name', $input)) {
            $changes['upi_payee_name'] = $v->string('upi_payee_name', false, 50) ?? '';
        }
        if (array_key_exists('owner_self_approval', $input)) {
            $changes['owner_self_approval'] = $v->bool('owner_self_approval') ? '1' : '0';
        }
        if (array_key_exists('require_collection_location', $input)) {
            $changes['require_collection_location'] = $v->bool('require_collection_location') ? '1' : '0';
        }
        if (array_key_exists('allow_bank_overdraft', $input)) {
            $changes['allow_bank_overdraft'] = $v->bool('allow_bank_overdraft') ? '1' : '0';
        }
        if (array_key_exists('mfa_required_for_privileged', $input)) {
            $required = $v->bool('mfa_required_for_privileged');
            // Prevent the admin locking themselves out of settings.
            if ($required && !$user->mfaEnabled) {
                $v->addError('mfa_required_for_privileged', 'Turn on two-factor sign-in for your own account first.');
            }
            $changes['mfa_required_for_privileged'] = $required ? '1' : '0';
        }
        if (array_key_exists('deposits_enabled', $input)) {
            $enabled = $v->bool('deposits_enabled');
            $changes['deposits_enabled'] = $enabled ? '1' : '0';
        }
        $reason = $v->string('reason', array_key_exists('deposits_enabled', $input), 500, 5);
        $v->validate();

        $before = Settings::all();
        Database::transaction(function () use ($changes, $user, $before, $reason, $request) {
            $old = $new = [];
            foreach ($changes as $key => $value) {
                if ($before[$key] !== $value) {
                    Settings::set($key, $value, $user->id);
                    $old[$key] = $before[$key];
                    $new[$key] = $value;
                }
            }
            if ($new !== []) {
                Audit::log($request, $user->id, 'settings.updated', 'settings', null, $old, $new, $reason);
            }
        });
        return $this->settings($request);
    }

    public function holidays(Request $request): Response
    {
        return Response::json([
            'weekly_off_days' => Settings::get('weekly_off_days'),
            'holidays' => Database::all('SELECT holiday_date, name FROM holidays WHERE holiday_date >= DATE_SUB(?, INTERVAL 30 DAY) ORDER BY holiday_date', [Settings::today()]),
        ]);
    }

    public function storeHoliday(Request $request): Response
    {
        $v = new Validator($request->all());
        $date = $v->date('holiday_date');
        $name = $v->string('name', true, 100, 2);
        $v->validate();
        if (Database::value('SELECT holiday_date FROM holidays WHERE holiday_date = ?', [$date]) !== null) {
            throw HttpException::validation(['holiday_date' => 'This date is already a holiday.']);
        }
        Database::transaction(function () use ($date, $name, $request) {
            Database::insert('holidays', ['holiday_date' => $date, 'name' => $name, 'created_by' => Auth::user()->id]);
            Audit::log($request, Auth::user()->id, 'holiday.created', 'holiday', null, null, compact('date', 'name'));
        });
        return $this->holidays($request);
    }

    public function deleteHoliday(Request $request): Response
    {
        $date = $request->param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw HttpException::notFound();
        }
        Database::transaction(function () use ($date, $request) {
            Database::query('DELETE FROM holidays WHERE holiday_date = ?', [$date]);
            Audit::log($request, Auth::user()->id, 'holiday.deleted', 'holiday', null, ['date' => $date], null);
        });
        return $this->holidays($request);
    }

    public function storeBranch(Request $request): Response
    {
        $v = new Validator($request->all());
        $code = $v->pattern('code', '/^[A-Z0-9]{2,20}$/', 'Use 2–20 capital letters or digits.', true, 20);
        $name = $v->string('name', true, 150, 2);
        $v->validate();
        if (Database::value('SELECT id FROM branches WHERE code = ?', [$code]) !== null) {
            throw HttpException::validation(['code' => 'This branch code is already in use.']);
        }
        $id = Database::transaction(function () use ($code, $name, $request) {
            $id = Database::insert('branches', compact('code', 'name'));
            Audit::log($request, Auth::user()->id, 'branch.created', 'branch', $id, null, compact('code', 'name'));
            return $id;
        });
        return Response::json(Database::one('SELECT id, code, name, status FROM branches WHERE id = ?', [$id]), 201);
    }
}
