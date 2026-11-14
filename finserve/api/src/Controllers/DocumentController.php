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
use App\Services\CustomerScope;
use App\Services\FileType;
use App\Services\Notifier;

/**
 * KYC documents. Files live outside the web root under random names and are only served
 * through download(), which checks scope and logs the access.
 */
final class DocumentController
{
    public const TYPES = ['aadhaar', 'pan', 'voter_id', 'passport', 'driving_licence', 'photo', 'address_proof', 'income_proof', 'other'];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    public static function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/documents';
    }

    public function index(Request $request): Response
    {
        $customer = $this->customer($request->intParam('id'));
        return Response::json(Database::all(
            'SELECT d.id, d.doc_type, d.doc_number_last4, d.original_name, d.mime_type, d.size_bytes, d.status, d.review_note,
                    d.uploaded_at, d.reviewed_at, u.name AS uploaded_by_name, r.name AS reviewed_by_name
               FROM customer_documents d JOIN users u ON u.id = d.uploaded_by LEFT JOIN users r ON r.id = d.reviewed_by
              WHERE d.customer_id = ? ORDER BY d.id DESC',
            [$customer['id']],
        ));
    }

    public function store(Request $request): Response
    {
        $user = Auth::user();
        $customer = $this->customer($request->intParam('id'));
        // A body over the host's post_max_size arrives empty: say so instead of "choose a type".
        if (FileType::bodyTooLarge()) {
            throw HttpException::validation(['file' => 'This file is too large for the server (limit ' . FileType::serverLimit() . '). Take the photo again or choose a smaller file.']);
        }
        $v = new Validator($request->all());
        $type = $v->in('doc_type', self::TYPES);
        $last4 = $v->pattern('doc_number_last4', '/^[A-Za-z0-9]{4}$/', 'Enter the last 4 characters only.', false, 4);
        $v->validate();

        $file = $request->file('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $tooBig = $file !== null && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $message = match (true) {
                $tooBig => 'This file is too large for the server (limit ' . FileType::serverLimit() . '). Take the photo again or choose a smaller file.',
                $file !== null && in_array($file['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE], true) => 'The server could not save the upload (temporary folder). Ask the administrator to check PHP upload settings in cPanel.',
                $file !== null && $file['error'] === UPLOAD_ERR_PARTIAL => 'The upload was cut off. Check the connection and try again.',
                default => 'Choose a file to upload.',
            };
            throw HttpException::validation(['file' => $message]);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw HttpException::validation(['file' => 'File is larger than 5 MB.']);
        }
        // Trust the bytes, not the browser-supplied type or extension.
        $mime = FileType::sniffFile($file['tmp_name']);
        if (!isset(self::MIME[$mime])) {
            throw HttpException::validation(['file' => 'Only JPG, PNG or PDF files are accepted.']);
        }

        $dir = self::storageDir();
        if ((!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) || !is_writable($dir)) {
            throw new HttpException(500, 'STORAGE_NOT_WRITABLE', 'The server cannot save documents: in cPanel File Manager set finserve/api/storage/documents to permission 755.');
        }
        $stored = bin2hex(random_bytes(32));
        if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$stored}")) {
            throw new HttpException(500, 'STORAGE_NOT_WRITABLE', 'The server could not save the file. In cPanel File Manager set finserve/api/storage/documents to permission 755.');
        }

        $id = Database::transaction(function () use ($customer, $type, $last4, $file, $mime, $stored, $dir, $user, $request) {
            $id = Database::insert('customer_documents', [
                'customer_id' => $customer['id'],
                'doc_type' => $type,
                'doc_number_last4' => $last4,
                'original_name' => mb_substr(basename($file['name']), 0, 255),
                'stored_name' => $stored,
                'mime_type' => $mime,
                'size_bytes' => $file['size'],
                'sha256' => hash_file('sha256', "{$dir}/{$stored}"),
                'uploaded_by' => $user->id,
            ]);
            Audit::log($request, $user->id, 'document.uploaded', 'customer', (int) $customer['id'], null, ['document_id' => $id, 'type' => $type]);
            if ($user->customerScope() === 'own') {
                Notifier::role('branch_manager', (int) $customer['branch_id'], 'document.uploaded', 'New KYC document',
                    "{$customer['full_name']} uploaded a " . str_replace('_', ' ', $type) . ' for review.', "/customers/{$customer['id']}");
            }
            return $id;
        });
        return Response::json(['id' => $id], 201);
    }

    public function download(Request $request): Response
    {
        $doc = $this->documentInScope($request->intParam('id'));
        $path = self::storageDir() . '/' . $doc['stored_name'];
        if (!is_file($path) || hash_file('sha256', $path) !== $doc['sha256']) {
            throw HttpException::notFound('The stored file is missing or has been altered.');
        }
        // Identity documents are sensitive: every view is audited.
        Audit::log($request, Auth::user()->id, 'document.viewed', 'customer', (int) $doc['customer_id'], null, ['document_id' => (int) $doc['id']]);
        $ext = self::MIME[$doc['mime_type']] ?? 'bin';
        return Response::file($path, $doc['mime_type'], "{$doc['doc_type']}-{$doc['id']}.{$ext}");
    }

    public function review(Request $request): Response
    {
        $user = Auth::user();
        $doc = $this->documentInScope($request->intParam('id'));
        $v = new Validator($request->all());
        $status = $v->in('status', ['verified', 'rejected']);
        $note = $v->string('note', $status === 'rejected', 500, 3);
        $v->validate();
        if ((int) $doc['uploaded_by'] === $user->id && !$user->maySelfApprove()) {
            throw HttpException::forbidden('Someone other than the uploader must review a document. Ask another staff member, or a Super Admin can allow single-owner approval in Settings → Security.');
        }
        Database::transaction(function () use ($doc, $status, $note, $user, $request) {
            Database::query('UPDATE customer_documents SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?',
                [$status, $note, $user->id, $doc['id']]);
            Audit::log($request, $user->id, 'document.' . $status, 'customer', (int) $doc['customer_id'], ['status' => $doc['status']], ['status' => $status, 'document_id' => (int) $doc['id']], $note);
            if ($status === 'rejected') {
                Notifier::customer((int) $doc['customer_id'], 'document.rejected', 'Document needs attention',
                    'Your ' . str_replace('_', ' ', $doc['doc_type']) . " was not accepted: {$note}. Please upload a clearer copy.", '/');
            }
        });
        return Response::json(['status' => $status]);
    }

    private function customer(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one("SELECT c.* FROM customers c WHERE c.id = ? AND {$scope}", array_merge([$id], $params))
            ?? throw HttpException::notFound('Customer not found.');
    }

    private function documentInScope(int $id): array
    {
        [$scope, $params] = CustomerScope::where(Auth::user());
        return Database::one(
            "SELECT d.* FROM customer_documents d JOIN customers c ON c.id = d.customer_id WHERE d.id = ? AND {$scope}",
            array_merge([$id], $params),
        ) ?? throw HttpException::notFound('Document not found.');
    }
}
