<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Creating loan applications and opening deposit accounts, shared by staff screens and by staff
 * accepting a customer's own application, so both paths run exactly the same product and credit checks.
 */
final class Origination
{
    /**
     * Product limits, NPA block and per-customer exposure cap for a new loan.
     * @return array{product: array<string, mixed>, terms: array<string, mixed>, preview: array<string, string>}
     */
    public static function checkLoan(array $customer, int $productId, string $principal, int $tenure, string $principalField = 'principal'): array
    {
        $product = Database::one('SELECT id, current_version_id, status FROM loan_products WHERE id = ?', [$productId]);
        if ($product === null || $product['status'] !== 'active') {
            throw HttpException::validation(['product_id' => 'Loan product not found or inactive.']);
        }
        $terms = json_decode((string) Database::value('SELECT terms_json FROM loan_product_versions WHERE id = ?', [$product['current_version_id']]), true);
        $errors = [];
        if (Money::cmp($principal, $terms['min_amount']) < 0 || Money::cmp($principal, $terms['max_amount']) > 0) {
            $errors[$principalField] = 'Amount must be between ' . Money::fmt($terms['min_amount']) . ' and ' . Money::fmt($terms['max_amount']) . '.';
        }
        if (($tenureError = LoanTerms::tenureError($terms, $tenure)) !== null) {
            $errors['tenure'] = $tenureError;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        // Credit discipline: no new lending to a customer with a bad loan, and a cap on total exposure.
        $bad = Database::value("SELECT loan_number FROM loans WHERE customer_id = ? AND (status = 'written_off' OR (status = 'active' AND asset_class IN ('substandard','doubtful','loss'))) LIMIT 1", [$customer['id']]);
        if ($bad !== null) {
            throw HttpException::validation(['customer_id' => "Customer has a non-performing or written-off loan ({$bad}). New loans are blocked until it is regularised."]);
        }
        $exposure = (string) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN l.status IN ('pending_approval','approved') THEN l.principal
                                      ELSE (SELECT COALESCE(SUM(i.principal_due - i.principal_paid), 0) FROM loan_installments i WHERE i.loan_id = l.id) END), 0)
               FROM loans l WHERE l.customer_id = ? AND l.status IN ('pending_approval','approved','active')",
            [$customer['id']],
        );
        $cap = Settings::get('max_customer_exposure');
        if (Money::cmp(Money::add($exposure, $principal), $cap) > 0) {
            throw HttpException::validation([$principalField => 'Total exposure would be Rs ' . Money::fmt(Money::add($exposure, $principal)) . ', above the Rs ' . Money::fmt($cap) . ' per-customer limit (current Rs ' . Money::fmt($exposure) . ').']);
        }

        return ['product' => $product, 'terms' => $terms, 'preview' => LoanTerms::preview($terms, $principal, $tenure)];
    }

    /** Inserts a loan in pending_approval (the normal maker-checker flow follows). Caller holds the transaction. */
    public static function createLoan(array $customer, array $checked, string $principal, int $tenure, ?string $purpose, int $appliedBy, Request $request): int
    {
        $preview = $checked['preview'];
        $id = Database::insert('loans', [
            'loan_number' => Database::nextNumber('loan'),
            'customer_id' => $customer['id'],
            'branch_id' => $customer['branch_id'],
            'product_id' => $checked['product']['id'],
            'product_version_id' => $checked['product']['current_version_id'],
            'principal' => $principal,
            'tenure' => $tenure,
            'installment_amount' => $preview['installment_amount'],
            'total_interest' => $preview['total_interest'],
            'processing_fee' => $preview['processing_fee'],
            'tax_on_fee' => $preview['tax_on_processing_fee'],
            'net_disbursement' => $preview['net_disbursement'],
            'purpose' => $purpose,
            'applied_by' => $appliedBy,
        ]);
        Audit::log($request, $appliedBy, 'loan.applied', 'loan', $id, null, compact('principal', 'tenure') + ['customer_id' => (int) $customer['id']]);
        Notifier::role('branch_manager', (int) $customer['branch_id'], 'loan.pending_approval', 'Loan awaiting approval',
            "{$customer['full_name']} applied for Rs " . Money::fmt($principal) . '.', "/loans/{$id}", $appliedBy);
        return $id;
    }

    /**
     * Product, amount and tenure rules for a new deposit account. No tenure given = the plan's first choice.
     * @return array{product: array<string, mixed>, terms: array<string, mixed>, tenure: array{value: int, unit: string}}
     */
    public static function checkDeposit(int $productId, string $amount, ?int $tenureValue = null, ?string $tenureUnit = null): array
    {
        $product = Database::one('SELECT id, status, current_version_id FROM deposit_products WHERE id = ?', [$productId]);
        if ($product === null || $product['status'] !== 'active') {
            throw HttpException::validation(['product_id' => 'Deposit product not found or inactive.']);
        }
        $terms = json_decode((string) Database::value('SELECT terms_json FROM deposit_product_versions WHERE id = ?', [$product['current_version_id']]), true);
        if ($terms['scheme'] === 'recurring' && Money::cmp($amount, $terms['min_installment']) < 0) {
            throw HttpException::validation(['amount' => 'Installment must be at least ' . Money::fmt($terms['min_installment']) . '.']);
        }
        if ($terms['scheme'] === 'fixed' && (Money::cmp($amount, $terms['min_amount']) < 0 || Money::cmp($amount, $terms['max_amount']) > 0)) {
            throw HttpException::validation(['amount' => 'Deposit must be between ' . Money::fmt($terms['min_amount']) . ' and ' . Money::fmt($terms['max_amount']) . '.']);
        }
        return ['product' => $product, 'terms' => $terms, 'tenure' => DepositTerms::chooseTenure($terms, $tenureValue, $tenureUnit)];
    }

    /** Opens an active deposit account. Caller holds the transaction. */
    public static function openDeposit(array $customer, array $checked, string $amount, int $openedBy, Request $request): int
    {
        ['product' => $product, 'terms' => $terms, 'tenure' => $tenure] = $checked;
        $today = Settings::today();
        $id = Database::insert('deposit_accounts', [
            'deposit_number' => Database::nextNumber('deposit'),
            'customer_id' => $customer['id'],
            'branch_id' => $customer['branch_id'],
            'product_id' => $product['id'],
            'product_version_id' => $product['current_version_id'],
            'scheme' => $terms['scheme'],
            'installment_amount' => $terms['scheme'] === 'recurring' ? $amount : null,
            'deposit_amount' => $terms['scheme'] === 'fixed' ? $amount : null,
            'opened_on' => $today,
            'maturity_date' => Tenure::endDate($today, $tenure),
            'tenure_value' => $tenure['value'],
            'tenure_unit' => $tenure['unit'],
            'last_accrual_date' => (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'),
            'opened_by' => $openedBy,
        ]);
        Audit::log($request, $openedBy, 'deposit.opened', 'deposit_account', $id, null, ['customer_id' => (int) $customer['id'], 'amount' => $amount, 'scheme' => $terms['scheme'], 'tenure' => Tenure::label($tenure)]);
        return $id;
    }
}
