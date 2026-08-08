<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Authorization\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $exception)
    {
        if ($request->expectsJson()) {
            return $this->handleJsonResponse($request, $exception);
        }

        return parent::render($request, $exception);
    }

    private function handleJsonResponse($request, Throwable $exception)
    {
        if ($exception instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        }

        if ($exception instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($exception instanceof AuthorizationException || $exception instanceof UnauthorizedException) {
            // Security audit hook — record every authorization failure so
            // suspicious patterns (repeated denied access) can be detected.
            // Covers both Laravel's AuthorizationException and Spatie's
            // UnauthorizedException (PermissionMiddleware), which is a
            // subclass of HttpException rather than AuthorizationException.
            try {
                $user = $request->user();
                if ($user) {
                    \App\Models\SystemLog::create([
                        'tenant_id'   => \App\Models\Tenant::current()?->id,
                        'user_id'     => $user->id,
                        'action'      => 'security.permission_denied',
                        'model'       => $exception->getMessage() ?: null,
                        'model_id'    => null,
                        'ip_address'  => $request->ip(),
                        'user_agent'  => $request->userAgent(),
                        'new_values'  => ['route' => $request->path(), 'method' => $request->method()],
                    ]);
                }
            } catch (\Throwable $e) {
                // Never let auditing break the response path
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($exception instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        }

        if ($exception instanceof \App\Exceptions\BusinessLogicException) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ], 400);
        }

        // Log unhandled exceptions
        \Log::error('Unhandled Exception', [
            'exception' => class_basename($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'An error occurred',
            'code' => $exception->getCode() ?: 500,
        ], 500);
    }
}
