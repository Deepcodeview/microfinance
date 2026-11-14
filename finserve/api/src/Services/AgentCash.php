<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Cash an agent holds and has not yet handed over: cash collections + floats received from the branch
 * − loans paid out in the field. This is exactly the agent's share of the AGENT_CASH ledger account and
 * what the next day-end handover expects.
 */
final class AgentCash
{
    /** @return array{collections: string, floats: string, field_disbursements: string, in_hand: string} */
    public static function position(int $agentId, string $date): array
    {
        $collections = (string) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by = ? AND method = 'cash' AND status = 'confirmed' AND handover_id IS NULL AND business_date <= ?",
            [$agentId, $date],
        );
        $moves = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN type = 'float_issued' THEN amount END), 0) AS floats,
                    COALESCE(SUM(CASE WHEN type = 'field_disbursement' THEN amount END), 0) AS paid_out
               FROM agent_cash_movements WHERE agent_id = ? AND handover_id IS NULL AND business_date <= ?",
            [$agentId, $date],
        );
        return [
            'collections' => Money::fmt($collections),
            'floats' => Money::fmt($moves['floats']),
            'field_disbursements' => Money::fmt($moves['paid_out']),
            'in_hand' => Money::fmt(Money::sub(Money::add($collections, $moves['floats']), $moves['paid_out'])),
        ];
    }
}
