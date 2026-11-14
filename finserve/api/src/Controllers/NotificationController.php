<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;

final class NotificationController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        return Response::json([
            'items' => Database::all(
                "SELECT id, event, title, body, link, status, created_at FROM notifications
                  WHERE user_id = ? AND channel = 'in_app' ORDER BY id DESC LIMIT 50",
                [$user->id],
            ),
            'unread' => (int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'in_app' AND status = 'unread'", [$user->id]),
        ]);
    }

    public function markRead(Request $request): Response
    {
        Database::query(
            "UPDATE notifications SET status = 'read', read_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND status = 'unread'",
            [$request->intParam('id'), Auth::user()->id],
        );
        return Response::noContent();
    }

    public function markAllRead(Request $request): Response
    {
        Database::query(
            "UPDATE notifications SET status = 'read', read_at = UTC_TIMESTAMP() WHERE user_id = ? AND channel = 'in_app' AND status = 'unread'",
            [Auth::user()->id],
        );
        return Response::noContent();
    }

    /** SMS/WhatsApp outbox, so admins can see what would be sent and what failed. */
    public function outbox(Request $request): Response
    {
        $where = ["n.channel <> 'in_app'"];
        $params = [];
        Paginator::filter($request, 'status', 'n.status', $where, $params, ['queued', 'sent', 'failed']);
        return Paginator::run(
            $request,
            'n.id, n.channel, n.event, n.recipient, n.body, n.status, n.created_at, n.sent_at, n.error, c.full_name AS customer_name',
            'notifications n LEFT JOIN customers c ON c.id = n.customer_id',
            implode(' AND ', $where),
            $params,
            ['created_at' => 'n.id'],
            'created_at',
        );
    }
}
