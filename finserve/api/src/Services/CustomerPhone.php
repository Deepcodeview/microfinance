<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Matches an Indian mobile number however it was typed: 98765 43210, +919876543210, 919876543210. */
final class CustomerPhone
{
    /** @return array{0: string, 1: string, 2: string, 3: string} */
    public static function variants(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);
        return [$phone, $last10, '+91' . $last10, '91' . $last10];
    }

    /** Id of the customer already holding this mobile number, if any. */
    public static function owner(string $phone, ?int $exceptCustomerId = null): ?int
    {
        $id = Database::value(
            'SELECT id FROM customers WHERE phone IN (?, ?, ?, ?) AND id <> ? LIMIT 1',
            [...self::variants($phone), $exceptCustomerId ?? 0],
        );
        return $id === null ? null : (int) $id;
    }
}
