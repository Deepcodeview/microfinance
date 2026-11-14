<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

final class Settings
{
    public const DEFAULTS = [
        // '1' = agents cannot record a collection without the phone's location (GPS).
        'require_collection_location' => '0',
        'company_name' => 'KBSAVE',
        'business_timezone' => 'Asia/Kolkata',
        // Compliance gate: deposit-taking stays off until legal review approves it (SRS §6).
        'deposits_enabled' => '0',
        // ISO weekdays with no collections or due dates (7 = Sunday). Comma-separated.
        'weekly_off_days' => '7',
        // When '1', Super Admin / Admin / Finance must set up two-factor login before using the system.
        'mfa_required_for_privileged' => '0',
        // Maker-checker relief for a one-owner business: when '1', a Super Admin may approve their own requests.
        'owner_self_approval' => '0',
        // Credit & cash controls (rupees). Branch managers can approve loans up to this principal;
        // larger loans need an Admin / Super Admin.
        'loan_approval_limit' => '200000',
        // Maximum total principal (outstanding + in process) one customer may owe.
        'max_customer_exposure' => '1000000',
        // Cash a collector may hold before handing over.
        'agent_cash_limit' => '100000',
        // Provisioning rates (% of principal outstanding), IRACP-style defaults; confirm with your accountant/regulator.
        'provision_rate_standard' => '0.40',
        'provision_rate_substandard' => '15',
        'provision_rate_doubtful' => '25',
        'provision_rate_loss' => '100',
        // Books closed through this business date (day-end close). Empty = nothing closed.
        'closed_through' => '',
        // Treasury: when '0', payments from the bank account are blocked if they would overdraw it.
        'allow_bank_overdraft' => '0',
        // UPI QR collection: the organisation's UPI ID (VPA) and the name customers see when they scan.
        'upi_vpa' => '',
        'upi_payee_name' => '',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::all('SELECT setting_key, setting_value FROM settings');
            self::$cache = array_merge(self::DEFAULTS, array_column($rows, 'setting_value', 'setting_key'));
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function set(string $key, string $value, int $userId): void
    {
        Database::query(
            'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $value, $userId],
        );
        self::$cache = null;
    }

    public static function depositsEnabled(): bool
    {
        return self::get('deposits_enabled') === '1';
    }

    public static function requireDeposits(): void
    {
        if (!self::depositsEnabled()) {
            throw new HttpException(403, 'FEATURE_DISABLED', 'Deposit operations are disabled until compliance approval. A Super Admin can enable them in Settings.');
        }
    }

    public static function closedThrough(): ?string
    {
        $value = self::get('closed_through');
        return $value === '' ? null : $value;
    }

    /** Today's date in the business timezone; all financial events are dated with this. */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::get('business_timezone') ?: 'Asia/Kolkata')))->format('Y-m-d');
    }
}
