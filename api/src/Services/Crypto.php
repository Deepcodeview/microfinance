<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

/** AES-256-GCM with APP_KEY, for secrets that must be recoverable (e.g. TOTP seeds). */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed ciphertext.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong APP_KEY?).');
        }
        return $plain;
    }

    private static function key(): string
    {
        $key = base64_decode((string) Env::get('APP_KEY', ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be 32 random bytes, base64-encoded. Generate with: php -r "echo base64_encode(random_bytes(32));"');
        }
        return $key;
    }
}
