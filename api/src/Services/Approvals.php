<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthUser;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Maker-checker for sensitive financial actions. A request stores everything needed to execute;
 * execution happens only when a *different* user holding the type's approval permission approves.
 */
final class Approvals
{
    /** type => permission required to approve */
    public const APPROVER_PERMISSION = [
        'payment_reversal' => 'approvals.financial',
        'manual_journal' => 'approvals.financial',
        'loan_write_off' => 'approvals.write_off',
    ];

    public static function request(AuthUser $user, string $type, ?string $entityType, ?int $entityId, ?int $branchId, ?string $amount, string $summary, array $payload, string $reason): int
    {
        if ($entityId !== null && Database::value(
            "SELECT id FROM approval_requests WHERE type = ? AND entity_type = ? AND entity_id = ? AND status = 'pending'",
            [$type, $entityType, $entityId],
        ) !== null) {
            throw HttpException::conflict('A request for this is already awaiting approval.');
        }
        $id = Database::insert('approval_requests', [
            'request_number' => Database::nextNumber('approval'),
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'branch_id' => $branchId,
            'amount' => $amount,
            'summary' => mb_substr($summary, 0, 255),
            'payload_json' => json_encode($payload),
            'reason' => $reason,
            'requested_by' => $user->id,
        ]);
        foreach (Database::all(
            "SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id WHERE p.permission_key = ? AND u.status = 'active' AND u.id <> ?",
            [self::APPROVER_PERMISSION[$type], $user->id],
        ) as $approver) {
            Notifier::user((int) $approver['id'], 'approval.requested', 'Approval needed: ' . str_replace('_', ' ', $type), $summary, '/approvals');
        }
        return $id;
    }

    /** Runs the approved action. Returns a result summary stored on the request. */
    public static function execute(array $request, AuthUser $approver): array
    {
        $payload = json_decode($request['payload_json'], true);
        $today = Settings::today();
        return match ($request['type']) {
            'payment_reversal' => self::reversePayment((int) $request['entity_id'], $request['reason'], $approver),
            'loan_write_off' => LoanAccounting::writeOff(PaymentService::lockLoan((int) $request['entity_id']), $today, $approver->id),
            'manual_journal' => ['journal_entry_id' => Ledger::post(
                $today,
                (int) $payload['branch_id'],
                'manual_journal',
                (int) $request['id'],
                $payload['description'],
                array_map(fn ($l) => [$l['account_code'], $l['debit'], $l['credit']], $payload['lines']),
                $approver->id,
            )],
        };
    }

    private static function reversePayment(int $paymentId, string $reason, AuthUser $approver): array
    {
        $payment = Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw HttpException::notFound('Payment not found.');
        PaymentService::reverse($payment, $reason, $approver->id);
        return ['payment_id' => $paymentId, 'reversal_entry_id' => (int) Database::value('SELECT reversal_entry_id FROM payments WHERE id = ?', [$paymentId])];
    }

    /**
     * Validates manual journal lines: balanced, at least two, real accounts, no control accounts.
     *
     * @return list<array{account_code: string, debit: string, credit: string}>
     */
    public static function validateJournalLines(mixed $lines): array
    {
        if (!is_array($lines) || count($lines) < 2 || count($lines) > 20) {
            throw HttpException::validation(['lines' => 'Enter between 2 and 20 lines.']);
        }
        $valid = array_column(Database::all('SELECT code FROM accounting_accounts WHERE is_active = 1'), 'code');
        $out = [];
        $debits = $credits = '0';
        foreach (array_values($lines) as $i => $line) {
            $code = (string) ($line['account_code'] ?? '');
            $debit = (string) ($line['debit'] ?? '0') ?: '0';
            $credit = (string) ($line['credit'] ?? '0') ?: '0';
            if (!in_array($code, $valid, true)) {
                throw HttpException::validation(["lines.{$i}" => "Unknown account {$code}."]);
            }
            if (in_array($code, Ledger::CONTROL_ACCOUNTS, true)) {
                throw HttpException::validation(["lines.{$i}" => "Account {$code} is a control account maintained by the system and cannot be posted manually."]);
            }
            foreach ([$debit, $credit] as $v) {
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
                    throw HttpException::validation(["lines.{$i}" => 'Amounts must be positive numbers with at most 2 decimals.']);
                }
            }
            if (Money::isPositive($debit) === Money::isPositive($credit)) {
                throw HttpException::validation(["lines.{$i}" => 'Each line needs either a debit or a credit.']);
            }
            $debits = Money::add($debits, $debit);
            $credits = Money::add($credits, $credit);
            $out[] = ['account_code' => $code, 'debit' => Money::fmt($debit), 'credit' => Money::fmt($credit)];
        }
        if (Money::cmp($debits, $credits) !== 0) {
            throw HttpException::validation(['lines' => 'Debits (' . Money::fmt($debits) . ') must equal credits (' . Money::fmt($credits) . ').']);
        }
        return $out;
    }
}
