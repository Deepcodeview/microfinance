<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @param array<string, string> $fields Field-level validation messages */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'BAD_REQUEST', $message);
    }

    public static function unauthenticated(string $message = 'Authentication required.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'CONFLICT', $message);
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields, string $message = 'Some fields are invalid.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $fields);
    }

    public static function tooManyRequests(string $message): self
    {
        return new self(429, 'RATE_LIMITED', $message);
    }
}
