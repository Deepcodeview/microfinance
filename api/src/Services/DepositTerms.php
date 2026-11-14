<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Validator;

/**
 * Deposit product terms. Simple interest on the daily balance, accrued to a payable account.
 * Rates and penalties must match what the business is legally authorised to offer.
 */
final class DepositTerms
{
    public const SCHEMES = ['recurring', 'fixed'];
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    /**
     * daily = simple interest at a yearly rate on each day's balance.
     * flat  = the rate is for the whole tenure, on the total deposited (₹50 a day for 6 months at 8%:
     *         9,000 deposited + 720 = 9,720 at maturity).
     */
    public const INTEREST_METHODS = ['daily', 'flat'];
    private const MAX_RATE = ['daily' => '15', 'flat' => '50'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'scheme' => 'recurring',
            'interest_method' => 'daily',
            'annual_interest_rate' => '6.0000',
            'contribution_frequency' => 'daily',
            'min_installment' => '50.0000',
            'min_amount' => '1000.0000',
            'max_amount' => '1000000.0000',
            // Customers / staff pick one of these when the account is opened; it sets the maturity date.
            'tenure_options' => [['value' => 6, 'unit' => 'months'], ['value' => 1, 'unit' => 'years']],
            'premature_withdrawal_allowed' => true,
            // Standard bank practice: premature closure earns the rate minus a penalty (percentage points).
            'premature_penalty_percent' => '1.0000',
            'lock_in_days' => 90,
        ];
    }

    /** @return array<string, list<string>> */
    public static function options(): array
    {
        return ['scheme' => self::SCHEMES, 'contribution_frequency' => self::FREQUENCIES, 'interest_method' => self::INTEREST_METHODS];
    }

    /** @return array<string, mixed> */
    public static function validate(mixed $input): array
    {
        if (!is_array($input)) {
            throw HttpException::validation(['terms' => 'Terms are required.']);
        }
        $v = new Validator($input);
        $method = $v->in('interest_method', self::INTEREST_METHODS, false) ?? 'daily';
        $maxRate = self::MAX_RATE[$method];
        $terms = [
            'scheme' => $v->in('scheme', self::SCHEMES),
            'interest_method' => $method,
            // For flat plans this is the rate for the whole tenure, not per year.
            'annual_interest_rate' => $v->decimal('annual_interest_rate', '0', $maxRate),
            'contribution_frequency' => $v->in('contribution_frequency', self::FREQUENCIES),
            'min_installment' => $v->decimal('min_installment', '1', '1000000'),
            'min_amount' => $v->decimal('min_amount', '1', '100000000'),
            'max_amount' => $v->decimal('max_amount', '1', '100000000'),
            // Older clients send a single tenure_months.
            'tenure_options' => Tenure::parse(
                $input['tenure_options'] ?? (isset($input['tenure_months']) ? [['value' => $input['tenure_months'], 'unit' => 'months']] : null),
                $v, 'tenure_options', true, $maxRate,
            ),
            'premature_withdrawal_allowed' => $v->bool('premature_withdrawal_allowed'),
            'premature_penalty_percent' => $v->decimal('premature_penalty_percent', '0', '5'),
            'lock_in_days' => $v->int('lock_in_days', 0, 3650),
        ];
        if ($terms['min_amount'] !== null && $terms['max_amount'] !== null && Money::cmp($terms['min_amount'], $terms['max_amount']) > 0) {
            $v->addError('max_amount', 'Maximum amount must be at least the minimum amount.');
        }
        if ($terms['scheme'] === 'recurring' && $terms['contribution_frequency'] !== null) {
            foreach ($terms['tenure_options'] ?? [] as $option) {
                if (self::installmentCount($terms['contribution_frequency'], $option) < 2) {
                    $v->addError('tenure_options', Tenure::label($option) . " is too short for {$terms['contribution_frequency']} savings — it must allow at least 2 installments.");
                    break;
                }
            }
        }
        try {
            $v->validate();
        } catch (HttpException $e) {
            $prefixed = [];
            foreach ($e->fields as $field => $message) {
                $prefixed['terms.' . $field] = $message;
            }
            throw HttpException::validation($prefixed);
        }
        return $terms;
    }

    /**
     * Tenure choices of a plan; versions saved before choices existed had one tenure_months.
     * @return list<array{value: int, unit: string}>
     */
    public static function tenureOptions(array $terms): array
    {
        return $terms['tenure_options'] ?? [['value' => (int) $terms['tenure_months'], 'unit' => 'months']];
    }

    /** The chosen tenure (default: the plan's first choice). Throws a field error if the plan does not offer it. */
    public static function chooseTenure(array $terms, ?int $value, ?string $unit): array
    {
        $options = self::tenureOptions($terms);
        if ($value === null || $unit === null) {
            return $options[0];
        }
        return Tenure::find($options, $value, $unit)
            ?? throw HttpException::validation(['tenure' => 'Choose one of the tenures for this plan: ' . Tenure::labels($options) . '.']);
    }

    /** Flat plans pay their rate once on the total deposited; older plans have no method and are daily. */
    public static function isFlat(array $terms): bool
    {
        return ($terms['interest_method'] ?? 'daily') === 'flat';
    }

    /** Contributions a recurring deposit takes over a tenure (one at the start of each period). */
    private static function installmentCount(string $frequency, array $option): int
    {
        $end = Tenure::endDate('2026-01-01', $option);
        if ($frequency === 'monthly') {
            $n = 0;
            while (LoanService::addPeriods('2026-01-01', 'monthly', $n) < $end) {
                $n++;
            }
            return $n;
        }
        $days = (int) (new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($end))->days;
        return intdiv($days, ['daily' => 1, 'weekly' => 7][$frequency]);
    }

    /** Maturity illustration for one tenure choice: total contributed, interest earned and maturity value. */
    public static function preview(array $terms, string $amount, ?array $tenure = null): array
    {
        $tenure ??= self::tenureOptions($terms)[0];
        $rate = bcdiv(Tenure::withRate($terms, $tenure)['annual_interest_rate'], '100', 10);
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable(Tenure::endDate('2026-01-01', $tenure));
        $days = (int) $start->diff($end)->days;

        if (self::isFlat($terms)) {
            $installments = $terms['scheme'] === 'fixed' ? 1 : self::installmentCount($terms['contribution_frequency'], $tenure);
            $principal = bcmul($amount, (string) $installments, 4);
            $interest = Money::round(bcmul($principal, $rate, 10));
        } elseif ($terms['scheme'] === 'fixed') {
            $principal = $amount;
            $interest = Money::round(bcdiv(bcmul(bcmul($amount, $rate, 10), (string) $days, 10), '365', 10));
            $installments = 1;
        } else {
            // Contributions at the start of each period, each earning interest for its remaining days.
            $step = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$terms['contribution_frequency']];
            $installments = self::installmentCount($terms['contribution_frequency'], $tenure);
            $principal = bcmul($amount, (string) $installments, 4);
            $dayProduct = '0';
            for ($k = 0; $k < $installments; $k++) {
                $remaining = $terms['contribution_frequency'] === 'monthly'
                    ? (int) (new \DateTimeImmutable(LoanService::addPeriods('2026-01-01', 'monthly', $k)))->diff($end)->days
                    : $days - $k * $step;
                $dayProduct = bcadd($dayProduct, (string) $remaining);
            }
            $interest = Money::round(bcdiv(bcmul(bcmul($amount, $dayProduct, 10), $rate, 10), '365', 10));
        }

        return [
            'installments' => $installments,
            'total_deposited' => Money::fmt($principal),
            'interest_earned' => $interest,
            'maturity_value' => Money::fmt(Money::add($principal, $interest)),
            'tenure_days' => $days,
            'tenure' => $tenure,
            'annual_interest_rate' => Tenure::withRate($terms, $tenure)['annual_interest_rate'],
        ];
    }
}
