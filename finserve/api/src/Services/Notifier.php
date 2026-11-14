<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Writes in-app notifications and queues SMS for customers. SMS stays 'queued' until a provider
 * is wired into bin/send-notifications.php; the outbox is visible to admins so nothing is lost.
 * Never throws into the caller's financial transaction for a duplicate (dedupe key) notification.
 */
final class Notifier
{
    public static function user(int $userId, string $event, string $title, string $body, ?string $link = null, ?string $dedupeKey = null): void
    {
        self::insert([
            'user_id' => $userId, 'channel' => 'in_app', 'event' => $event, 'title' => $title,
            'body' => $body, 'link' => $link, 'status' => 'unread', 'dedupe_key' => $dedupeKey,
        ]);
    }

    /** Portal (if the customer has a login) + SMS to the registered mobile. */
    public static function customer(int $customerId, string $event, string $title, string $body, ?string $link = null, ?string $dedupeKey = null): void
    {
        $customer = Database::one('SELECT user_id, phone FROM customers WHERE id = ?', [$customerId]);
        if ($customer === null) {
            return;
        }
        if ($customer['user_id'] !== null) {
            self::insert([
                'user_id' => $customer['user_id'], 'customer_id' => $customerId, 'channel' => 'in_app', 'event' => $event,
                'title' => $title, 'body' => $body, 'link' => $link, 'status' => 'unread',
                'dedupe_key' => $dedupeKey === null ? null : $dedupeKey . ':app',
            ]);
        }
        self::insert([
            'customer_id' => $customerId, 'channel' => 'sms', 'event' => $event, 'title' => $title,
            'body' => mb_substr(Settings::get('company_name') . ': ' . $body, 0, 320), 'recipient' => $customer['phone'], 'status' => 'queued',
            'dedupe_key' => $dedupeKey === null ? null : $dedupeKey . ':sms',
        ]);
    }

    /** All active users holding a role, optionally limited to a branch. */
    public static function role(string $role, ?int $branchId, string $event, string $title, string $body, ?string $link = null, ?int $exceptUserId = null): void
    {
        $users = Database::all(
            "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE r.role_key = ? AND u.status = 'active'" . ($branchId === null ? '' : ' AND (u.branch_id = ? OR u.branch_id IS NULL)'),
            $branchId === null ? [$role] : [$role, $branchId],
        );
        foreach ($users as $u) {
            if ((int) $u['id'] !== $exceptUserId) {
                self::user((int) $u['id'], $event, $title, $body, $link);
            }
        }
    }

    private static function insert(array $row): void
    {
        $columns = array_keys($row);
        Database::query(
            'INSERT IGNORE INTO notifications (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );
    }
}
