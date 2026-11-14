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
use App\Services\Approvals;
use App\Services\Audit;
use App\Services\CustomerLocation;
use App\Services\CustomerScope;
use App\Services\DepositService;
use App\Services\GeoPoint;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\PaymentProof;
use App\Services\PaymentService;
use App\Services\Settings;
use PDOException;

final class PaymentController
{
    /** Every method found in history (for filters); new money is taken only as ACCEPTED. */
    public const METHODS = ['cash', 'upi', 'bank_transfer', 'cheque'];
    public const ACCEPTED = ['cash', 'upi'];

    public function index(Request $request): Response
    {
        [$where, $params] = $this->scope();
        Paginator::filter($request, 'status', 'p.status', $where, $params, ['pending', 'confirmed', 'failed', 'reversed']);
        Paginator::filter($request, 'method', 'p.method', $where, $params, self::METHODS);
        Paginator::filter($request, 'account_type', 'p.account_type', $where, $params, ['loan', 'deposit']);
        Paginator::filter($request, 'collected_by', 'p.collected_by', $where, $params);
        Paginator::filter($request, 'customer_id', 'p.customer_id', $where, $params);
        Paginator::filter($request, 'branch_id', 'p.branch_id', $where, $params);
        foreach (['date_from' => '>=', 'date_to' => '<='] as $param => $op) {
            $date = $request->query[$param] ?? '';
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = "p.business_date {$op} ?";
                $params[] = $date;
            }
        }
        $search = trim((string) ($request->query['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(p.payment_number LIKE ? OR c.full_name LIKE ? OR p.reference LIKE ? OR l.loan_number LIKE ? OR d.deposit_number LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return Paginator::run(
            $request,
            'p.id, p.payment_number, p.amount, p.method, p.purpose, p.status, p.business_date, p.collected_at, p.account_type, p.reference,
             c.full_name AS customer_name, COALESCE(l.loan_number, d.deposit_number) AS account_number,
             u.name AS collected_by_name, r.receipt_number',
            'payments p JOIN customers c ON c.id = p.customer_id JOIN users u ON u.id = p.collected_by
             LEFT JOIN loans l ON l.id = p.loan_id LEFT JOIN deposit_accounts d ON d.id = p.deposit_account_id
             LEFT JOIN receipts r ON r.payment_id = p.id',
            implode(' AND ', $where),
            $params,
            ['collected_at' => 'p.id', 'amount' => 'p.amount'],
            'collected_at',
        );
    }

    public function show(Request $request): Response
    {
        return Response::json($this->load($request->intParam('id')));
    }

    /**
     * Records a payment. Requires an Idempotency-Key header: an exact retry returns the original payment;
     * the same key with a different body is rejected.
     */
    public function store(Request $request): Response
    {
        return $this->record($request, false);
    }

    /**
     * A customer paying their own installment by UPI in the customer app: always UPI, always waits for
     * finance to confirm the UTR / screenshot. Same savings and same-day rules as staff collections.
     */
    public function selfPay(Request $request): Response
    {
        return $this->record($request, true);
    }

    private function record(Request $request, bool $selfService): Response
    {
        $user = Auth::user();
        $key = $request->header('idempotency-key') ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw HttpException::badRequest('An Idempotency-Key header (8–100 letters, digits, - or _) is required.');
        }

        $v = new Validator($request->all());
        $accountType = $v->in('account_type', ['loan', 'deposit']);
        $accountId = $v->int('account_id', 1, PHP_INT_MAX);
        $amount = $v->decimal('amount', '0.01', '100000000', 2);
        $method = $selfService ? 'upi' : $v->in('method', self::ACCEPTED);
        $hasProof = is_string($request->input('proof_image')) && $request->input('proof_image') !== '';
        // UPI: the 12-digit UTR, or a payment screenshot from which finance reads it before confirming.
        $reference = $method === 'upi'
            ? $v->pattern('reference', '/^\d{12}$/', 'Enter the 12-digit UTR / UPI reference number.', !$hasProof, 12)
            : $v->string('reference', $method !== null && $method !== 'cash', 100);
        $upiTr = $v->pattern('upi_tr', '/^[A-Za-z0-9]{6,35}$/', 'Invalid QR reference.', false, 35);
        $purpose = $selfService
            ? ($accountType === 'deposit' ? 'contribution' : 'installment')
            : $v->in('purpose', ['installment', 'settlement', 'contribution'], false) ?? ($accountType === 'deposit' ? 'contribution' : 'installment');
        $notes = $v->string('notes', false, 500);
        // A second loan payment on the same day is usually a duplicate; the collector must say it is not.
        $allowRepeat = $v->bool('allow_repeat', false) ?? false;
        $v->validate();
        // Where the collector was (agent app). Optional unless the owner requires it for field agents.
        $location = $selfService ? null : GeoPoint::fromInput($request->input('location'));
        if ($location === null && !$selfService && Settings::get('require_collection_location') === '1' && in_array('collection_agent', $user->roles, true)) {
            throw HttpException::validation(['location' => 'Turn on location (GPS) for the KBSAVE Agent app — collections must record where they were taken.']);
        }
        $proof = $hasProof ? PaymentProof::decode((string) $request->input('proof_image')) : null;
        if ($proof !== null && $method === 'cash') {
            throw HttpException::validation(['proof_image' => 'Screenshots are only for digital payments.']);
        }
        if (($accountType === 'deposit') !== ($purpose === 'contribution')) {
            throw HttpException::validation(['purpose' => 'Deposits take contributions; loans take installment or settlement payments.']);
        }

        $hash = hash('sha256', json_encode([$accountType, $accountId, $amount, $method, $reference, $purpose, $proof['sha256'] ?? null]));
        if ($existing = $this->replay($key, $hash, $user->id)) {
            return $existing;
        }

        $target = $this->target($accountType, $accountId);
        $date = Settings::today();
        // Cash-risk control: a collector may hold only so much cash before handing it over.
        if ($method === 'cash' && !$selfService) {
            $inHand = (string) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by = ? AND method = 'cash' AND status = 'confirmed' AND handover_id IS NULL", [$user->id]);
            $limit = Settings::get('agent_cash_limit');
            if (Money::cmp(Money::add($inHand, $amount), $limit) > 0) {
                throw HttpException::validation(['amount' => 'This would take your cash in hand to Rs ' . Money::fmt(Money::add($inHand, $amount)) . ', above the Rs ' . Money::fmt($limit) . ' limit. Hand over cash first.']);
            }
        }
        // Cash is in the collector's hand (settled later via handover); digital needs finance confirmation
        // unless the person recording it is allowed to confirm.
        // A UPI payment without its UTR can't be reconciled, so it always waits for finance to read the proof.
        $confirmNow = !$selfService && ($method === 'cash' || ($user->can('payments.confirm') && $reference !== null));
        $storedProof = $proof === null ? null : PaymentProof::store($proof);

        try {
            $id = Database::transaction(function () use ($user, $key, $hash, $target, $accountType, $amount, $method, $reference, $upiTr, $storedProof, $purpose, $notes, $date, $confirmNow, $allowRepeat, $location, $selfService, $request) {
                // Validate now so agents get immediate feedback, even when allocation waits for confirmation.
                // The account row stays locked, so two collectors cannot both take this period's collection.
                if ($accountType === 'loan') {
                    $loan = PaymentService::lockLoan((int) $target['id']);
                    if (!$confirmNow) {
                        \App\Services\LoanService::assertActive($loan);
                        PaymentService::assertLoanAmount($loan, $purpose, $amount, $date);
                    }
                    if (!$allowRepeat && $purpose !== 'settlement') {
                        $earlier = Database::one(
                            "SELECT payment_number, amount FROM payments WHERE loan_id = ? AND business_date = ? AND status IN ('pending','confirmed') ORDER BY id LIMIT 1",
                            [$loan['id'], $date],
                        );
                        if ($earlier !== null) {
                            throw new HttpException(409, 'REPEAT_TODAY', 'Rs ' . Money::fmt($earlier['amount']) . " was already collected for {$loan['loan_number']} today ({$earlier['payment_number']}). Record another payment only if the customer really paid again.");
                        }
                    }
                } else {
                    $account = DepositService::lock((int) $target['id']);
                    PaymentService::assertDepositAmount($account, $amount, $date);
                    $covers = DepositService::assertContribution($account, $amount, $date);
                }
                $id = Database::insert('payments', [
                    'payment_number' => Database::nextNumber('payment'),
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'customer_id' => $target['customer_id'],
                    'branch_id' => $target['branch_id'],
                    'account_type' => $accountType,
                    'loan_id' => $accountType === 'loan' ? $target['id'] : null,
                    'deposit_account_id' => $accountType === 'deposit' ? $target['id'] : null,
                    'purpose' => $purpose,
                    'amount' => $amount,
                    'method' => $method,
                    'reference' => $reference,
                    'upi_tr' => $upiTr,
                    'proof_stored_name' => $storedProof['stored_name'] ?? null,
                    'proof_mime' => $storedProof['mime'] ?? null,
                    'proof_sha256' => $storedProof['sha256'] ?? null,
                    'status' => 'pending',
                    'business_date' => $date,
                    'collected_by' => $user->id,
                    'notes' => $notes,
                ] + ($covers ?? []) + ($location ?? []));
                if ($confirmNow) {
                    PaymentService::confirm(Database::one('SELECT * FROM payments WHERE id = ?', [$id]), $user->id);
                }
                if ($location !== null) {
                    CustomerLocation::pinIfMissing((int) $target['customer_id'], $location, $user->id);
                }
                Audit::log($request, $user->id, $selfService ? 'payment.self_paid' : 'payment.recorded', 'payment', $id, null,
                    compact('accountType', 'amount', 'method', 'reference', 'purpose') + ['account_id' => (int) $target['id'], 'confirmed' => $confirmNow, 'located' => $location !== null]);
                if ($selfService) {
                    Notifier::role('finance', null, 'payment.self_paid', 'Customer UPI payment to confirm',
                        'Rs ' . Money::fmt($amount) . ' paid from the customer app' . ($reference ? " (UTR {$reference})" : ' (screenshot)') . '.', '/payments?status=pending');
                }
                return $id;
            });
        } catch (\Throwable $e) {
            if ($storedProof !== null) {
                PaymentProof::delete($storedProof['stored_name']);
            }
            if (!$e instanceof PDOException || $e->getCode() !== '23000') {
                throw $e;
            }
            // Concurrent retry won the race on the idempotency key, or a duplicate provider reference.
            if ($existing = $this->replay($key, $hash, $user->id)) {
                return $existing;
            }
            throw HttpException::validation(['reference' => 'This UTR / transaction reference has already been recorded.']);
        }

        return Response::json($this->load($id), 201);
    }

    public function confirm(Request $request): Response
    {
        $user = Auth::user();
        $this->load($request->intParam('id'));
        $v = new Validator($request->all());
        $reference = $v->string('reference', false, 100);
        $v->validate();

        Database::transaction(function () use ($request, $user, $reference) {
            $payment = $this->lock($request->intParam('id'));
            if ($payment['method'] === 'upi' && $reference !== null && !preg_match('/^\d{12}$/', $reference)) {
                throw HttpException::validation(['reference' => 'Enter the 12-digit UTR.']);
            }
            if ($payment['method'] === 'upi' && $reference === null && $payment['reference'] === null) {
                throw HttpException::validation(['reference' => 'Read the 12-digit UTR from the screenshot and enter it to confirm.']);
            }
            if ($reference !== null && $reference !== $payment['reference']) {
                Database::query('UPDATE payments SET reference = ? WHERE id = ?', [$reference, $payment['id']]);
                $payment['reference'] = $reference;
            }
            PaymentService::confirm($payment, $user->id);
            Audit::log($request, $user->id, 'payment.confirmed', 'payment', (int) $payment['id'], ['status' => 'pending'], ['status' => 'confirmed']);
        });
        return $this->show($request);
    }

    public function fail(Request $request): Response
    {
        $user = Auth::user();
        $this->load($request->intParam('id'));
        $v = new Validator($request->all());
        $reason = $v->string('reason', true, 500, 3);
        $v->validate();

        Database::transaction(function () use ($request, $user, $reason) {
            $payment = $this->lock($request->intParam('id'));
            if ($payment['status'] !== 'pending') {
                throw HttpException::conflict('Only pending payments can be marked failed.');
            }
            Database::query("UPDATE payments SET status = 'failed', status_reason = ?, confirmed_by = ?, confirmed_at = UTC_TIMESTAMP() WHERE id = ?",
                [$reason, $user->id, $payment['id']]);
            Audit::log($request, $user->id, 'payment.failed', 'payment', (int) $payment['id'], ['status' => 'pending'], ['status' => 'failed'], $reason);
        });
        return $this->show($request);
    }

    /**
     * Requests a reversal. Nothing changes until a second authorised person approves it in the approvals
     * inbox; the reversal then posts a linked mirror entry and restores balances (never deletes).
     */
    public function reverse(Request $request): Response
    {
        $user = Auth::user();
        $loaded = $this->load($request->intParam('id'));
        $v = new Validator($request->all());
        $reason = $v->string('reason', true, 500, 5);
        $v->validate();

        $approvalId = Database::transaction(function () use ($request, $user, $reason, $loaded) {
            $payment = $this->lock($request->intParam('id'));
            PaymentService::assertReversible($payment);
            $id = Approvals::request($user, 'payment_reversal', 'payment', (int) $payment['id'], (int) $payment['branch_id'], $payment['amount'],
                "Reverse {$payment['payment_number']} ({$loaded['customer_name']}, Rs " . Money::fmt($payment['amount']) . ')', [], $reason);
            Audit::log($request, $user->id, 'payment.reversal_requested', 'payment', (int) $payment['id'], null, ['approval_request_id' => $id], $reason);
            return $id;
        });
        return new Response(202, ['data' => $this->load($request->intParam('id')) + ['approval_request_id' => $approvalId]]);
    }

    /**
     * UPI QR for a collection: a upi://pay link with the organisation's UPI ID, payee name, exact amount
     * and a unique transaction reference. The app renders it as a QR the customer scans.
     */
    public function upiIntent(Request $request): Response
    {
        $v = new Validator($request->all());
        $accountType = $v->in('account_type', ['loan', 'deposit']);
        $accountId = $v->int('account_id', 1, PHP_INT_MAX);
        $amount = $v->decimal('amount', '1', '100000', 2);
        $v->validate();
        $vpa = Settings::get('upi_vpa');
        if ($vpa === '') {
            throw new HttpException(409, 'UPI_NOT_CONFIGURED', 'UPI collection is not set up. An admin must enter the UPI ID in Settings.');
        }
        $target = $this->target($accountType, $accountId);
        if ($accountType === 'deposit') {
            // Same once-per-period rule as recording, so nobody is shown a QR for a payment that would be refused.
            $account = Database::one('SELECT * FROM deposit_accounts WHERE id = ?', [$target['id']]);
            PaymentService::assertDepositAmount($account, $amount, Settings::today());
            DepositService::assertContribution($account, $amount, Settings::today());
        }
        $number = (string) Database::value($accountType === 'loan' ? 'SELECT loan_number FROM loans WHERE id = ?' : 'SELECT deposit_number FROM deposit_accounts WHERE id = ?', [$target['id']]);
        $tr = 'FS' . strtoupper(bin2hex(random_bytes(6)));
        $payee = Settings::get('upi_payee_name') ?: Settings::get('company_name');
        $note = mb_substr("{$number} payment", 0, 50);
        $uri = 'upi://pay?' . http_build_query([
            'pa' => $vpa, 'pn' => $payee, 'am' => $amount, 'cu' => 'INR', 'tn' => $note, 'tr' => $tr,
        ], '', '&', PHP_QUERY_RFC3986);
        return Response::json(['uri' => $uri, 'vpa' => $vpa, 'payee' => $payee, 'amount' => $amount, 'tr' => $tr, 'note' => $note, 'account_number' => $number]);
    }

    /** Payment screenshot, for whoever can see the payment. Views are audited. */
    public function proof(Request $request): Response
    {
        $payment = $this->load($request->intParam('id'));
        $row = Database::one('SELECT proof_stored_name, proof_mime, proof_sha256 FROM payments WHERE id = ?', [$payment['id']]);
        if ($row['proof_stored_name'] === null) {
            throw HttpException::notFound('No proof attached.');
        }
        $path = PaymentProof::path($row['proof_stored_name']);
        if (!is_file($path) || hash_file('sha256', $path) !== $row['proof_sha256']) {
            throw HttpException::notFound('The stored proof is missing or has been altered.');
        }
        Audit::log($request, Auth::user()->id, 'payment.proof_viewed', 'payment', (int) $payment['id']);
        return Response::file($path, $row['proof_mime'], "proof-{$payment['payment_number']}." . (PaymentProof::EXT[$row['proof_mime']] ?? 'img'));
    }

    /** Public receipt check: confirms a receipt is genuine without exposing personal data. */
    public function verifyReceipt(Request $request): Response
    {
        $code = strtoupper($request->param('code'));
        $row = Database::one(
            'SELECT r.receipt_number, r.issued_at, p.amount, p.status, p.business_date, c.full_name
               FROM receipts r JOIN payments p ON p.id = r.payment_id JOIN customers c ON c.id = p.customer_id
              WHERE r.verification_code = ?',
            [$code],
        ) ?? throw HttpException::notFound('No receipt matches this code.');

        $parts = explode(' ', $row['full_name']);
        $row['customer'] = $parts[0] . (count($parts) > 1 ? ' ' . mb_substr(end($parts), 0, 1) . '.' : '');
        unset($row['full_name']);
        return Response::json($row);
    }

    private function replay(string $key, string $hash, int $userId): ?Response
    {
        $existing = Database::one('SELECT id, request_hash, collected_by FROM payments WHERE idempotency_key = ?', [$key]);
        if ($existing === null) {
            return null;
        }
        if ($existing['request_hash'] !== $hash || (int) $existing['collected_by'] !== $userId) {
            throw HttpException::conflict('This Idempotency-Key was already used for a different payment.');
        }
        return new Response(200, ['data' => $this->load((int) $existing['id']), 'meta' => ['idempotent_replay' => true]]);
    }

    private function target(string $type, int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        if ($type === 'loan') {
            $row = Database::one("SELECT l.id, l.customer_id, l.branch_id, l.status FROM loans l JOIN customers c ON c.id = l.customer_id WHERE l.id = ? AND {$scope}",
                array_merge([$id], $params));
        } else {
            Settings::requireDeposits();
            $row = Database::one("SELECT d.id, d.customer_id, d.branch_id, d.status FROM deposit_accounts d JOIN customers c ON c.id = d.customer_id WHERE d.id = ? AND {$scope}",
                array_merge([$id], $params));
        }
        return $row ?? throw HttpException::validation(['account_id' => 'Account not found.']);
    }

    /** @return array{0: list<string>, 1: list<mixed>} Customer scope, plus agents always see what they collected. */
    private function scope(): array
    {
        $user = Auth::user();
        [$scope, $params] = CustomerScope::where($user);
        if ($user->customerScope() === 'assigned') {
            return [["({$scope} OR p.collected_by = ?)"], array_merge($params, [$user->id])];
        }
        return [[$scope], $params];
    }

    private function load(int $id): array
    {
        [$where, $params] = $this->scope();
        $payment = Database::one(
            'SELECT p.*, c.full_name AS customer_name, c.customer_number, c.phone AS customer_phone, b.name AS branch_name,
                    COALESCE(l.loan_number, d.deposit_number) AS account_number,
                    u.name AS collected_by_name, uc.name AS confirmed_by_name, ur.name AS reversed_by_name,
                    r.receipt_number, r.verification_code, r.issued_at AS receipt_issued_at
               FROM payments p
               JOIN customers c ON c.id = p.customer_id
               JOIN branches b ON b.id = p.branch_id
               JOIN users u ON u.id = p.collected_by
               LEFT JOIN users uc ON uc.id = p.confirmed_by
               LEFT JOIN users ur ON ur.id = p.reversed_by
               LEFT JOIN loans l ON l.id = p.loan_id
               LEFT JOIN deposit_accounts d ON d.id = p.deposit_account_id
               LEFT JOIN receipts r ON r.payment_id = p.id
              WHERE p.id = ? AND ' . implode(' AND ', $where),
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Payment not found.');

        $payment['has_proof'] = $payment['proof_stored_name'] !== null;
        unset($payment['idempotency_key'], $payment['request_hash'], $payment['proof_stored_name'], $payment['proof_sha256']);
        $payment['allocations'] = Database::all(
            'SELECT a.component, a.amount, i.number AS installment_number, i.due_date
               FROM payment_allocations a LEFT JOIN loan_installments i ON i.id = a.installment_id
              WHERE a.payment_id = ? ORDER BY a.id',
            [$id],
        );
        return $payment;
    }

    private function lock(int $id): array
    {
        return Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Payment not found.');
    }
}
