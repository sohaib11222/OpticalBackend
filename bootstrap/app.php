<?php

use App\Http\Middleware\Actor;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SessionAudience;
use App\Http\Middleware\ValidSession;
use App\Http\Middleware\VerifiedAccount;
use App\Integrations\Vista\VistaFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->trimStrings(except: [fn (Request $r) => $r->is('api/v1/communications/callback/*', 'api/v1/communications/inbound')]);
        $m->convertEmptyStringsToNull(except: [fn (Request $r) => $r->is('api/v1/communications/callback/*', 'api/v1/communications/inbound')]);
        $m->append(RequestContext::class);
        $m->prepend(SessionAudience::class);
        $m->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
        $m->alias(['session.valid' => ValidSession::class, 'actor' => Actor::class, 'account.verified' => VerifiedAccount::class, 'tenant' => ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());
        $e->render(function (Throwable $error, Request $r) {
            if (! $r->is('api/*')) {
                return null;
            }
            if ($error instanceof VistaFailure) {
                $status = in_array($error->status, [409, 422]) ? $error->status : 503;

                return response()->json(['message' => $error->getMessage(), 'code' => 'vista_unavailable', 'errors' => (object) [], 'request_id' => $r->attributes->get('request_id')], $status);
            }
            $status = $error instanceof ValidationException ? 422 : ($error instanceof AuthenticationException ? 401 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500));
            $codes = [401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 419 => 'csrf_expired', 422 => 'validation_failed', 429 => 'rate_limited', 500 => 'server_error', 503 => 'unavailable'];
            $message = $status >= 500 ? 'The request could not be completed. Please try again.' : ($error->getMessage() ?: 'The request was rejected.');

            return response()->json(['message' => $message, 'code' => $codes[$status] ?? 'request_failed', 'errors' => $error instanceof ValidationException ? $error->errors() : (object) [], 'request_id' => $r->attributes->get('request_id')], $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
        });
    })->create();
