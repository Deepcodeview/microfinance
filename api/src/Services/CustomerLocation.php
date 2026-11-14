<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** A customer's pin on the agents' map. Callers hold the transaction. */
final class CustomerLocation
{
    /** @param array{latitude: string, longitude: string, location_accuracy_m: ?int} $point */
    public static function set(int $customerId, array $point, int $userId): void
    {
        Database::query(
            'UPDATE customers SET latitude = ?, longitude = ?, location_accuracy_m = ?, location_set_at = UTC_TIMESTAMP(), location_set_by = ? WHERE id = ?',
            [$point['latitude'], $point['longitude'], $point['location_accuracy_m'] ?? null, $userId, $customerId],
        );
    }

    /** First located collection or visit at a customer who has no pin yet becomes their pin (if reasonably precise). */
    public static function pinIfMissing(int $customerId, array $point, int $userId): void
    {
        if (($point['location_accuracy_m'] ?? 0) > 150) {
            return;
        }
        if (Database::value('SELECT latitude FROM customers WHERE id = ?', [$customerId]) === null) {
            self::set($customerId, $point, $userId);
        }
    }
}
