<?php

declare(strict_types=1);

namespace App\Services;

/**
 * File type from the file's first bytes (its "magic number"), never from the name or the browser's claim.
 * Built in rather than PHP's fileinfo extension, which many shared hosts leave switched off.
 */
final class FileType
{
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    public static function sniffFile(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 16);
        return $head === false ? null : self::sniff($head);
    }

    /** The host's upload limit (the smaller of upload_max_filesize and post_max_size), e.g. "2 MB". */
    public static function serverLimit(): string
    {
        $bytes = min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size')));
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    /** True when the request body was larger than post_max_size, so PHP dropped the whole form. */
    public static function bodyTooLarge(): bool
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = self::iniBytes((string) ini_get('post_max_size'));
        return $max > 0 && $length > $max;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
