<?php

declare(strict_types=1);

/*
 * KBSAVE setup and updates for cPanel. Extract the package in the website folder and open the site.
 * First time: it writes finserve/api/.env, creates the database tables and the owner account.
 * After an update zip: .htaccess sends you here while the database is behind the code; entering the
 * database password applies the new database updates (data, the security key and uploads are kept).
 * Once installed and up to date, this page only answers 404.
 */

$api = __DIR__ . '/finserve/api';
$envFile = $api . '/.env';
$lock = $api . '/storage/installed.lock';
// Marker for the newest bundled migration; .htaccess redirects here while it is missing (see build-cpanel.sh).
$migrations = glob(__DIR__ . '/finserve/database/migrations/*.sql') ?: [];
sort($migrations);
$schemaMarker = $api . '/storage/schema-' . preg_replace('/[^A-Za-z0-9_.-]/', '', basename((string) end($migrations), '.sql')) . '.done';
$attemptsFile = $api . '/storage/update-attempts.json';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (is_file($lock) && is_file($schemaMarker)) {
    http_response_code(404);
    exit('Already installed and up to date.');
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** @return array<string, string> */
function readEnv(string $file): array
{
    $values = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
            $values[$m[1]] = trim($m[2], " \t\"'");
        }
    }
    return $values;
}

function connect(string $host, string $name, string $user, string $pass): PDO
{
    [$h, $port] = array_pad(explode(':', $host, 2), 2, '3306');
    return new PDO("mysql:host={$h};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Runs the app's own migration and seed scripts against the configured database; returns their output. */
function runSetup(string $api, array $seedOwner): string
{
    ob_start();
    (static function () use ($api) {
        require $api . '/bin/migrate.php';
    })();
    (static function () use ($api, $seedOwner) {
        $argv = ['seed.php'];
        require $api . '/bin/seed.php';
    })();
    return (string) ob_get_clean();
}

function cliPhp(): string
{
    $bin = PHP_BINARY;
    foreach (['lsphp', 'php-cgi', 'php-fpm'] as $sapi) {
        if (str_ends_with($bin, $sapi) && is_file(dirname($bin) . '/php')) {
            return dirname($bin) . '/php';
        }
    }
    return str_ends_with($bin, 'php') ? $bin : '/usr/local/bin/php';
}

// ---- Requirements -----------------------------------------------------------------------------
$checks = [
    'PHP 8.2 or newer (you have ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PHP extension: pdo_mysql' => extension_loaded('pdo_mysql'),
    'PHP extension: bcmath' => extension_loaded('bcmath'),
    'PHP extension: openssl' => extension_loaded('openssl'),
    'PHP extension: mbstring' => extension_loaded('mbstring'),
    'App files present (finserve/api)' => is_file($api . '/src/bootstrap.php'),
    'finserve/api is writable (for .env)' => is_writable($api),
    'finserve/api/storage is writable (uploads)' => is_dir($api . '/storage') && is_writable($api . '/storage'),
];
$ready = !in_array(false, $checks, true);

// ---- Detect an existing install --------------------------------------------------------------
$env = readEnv($envFile);
$mode = 'fresh';
$existingAdmins = 0;
if ($ready && ($env['DB_NAME'] ?? '') !== '' && !str_contains($env['DB_NAME'], 'CHANGE_ME')) {
    try {
        $pdo = connect($env['DB_HOST'] ?? 'localhost', $env['DB_NAME'], $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '');
        $hasUsers = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        $existingAdmins = $hasUsers ? (int) $pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.role_key = 'super_admin'")->fetchColumn() : 0;
        $mode = $existingAdmins > 0 ? 'upgrade' : 'owner';
    } catch (Throwable) {
        $mode = 'fresh';
    }
}
// An installed site never falls back to a fresh install, even if its database is unreachable right now.
$brokenInstall = is_file($lock) && $mode !== 'upgrade';

// ---- Submit ----------------------------------------------------------------------------------
$errors = [];
$done = null;
$in = fn (string $k, string $d = '') => trim((string) ($_POST[$k] ?? $d));

if ($ready && !$brokenInstall && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $db = ['host' => $in('db_host', 'localhost'), 'name' => $in('db_name'), 'user' => $in('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? '')];
    if ($mode !== 'fresh') {
        $db = ['host' => $env['DB_HOST'] ?? 'localhost', 'name' => $env['DB_NAME'], 'user' => $env['DB_USER'] ?? '', 'pass' => $env['DB_PASS'] ?? ''];
    }
    $owner = ['name' => $in('admin_name'), 'email' => strtolower($in('admin_email')), 'password' => (string) ($_POST['admin_password'] ?? '')];
    $company = $in('company', 'KBSAVE');

    if ($mode === 'fresh') {
        // Only this server's own MySQL: an installer must never be pointed at someone else's database.
        if (!preg_match('/^(localhost|127\.0\.0\.1)(:\d{2,5})?$/', $db['host'])) $errors['db_host'] = 'Use localhost (your cPanel MySQL).';
        if ($db['name'] === '') $errors['db_name'] = 'Required.';
        if ($db['user'] === '') $errors['db_user'] = 'Required.';
    }
    if ($mode === 'upgrade') {
        // Only the owner can trigger an update: they confirm with the database password from cPanel.
        $recent = array_filter((array) json_decode((string) @file_get_contents($attemptsFile), true), fn ($t) => is_int($t) && $t > time() - 3600);
        if (count($recent) >= 10) {
            $errors['confirm_pass'] = 'Too many wrong passwords. Try again in an hour.';
        } elseif (!hash_equals((string) ($env['DB_PASS'] ?? ''), (string) ($_POST['confirm_pass'] ?? ''))) {
            $recent[] = time();
            @file_put_contents($attemptsFile, json_encode(array_values($recent)));
            sleep(1);
            $errors['confirm_pass'] = 'That is not the database password in finserve/api/.env.';
        }
    } else {
        if (mb_strlen($owner['name']) < 2) $errors['admin_name'] = 'Enter your name.';
        if (!filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) $errors['admin_email'] = 'Enter a valid email.';
        if (strlen($owner['password']) < 8) $errors['admin_password'] = 'At least 8 characters.';
        elseif ($owner['password'] !== (string) ($_POST['admin_password2'] ?? '')) $errors['admin_password2'] = 'Passwords do not match.';
        if (mb_strlen($company) < 2) $errors['company'] = 'Enter the company name.';
    }

    if (!$errors && $mode === 'fresh') {
        try {
            connect($db['host'], $db['name'], $db['user'], $db['pass']);
        } catch (Throwable $e) {
            $errors['db'] = 'Could not connect to the database: ' . $e->getMessage() . ' — check the name, user and password, and that the user was added to the database with ALL PRIVILEGES.';
        }
    }

    if (!$errors) {
        try {
            if ($mode === 'fresh') {
                $host = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $key = ($env['APP_KEY'] ?? '') !== '' ? $env['APP_KEY'] : base64_encode(random_bytes(32));
                $q = fn (string $v) => preg_match('/^[A-Za-z0-9_.@\-]*$/', $v) ? $v : '"' . addcslashes($v, '"\\') . '"';
                $contents = implode("\n", [
                    'APP_ENV=production',
                    'APP_DEBUG=false',
                    '',
                    'DB_HOST=' . explode(':', $db['host'])[0],
                    'DB_PORT=' . (explode(':', $db['host'])[1] ?? '3306'),
                    'DB_NAME=' . $q($db['name']),
                    'DB_USER=' . $q($db['user']),
                    'DB_PASS=' . $q($db['pass']),
                    '',
                    'TOKEN_TTL_MINUTES=480',
                    'LOGIN_MAX_ATTEMPTS=5',
                    'LOGIN_WINDOW_MINUTES=15',
                    '',
                    '# Encrypts two-factor secrets. Keep a copy of this file somewhere safe; never change the key after go-live.',
                    'APP_KEY=' . $key,
                    '',
                    'SMS_DRIVER=log',
                    'CORS_ORIGINS=https://localhost,http://localhost,capacitor://localhost,https://' . $host,
                    '',
                ]);
                if (file_put_contents($envFile, $contents) === false) {
                    throw new RuntimeException('Could not write finserve/api/.env — check folder permissions.');
                }
                @chmod($envFile, 0600);
            }

            runSetup($api, $mode === 'upgrade' ? [] : $owner);

            if ($mode !== 'upgrade') {
                \App\Core\Database::query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('company_name', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$company],
                );
            } else {
                // Rebrand: replace the old default name; a name the owner chose is kept.
                \App\Core\Database::query("UPDATE settings SET setting_value = ? WHERE setting_key = 'company_name' AND setting_value IN ('FinServe', '')", ['KBSAVE']);
            }
            if (!is_file($lock) && file_put_contents($lock, 'Installed ' . gmdate('c') . "\n") === false) {
                throw new RuntimeException('Could not write finserve/api/storage/installed.lock — check folder permissions.');
            }
            file_put_contents($schemaMarker, 'Database updated ' . gmdate('c') . "\n");
            $done = ['mode' => $mode, 'email' => $owner['email']];
        } catch (Throwable $e) {
            $errors['db'] = 'Setup stopped: ' . $e->getMessage();
        }
    }
}

$php = cliPhp();
$daily = "{$php} {$api}/bin/daily.php >> " . dirname($api) . '/daily.log 2>&1';
$notify = "{$php} {$api}/bin/send-notifications.php >/dev/null 2>&1";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KBSAVE setup</title>
<style>
  :root { --bg: #faf9f5; --card: #fff; --text: #1f1e1d; --muted: #6b6a66; --line: #e6e3da; --accent: #c96442; --ok: #2f7d4f; --bad: #b3261e; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 640px; margin: 40px auto; padding: 0 16px; }
  h1 { font-family: Georgia, serif; font-weight: 500; font-size: 30px; margin: 0 0 4px; }
  h2 { font-size: 16px; margin: 0 0 12px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin: 16px 0; }
  .muted { color: var(--muted); font-size: 13.5px; }
  label { display: block; margin: 12px 0 0; font-weight: 600; font-size: 13.5px; }
  input { width: 100%; margin-top: 4px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
  @media (max-width: 520px) { .grid { grid-template-columns: 1fr; } }
  .err { color: var(--bad); font-size: 13px; }
  .banner { padding: 10px 12px; border-radius: 8px; background: #fbe5e3; color: var(--bad); margin-bottom: 8px; }
  .good { background: #e3f1e7; color: var(--ok); }
  ul.checks { list-style: none; padding: 0; margin: 0; }
  ul.checks li { padding: 4px 0; }
  button, .btn { display: inline-block; margin-top: 18px; padding: 11px 18px; background: var(--accent); color: #fff; border: 0; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
  code { display: block; white-space: pre-wrap; word-break: break-all; background: #f3f1ea; padding: 8px 10px; border-radius: 6px; font-size: 12.5px; margin-top: 4px; }
</style>
</head>
<body>
<main>
  <h1>KBSAVE setup</h1>
  <p class="muted">One-time setup. This page switches itself off when it finishes.</p>

<?php if ($done): ?>
  <div class="card">
    <div class="banner good"><?= $done['mode'] === 'upgrade' ? 'Updated. All your existing data was kept.' : 'Setup complete.' ?></div>
    <?php if ($done['mode'] !== 'upgrade'): ?>
      <p>Sign in with <strong><?= h($done['email']) ?></strong> and the password you just chose.</p>
    <?php endif; ?>
    <p class="muted">This setup page is now switched off. You may also delete <b>kbsave-setup.php</b> and the uploaded zip in File Manager.</p>
    <a class="btn" href="/login">Open KBSAVE</a>
  </div>
  <div class="card">
    <h2>Last step: cPanel → Cron Jobs (add these two)</h2>
    <p class="muted">Daily at 00:15 — late fees, overdue/NPA status, savings interest, reminders:</p>
    <code>15 0 * * *  <?= h($daily) ?></code>
    <p class="muted">Every 5 minutes — sends queued SMS once an SMS provider is connected:</p>
    <code>*/5 * * * *  <?= h($notify) ?></code>
    <p class="muted">Backups: turn on cPanel → <b>Backup</b> / JetBackup, or see DEPLOY-GUIDE.md for a nightly backup cron.</p>
    <p class="muted">If the php path does not work in cron, try <b>/usr/local/bin/php</b>.</p>
  </div>
<?php elseif ($brokenInstall): ?>
  <div class="card">
    <h2>Cannot reach your database</h2>
    <p>KBSAVE is installed, but the database in <b>finserve/api/.env</b> does not answer. Check in cPanel → MySQL® Databases that the database and user still exist and the user has ALL PRIVILEGES, and that DB_NAME / DB_USER / DB_PASS in that file are right. Then reload this page.</p>
  </div>
<?php elseif (!$ready): ?>
  <div class="card">
    <h2>Fix these in cPanel first</h2>
    <ul class="checks">
      <?php foreach ($checks as $label => $ok): ?><li><?= $ok ? '✅' : '❌' ?> <?= h($label) ?></li><?php endforeach; ?>
    </ul>
    <p class="muted">PHP version and extensions: cPanel → <b>Select PHP Version</b> (tick bcmath, pdo_mysql, mbstring). Then reload this page.</p>
  </div>
<?php else: ?>
  <form method="post" class="card" autocomplete="off">
    <?php if (isset($errors['db'])): ?><div class="banner"><?= h($errors['db']) ?></div><?php endif; ?>

    <?php if ($mode === 'upgrade'): ?>
      <h2><?= is_file($lock) ? 'Database update needed' : 'Existing KBSAVE found' ?></h2>
      <p>The new version needs a few database updates (new tables, columns and permissions). <b>Customers, loans, payments, settings and logins are kept.</b></p>
      <label>Database password</label>
      <input name="confirm_pass" type="password" required autocomplete="off">
      <?php if (isset($errors['confirm_pass'])): ?><div class="err"><?= h($errors['confirm_pass']) ?></div><?php else: ?><div class="muted">The password of database user <b><?= h($env['DB_USER'] ?? '') ?></b> (cPanel → MySQL® Databases). This proves you own the site.</div><?php endif; ?>
      <button type="submit">Update now</button>
    <?php else: ?>
      <?php if ($mode === 'fresh'): ?>
        <h2>1. Database</h2>
        <p class="muted">Create it in cPanel → <b>MySQL® Databases</b>: a database, a user, then "Add user to database" with ALL PRIVILEGES.</p>
        <div class="grid">
          <div><label>Database name</label><input name="db_name" value="<?= h($in('db_name')) ?>" placeholder="cpaneluser_finserve" required><?php if (isset($errors['db_name'])): ?><div class="err"><?= h($errors['db_name']) ?></div><?php endif; ?></div>
          <div><label>Database user</label><input name="db_user" value="<?= h($in('db_user')) ?>" placeholder="cpaneluser_finserve" required><?php if (isset($errors['db_user'])): ?><div class="err"><?= h($errors['db_user']) ?></div><?php endif; ?></div>
          <div><label>Database password</label><input name="db_pass" type="password" required></div>
          <div><label>Database host</label><input name="db_host" value="<?= h($in('db_host', 'localhost')) ?>"><?php if (isset($errors['db_host'])): ?><div class="err"><?= h($errors['db_host']) ?></div><?php endif; ?></div>
        </div>
      <?php else: ?>
        <div class="banner good">Database connected (<?= h($env['DB_NAME'] ?? '') ?>).</div>
      <?php endif; ?>

      <h2 style="margin-top:20px"><?= $mode === 'fresh' ? '2.' : '' ?> Your owner account (Super Admin)</h2>
      <label>Company name</label><input name="company" value="<?= h($in('company', 'KBSAVE')) ?>"><?php if (isset($errors['company'])): ?><div class="err"><?= h($errors['company']) ?></div><?php endif; ?>
      <div class="grid">
        <div><label>Your name</label><input name="admin_name" value="<?= h($in('admin_name')) ?>" required><?php if (isset($errors['admin_name'])): ?><div class="err"><?= h($errors['admin_name']) ?></div><?php endif; ?></div>
        <div><label>Your email (login)</label><input name="admin_email" type="email" value="<?= h($in('admin_email')) ?>" required><?php if (isset($errors['admin_email'])): ?><div class="err"><?= h($errors['admin_email']) ?></div><?php endif; ?></div>
        <div><label>Password</label><input name="admin_password" type="password" minlength="8" required><?php if (isset($errors['admin_password'])): ?><div class="err"><?= h($errors['admin_password']) ?></div><?php endif; ?></div>
        <div><label>Password again</label><input name="admin_password2" type="password" minlength="8" required><?php if (isset($errors['admin_password2'])): ?><div class="err"><?= h($errors['admin_password2']) ?></div><?php endif; ?></div>
      </div>
      <button type="submit">Install KBSAVE</button>
      <p class="muted">The database starts clean: your account, a Head Office branch, default loan and savings plans and settings. No demo data.</p>
    <?php endif; ?>
  </form>
<?php endif; ?>
</main>
</body>
</html>
