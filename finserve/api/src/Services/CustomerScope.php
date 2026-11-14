<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthUser;

/**
 * SQL filter restricting customers (aliased "c") to what the user may see.
 * Applied on every customer read and write, so an agent can never reach an unassigned customer by ID.
 */
final class CustomerScope
{
    /** @return array{0: string, 1: list<mixed>} */
    public static function where(AuthUser $user): array
    {
        return match ($user->customerScope()) {
            'all' => ['1 = 1', []],
            'branch' => $user->branchId === null ? ['1 = 0', []] : ['c.branch_id = ?', [$user->branchId]],
            'assigned' => [
                'EXISTS (SELECT 1 FROM agent_assignments aa
                          WHERE aa.customer_id = c.id AND aa.agent_user_id = ?
                            AND aa.valid_from <= UTC_DATE()
                            AND (aa.valid_to IS NULL OR aa.valid_to >= UTC_DATE()))',
                [$user->id],
            ],
            'own' => ['c.user_id = ?', [$user->id]],
            default => ['1 = 0', []],
        };
    }
}
