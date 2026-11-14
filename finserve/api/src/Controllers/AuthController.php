<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Audit;
use App\Services\Crypto;
use App\Services\CustomerPhone;
use App\Services\Settings;
use App\Services\Totp;

final class AuthController
{
    public function login(Request $request): Response
    {
        $v = new Validator($request->all());
        // "email" may also be a customer's registered mobile number.
        $email = mb_strtolower((string) $v->string('email', true, 190));
        $password = $v->string('password', true, 200);
        $v->validate();
        $phone = str_contains($email, '@') ? null : preg_replace('/[\s-]/', '', $email);
        if ($phone !== null && !preg_match('/^\+?\d{10,15}$/', $phone)) {
            throw HttpException::validation(['email' => 'Enter your email or 10-digit mobile number.']);
        }

        $window = Env::int('LOGIN_WINDOW_MINUTES', 15);
        $failures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND ip = ? AND success = 0
                AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$email, $request->ip, $window],
        );
        if ($failures >= Env::int('LOGIN_MAX_ATTEMPTS', 5)) {
            throw HttpException::tooManyRequests("Too many failed attempts. Try again in {$window} minutes.");
        }

        $user = $phone === null
            ? Database::one('SELECT id, password_hash, status, mfa_enabled_at FROM users WHERE email = ?', [$email])
            : Database::one(
                'SELECT u.id, u.password_hash, u.status, u.mfa_enabled_at FROM customers c JOIN users u ON u.id = c.user_id WHERE c.phone IN (?, ?, ?, ?) LIMIT 1',
                CustomerPhone::variants($phone),
            );
        // Unknown emails still pay for a real hash check, so response time does not reveal which emails exist.
        $valid = password_verify($password, $user['password_hash'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT))
            && $user !== null
            && $user['status'] === 'active';

        Database::insert('login_attempts', ['email' => $email, 'ip' => $request->ip, 'success' => $valid ? 1 : 0]);

        if (!$valid) {
            Audit::log($request, $user === null ? null : (int) $user['id'], 'auth.login_failed', 'user', $user === null ? null : (int) $user['id']);
            throw HttpException::unauthenticated($phone === null ? 'Invalid email or password.' : 'Invalid mobile number or password.');
        }

        $userId = (int) $user['id'];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
        }

        if ($user['mfa_enabled_at'] !== null) {
            $challenge = bin2hex(random_bytes(32));
            Database::insert('mfa_challenges', [
                'user_id' => $userId,
                'token_hash' => hash('sha256', $challenge),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
            ]);
            Audit::log($request, $userId, 'auth.mfa_challenged', 'user', $userId);
            return Response::json(['mfa_required' => true, 'challenge' => $challenge]);
        }

        return $this->startSession($userId, $request);
    }

    /** Second step: the 6-digit code from the authenticator app. */
    public function verifyMfa(Request $request): Response
    {
        $v = new Validator($request->all());
        $challenge = $v->pattern('challenge', '/^[a-f0-9]{64}$/', 'Invalid challenge.', true, 64);
        $code = $v->pattern('code', '/^\d{6}$/', 'Enter the 6-digit code.', true, 6);
        $v->validate();

        $userId = Database::transaction(function () use ($challenge, $code, $request) {
            $row = Database::one(
                'SELECT c.id, c.user_id, c.attempts, u.mfa_secret_enc, u.mfa_last_step
                   FROM mfa_challenges c JOIN users u ON u.id = c.user_id
                  WHERE c.token_hash = ? AND c.used_at IS NULL AND c.expires_at > UTC_TIMESTAMP() FOR UPDATE',
                [hash('sha256', $challenge)],
            );
            if ($row === null || (int) $row['attempts'] >= 5) {
                throw HttpException::unauthenticated('This sign-in attempt has expired. Please sign in again.');
            }
            $step = Totp::verify(Crypto::decrypt($row['mfa_secret_enc']), $code, $row['mfa_last_step'] === null ? null : (int) $row['mfa_last_step']);
            if ($step === null) {
                Database::query('UPDATE mfa_challenges SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
                Audit::log($request, (int) $row['user_id'], 'auth.mfa_failed', 'user', (int) $row['user_id']);
                return null;
            }
            Database::query('UPDATE mfa_challenges SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
            Database::query('UPDATE users SET mfa_last_step = ? WHERE id = ?', [$step, $row['user_id']]);
            return (int) $row['user_id'];
        });
        if ($userId === null) {
            throw HttpException::validation(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }
        return $this->startSession($userId, $request);
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        Database::query('UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$user->tokenId]);
        Audit::log($request, $user->id, 'auth.logout', 'user', $user->id);
        Auth::clearCookie($request);
        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        return Response::json($this->profile());
    }

    /** Step 1 of enrolment: a fresh secret (not active until confirmed with a code). */
    public function mfaSetup(Request $request): Response
    {
        $user = Auth::user();
        if ($user->mfaEnabled) {
            throw HttpException::conflict('Two-factor sign-in is already on. Turn it off first to re-enrol.');
        }
        $secret = Totp::newSecret();
        Database::query('UPDATE users SET mfa_secret_enc = ? WHERE id = ?', [Crypto::encrypt($secret), $user->id]);
        return Response::json([
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, $user->email ?? $user->name, Settings::get('company_name') ?: 'KBSAVE'),
        ]);
    }

    public function mfaEnable(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $code = $v->pattern('code', '/^\d{6}$/', 'Enter the 6-digit code.', true, 6);
        $v->validate();

        $encrypted = Database::value('SELECT mfa_secret_enc FROM users WHERE id = ?', [$user->id]);
        if ($encrypted === null) {
            throw HttpException::conflict('Start setup first.');
        }
        $step = Totp::verify(Crypto::decrypt($encrypted), $code);
        if ($step === null) {
            throw HttpException::validation(['code' => 'That code is not valid. Make sure your phone clock is correct.']);
        }
        Database::query('UPDATE users SET mfa_enabled_at = UTC_TIMESTAMP(), mfa_last_step = ? WHERE id = ?', [$step, $user->id]);
        Audit::log($request, $user->id, 'auth.mfa_enabled', 'user', $user->id);
        return Response::json($this->profile(true));
    }

    public function mfaDisable(Request $request): Response
    {
        $user = Auth::user();
        $v = new Validator($request->all());
        $password = $v->string('password', true, 200);
        $v->validate();
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$user->id]);
        if (!password_verify($password, $hash)) {
            throw HttpException::validation(['password' => 'Password is incorrect.']);
        }
        Database::query('UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE id = ?', [$user->id]);
        Audit::log($request, $user->id, 'auth.mfa_disabled', 'user', $user->id);
        return Response::json($this->profile(true));
    }

    public function startSession(int $userId, Request $request): Response
    {
        Database::query('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$userId]);
        $session = Auth::issueToken($userId, $request);
        Audit::log($request, $userId, 'auth.login', 'user', $userId);
        return Response::json($session + ['user' => $this->profile()]);
    }

    /** The signed-in user plus the settings the UI needs to decide what to show. */
    private function profile(bool $reload = false): array
    {
        $user = Auth::user();
        if ($reload) {
            $user = Auth::load($user->id, $user->tokenId);
        }
        $profile = $user->toArray();
        $profile['customer_id'] = $user->customerScope() === 'own'
            ? (int) Database::value('SELECT id FROM customers WHERE user_id = ?', [$user->id]) : null;
        $profile['unread_notifications'] = (int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'in_app' AND status = 'unread'", [$user->id]);
        $profile['settings'] = [
            'company_name' => Settings::get('company_name'),
            'deposits_enabled' => Settings::depositsEnabled(),
            'upi_enabled' => Settings::get('upi_vpa') !== '',
            'business_date' => Settings::today(),
            // Single-owner mode: this user may approve their own requests (Super Admin + setting on).
            'self_approval' => $user->maySelfApprove(),
        ];
        return $profile;
    }
}
