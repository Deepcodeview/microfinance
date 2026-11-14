<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;

/**
 * Loan product terms that the admin configures from the panel.
 * Defaults follow common Indian bank/NBFC practice; the business must confirm them before go-live.
 */
final class LoanTerms
{
    public const METHODS = ['reducing_balance', 'flat'];
    public const FREQUENCIES = ['daily', 'weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly'];
    public const LATE_FEE_TYPES = ['fixed', 'percent_of_overdue'];
    public const ROUNDING = ['paise', 'rupee'];
    public const ALLOCATION_COMPONENTS = ['late_fee', 'fees', 'interest', 'principal'];
    public const HOLIDAY_RULES = ['next_business_day', 'none'];

    public const PERIODS_PER_YEAR = ['daily' => 365, 'weekly' => 52, 'fortnightly' => 26, 'monthly' => 12, 'quarterly' => 4, 'yearly' => 1];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'interest_method' => 'reducing_balance',
            'annual_interest_rate' => '18.0000',
            'repayment_frequency' => 'monthly',
            'min_amount' => '10000.0000',
            'max_amount' => '500000.0000',
            'min_tenure' => 6,
            'max_tenure' => 60,
            // Optional fixed choices ({value, unit, installments}); empty = any tenure from min to max.
            'tenure_options' => [],
            'processing_fee_percent' => '2.0000',
            'tax_on_fees_percent' => '18.0000',
            'late_fee_type' => 'fixed',
            'late_fee_value' => '500.0000',
            'grace_period_days' => 5,
            'prepayment_allowed' => true,
            'prepayment_charge_percent' => '0.0000',
            // Standard bank appropriation: charges first, then interest, then principal.
            'allocation_order' => ['late_fee', 'fees', 'interest', 'principal'],
            'installment_rounding' => 'rupee',
            // Due dates falling on a weekly off-day or holiday move to the next business day.
            'holiday_adjustment' => 'next_business_day',
        ];
    }

    /**
     * Validates a full terms object. Field errors are prefixed with "terms." for the UI.
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function validate(mixed $input): array
    {
        if (!is_array($input)) {
            throw \App\Core\HttpException::validation(['terms' => 'Terms are required.']);
        }

        $v = new Validator($input);
        $frequency = $v->in('repayment_frequency', self::FREQUENCIES);
        $options = Tenure::parse($input['tenure_options'] ?? [], $v, 'tenure_options', false, '60');
        $withOptions = $options !== null && $options !== [];
        if ($withOptions && $frequency !== null) {
            $options = self::withInstallments($options, $frequency, $v);
        }
        $terms = [
            'interest_method' => $v->in('interest_method', self::METHODS),
            'annual_interest_rate' => $v->decimal('annual_interest_rate', '0', '60'),
            'repayment_frequency' => $frequency,
            'min_amount' => $v->decimal('min_amount', '1', '100000000'),
            'max_amount' => $v->decimal('max_amount', '1', '100000000'),
            // With tenure choices the range is simply the shortest and longest choice.
            'min_tenure' => $withOptions && $options !== null ? $options[0]['installments'] : $v->int('min_tenure', 1, 1000),
            'max_tenure' => $withOptions && $options !== null ? $options[count($options) - 1]['installments'] : $v->int('max_tenure', 1, 1000),
            'tenure_options' => $options ?? [],
            'processing_fee_percent' => $v->decimal('processing_fee_percent', '0', '10'),
            'tax_on_fees_percent' => $v->decimal('tax_on_fees_percent', '0', '50'),
            'late_fee_type' => $v->in('late_fee_type', self::LATE_FEE_TYPES),
            'late_fee_value' => $v->decimal('late_fee_value', '0', '100000'),
            'grace_period_days' => $v->int('grace_period_days', 0, 90),
            'prepayment_allowed' => $v->bool('prepayment_allowed'),
            'prepayment_charge_percent' => $v->decimal('prepayment_charge_percent', '0', '10'),
            'allocation_order' => $input['allocation_order'] ?? null,
            'installment_rounding' => $v->in('installment_rounding', self::ROUNDING),
            'holiday_adjustment' => $v->in('holiday_adjustment', self::HOLIDAY_RULES, false) ?? 'none',
        ];

        if ($terms['min_amount'] !== null && $terms['max_amount'] !== null
            && bccomp($terms['min_amount'], $terms['max_amount'], 4) > 0) {
            $v->addError('max_amount', 'Maximum amount must be at least the minimum amount.');
        }
        if ($terms['min_tenure'] !== null && $terms['max_tenure'] !== null && $terms['min_tenure'] > $terms['max_tenure']) {
            $v->addError('max_tenure', 'Maximum tenure must be at least the minimum tenure.');
        }
        if ($terms['late_fee_type'] === 'percent_of_overdue' && $terms['late_fee_value'] !== null
            && bccomp($terms['late_fee_value'], '100', 4) > 0) {
            $v->addError('late_fee_value', 'A percentage late fee cannot exceed 100.');
        }

        $order = $terms['allocation_order'];
        $expected = self::ALLOCATION_COMPONENTS;
        if (!is_array($order) || !array_is_list($order) || count($order) !== count($expected)
            || array_diff($expected, $order) !== []) {
            $v->addError('allocation_order', 'Must list each of late_fee, fees, interest and principal exactly once.');
        }

        try {
            $v->validate();
        } catch (\App\Core\HttpException $e) {
            $prefixed = [];
            foreach ($e->fields as $field => $message) {
                $prefixed['terms.' . $field] = $message;
            }
            throw \App\Core\HttpException::validation($prefixed);
        }

        return $terms;
    }

    /**
     * Adds the installment count to each tenure choice; drops duplicates that mean the same count.
     *
     * @param list<array{value: int, unit: string}> $options
     * @return list<array{value: int, unit: string, installments: int}>|null
     */
    private static function withInstallments(array $options, string $frequency, Validator $v): ?array
    {
        $out = [];
        foreach ($options as $option) {
            $n = Tenure::installments($frequency, $option);
            if ($n === null) {
                $v->addError('tenure_options', Tenure::label($option) . " does not fit a {$frequency} loan — use a tenure that is a whole number of installments.");
                return null;
            }
            if ($n > 1000) {
                $v->addError('tenure_options', Tenure::label($option) . " is {$n} installments; the maximum is 1000.");
                return null;
            }
            $out[$n] ??= $option + ['installments' => $n];
        }
        ksort($out);
        return array_values($out);
    }

    /** Terms as they apply to a loan of $tenure installments: a tenure choice with its own rate overrides the plan rate. */
    public static function forTenure(array $terms, int $tenure): array
    {
        foreach ($terms['tenure_options'] ?? [] as $option) {
            if ((int) ($option['installments'] ?? 0) === $tenure) {
                return Tenure::withRate($terms, $option);
            }
        }
        return $terms;
    }

    /** Why this installment count is not allowed by the terms, or null if it is. */
    public static function tenureError(array $terms, int $tenure): ?string
    {
        $options = $terms['tenure_options'] ?? [];
        if ($options !== []) {
            return in_array($tenure, array_column($options, 'installments'), true)
                ? null
                : 'Choose one of the tenures for this plan: ' . Tenure::labels($options) . '.';
        }
        return $tenure < $terms['min_tenure'] || $tenure > $terms['max_tenure']
            ? "Tenure must be between {$terms['min_tenure']} and {$terms['max_tenure']}."
            : null;
    }

    /**
     * Repayment preview for given terms. All arithmetic is bcmath on decimal strings.
     *
     * @param array<string, mixed> $terms Validated terms
     * @return array<string, mixed>
     */
    public static function preview(array $terms, string $principal, int $tenure): array
    {
        $terms = self::forTenure($terms, $tenure);
        $periodsPerYear = (string) self::PERIODS_PER_YEAR[$terms['repayment_frequency']];
        $periodRate = bcdiv(bcdiv($terms['annual_interest_rate'], '100'), $periodsPerYear);
        $roundTo = $terms['installment_rounding'] === 'rupee' ? 0 : 2;
        $n = (string) $tenure;

        if ($terms['interest_method'] === 'flat') {
            $totalInterest = self::round(bcmul(bcmul($principal, bcdiv($terms['annual_interest_rate'], '100')), bcdiv($n, $periodsPerYear)), 2);
            $installment = self::round(bcdiv(bcadd($principal, $totalInterest), $n), $roundTo);
            $interestPerPeriod = self::round(bcdiv($totalInterest, $n), 2);
        } elseif (bccomp($periodRate, '0') === 0) {
            $installment = self::round(bcdiv($principal, $n), $roundTo);
        } else {
            // EMI = P * r * (1+r)^n / ((1+r)^n - 1)
            $growth = bcpow(bcadd('1', $periodRate), $n);
            $installment = self::round(bcdiv(bcmul(bcmul($principal, $periodRate), $growth), bcsub($growth, '1')), $roundTo);
        }

        $schedule = [];
        $balance = $principal;
        $interestPaid = '0';
        $interestLeft = $totalInterest ?? null;
        for ($i = 1; $i <= $tenure; $i++) {
            $interest = match (true) {
                $terms['interest_method'] === 'flat' => $i === $tenure ? $interestLeft : $interestPerPeriod,
                default => self::round(bcmul($balance, $periodRate), 2),
            };
            $principalPart = bcsub($installment, $interest, 2);
            // Last installment clears whatever rounding left behind.
            if ($i === $tenure || bccomp($principalPart, $balance, 2) > 0) {
                $principalPart = $balance;
            }
            if (bccomp($principalPart, '0', 2) < 0) {
                $principalPart = '0.00';
            }
            $balance = bcsub($balance, $principalPart, 2);
            $interestPaid = bcadd($interestPaid, $interest, 2);
            if ($interestLeft !== null) {
                $interestLeft = bcsub($interestLeft, $interest, 2);
            }
            $schedule[] = [
                'number' => $i,
                'installment' => bcadd($principalPart, $interest, 2),
                'principal' => bcadd($principalPart, '0', 2),
                'interest' => bcadd($interest, '0', 2),
                'balance' => $balance,
            ];
            if (bccomp($balance, '0', 2) === 0 && $i < $tenure) {
                break;
            }
        }

        $processingFee = self::round(bcmul($principal, bcdiv($terms['processing_fee_percent'], '100')), 2);
        $taxOnFee = self::round(bcmul($processingFee, bcdiv($terms['tax_on_fees_percent'], '100')), 2);

        return [
            'principal' => bcadd($principal, '0', 2),
            'tenure' => $tenure,
            // The rate actually used (a tenure choice may have its own).
            'annual_interest_rate' => $terms['annual_interest_rate'],
            'installment_amount' => bcadd($installment, '0', 2),
            'total_interest' => $interestPaid,
            'total_payable' => bcadd($principal, $interestPaid, 2),
            'processing_fee' => $processingFee,
            'tax_on_processing_fee' => $taxOnFee,
            'net_disbursement' => bcsub(bcsub($principal, $processingFee, 2), $taxOnFee, 2),
            'schedule' => $schedule,
        ];
    }

    /** Half-up rounding for non-negative decimal strings. */
    public static function round(string $value, int $places): string
    {
        $half = '0.' . str_repeat('0', $places) . '5';
        return bcadd($value, $half, $places);
    }
}
