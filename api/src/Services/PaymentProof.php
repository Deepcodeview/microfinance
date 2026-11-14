<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/** Payment screenshots (UPI proof). Stored outside the web root under random names; served only via the API. */
final class PaymentProof
{
    public const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** @return array{bytes: string, mime: string, sha256: string} */
    public static function decode(string $dataUrl): array
    {
        if (!preg_match('#^data:image/[a-z]+;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw HttpException::validation(['proof_image' => 'Attach the screenshot as an image.']);
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || $bytes === '') {
            throw HttpException::validation(['proof_image' => 'The screenshot could not be read.']);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw HttpException::validation(['proof_image' => 'Screenshot is larger than 4 MB.']);
        }
        // Trust the bytes, not the declared type.
        $mime = FileType::sniff(substr($bytes, 0, 16));
        if (!isset(self::EXT[$mime])) {
            throw HttpException::validation(['proof_image' => 'Only JPG, PNG or WebP screenshots are accepted.']);
        }
        return ['bytes' => $bytes, 'mime' => $mime, 'sha256' => hash('sha256', $bytes)];
    }

    /** @param array{bytes: string, mime: string, sha256: string} $proof */
    public static function store(array $proof): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create payment proof storage.');
        }
        $name = bin2hex(random_bytes(32));
        if (file_put_contents("{$dir}/{$name}", $proof['bytes'], LOCK_EX) === false) {
            throw new \RuntimeException('Could not store the payment proof.');
        }
        return ['stored_name' => $name, 'mime' => $proof['mime'], 'sha256' => $proof['sha256']];
    }

    public static function delete(string $storedName): void
    {
        @unlink(self::path($storedName));
    }

    public static function path(string $storedName): string
    {
        return self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $storedName);
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/payment-proofs';
    }
}
