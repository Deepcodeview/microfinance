<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{class-string, string}, permission: ?string, public: bool}> */
    private array $routes = [];

    /**
     * @param array{class-string, string} $handler
     * @param ?string $permission Required permission key; null means any authenticated user.
     */
    public function add(string $method, string $pattern, array $handler, ?string $permission = null, bool $public = false): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/')) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'permission', 'public');
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if (!$route['public']) {
                $user = Auth::authenticate($request);
                // Privileged users who must enrol in MFA can only reach their profile, MFA setup and logout.
                if ($user->mfaSetupRequired && !preg_match('#^/api/v1/(me|me/mfa/.*|auth/logout)$#', $request->path)) {
                    throw new HttpException(403, 'MFA_SETUP_REQUIRED', 'Set up two-factor sign-in to continue.');
                }
                if ($route['permission'] !== null && !$user->can($route['permission'])) {
                    throw HttpException::forbidden();
                }
            }

            [$class, $method] = $route['handler'];
            return (new $class())->$method($request);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
        }
        throw HttpException::notFound('Endpoint not found.');
    }
}
