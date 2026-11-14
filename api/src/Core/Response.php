<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        private readonly ?string $csvFilename = null,
        private readonly ?array $file = null,
    ) {
    }

    /** Streams a private file. $inline lets images/PDFs open in the browser instead of downloading. */
    public static function file(string $path, string $mime, string $downloadName, bool $inline = true): self
    {
        return new self(200, null, null, compact('path', 'mime', 'downloadName', 'inline'));
    }

    /** @param list<array<string, mixed>> $rows */
    public static function csv(string $filename, array $rows): self
    {
        $out = fopen('php://temp', 'r+');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]), escape: '');
        }
        foreach ($rows as $row) {
            // Neutralise spreadsheet formula injection in text cells.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && !is_numeric($v) ? "'" . $v : $v, $row), escape: '');
        }
        rewind($out);
        return new self(200, stream_get_contents($out), $filename);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, ['data' => $data]);
    }

    /** @param array<string, mixed> $meta */
    public static function paginated(array $items, array $meta): self
    {
        return new self(200, ['data' => $items, 'meta' => $meta]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function error(int $status, string $code, string $message, string $requestId, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message, 'request_id' => $requestId];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return new self($status, ['error' => $error]);
    }

    public function send(string $requestId): void
    {
        http_response_code($this->status);
        header('X-Request-Id: ' . $requestId);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($this->file !== null) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $this->file['downloadName']);
            header('Content-Type: ' . $this->file['mime']);
            header('Content-Length: ' . filesize($this->file['path']));
            header('Content-Disposition: ' . ($this->file['inline'] ? 'inline' : 'attachment') . '; filename="' . $name . '"');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
            readfile($this->file['path']);
            return;
        }
        if ($this->csvFilename !== null) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $this->csvFilename . '"');
            echo $this->body;
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
