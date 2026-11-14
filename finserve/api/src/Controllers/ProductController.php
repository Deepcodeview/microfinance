<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;

/**
 * Versioned, admin-configurable products (loan and deposit). Terms are never edited in place:
 * each change is a new immutable version, and accounts keep the version they were opened on.
 */
abstract class ProductController
{
    /** e.g. "loan_products" */
    abstract protected function table(): string;

    abstract protected function versionTable(): string;

    /** Audit entity name, e.g. "loan_product" */
    abstract protected function entity(): string;

    /** @return array<string, mixed> */
    abstract protected function validateTerms(mixed $input): array;

    /** @return array<string, mixed> */
    abstract protected function defaultTerms(): array;

    /** @return array<string, list<string>> */
    abstract protected function termOptions(): array;

    /** Accounts opened on this kind of product, e.g. "loans". */
    abstract protected function accountsTable(): string;

    public function index(Request $request): Response
    {
        $rows = Database::all(
            "SELECT p.id, p.code, p.name, p.description, p.status, p.updated_at,
                    v.id AS version_id, v.version, v.terms_json, v.effective_from,
                    (SELECT COUNT(*) FROM {$this->accountsTable()} a WHERE a.product_id = p.id) AS accounts_count
               FROM {$this->table()} p
               LEFT JOIN {$this->versionTable()} v ON v.id = p.current_version_id
              ORDER BY p.status, p.name",
        );
        return Response::json(array_map([$this, 'present'], $rows));
    }

    public function show(Request $request): Response
    {
        $product = $this->find($request->intParam('id'));
        $result = $this->present($product);
        $result['versions'] = array_map(
            fn (array $v) => ['terms' => json_decode($v['terms_json'], true)] + array_diff_key($v, ['terms_json' => 1]),
            Database::all(
                "SELECT v.id, v.version, v.terms_json, v.effective_from, v.change_reason, u.name AS created_by_name, v.created_at
                   FROM {$this->versionTable()} v
                   JOIN users u ON u.id = v.created_by
                  WHERE v.product_id = ?
                  ORDER BY v.version DESC",
                [$product['id']],
            ),
        );
        return Response::json($result);
    }

    public function defaults(Request $request): Response
    {
        return Response::json(['terms' => $this->defaultTerms(), 'options' => $this->termOptions()]);
    }

    public function store(Request $request): Response
    {
        $v = new Validator($request->all());
        $code = $v->pattern('code', '/^[A-Z0-9_-]{2,20}$/', 'Use 2–20 capital letters, digits, - or _.', true, 20);
        $name = $v->string('name', true, 150, 3);
        $description = $v->string('description', false, 500);
        $v->validate();
        $terms = $this->validateTerms($request->input('terms'));

        if (Database::value("SELECT id FROM {$this->table()} WHERE code = ?", [$code]) !== null) {
            throw HttpException::validation(['code' => 'This product code is already in use.']);
        }

        $userId = Auth::user()->id;
        $id = Database::transaction(function () use ($code, $name, $description, $terms, $userId, $request) {
            $productId = Database::insert($this->table(), [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'created_by' => $userId,
            ]);
            $versionId = $this->insertVersion($productId, 1, $terms, 'Initial terms', $userId);
            Database::query("UPDATE {$this->table()} SET current_version_id = ? WHERE id = ?", [$versionId, $productId]);
            Audit::log($request, $userId, $this->entity() . '.created', $this->entity(), $productId, null, compact('code', 'name', 'terms'));
            return $productId;
        });

        $request->params['id'] = (string) $id;
        return new Response(201, $this->show($request)->body);
    }

    public function addVersion(Request $request): Response
    {
        $productId = $request->intParam('id');
        $v = new Validator($request->all());
        $reason = $v->string('change_reason', true, 500, 5);
        $v->validate();
        $terms = $this->validateTerms($request->input('terms'));

        $userId = Auth::user()->id;
        Database::transaction(function () use ($productId, $terms, $reason, $userId, $request) {
            $product = Database::one("SELECT id, current_version_id FROM {$this->table()} WHERE id = ? FOR UPDATE", [$productId])
                ?? throw HttpException::notFound('Product not found.');
            $current = Database::one("SELECT version, terms_json FROM {$this->versionTable()} WHERE id = ?", [$product['current_version_id']]);
            $currentTerms = json_decode($current['terms_json'], true);
            if ($currentTerms == $terms) {
                throw HttpException::validation(['terms' => 'No term changes compared to the current version.']);
            }

            $next = (int) $current['version'] + 1;
            $versionId = $this->insertVersion($productId, $next, $terms, $reason, $userId);
            Database::query("UPDATE {$this->table()} SET current_version_id = ? WHERE id = ?", [$versionId, $productId]);

            $before = $after = [];
            foreach ($terms as $key => $value) {
                if (($currentTerms[$key] ?? null) != $value) {
                    $before[$key] = $currentTerms[$key] ?? null;
                    $after[$key] = $value;
                }
            }
            Audit::log($request, $userId, $this->entity() . '.terms_changed', $this->entity(), $productId,
                $before + ['version' => (int) $current['version']], $after + ['version' => $next], $reason);
        });

        return $this->show($request);
    }

    public function update(Request $request): Response
    {
        $product = $this->find($request->intParam('id'));
        $input = $request->all();
        $v = new Validator($input);
        $data = [];
        if (array_key_exists('name', $input)) $data['name'] = $v->string('name', true, 150, 3);
        if (array_key_exists('description', $input)) $data['description'] = $v->string('description', false, 500);
        if (array_key_exists('status', $input)) $data['status'] = $v->in('status', ['active', 'inactive']);
        $v->validate();

        [$old, $new] = Audit::diff($product, $data);
        if ($new !== []) {
            Database::transaction(function () use ($new, $old, $product, $request) {
                $sets = implode(', ', array_map(fn ($c) => "`{$c}` = :{$c}", array_keys($new)));
                Database::query("UPDATE {$this->table()} SET {$sets} WHERE id = :id", $new + ['id' => $product['id']]);
                Audit::log($request, Auth::user()->id, $this->entity() . '.updated', $this->entity(), (int) $product['id'], $old, $new, $request->input('reason'));
            });
        }
        return $this->show($request);
    }

    /**
     * Removes a plan that no account was ever opened on (e.g. created by mistake). A plan in use can only be
     * deactivated: its accounts, schedules and ledger entries depend on the terms it carried.
     */
    public function destroy(Request $request): Response
    {
        $product = $this->find($request->intParam('id'));
        Database::transaction(function () use ($product, $request) {
            Database::one("SELECT id FROM {$this->table()} WHERE id = ? FOR UPDATE", [$product['id']]);
            $used = (int) Database::value("SELECT COUNT(*) FROM {$this->accountsTable()} WHERE product_id = ?", [$product['id']]);
            if ($used > 0) {
                throw HttpException::conflict("{$used} account(s) were opened on this plan, so it cannot be deleted. Deactivate it instead: it stops appearing for new accounts and existing ones keep working.");
            }
            // The product points at its current version and versions point back at the product; unlink first.
            Database::query("UPDATE {$this->table()} SET current_version_id = NULL WHERE id = ?", [$product['id']]);
            Database::query("DELETE FROM {$this->versionTable()} WHERE product_id = ?", [$product['id']]);
            Database::query("DELETE FROM {$this->table()} WHERE id = ?", [$product['id']]);
            Audit::log($request, Auth::user()->id, $this->entity() . '.deleted', $this->entity(), (int) $product['id'],
                ['code' => $product['code'], 'name' => $product['name'], 'terms' => json_decode($product['terms_json'] ?? 'null', true)], null, $request->input('reason'));
        });
        return Response::noContent();
    }

    private function insertVersion(int $productId, int $version, array $terms, string $reason, int $userId): int
    {
        return Database::insert($this->versionTable(), [
            'product_id' => $productId,
            'version' => $version,
            'terms_json' => json_encode($terms),
            'effective_from' => gmdate('Y-m-d H:i:s'),
            'change_reason' => $reason,
            'created_by' => $userId,
        ]);
    }

    protected function find(int $id): array
    {
        return Database::one(
            "SELECT p.id, p.code, p.name, p.description, p.status, p.updated_at,
                    v.id AS version_id, v.version, v.terms_json, v.effective_from,
                    (SELECT COUNT(*) FROM {$this->accountsTable()} a WHERE a.product_id = p.id) AS accounts_count
               FROM {$this->table()} p
               LEFT JOIN {$this->versionTable()} v ON v.id = p.current_version_id
              WHERE p.id = ?",
            [$id],
        ) ?? throw HttpException::notFound('Product not found.');
    }

    private function present(array $row): array
    {
        $row['terms'] = json_decode($row['terms_json'] ?? 'null', true);
        unset($row['terms_json']);
        return $row;
    }
}
