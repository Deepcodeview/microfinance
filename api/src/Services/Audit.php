<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

final class Audit
{
    /**
     * @param ?array<string, mixed> $before
     * @param ?array<string, mixed> $after
     */
    public static function log(
        Request $request,
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        Database::insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'ip' => $request->ip,
            'request_id' => $request->id,
        ]);
    }

    /**
     * Only the fields that actually changed, for compact before/after records.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) && (string) $before[$key] !== (string) $value) {
                $old[$key] = $before[$key];
                $new[$key] = $value;
            }
        }
        return [$old, $new];
    }
}
