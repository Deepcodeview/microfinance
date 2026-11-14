<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Settings;

/**
 * Two session transports, one token store:
 *  - Browser: HttpOnly SameSite=Strict cookie (JS never sees the token). State-changing requests must also
 *    send "X-Requested-With: FinServe", which a cross-site form cannot set — CSRF protection.
 *  - API clients / tests: "Authorization: Bearer <token>".
 */
final class Auth
{
    public const COOKIE = 'fs_session';
    private const PRIVILEGED = ['super_admin', 'admin', 'finance'];

    private static ?AuthUser $user = null;

    public static function user(): AuthUser
    {
        if (self::$user === null) {
            throw HttpException::unauthenticated();
        }
        return self::$user;
    }

    public static function authenticate(Request $request): AuthUser
    {
        $token = $request->bearerToken();
        if ($token === null) {
            $cookie = $_COOKIE[self::COOKIE] ?? '';
            if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
                $token = $cookie;
                if (!in_array($request->method, ['GET', 'HEAD'], true) && $request->header('x-requested-with') !== 'FinServe') {
                    throw HttpException::forbidden('Missing CSRF header.');
                }
            }
        }
        if ($token === null) {
            throw HttpException::unauthenticated();
        }

        $row = Database::one(
            'SELECT t.id AS token_id, u.id
               FROM api_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ?
                AND t.revoked_at IS NULL
                AND t.expires_at > UTC_TIMESTAMP()
                AND u.status = \'active\'',
            [hash('sha256', $token)],
        );
        if ($row === null) {
            throw HttpException::unauthenticated('Your session has expired. Please sign in again.');
        }

        Database::query('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['token_id']]);

        self::$user = self::load((int) $row['id'], (int) $row['token_id']);
        return self::$user;
    }

    public static function load(int $userId, int $tokenId): AuthUser
    {
        $user = Database::one('SELECT id, name, email, branch_id, mfa_enabled_at FROM users WHERE id = ?', [$userId]);
        $roles = array_column(Database::all(
            'SELECT r.role_key FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.role_key',
            [$userId],
        ), 'role_key');
        $permissions = array_column(Database::all(
            'SELECT DISTINCT p.permission_key
               FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
              ORDER BY p.permission_key',
            [$userId],
        ), 'permission_key');

        $mfaEnabled = $user['mfa_enabled_at'] !== null;
        $mustSetup = !$mfaEnabled
            && Settings::get('mfa_required_for_privileged') === '1'
            && array_intersect($roles, self::PRIVILEGED) !== [];

        return new AuthUser(
            (int) $user['id'],
            $user['name'],
            $user['email'],
            $user['branch_id'] === null ? null : (int) $user['branch_id'],
            $roles,
            $permissions,
            $tokenId,
            $mfaEnabled,
            $mustSetup,
        );
    }

    /**
     * Creates a session. In cookie mode the token is set as an HttpOnly cookie and not returned;
     * otherwise the raw token is returned once (only its hash is stored).
     */
    public static function issueToken(int $userId, Request $request): array
    {
        $token = bin2hex(random_bytes(32));
        $ttl = Env::int('TOKEN_TTL_MINUTES', 480);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl * 60);

        $tokenId = Database::insert('api_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'ip' => $request->ip,
            'user_agent' => $request->userAgent(),
        ]);
        self::$user = self::load($userId, $tokenId);

        if (self::cookieMode($request)) {
            self::setCookie($token, time() + $ttl * 60, $request);
            return ['expires_at' => $expiresAt . 'Z'];
        }
        return ['token' => $token, 'expires_at' => $expiresAt . 'Z'];
    }

    public static function cookieMode(Request $request): bool
    {
        return $request->header('x-auth-mode') === 'cookie';
    }

    public static function clearCookie(Request $request): void
    {
        self::setCookie('', time() - 3600, $request);
    }

    private static function setCookie(string $value, int $expires, Request $request): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($request->header('x-forwarded-proto') === 'https');
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/api',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
