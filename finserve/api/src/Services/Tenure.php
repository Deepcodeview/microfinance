<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use DateTimeImmutable;

/**
 * Tenure choices a plan offers ("45 days", "3 months", "1 year"). Admins list them per product; staff and
 * customers pick one. Loans convert a choice into a number of installments of the repayment frequency.
 */
final class Tenure
{
    public const UNITS = ['days', 'weeks', 'months', 'years'];
    private const MAX = ['days' => 3650, 'weeks' => 520, 'months' => 120, 'years' => 10];
    private const MAX_OPTIONS = 12;

    /**
     * Installments per unit for each repayment frequency: an int factor, or [numerator, denominator] for
     * conversions that must come out whole (60 days = 2 fortnights...), or 'round' for calendar months
     * of weekly / fortnightly loans (3 months = 13 weeks). Missing = not offered for that frequency.
     */
    private const LOAN_FACTORS = [
        'daily' => ['days' => 1, 'weeks' => 7, 'months' => 30, 'years' => 365],
        'weekly' => ['days' => [1, 7], 'weeks' => 1, 'months' => ['round', 52, 12], 'years' => 52],
        'fortnightly' => ['days' => [1, 14], 'weeks' => [1, 2], 'months' => ['round', 26, 12], 'years' => 26],
        'monthly' => ['months' => 1, 'years' => 12],
        'quarterly' => ['months' => [1, 3], 'years' => 4],
        'yearly' => ['months' => [1, 12], 'years' => 1],
    ];

    /**
     * Validates a list of {value, unit, rate?}; errors go to $field on $v. Returns the list sorted shortest
     * first, without duplicates, or null if invalid. $required = at least one choice. `rate` = an optional
     * annual interest rate (%) for that tenure, up to $maxRate; without it the plan's own rate applies.
     *
     * @return list<array{value: int, unit: string, rate?: string}>|null
     */
    public static function parse(mixed $input, Validator $v, string $field, bool $required, string $maxRate = '60'): ?array
    {
        if ($input === null || $input === []) {
            if ($required) {
                $v->addError($field, 'Add at least one tenure, e.g. 6 months or 1 year.');
            }
            return $required ? null : [];
        }
        if (!is_array($input) || !array_is_list($input) || count($input) > self::MAX_OPTIONS) {
            $v->addError($field, 'Give up to ' . self::MAX_OPTIONS . ' tenures, each with a number and a unit.');
            return null;
        }
        $options = [];
        foreach ($input as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            $unit = is_array($item) ? ($item['unit'] ?? null) : null;
            if (is_string($value) && preg_match('/^\d+$/', $value)) {
                $value = (int) $value;
            }
            if (!is_int($value) || !in_array($unit, self::UNITS, true) || $value < 1 || $value > self::MAX[$unit]) {
                $v->addError($field, 'Each tenure needs a whole number and a unit (days, weeks, months or years).');
                return null;
            }
            $option = ['value' => $value, 'unit' => $unit];
            $rate = is_array($item) ? ($item['rate'] ?? null) : null;
            if ($rate !== null && $rate !== '') {
                $rate = is_numeric($rate) ? (string) $rate : '';
                if ($rate === '' || bccomp($rate, '0', 4) < 0 || bccomp($rate, $maxRate, 4) > 0) {
                    $v->addError($field, "The rate for {$value} {$unit} must be a number from 0 to {$maxRate} (% a year), or empty to use the plan's rate.");
                    return null;
                }
                $option['rate'] = bcadd($rate, '0', 4);
            }
            $options[self::days($option) . $unit . $value] = $option;
        }
        uasort($options, fn ($a, $b) => self::days($a) <=> self::days($b));
        return array_values($options);
    }

    /** Approximate length in days, for ordering and limits. */
    public static function days(array $option): int
    {
        return $option['value'] * ['days' => 1, 'weeks' => 7, 'months' => 30, 'years' => 365][$option['unit']];
    }

    /** "45 days", "1 month", "2 years". */
    public static function label(array $option): string
    {
        $unit = $option['value'] === 1 ? rtrim($option['unit'], 's') : $option['unit'];
        return "{$option['value']} {$unit}";
    }

    /** @param list<array{value: int, unit: string}> $options */
    public static function labels(array $options): string
    {
        return implode(', ', array_map([self::class, 'label'], $options));
    }

    /** Day the tenure ends, counted from $start (calendar months clamp to month end). */
    public static function endDate(string $start, array $option): string
    {
        return match ($option['unit']) {
            'days' => (new DateTimeImmutable($start))->modify("+{$option['value']} days")->format('Y-m-d'),
            'weeks' => (new DateTimeImmutable($start))->modify('+' . (7 * $option['value']) . ' days')->format('Y-m-d'),
            'months' => LoanService::addPeriods($start, 'monthly', $option['value']),
            'years' => LoanService::addPeriods($start, 'monthly', 12 * $option['value']),
        };
    }

    /** Number of installments a loan tenure means for this repayment frequency, or null if it does not divide. */
    public static function installments(string $frequency, array $option): ?int
    {
        $factor = self::LOAN_FACTORS[$frequency][$option['unit']] ?? null;
        if ($factor === null) {
            return null;
        }
        if (is_int($factor)) {
            return $option['value'] * $factor;
        }
        if ($factor[0] === 'round') {
            return max(1, (int) round($option['value'] * $factor[1] / $factor[2]));
        }
        [$num, $den] = $factor;
        return ($option['value'] * $num) % $den === 0 ? intdiv($option['value'] * $num, $den) : null;
    }

    /** Terms with the rate of the chosen tenure, when that tenure has its own rate. */
    public static function withRate(array $terms, ?array $option): array
    {
        if ($option !== null && isset($option['rate']) && $option['rate'] !== '') {
            $terms['annual_interest_rate'] = $option['rate'];
        }
        return $terms;
    }

    /** The option matching value + unit, or null. */
    public static function find(array $options, int $value, string $unit): ?array
    {
        foreach ($options as $option) {
            if ($option['value'] === $value && $option['unit'] === $unit) {
                return $option;
            }
        }
        return null;
    }
}
