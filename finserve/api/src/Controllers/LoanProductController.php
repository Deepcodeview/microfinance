<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LoanTerms;

final class LoanProductController extends ProductController
{
    protected function table(): string
    {
        return 'loan_products';
    }

    protected function accountsTable(): string
    {
        return 'loans';
    }

    protected function versionTable(): string
    {
        return 'loan_product_versions';
    }

    protected function entity(): string
    {
        return 'loan_product';
    }

    protected function validateTerms(mixed $input): array
    {
        return LoanTerms::validate($input);
    }

    protected function defaultTerms(): array
    {
        return LoanTerms::defaults();
    }

    protected function termOptions(): array
    {
        return [
            'interest_method' => LoanTerms::METHODS,
            'repayment_frequency' => LoanTerms::FREQUENCIES,
            'late_fee_type' => LoanTerms::LATE_FEE_TYPES,
            'installment_rounding' => LoanTerms::ROUNDING,
            'allocation_components' => LoanTerms::ALLOCATION_COMPONENTS,
        ];
    }

    /** Lets the admin see the effect of terms before saving them. Nothing is stored. */
    public function preview(Request $request): Response
    {
        $terms = LoanTerms::validate($request->input('terms'));
        $v = new Validator($request->all());
        $principal = $v->decimal('principal', '1', '100000000', 2);
        $tenure = $v->int('tenure', 1, 1000);
        $v->validate();

        if (bccomp($principal, $terms['min_amount'], 4) < 0 || bccomp($principal, $terms['max_amount'], 4) > 0) {
            throw HttpException::validation(['principal' => 'Amount is outside the product minimum/maximum.']);
        }
        if (($tenureError = LoanTerms::tenureError($terms, $tenure)) !== null) {
            throw HttpException::validation(['tenure' => $tenureError]);
        }

        return Response::json(LoanTerms::preview($terms, $principal, $tenure));
    }
}
