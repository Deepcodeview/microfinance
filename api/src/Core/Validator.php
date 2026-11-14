<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Collects field errors, then throws one 422 with all of them.
 * Money and percentages are handled as decimal strings, never floats.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, bool $required = true, int $max = 255, int $min = 1): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (!is_string($value)) {
            $this->errors[$field] = 'Must be text.';
            return null;
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->errors[$field] = "Must be between {$min} and {$max} characters.";
            return null;
        }
        return $value;
    }

    public function email(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 190);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'Must be a valid email address.';
            return null;
        }
        return $value === null ? null : strtolower($value);
    }

    public function phone(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 20);
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/[\s-]/', '', $value);
        if (!preg_match('/^\+?\d{10,15}$/', $digits)) {
            $this->errors[$field] = 'Must be a valid phone number (10–15 digits).';
            return null;
        }
        return $digits;
    }

    public function pattern(string $field, string $regex, string $message, bool $required = true, int $max = 255): ?string
    {
        $value = $this->string($field, $required, $max);
        if ($value !== null && !preg_match($regex, $value)) {
            $this->errors[$field] = $message;
            return null;
        }
        return $value;
    }

    public function date(string $field, bool $required = true): ?string
    {
        $value = $this->string($field, $required, 10);
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = 'Must be a date in YYYY-MM-DD format.';
            return null;
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public function in(string $field, array $allowed, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] = 'Must be one of: ' . implode(', ', $allowed) . '.';
            return null;
        }
        return $value;
    }

    public function int(string $field, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->errors[$field] = "Must be a whole number from {$min} to {$max}.";
            return null;
        }
        return $value;
    }

    /** Decimal as a string with up to $scale places, within [$min, $max]. Floats are rejected for safety. */
    public function decimal(string $field, string $min, string $max, int $scale = 4, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^\d+(\.\d{1,' . $scale . '})?$/', $value)) {
            $this->errors[$field] = "Must be a number with at most {$scale} decimal places, sent as a string.";
            return null;
        }
        if (bccomp($value, $min, $scale) < 0 || bccomp($value, $max, $scale) > 0) {
            $this->errors[$field] = "Must be between {$min} and {$max}.";
            return null;
        }
        return bcadd($value, '0', $scale);
    }

    public function bool(string $field, bool $required = true): ?bool
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            if ($required) {
                $this->errors[$field] = 'This field is required.';
            }
            return null;
        }
        if (!is_bool($value)) {
            $this->errors[$field] = 'Must be true or false.';
            return null;
        }
        return $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
