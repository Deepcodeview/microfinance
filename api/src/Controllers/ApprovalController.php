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
use App\Services\Approvals;
use App\Services\Audit;
use App\Services\Notifier;

final class ApprovalController
{
    public function index(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        Paginator::filter($request, 'status', 'a.status', $where, $params, ['pending', 'approved', 'rejected', 'cancelled', 'failed']);
        Paginator::filter($request, 'type', 'a.type', $where, $params, array_keys(Approvals::APPROVER_PERMISSION));
        return Paginator::run(
            $request,
            'a.id, a.request_number, a.type, a.entity_type, a.entity_id, a.amount, a.summary, a.payload_json, a.reason, a.status,
             a.requested_by, a.requested_at, a.decided_at, a.decision_note, a.result_json, r.name AS requested_by_name, d.name AS decided_by_name',
            'approval_requests a JOIN users r ON r.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by',
            implode(' AND ', $where),
            $params,
            ['requested_at' => 'a.id'],
            'requested_at',
        );
    }

    public function decide(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $decision = $v->in('decision', ['approved', 'rejected']);
        $note = $v->string('note', $decision === 'rejected', 500, 3);
        $v->validate();

        $id = $request->intParam('id');
        $result = Database::transaction(function () use ($id, $user, $decision, $note, $request) {
            $req = Database::one('SELECT * FROM approval_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Request not found.');
            if ($req['status'] !== 'pending') {
                throw HttpException::conflict("This request is already {$req['status']}.");
            }
            if ((int) $req['requested_by'] === $user->id && !$user->maySelfApprove()) {
                throw HttpException::forbidden('You cannot approve or reject your own request. Ask another approver, or a Super Admin can allow single-owner approval in Settings → Security.');
            }
            if (!$user->can(Approvals::APPROVER_PERMISSION[$req['type']])) {
                throw HttpException::forbidden('Your role cannot decide this type of request.');
            }
            $result = $decision === 'approved' ? Approvals::execute($req, $user) : null;
            Database::query(
                'UPDATE approval_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ?, result_json = ? WHERE id = ?',
                [$decision, $user->id, $note, $result === null ? null : json_encode($result), $id],
            );
            Audit::log($request, $user->id, 'approval.' . $decision, 'approval_request', $id, ['status' => 'pending'],
                ['status' => $decision, 'type' => $req['type'], 'result' => $result], $note);
            Notifier::user((int) $req['requested_by'], 'approval.' . $decision, "{$req['request_number']} {$decision}", $req['summary'] . ($note ? " — {$note}" : ''), '/approvals');
            return $result;
        });
        return Response::json(['status' => $decision, 'result' => $result]);
    }

    public function cancel(Request $request): Response
    {
        $user = Auth::user();
        $id = $request->intParam('id');
        Database::transaction(function () use ($id, $user, $request) {
            $req = Database::one('SELECT * FROM approval_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Request not found.');
            if ($req['status'] !== 'pending' || (int) $req['requested_by'] !== $user->id) {
                throw HttpException::conflict('Only your own pending requests can be cancelled.');
            }
            Database::query("UPDATE approval_requests SET status = 'cancelled', decided_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            Audit::log($request, $user->id, 'approval.cancelled', 'approval_request', $id);
        });
        return Response::noContent();
    }
}
