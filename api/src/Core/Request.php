<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, string> Route parameters such as {id} */
    public array $params = [];

    /** @var array<string, mixed> */
    private array $body;

    public readonly string $id;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string, mixed> */
        public readonly array $query,
        /** @var array<string, string> */
        private readonly array $headers,
        string $rawBody,
        public readonly string $ip,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->body = [];
        if (str_starts_with($this->header('content-type') ?? '', 'multipart/form-data')) {
            // Multipart uploads: text fields arrive in $_POST, files via file().
            $this->body = $_POST;
        } elseif ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('Request body must be a JSON object.');
            }
            $this->body = $decoded;
        }
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        // PHP exposes these two without the HTTP_ prefix.
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        // Apache may drop Authorization unless forwarded via .htaccess.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $auth, $m) ? $m[1] : null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function intParam(string $key): int
    {
        $value = $this->param($key);
        if (!ctype_digit($value)) {
            throw HttpException::notFound();
        }
        return (int) $value;
    }

    /** @return array{name: string, tmp_name: string, size: int, error: int}|null A single uploaded file. */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return null;
        }
        return $file;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent') ?? '', 0, 255);
    }
}
