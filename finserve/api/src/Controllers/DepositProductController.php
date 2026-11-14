<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\DepositTerms;
use App\Services\Tenure;

final class DepositProductController extends ProductController
{
    protected function table(): string
    {
        return 'deposit_products';
    }

    protected function accountsTable(): string
    {
        return 'deposit_accounts';
    }

    protected function versionTable(): string
    {
        return 'deposit_product_versions';
    }

    protected function entity(): string
    {
        return 'deposit_product';
    }

    protected function validateTerms(mixed $input): array
    {
        return DepositTerms::validate($input);
    }

    protected function defaultTerms(): array
    {
        return DepositTerms::defaults();
    }

    protected function termOptions(): array
    {
        return DepositTerms::options();
    }

    public function preview(Request $request): Response
    {
        $terms = DepositTerms::validate($request->input('terms'));
        $v = new Validator($request->all());
        $amount = $v->decimal('amount', '1', '100000000', 2);
        $tenureValue = $v->int('tenure_value', 1, 3650, false);
        $tenureUnit = $v->in('tenure_unit', Tenure::UNITS, false);
        $v->validate();
        return Response::json(DepositTerms::preview($terms, $amount, DepositTerms::chooseTenure($terms, $tenureValue, $tenureUnit)));
    }
}
