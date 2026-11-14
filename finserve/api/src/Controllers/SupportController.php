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
use App\Services\Audit;
use App\Services\CustomerScope;
use App\Services\Notifier;

/** Customer service requests and complaints (SRS FR-001 complaint history, §7.3 support flow). */
final class SupportController
{
    public const CATEGORIES = ['payment_issue', 'statement', 'loan_query', 'deposit_query', 'profile_update', 'complaint', 'other'];

    public function index(Request $request): Response
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        $where = [$scope];
        Paginator::filter($request, 'status', 's.status', $where, $params, ['open', 'in_progress', 'resolved', 'closed']);
        Paginator::filter($request, 'category', 's.category', $where, $params, self::CATEGORIES);
        Paginator::filter($request, 'customer_id', 's.customer_id', $where, $params);
        if (($request->query['mine'] ?? '') === '1') {
            $where[] = 's.assigned_to = ?';
            $params[] = Auth::user()->id;
        }
        return Paginator::run(
            $request,
            's.id, s.request_number, s.category, s.subject, s.status, s.priority, s.created_at, s.updated_at,
             c.full_name AS customer_name, c.customer_number, a.name AS assigned_to_name,
             (SELECT COUNT(*) FROM service_request_messages m WHERE m.request_id = s.id) AS message_count',
            'service_requests s JOIN customers c ON c.id = s.customer_id LEFT JOIN users a ON a.id = s.assigned_to',
            implode(' AND ', $where),
            $params,
            ['updated_at' => 's.updated_at', 'created_at' => 's.id'],
            'updated_at',
        );
    }

    public function show(Request $request): Response
    {
        $sr = $this->find($request->intParam('id'));
        $sr['messages'] = Database::all(
            'SELECT m.id, m.body, m.is_staff, m.created_at, u.name AS author_name
               FROM service_request_messages m JOIN users u ON u.id = m.author_id WHERE m.request_id = ? ORDER BY m.id',
            [$sr['id']],
        );
        return Response::json($sr);
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $category = $v->in('category', self::CATEGORIES);
        $subject = $v->string('subject', true, 150, 4);
        $body = $v->string('message', true, 2000, 5);
        $customerId = $user->customerScope() === 'own'
            ? (int) Database::value('SELECT id FROM customers WHERE user_id = ?', [$user->id])
            : $v->int('customer_id', 1, PHP_INT_MAX);
        $v->validate();

        [$scope, $params] = CustomerScope::where($user);
        $customer = Database::one("SELECT c.id, c.branch_id, c.full_name FROM customers c WHERE c.id = ? AND {$scope}", array_merge([$customerId], $params))
            ?? throw HttpException::validation(['customer_id' => 'Customer not found.']);

        $id = Database::transaction(function () use ($customer, $category, $subject, $body, $user, $request) {
            $id = Database::insert('service_requests', [
                'request_number' => Database::nextNumber('service_request'),
                'customer_id' => $customer['id'],
                'branch_id' => $customer['branch_id'],
                'category' => $category,
                'subject' => $subject,
                'priority' => $category === 'complaint' ? 'high' : 'normal',
                'created_by' => $user->id,
            ]);
            Database::insert('service_request_messages', [
                'request_id' => $id, 'author_id' => $user->id, 'is_staff' => $user->customerScope() === 'own' ? 0 : 1, 'body' => $body,
            ]);
            Audit::log($request, $user->id, 'support.created', 'service_request', $id, null, compact('category', 'subject'));
            Notifier::role('branch_manager', (int) $customer['branch_id'], 'support.created',
                ($category === 'complaint' ? 'New complaint: ' : 'New request: ') . $subject, "From {$customer['full_name']}", "/support/{$id}", $user->id);
            return $id;
        });
        $request->params['id'] = (string) $id;
        return new Response(201, $this->show($request)->body);
    }

    public function reply(Request $request): Response
    {
        $user = Auth::user();
        $sr = $this->find($request->intParam('id'));
        $v = new Validator($request->all());
        $body = $v->string('message', true, 2000, 1);
        $v->validate();
        if ($sr['status'] === 'closed') {
            throw HttpException::conflict('This request is closed. Please open a new one.');
        }
        $isStaff = $user->customerScope() !== 'own';
        if ($isStaff && !$user->can('support.manage')) {
            throw HttpException::forbidden();
        }

        Database::transaction(function () use ($sr, $user, $body, $isStaff) {
            Database::insert('service_request_messages', ['request_id' => $sr['id'], 'author_id' => $user->id, 'is_staff' => $isStaff ? 1 : 0, 'body' => $body]);
            $status = $isStaff && $sr['status'] === 'open' ? 'in_progress' : ($sr['status'] === 'resolved' && !$isStaff ? 'open' : $sr['status']);
            Database::query('UPDATE service_requests SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $sr['id']]);
            if ($isStaff) {
                Notifier::customer((int) $sr['customer_id'], 'support.reply', "Reply on {$sr['request_number']}", mb_substr($body, 0, 200), "/support/{$sr['id']}");
            } elseif ($sr['assigned_to'] !== null) {
                Notifier::user((int) $sr['assigned_to'], 'support.reply', "Customer replied on {$sr['request_number']}", mb_substr($body, 0, 200), "/support/{$sr['id']}");
            }
        });
        return $this->show($request);
    }

    public function update(Request $request): Response
    {
        $user = Auth::user();
        $sr = $this->find($request->intParam('id'));
        $input = $request->all();
        $v = new Validator($input);
        $data = [];
        if (array_key_exists('status', $input)) $data['status'] = $v->in('status', ['open', 'in_progress', 'resolved', 'closed']);
        if (array_key_exists('priority', $input)) $data['priority'] = $v->in('priority', ['normal', 'high']);
        if (array_key_exists('assigned_to', $input)) $data['assigned_to'] = $v->int('assigned_to', 1, PHP_INT_MAX, false);
        $v->validate();
        if (isset($data['assigned_to']) && Database::value("SELECT id FROM users WHERE id = ? AND status = 'active'", [$data['assigned_to']]) === null) {
            throw HttpException::validation(['assigned_to' => 'User not found.']);
        }

        Database::transaction(function () use ($sr, $data, $user, $request) {
            [$old, $new] = Audit::diff($sr, $data);
            if ($new === []) {
                return;
            }
            $sets = implode(', ', array_map(fn ($c) => "`{$c}` = :{$c}", array_keys($new)));
            $resolved = in_array($new['status'] ?? '', ['resolved', 'closed'], true) ? ', resolved_at = UTC_TIMESTAMP()' : '';
            Database::query("UPDATE service_requests SET {$sets}{$resolved} WHERE id = :id", $new + ['id' => $sr['id']]);
            Audit::log($request, $user->id, 'support.updated', 'service_request', (int) $sr['id'], $old, $new);
            if (($new['status'] ?? '') === 'resolved') {
                Notifier::customer((int) $sr['customer_id'], 'support.resolved', "{$sr['request_number']} resolved", "Your request \"{$sr['subject']}\" has been marked resolved.", "/support/{$sr['id']}");
            }
            if (isset($new['assigned_to']) && (int) $new['assigned_to'] !== $user->id) {
                Notifier::user((int) $new['assigned_to'], 'support.assigned', "Assigned: {$sr['request_number']}", $sr['subject'], "/support/{$sr['id']}");
            }
        });
        return $this->show($request);
    }

    private function find(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one(
            "SELECT s.*, c.full_name AS customer_name, c.customer_number, c.phone AS customer_phone, a.name AS assigned_to_name, cb.name AS created_by_name
               FROM service_requests s JOIN customers c ON c.id = s.customer_id
               LEFT JOIN users a ON a.id = s.assigned_to JOIN users cb ON cb.id = s.created_by
              WHERE s.id = ? AND {$scope}",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Request not found.');
    }
}
