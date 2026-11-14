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
use App\Services\CustomerScope;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\Origination;
use App\Services\Settings;
use App\Services\Tenure;

/**
 * Loan and savings applications customers make in the app. Staff accept them into the normal flow:
 * a loan becomes a loan application awaiting approval (then disbursement); savings opens the account.
 */
final class ApplicationController
{
    private const MAX_OPEN_PER_CUSTOMER = 3;

    private const SELECT = "a.*, c.full_name AS customer_name, c.customer_number, c.phone AS customer_phone, c.status AS customer_status,
        b.name AS branch_name, d.name AS decided_by_name, l.loan_number, l.status AS loan_status, dp.deposit_number,
        COALESCE(lp.name, sp.name) AS product_name";
    private const FROM = "customer_applications a
        JOIN customers c ON c.id = a.customer_id
        JOIN branches b ON b.id = c.branch_id
        LEFT JOIN users d ON d.id = a.decided_by
        LEFT JOIN loans l ON l.id = a.loan_id
        LEFT JOIN deposit_accounts dp ON dp.id = a.deposit_account_id
        LEFT JOIN loan_products lp ON a.type = 'loan' AND lp.id = a.product_id
        LEFT JOIN deposit_products sp ON a.type = 'savings' AND sp.id = a.product_id";

    // ---- Customer (own applications) ---------------------------------------------------------

    public function mine(Request $request): Response
    {
        $customer = $this->ownCustomer();
        return Response::json(Database::all('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE a.customer_id = ? ORDER BY a.id DESC LIMIT 50', [$customer['id']]));
    }

    public function submit(Request $request): Response
    {
        $customer = $this->ownCustomer();
        if (!in_array($customer['status'], ['pending', 'active'], true)) {
            throw HttpException::forbidden('Your account cannot make new applications. Please contact the branch.');
        }
        $v = new Validator($request->all());
        $type = $v->in('type', ['loan', 'savings']);
        $productId = $v->int('product_id', 1, PHP_INT_MAX);
        $amount = $v->decimal('amount', '1', '100000000', 2);
        // Loans: tenure = number of installments. Savings: tenure + tenure_unit = one of the plan's choices.
        $tenure = $v->int('tenure', 1, 3650, $type === 'loan');
        $tenureUnit = $type === 'savings' ? $v->in('tenure_unit', Tenure::UNITS, false) : null;
        $purpose = $v->string('purpose', false, 255);
        $v->validate();

        if ($type === 'loan') {
            Origination::checkLoan($customer, $productId, $amount, (int) $tenure, 'amount');
        } else {
            Settings::requireDeposits();
            $chosen = Origination::checkDeposit($productId, $amount, $tenure, $tenureUnit)['tenure'];
            [$tenure, $tenureUnit] = [$chosen['value'], $chosen['unit']];
        }
        $open = (int) Database::value("SELECT COUNT(*) FROM customer_applications WHERE customer_id = ? AND status = 'submitted'", [$customer['id']]);
        if ($open >= self::MAX_OPEN_PER_CUSTOMER) {
            throw HttpException::conflict('You already have ' . self::MAX_OPEN_PER_CUSTOMER . ' applications waiting. Please wait for a decision, or cancel one.');
        }

        $id = Database::transaction(function () use ($customer, $type, $productId, $amount, $tenure, $tenureUnit, $purpose, $request) {
            $id = Database::insert('customer_applications', [
                'application_number' => Database::nextNumber('application'),
                'customer_id' => $customer['id'],
                'type' => $type,
                'product_id' => $productId,
                'amount' => $amount,
                'tenure' => $tenure,
                'tenure_unit' => $tenureUnit,
                'purpose' => $purpose,
            ]);
            $userId = Auth::user()->id;
            Audit::log($request, $userId, 'application.submitted', 'customer_application', $id, null, compact('type', 'productId', 'amount', 'tenure'));
            $title = $type === 'loan' ? 'New loan application from the app' : 'New savings application from the app';
            $body = "{$customer['full_name']} applied for " . ($type === 'loan' ? 'a loan of' : 'savings of') . ' Rs ' . Money::fmt($amount) . '.';
            $agent = Database::value('SELECT agent_user_id FROM agent_assignments WHERE customer_id = ? AND valid_to IS NULL', [$customer['id']]);
            if ($agent !== null) {
                Notifier::user((int) $agent, 'application.submitted', $title, $body, '/applications');
            }
            Notifier::role('branch_manager', (int) $customer['branch_id'], 'application.submitted', $title, $body, '/applications');
            foreach (['super_admin', 'admin'] as $role) {
                Notifier::role($role, null, 'application.submitted', $title, $body, '/applications', $agent === null ? null : (int) $agent);
            }
            return $id;
        });
        return Response::json($this->find($id), 201);
    }

    public function cancel(Request $request): Response
    {
        $customer = $this->ownCustomer();
        $id = $request->intParam('id');
        Database::transaction(function () use ($customer, $id, $request) {
            $app = Database::one('SELECT * FROM customer_applications WHERE id = ? AND customer_id = ? FOR UPDATE', [$id, $customer['id']])
                ?? throw HttpException::notFound('Application not found.');
            if ($app['status'] !== 'submitted') {
                throw HttpException::conflict('Only an application that is still waiting can be cancelled.');
            }
            Database::query("UPDATE customer_applications SET status = 'cancelled' WHERE id = ?", [$id]);
            Audit::log($request, Auth::user()->id, 'application.cancelled', 'customer_application', $id);
        });
        return Response::json($this->find($id));
    }

    // ---- Staff review ------------------------------------------------------------------------

    public function index(Request $request): Response
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $where = [$scope];
        Paginator::filter($request, 'status', 'a.status', $where, $params, ['submitted', 'accepted', 'rejected', 'cancelled']);
        Paginator::filter($request, 'type', 'a.type', $where, $params, ['loan', 'savings']);
        Paginator::filter($request, 'customer_id', 'a.customer_id', $where, $params);
        return Paginator::run($request, self::SELECT, self::FROM, implode(' AND ', $where), $params, ['created_at' => 'a.id'], 'created_at');
    }

    public function accept(Request $request): Response
    {
        $user = Auth::user();
        $id = $request->intParam('id');
        $v = new Validator($request->all());
        $note = $v->string('note', false, 255);
        $v->validate();

        $result = Database::transaction(function () use ($user, $id, $note, $request) {
            [$app, $customer] = $this->lockForDecision($id);
            if ($customer['status'] !== 'active') {
                throw HttpException::conflict($customer['status'] === 'pending'
                    ? "Approve {$customer['full_name']}'s registration first (Applications → New registrations)."
                    : 'This customer is not active.');
            }
            $amount = Money::fmt($app['amount']);
            if ($app['type'] === 'loan') {
                if (!$user->can('loans.apply')) {
                    throw HttpException::forbidden('You do not have permission to create loan applications.');
                }
                $checked = Origination::checkLoan($customer, (int) $app['product_id'], $amount, (int) $app['tenure'], 'amount');
                $loanId = Origination::createLoan($customer, $checked, $amount, (int) $app['tenure'], $app['purpose'], $user->id, $request);
                $link = ['loan_id' => $loanId];
                $message = 'Your loan application ' . $app['application_number'] . ' was accepted and is now with our approver.';
            } else {
                Settings::requireDeposits();
                if (!$user->can('deposits.open')) {
                    throw HttpException::forbidden('You do not have permission to open savings accounts.');
                }
                $checked = Origination::checkDeposit((int) $app['product_id'], $amount, $app['tenure'] === null ? null : (int) $app['tenure'], $app['tenure_unit']);
                $depositId = Origination::openDeposit($customer, $checked, $amount, $user->id, $request);
                $link = ['deposit_account_id' => $depositId];
                $number = Database::value('SELECT deposit_number FROM deposit_accounts WHERE id = ?', [$depositId]);
                $message = "Your savings account {$number} is open. Start saving with your agent or by UPI.";
            }
            Database::query(
                'UPDATE customer_applications SET status = \'accepted\', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ?, loan_id = ?, deposit_account_id = ? WHERE id = ?',
                [$user->id, $note, $link['loan_id'] ?? null, $link['deposit_account_id'] ?? null, $id],
            );
            Audit::log($request, $user->id, 'application.accepted', 'customer_application', $id, null, $link);
            Notifier::customer((int) $customer['id'], 'application.accepted', 'Application accepted', $message,
                isset($link['loan_id']) ? "/loans/{$link['loan_id']}" : "/deposits/{$link['deposit_account_id']}");
            return $link;
        });
        return Response::json($this->find($id) + $result);
    }

    public function reject(Request $request): Response
    {
        $user = Auth::user();
        $id = $request->intParam('id');
        $v = new Validator($request->all());
        $reason = $v->string('reason', true, 255, 3);
        $v->validate();

        Database::transaction(function () use ($user, $id, $reason, $request) {
            [$app, $customer] = $this->lockForDecision($id);
            Database::query(
                "UPDATE customer_applications SET status = 'rejected', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?",
                [$user->id, $reason, $id],
            );
            Audit::log($request, $user->id, 'application.rejected', 'customer_application', $id, null, null, $reason);
            Notifier::customer((int) $customer['id'], 'application.rejected', 'Application not approved',
                "Your application {$app['application_number']} was not approved: {$reason}");
        });
        return Response::json($this->find($id));
    }

    // ---- Helpers -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function ownCustomer(): array
    {
        return Database::one('SELECT * FROM customers WHERE user_id = ?', [Auth::user()->id])
            ?? throw HttpException::forbidden('Your login is not linked to a customer profile.');
    }

    /** Locks a waiting application whose customer is within the reviewer's scope. Caller holds the transaction. */
    private function lockForDecision(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $app = Database::one(
            "SELECT a.* FROM customer_applications a JOIN customers c ON c.id = a.customer_id WHERE a.id = ? AND {$scope} FOR UPDATE",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Application not found.');
        if ($app['status'] !== 'submitted') {
            throw HttpException::conflict('This application has already been ' . $app['status'] . '.');
        }
        $customer = Database::one('SELECT * FROM customers WHERE id = ?', [$app['customer_id']]);
        return [$app, $customer];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return Database::one('SELECT ' . self::SELECT . ' FROM ' . self::FROM . ' WHERE a.id = ?', [$id]);
    }
}
