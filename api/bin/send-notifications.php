<?php

declare(strict_types=1);

// Delivers queued SMS/WhatsApp/email notifications. Run every few minutes from cron.
//   SMS_DRIVER=none (default)  leave messages queued; nothing leaves the system
//   SMS_DRIVER=log             write to storage/logs/outbox.log and mark sent (development)
// To go live, add a driver for the chosen provider here (signature-verified, with retries).

use App\Core\Database;
use App\Core\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$driver = Env::get('SMS_DRIVER', 'none');
if ($driver === 'none') {
    echo "SMS_DRIVER=none: outbox left queued.\n";
    exit(0);
}

$logDir = dirname(__DIR__) . '/storage/logs';
is_dir($logDir) || mkdir($logDir, 0750, true);
$sent = 0;
foreach (Database::all("SELECT id, channel, recipient, body FROM notifications WHERE channel <> 'in_app' AND status = 'queued' ORDER BY id LIMIT 200") as $n) {
    try {
        match ($driver) {
            'log' => file_put_contents("{$logDir}/outbox.log", sprintf("[%s] %s to %s: %s\n", gmdate('c'), $n['channel'], $n['recipient'], $n['body']), FILE_APPEND | LOCK_EX),
            default => throw new RuntimeException("Unknown SMS_DRIVER '{$driver}'"),
        };
        Database::query("UPDATE notifications SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = ?", [$n['id']]);
        $sent++;
    } catch (Throwable $e) {
        Database::query("UPDATE notifications SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $n['id']]);
    }
}
echo "Delivered {$sent} message(s) via {$driver}.\n";
