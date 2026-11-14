<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    /**
     * Runs "SELECT {$select} FROM {$from} WHERE {$where} ORDER BY ..." with page/per_page/sort/direction query params.
     *
     * @param list<mixed> $params
     * @param array<string, string> $sortable Allowed sort keys => SQL expression
     */
    public static function run(Request $request, string $select, string $from, string $where, array $params, array $sortable, string $defaultSort): Response
    {
        $sortKey = $request->query['sort'] ?? '';
        $sort = $sortable[$sortKey] ?? $sortable[$defaultSort];
        $direction = ($request->query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));

        $total = (int) Database::value("SELECT COUNT(*) FROM {$from} WHERE {$where}", $params);
        $rows = Database::all(
            "SELECT {$select} FROM {$from} WHERE {$where} ORDER BY {$sort} {$direction} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params,
        );
        return Response::paginated($rows, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    /** Adds "col = ?" for a query param when present and in the allowed list (or numeric when $allowed is null). */
    public static function filter(Request $request, string $param, string $column, array &$where, array &$params, ?array $allowed = null): void
    {
        $value = $request->query[$param] ?? '';
        if (!is_string($value) || $value === '') {
            return;
        }
        if ($allowed === null ? !ctype_digit($value) : !in_array($value, $allowed, true)) {
            return;
        }
        $where[] = "{$column} = ?";
        $params[] = $value;
    }
}
