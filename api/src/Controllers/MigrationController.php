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
use App\Services\CustomerPhone;
use App\Services\Migration;
use App\Services\Money;
use App\Services\Settings;

/**
 * Bringing in loans and savings from the old manual register — one at a time or a whole sheet.
 * Each row runs in its own transaction: a preview (commit = false) does all the work and rolls it back,
 * so what it shows is exactly what an import would do; an import keeps the rows that pass.
 */
final class MigrationController
{
    private const MAX_ROWS = 500;

    public function import(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $kind = $v->in('kind', ['loans', 'savings']);
        $commit = $v->bool('commit', false) ?? false;
        $v->validate();
        $rows = $request->input('rows');
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            throw HttpException::validation(['rows' => 'Send 1 to ' . self::MAX_ROWS . ' rows.']);
        }
        if ($kind === 'savings') {
            Settings::requireDeposits();
        }

        $pdo = Database::pdo();
        $results = [];
        foreach ($rows as $i => $row) {
            $pdo->beginTransaction();
            try {
                $row = is_array($row) ? $row : [];
                // Check the customer and the account fields together, so a row shows all its mistakes at once.
                $errors = [];
                try {
                    [$customer, $isNew] = $this->customer($row, $request);
                } catch (HttpException $e) {
                    $errors += $e->fields ?: ['customer' => $e->getMessage()];
                }
                try {
                    $values = $kind === 'loans' ? $this->loanValues($row) : $this->savingsValues($row);
                } catch (HttpException $e) {
                    $errors += $e->fields ?: ['row' => $e->getMessage()];
                }
                if ($errors !== []) {
                    throw HttpException::validation($errors);
                }
                $userId = Auth::user()->id;
                $summary = $kind === 'loans'
                    ? Migration::loan($customer, ...[...$values, 'userId' => $userId, 'request' => $request])
                    : Migration::deposit($customer, ...[...$values, 'userId' => $userId, 'request' => $request]);
                $commit ? $pdo->commit() : $pdo->rollBack();
                $results[] = ['row' => $i + 1, 'ok' => true, 'customer' => $customer['full_name'], 'customer_is_new' => $isNew] + $summary;
            } catch (HttpException $e) {
                $pdo->rollBack();
                $results[] = ['row' => $i + 1, 'ok' => false, 'customer' => $row['name'] ?? null, 'errors' => $e->fields ?: ['row' => $e->getMessage()]];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        if ($commit) {
            Audit::log($request, $user->id, 'migration.imported', null, null, null, ['kind' => $kind, 'imported' => $ok, 'failed' => count($results) - $ok]);
        }
        return Response::json(['kind' => $kind, 'committed' => $commit, 'ok' => $ok, 'failed' => count($results) - $ok, 'rows' => $results]);
    }

    /** The loan fields of a row, checked; named as Migration::loan() takes them. @return array<string, mixed> */
    private function loanValues(array $row): array
    {
        $errors = [];
        try {
            $product = $this->product('loan_products', $row);
        } catch (HttpException $e) {
            $errors += $e->fields;
            $product = null;
        }
        $v = new Validator($row);
        $principal = Migration::money($row['loan_amount'] ?? '');
        $collected = Migration::money($row['amount_received'] ?? '', true);
        $start = Migration::date($row['start_date'] ?? '');
        $tenure = $v->int('installments', 1, 1000);
        $note = $v->string('note', false, 255);
        if ($principal === null || !Money::isPositive($principal)) $errors['loan_amount'] = 'Enter the loan amount, e.g. 10000.';
        if ($collected === null) $errors['amount_received'] = 'Enter the total received so far (0 if nothing), e.g. 4500.';
        if ($start === null) $errors['start_date'] = 'Use a date like 15-03-2026.';
        $this->validate($v, $errors);
        return compact('product', 'principal', 'tenure', 'start', 'collected', 'note');
    }

    /** The savings fields of a row, checked; named as Migration::deposit() takes them. @return array<string, mixed> */
    private function savingsValues(array $row): array
    {
        $errors = [];
        try {
            $product = $this->product('deposit_products', $row);
        } catch (HttpException $e) {
            $errors += $e->fields;
            $product = null;
        }
        $v = new Validator($row);
        $amount = Migration::money($row['amount'] ?? '');
        $opened = Migration::date($row['opened_on'] ?? '');
        $balance = trim((string) ($row['balance'] ?? '')) === '' ? null : Migration::money($row['balance']);
        $interest = Migration::money($row['interest'] ?? '', true);
        $tenureText = trim((string) ($row['tenure'] ?? ''));
        $tenure = $tenureText === '' ? null : Migration::tenure($tenureText);
        if ($amount === null || !Money::isPositive($amount)) $errors['amount'] = 'Enter the installment (or the fixed deposit amount).';
        if ($opened === null) $errors['opened_on'] = 'Use a date like 15-03-2026.';
        if ($balance === null && trim((string) ($row['balance'] ?? '')) !== '') $errors['balance'] = 'Enter the balance saved so far, e.g. 3650.';
        if ($interest === null) $errors['interest'] = 'Enter interest so far, or leave it empty.';
        if ($tenureText !== '' && $tenure === null) $errors['tenure'] = 'Write the tenure like "12 months", "45 days" or "1 year".';
        $this->validate($v, $errors);
        return compact('product', 'amount', 'tenure', 'opened', 'balance', 'interest');
    }

    /** The plan by code (as in Loan & savings plans) or id; inactive plans are fine for old accounts. */
    private function product(string $table, array $row): array
    {
        $code = strtoupper(trim((string) ($row['plan_code'] ?? '')));
        $id = (int) ($row['product_id'] ?? 0);
        $product = $id > 0
            ? Database::one("SELECT id, code, current_version_id FROM {$table} WHERE id = ?", [$id])
            : Database::one("SELECT id, code, current_version_id FROM {$table} WHERE code = ?", [$code]);
        return $product ?? throw HttpException::validation(['plan_code' => $code === '' ? 'Choose the plan.' : "No plan with code {$code}. Check Setup → Loan & savings plans."]);
    }

    /**
     * The row's customer: by id, else by mobile number (matched in any format), else created from the row.
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function customer(array $row, Request $request): array
    {
        $user = Auth::user();
        if (!empty($row['customer_id'])) {
            $customer = Database::one('SELECT * FROM customers WHERE id = ?', [(int) $row['customer_id']]);
            return [$customer ?? throw HttpException::validation(['customer' => 'Customer not found.']), false];
        }
        $v = new Validator($row);
        $phone = $v->phone('mobile');
        $v->validate();
        $existing = CustomerPhone::owner($phone);
        if ($existing !== null) {
            return [Database::one('SELECT * FROM customers WHERE id = ?', [$existing]), false];
        }

        $name = $v->string('name', true, 150, 2);
        $address = $v->string('address', true, 255, 3);
        $city = $v->string('city', true, 100);
        $state = $v->string('state', true, 100);
        $pincode = $v->pattern('pincode', '/^\d{6}$/', 'Must be a 6-digit PIN code.', true, 6);
        $v->validate();
        $branch = trim((string) ($row['branch'] ?? ''));
        $branchId = (int) ($branch === ''
            ? (Database::value("SELECT id FROM branches WHERE code = 'HO'") ?? Database::value('SELECT MIN(id) FROM branches'))
            : Database::value("SELECT id FROM branches WHERE (code = ? OR name = ?) AND status = 'active'", [$branch, $branch]));
        if ($branchId === 0) {
            throw HttpException::validation(['branch' => "No branch named {$branch}."]);
        }
        $agentId = null;
        $agentEmail = strtolower(trim((string) ($row['agent_email'] ?? '')));
        if ($agentEmail !== '') {
            $agentId = Database::value(
                "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                  WHERE u.email = ? AND r.role_key = 'collection_agent' AND u.status = 'active'",
                [$agentEmail],
            ) ?? throw HttpException::validation(['agent_email' => "{$agentEmail} is not an active collection agent."]);
        }
        $id = Database::insert('customers', [
            'customer_number' => Database::nextNumber('customer'),
            'branch_id' => $branchId,
            'full_name' => $name,
            'phone' => $phone,
            'address_line' => $address,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'created_by' => $user->id,
        ]);
        if ($agentId !== null) {
            Database::insert('agent_assignments', ['customer_id' => $id, 'agent_user_id' => $agentId, 'valid_from' => gmdate('Y-m-d'), 'assigned_by' => $user->id]);
        }
        Audit::log($request, $user->id, 'customer.created', 'customer', $id, null, ['source' => 'import', 'phone' => $phone]);
        return [Database::one('SELECT * FROM customers WHERE id = ?', [$id]), true];
    }

    /** Validator errors plus our own, as one 422. */
    private function validate(Validator $v, array $errors): void
    {
        try {
            $v->validate();
        } catch (HttpException $e) {
            $errors += $e->fields;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
    }
}
