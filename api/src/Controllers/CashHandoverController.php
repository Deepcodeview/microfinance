<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AgentCash;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Money;
use App\Services\Notifier;
use App\Services\Settings;

/**
 * Day-end cash control. Collected cash — plus any float the branch gave the agent, minus loans the agent
 * paid out from it — sits in "Agent cash in hand" until a supervisor counts it into branch cash.
 * Any difference stays open as an exception until resolved.
 */
final class CashHandoverController
{
    private const UNHANDED_SQL = "FROM payments WHERE collected_by = ? AND method = 'cash' AND status = 'confirmed' AND handover_id IS NULL AND business_date <= ?";

    public function mySummary(Request $request): Response
    {
        $user = Auth::user();
        $date = Settings::today();
        $position = AgentCash::position($user->id, $date);
        return Response::json([
            'business_date' => $date,
            'expected_cash' => $position['in_hand'],
            'position' => $position,
            'movements' => Database::all(
                "SELECT m.id, m.movement_number, m.type, m.amount, m.business_date, m.note, l.loan_number, c.full_name AS customer_name, u.name AS created_by_name
                   FROM agent_cash_movements m JOIN users u ON u.id = m.created_by
                   LEFT JOIN loans l ON l.id = m.loan_id LEFT JOIN customers c ON c.id = l.customer_id
                  WHERE m.agent_id = ? AND m.handover_id IS NULL AND m.business_date <= ? ORDER BY m.id",
                [$user->id, $date],
            ),
            'payments' => Database::all(
                'SELECT p.id, p.payment_number, p.amount, p.business_date, p.collected_at, c.full_name AS customer_name
                   FROM payments p JOIN customers c ON c.id = p.customer_id
                  WHERE p.collected_by = ? AND p.method = \'cash\' AND p.status = \'confirmed\' AND p.handover_id IS NULL AND p.business_date <= ?
                  ORDER BY p.id',
                [$user->id, $date],
            ),
            'today_handover' => Database::one('SELECT * FROM cash_handovers WHERE agent_id = ? AND business_date = ?', [$user->id, $date]),
        ]);
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $where = ['1 = 1'];
        $params = [];
        if (!$user->can('cash.verify')) {
            $where[] = 'h.agent_id = ?';
            $params[] = $user->id;
        } elseif ($user->customerScope() !== 'all') {
            $where[] = 'h.branch_id = ?';
            $params[] = $user->branchId;
        }
        Paginator::filter($request, 'status', 'h.status', $where, $params, ['submitted', 'verified', 'discrepancy', 'resolved']);
        Paginator::filter($request, 'agent_id', 'h.agent_id', $where, $params);

        return Paginator::run(
            $request,
            'h.*, a.name AS agent_name, b.name AS branch_name, vu.name AS verified_by_name, ru.name AS resolved_by_name,
             (SELECT COUNT(*) FROM payments p WHERE p.handover_id = h.id) AS payment_count',
            'cash_handovers h JOIN users a ON a.id = h.agent_id JOIN branches b ON b.id = h.branch_id
             LEFT JOIN users vu ON vu.id = h.verified_by LEFT JOIN users ru ON ru.id = h.resolved_by',
            implode(' AND ', $where),
            $params,
            ['business_date' => 'h.business_date, h.id', 'submitted_at' => 'h.id'],
            'submitted_at',
        );
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $declared = $v->decimal('declared_cash', '0', '100000000', 2);
        $note = $v->string('note', false, 500);
        $v->validate();
        if ($user->branchId === null) {
            throw HttpException::forbidden('Your account is not linked to a branch.');
        }

        $date = Settings::today();
        $id = Database::transaction(function () use ($user, $date, $declared, $note, $request) {
            if (Database::value('SELECT id FROM cash_handovers WHERE agent_id = ? AND business_date = ?', [$user->id, $date]) !== null) {
                throw HttpException::conflict('You have already submitted a handover today.');
            }
            $ids = array_column(Database::all('SELECT id ' . self::UNHANDED_SQL . ' FOR UPDATE', [$user->id, $date]), 'id');
            $moveIds = array_column(Database::all('SELECT id FROM agent_cash_movements WHERE agent_id = ? AND handover_id IS NULL AND business_date <= ? FOR UPDATE', [$user->id, $date]), 'id');
            $expected = AgentCash::position($user->id, $date)['in_hand'];
            if ($ids === [] && $moveIds === [] && Money::isZero($declared)) {
                throw HttpException::conflict('There is no collected cash to hand over.');
            }
            $id = Database::insert('cash_handovers', [
                'handover_number' => Database::nextNumber('handover'),
                'agent_id' => $user->id,
                'branch_id' => $user->branchId,
                'business_date' => $date,
                'expected_cash' => $expected,
                'declared_cash' => $declared,
                'agent_note' => $note,
            ]);
            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                Database::query("UPDATE payments SET handover_id = ? WHERE id IN ({$in})", array_merge([$id], $ids));
            }
            if ($moveIds !== []) {
                $in = implode(',', array_fill(0, count($moveIds), '?'));
                Database::query("UPDATE agent_cash_movements SET handover_id = ? WHERE id IN ({$in})", array_merge([$id], $moveIds));
            }
            Notifier::role('collection_supervisor', $user->branchId, 'cash.handover_submitted', 'Cash handover to verify',
                "{$user->name}: Rs " . Money::fmt($declared) . ' declared.', '/cash?status=submitted', $user->id);
            Audit::log($request, $user->id, 'cash.handover_submitted', 'cash_handover', $id, null,
                ['expected' => Money::fmt($expected), 'declared' => $declared, 'payments' => count($ids)]);
            return $id;
        });
        return Response::json($this->find($id), 201);
    }

    public function verify(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $counted = $v->decimal('verified_cash', '0', '100000000', 2);
        $note = $v->string('note', false, 500);
        $v->validate();

        $id = $request->intParam('id');
        Database::transaction(function () use ($id, $user, $counted, $note, $request) {
            $h = $this->lockInScope($id);
            if ($h['status'] !== 'submitted') {
                throw HttpException::conflict("Handover is already {$h['status']}.");
            }
            if ((int) $h['agent_id'] === $user->id && !$user->maySelfApprove()) {
                throw HttpException::forbidden('You cannot verify your own cash handover.');
            }
            $expected = $h['expected_cash'];
            $difference = Money::sub($counted, $expected);
            $toBranch = Money::min($counted, $expected);
            $lines = [
                [Ledger::BRANCH_CASH, $counted, '0'],
                [Ledger::AGENT_CASH, '0', Money::fmt($toBranch)],
            ];
            if (Money::isPositive($difference)) {
                $lines[] = [Ledger::CASH_OVERAGE_SUSPENSE, '0', Money::fmt($difference)];
            }
            if (Money::isPositive($counted)) {
                Ledger::post(Settings::today(), (int) $h['branch_id'], 'cash_handover', $id, "Cash handover {$h['handover_number']} verified", $lines, $user->id);
            }
            $status = Money::isZero($difference) ? 'verified' : 'discrepancy';
            Database::query(
                'UPDATE cash_handovers SET verified_cash = ?, difference = ?, status = ?, verified_by = ?, verified_at = UTC_TIMESTAMP(), verifier_note = ? WHERE id = ?',
                [$counted, $difference, $status, $user->id, $note, $id],
            );
            if ($status === 'discrepancy') {
                Notifier::user((int) $h['agent_id'], 'cash.discrepancy', "Cash difference on {$h['handover_number']}",
                    'Counted Rs ' . Money::fmt($counted) . ' against expected Rs ' . Money::fmt($expected) . '. Please see your supervisor.', '/cash');
                Notifier::role('finance', null, 'cash.discrepancy', 'Cash discrepancy to resolve', "{$h['handover_number']}: Rs " . Money::fmt($difference), '/cash?status=discrepancy');
            }
            Audit::log($request, $user->id, 'cash.handover_verified', 'cash_handover', $id, ['status' => 'submitted'],
                ['status' => $status, 'verified_cash' => $counted, 'difference' => Money::fmt($difference)], $note);
        });
        return Response::json($this->find($id));
    }

    /** Closes a discrepancy: shortage recovered from the agent or written off; overage taken to income. */
    public function resolve(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $resolution = $v->in('resolution', ['recovered', 'written_off', 'overage_income']);
        $note = $v->string('note', true, 500, 5);
        $v->validate();

        $id = $request->intParam('id');
        Database::transaction(function () use ($id, $user, $resolution, $note, $request) {
            $h = $this->lockInScope($id);
            if ($h['status'] !== 'discrepancy') {
                throw HttpException::conflict('Only handovers with a discrepancy can be resolved.');
            }
            $diff = $h['difference'];
            $amount = Money::fmt(ltrim($diff, '-'));
            $shortage = Money::cmp($diff, '0') < 0;
            if ($shortage === ($resolution === 'overage_income')) {
                throw HttpException::validation(['resolution' => $shortage ? 'A shortage must be recovered or written off.' : 'An overage can only be taken to income.']);
            }
            $lines = match ($resolution) {
                'recovered' => [[Ledger::BRANCH_CASH, $amount, '0'], [Ledger::AGENT_CASH, '0', $amount]],
                'written_off' => [[Ledger::CASH_SHORTAGE_EXPENSE, $amount, '0'], [Ledger::AGENT_CASH, '0', $amount]],
                'overage_income' => [[Ledger::CASH_OVERAGE_SUSPENSE, $amount, '0'], [Ledger::MISC_INCOME, '0', $amount]],
            };
            Ledger::post(Settings::today(), (int) $h['branch_id'], 'cash_handover_resolution', $id, "Handover {$h['handover_number']}: {$resolution}", $lines, $user->id);
            Database::query(
                "UPDATE cash_handovers SET status = 'resolved', resolution = ?, resolution_note = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?",
                [$resolution, $note, $user->id, $id],
            );
            Audit::log($request, $user->id, 'cash.discrepancy_resolved', 'cash_handover', $id, ['status' => 'discrepancy'],
                ['status' => 'resolved', 'resolution' => $resolution, 'amount' => $amount], $note);
        });
        return Response::json($this->find($id));
    }

    public function show(Request $request): Response
    {
        $user = Auth::user();
        $h = $this->find($request->intParam('id'));
        if ((int) $h['agent_id'] !== $user->id && !$user->can('cash.verify')) {
            throw HttpException::notFound('Handover not found.');
        }
        return Response::json($h);
    }

    /** Floats and field payouts: an agent sees their own; cash managers see their branch (or all). */
    public function movements(Request $request): Response
    {
        $user = Auth::user();
        $where = ['1 = 1'];
        $params = [];
        if (!$user->can('cash.float')) {
            $where[] = 'm.agent_id = ?';
            $params[] = $user->id;
        } elseif ($user->customerScope() !== 'all') {
            $where[] = 'm.branch_id = ?';
            $params[] = $user->branchId;
        }
        Paginator::filter($request, 'agent_id', 'm.agent_id', $where, $params);
        Paginator::filter($request, 'type', 'm.type', $where, $params, ['float_issued', 'field_disbursement']);
        return Paginator::run(
            $request,
            'm.id, m.movement_number, m.type, m.amount, m.business_date, m.note, m.handover_id, m.created_at,
             a.name AS agent_name, u.name AS created_by_name, l.loan_number, h.handover_number',
            'agent_cash_movements m JOIN users a ON a.id = m.agent_id JOIN users u ON u.id = m.created_by
             LEFT JOIN loans l ON l.id = m.loan_id LEFT JOIN cash_handovers h ON h.id = m.handover_id',
            implode(' AND ', $where),
            $params,
            ['created_at' => 'm.id'],
            'created_at',
        );
    }

    /** Field agents the user can give a float to, with the cash each holds right now. */
    public function agents(Request $request): Response
    {
        $user = Auth::user();
        $params = [];
        $branch = '';
        if ($user->customerScope() !== 'all') {
            $branch = 'AND u.branch_id = ?';
            $params[] = $user->branchId;
        }
        $agents = Database::all(
            "SELECT u.id, u.name, b.name AS branch_name FROM users u
               JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.role_key = 'collection_agent'
               JOIN branches b ON b.id = u.branch_id
              WHERE u.status = 'active' {$branch} ORDER BY u.name",
            $params,
        );
        $date = Settings::today();
        return Response::json(array_map(fn ($a) => $a + AgentCash::position((int) $a['id'], $date), $agents));
    }

    /** The branch hands cash to an agent to carry (e.g. to pay out approved loans in the field). */
    public function issueFloat(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $agentId = $v->int('agent_user_id', 1, PHP_INT_MAX);
        $amount = $v->decimal('amount', '1', '10000000', 2);
        $note = $v->string('note', false, 255);
        $v->validate();

        $agent = Database::one(
            "SELECT u.id, u.name, u.branch_id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.id = ? AND u.status = 'active' AND r.role_key = 'collection_agent'",
            [$agentId],
        );
        if ($agent === null || $agent['branch_id'] === null || ($user->customerScope() !== 'all' && (int) $agent['branch_id'] !== $user->branchId)) {
            throw HttpException::validation(['agent_user_id' => 'Choose an active agent of your branch.']);
        }
        if ((int) $agent['id'] === $user->id && !$user->maySelfApprove()) {
            throw HttpException::forbidden('You cannot give a cash float to yourself.');
        }
        $branchId = (int) $agent['branch_id'];
        $date = Settings::today();

        $id = Database::transaction(function () use ($agent, $branchId, $amount, $note, $date, $user, $request) {
            Ledger::assertFunds('cash', $amount, $branchId);
            $id = Database::insert('agent_cash_movements', [
                'movement_number' => Database::nextNumber('cash_movement'),
                'agent_id' => $agent['id'],
                'branch_id' => $branchId,
                'type' => 'float_issued',
                'amount' => $amount,
                'business_date' => $date,
                'note' => $note,
                'created_by' => $user->id,
            ]);
            $entryId = Ledger::post($date, $branchId, 'cash_float', $id, "Cash float to {$agent['name']}", [
                [Ledger::AGENT_CASH, $amount, '0'],
                [Ledger::BRANCH_CASH, '0', $amount],
            ], $user->id);
            Database::query('UPDATE agent_cash_movements SET journal_entry_id = ? WHERE id = ?', [$entryId, $id]);
            Notifier::user((int) $agent['id'], 'cash.float_issued', 'Cash float received',
                'Rs ' . Money::fmt($amount) . " from the branch. It is part of today's cash handover.", '/cash');
            Audit::log($request, $user->id, 'cash.float_issued', 'agent_cash_movement', $id, null, ['agent_id' => (int) $agent['id'], 'amount' => $amount], $note);
            return $id;
        });
        return Response::json(Database::one('SELECT * FROM agent_cash_movements WHERE id = ?', [$id]), 201);
    }

    private function find(int $id): array
    {
        $h = Database::one(
            'SELECT h.*, a.name AS agent_name, b.name AS branch_name, vu.name AS verified_by_name, ru.name AS resolved_by_name
               FROM cash_handovers h JOIN users a ON a.id = h.agent_id JOIN branches b ON b.id = h.branch_id
               LEFT JOIN users vu ON vu.id = h.verified_by LEFT JOIN users ru ON ru.id = h.resolved_by
              WHERE h.id = ?',
            [$id],
        ) ?? throw HttpException::notFound('Handover not found.');
        $h['movements'] = Database::all(
            'SELECT m.id, m.movement_number, m.type, m.amount, m.business_date, l.loan_number
               FROM agent_cash_movements m LEFT JOIN loans l ON l.id = m.loan_id WHERE m.handover_id = ? ORDER BY m.id',
            [$id],
        );
        $h['payments'] = Database::all(
            'SELECT p.id, p.payment_number, p.amount, p.status, p.business_date, c.full_name AS customer_name
               FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.handover_id = ? ORDER BY p.id',
            [$id],
        );
        return $h;
    }

    private function lockInScope(int $id): array
    {
        $user = Auth::user();
        $h = Database::one('SELECT * FROM cash_handovers WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Handover not found.');
        if ($user->customerScope() !== 'all' && (int) $h['branch_id'] !== $user->branchId) {
            throw HttpException::notFound('Handover not found.');
        }
        return $h;
    }
}
