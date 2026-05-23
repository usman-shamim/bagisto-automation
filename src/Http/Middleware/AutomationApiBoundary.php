<?php

namespace Webkul\Automation\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared envelope for `/api/automation/v1/*`:
 *  - assigns / echoes an `X-Request-ID` (round-trip, also useful in logs)
 *  - rejects malformed JSON bodies with our `{error:{code,message}}` shape
 *  - catches the framework's ThrottleRequestsException so 429s also use our shape
 */
class AutomationApiBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Request-ID', $requestId);

        if ($malformed = $this->malformedJsonResponse($request)) {
            $malformed->headers->set('X-Request-ID', $requestId);

            return $malformed;
        }

        try {
            $response = $next($request);
        } catch (ThrottleRequestsException $e) {
            $response = $this->throttleResponse($e);
        }

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    protected function malformedJsonResponse(Request $request): ?JsonResponse
    {
        $body = $request->getContent();

        if ($body === '' || $body === null) {
            return null;
        }

        $contentType = (string) $request->header('Content-Type', '');

        if (! str_contains($contentType, 'application/json')) {
            return null;
        }

        try {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return null;
        } catch (\JsonException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'invalid_json',
                    'message' => 'Malformed JSON in request body: '.$e->getMessage(),
                ],
            ], 400);
        }
    }

    protected function throttleResponse(ThrottleRequestsException $e): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'rate_limited',
                'message' => 'Too many requests. Try again later.',
            ],
        ], 429, $e->getHeaders());
    }
}
