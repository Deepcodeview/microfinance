<?php

declare(strict_types=1);

namespace App\Services;

/** Decimal-string arithmetic for rupee amounts (2 dp for transactions, 4 dp where stored precision matters). */
final class Money
{
    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v, 4);
        }
        return $sum;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 4);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, 10);
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 4) <= 0 ? $a : $b;
    }

    /** The amount, or 0 when it is negative. */
    public static function max0(string $a): string
    {
        return self::cmp($a, '0') > 0 ? $a : '0';
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 4);
    }

    public static function isPositive(string $a): bool
    {
        return bccomp($a, '0', 4) > 0;
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', 4) === 0;
    }

    /** Half-up rounding that also handles negatives. */
    public static function round(string $value, int $places = 2): string
    {
        $half = '0.' . str_repeat('0', $places) . '5';
        return str_starts_with($value, '-')
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);
    }

    /** Percentage of an amount, rounded to paise. */
    public static function percentOf(string $amount, string $percent): string
    {
        return self::round(bcdiv(bcmul($amount, $percent, 10), '100', 10));
    }

    public static function fmt(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
