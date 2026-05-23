<?php

namespace Webkul\Automation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Automation\Models\AutomationAuditLogProxy;
use Webkul\Automation\Services\TokenIssuer;

class AuthenticateApiToken
{
    public function __construct(protected TokenIssuer $issuer) {}

    /**
     * Authenticate the request via an `Authorization: Bearer <token>` header.
     *
     * Route usage: `Route::middleware('auth.api-token:read')->...`
     * The middleware parameter is the required scope; omit it to require
     * authentication only (no specific scope).
     *
     * Rate limiting is enforced here (rather than via `throttle:` middleware)
     * because Laravel's `ThrottleRequests` runs before our middleware due to
     * the framework's priority ordering — meaning the resolved token wouldn't
     * be on the request yet when keying the bucket.
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $plaintext = $request->bearerToken();

        if (! $plaintext) {
            return $this->error(401, 'missing_token', 'A bearer token is required.');
        }

        $token = $this->issuer->find($plaintext);

        if (! $token) {
            return $this->error(401, 'invalid_token', 'Token is invalid, revoked, or expired.');
        }

        if ($scope !== null && ! $token->hasScope($scope)) {
            $this->audit($token, $request, 403);

            return $this->error(403, 'insufficient_scope', "Token is missing required scope: {$scope}.");
        }

        $rateKey = 'automation-token:'.$token->id;
        $limit = (int) config('automation.api.rate_limit_per_minute', 60);

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return $this->error(429, 'rate_limited', 'Too many requests. Try again later.');
        }

        RateLimiter::hit($rateKey, 60);

        $request->attributes->set('automation_token', $token);
        $this->issuer->touch($token);

        $response = $next($request);

        $this->audit($token, $request, $response->getStatusCode());

        return $response;
    }

    protected function audit(object $token, Request $request, int $status): void
    {
        $body = $request->getContent();

        AutomationAuditLogProxy::modelClass()::create([
            'token_id' => $token->id,
            'admin_id' => $token->admin_id,
            'endpoint' => '/'.ltrim($request->path(), '/'),
            'method' => $request->method(),
            'payload_hash' => $body === '' ? null : hash('sha256', $body),
            'ip' => $request->ip(),
            'status_code' => $status,
        ]);
    }

    protected function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
