<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Support\ApiException;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Turns every uncaught exception into the API's structured error shape (§28).
 *
 *     { "error": { "code": "SURFACE_NOT_ALLOWED", "message": "…", "details": {…} } }
 *
 * The `code` is the contract the frontend switches on to show a written
 * explanation instead of a status number (§39). The `message` is safe to
 * display, because an {@see ApiException} is only ever constructed with wording
 * meant for a person.
 *
 * Anything that is *not* an ApiException is a bug, and is handled differently:
 * the real message and stack trace go to the log, and the caller gets a generic
 * 500. Leaking an SQL error to the browser tells an attacker about the schema
 * and tells the user nothing they can act on.
 *
 * Every answer carries a reference (`request_id`, also the X-Request-Id header):
 * the X-Request-Id the browser sent when it is 6-64 characters of
 * [A-Za-z0-9._-], else a fresh 32-hex id. A 5xx logs that same id beside the
 * exception's class, message, file:line and the method and path, so the
 * "Ref: …" the screen shows is what support searches the log for (find-ref
 * workflow). The exception's own text never reaches the body of a 5xx.
 */
class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        [$status, $body, $requestId] = $this->answer($exception, $request, $statusCode);

        $response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Request-Id', $requestId)
            ->setContentType('application/json')
            ->setBody((string) json_encode($body, JSON_UNESCAPED_SLASHES))
            ->send();

        exit($exitCode);
    }

    /**
     * The status, body and reference for an uncaught exception; a 5xx is logged against the reference.
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    public function answer(Throwable $exception, RequestInterface $request, int $statusCode): array
    {
        [$status, $payload] = $this->describe($exception, $statusCode);
        $requestId          = self::requestId($request);
        $payload['request_id'] = $requestId;

        if ($status >= 500) {
            $route = method_exists($request, 'getMethod') ? strtoupper((string) $request->getMethod()) : '';
            $route .= ' /' . ltrim(method_exists($request, 'getPath') ? (string) $request->getPath() : '', '/');
            log_message('critical', "Remote API uncaught {class} [{rid}] {route}: {message} @ {exFile}:{exLine}\n{trace}", [
                'class'   => $exception::class,
                'rid'     => $requestId,
                'route'   => $route,
                'message' => $exception->getMessage(),
                'exFile'  => $exception->getFile(),
                'exLine'  => $exception->getLine(),
                'trace'   => $exception->getTraceAsString(),
            ]);
        }

        return [$status, ['error' => $payload, 'request_id' => $requestId], $requestId];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function describe(Throwable $exception, int $statusCode): array
    {
        if ($exception instanceof ApiException) {
            $payload = [
                'code'    => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ];

            if ($exception->details() !== []) {
                $payload['details'] = $exception->details();
            }

            return [$exception->status(), $payload];
        }

        if ($exception instanceof PageNotFoundException) {
            return [404, ['code' => 'NOT_FOUND', 'message' => 'Not found.']];
        }

        $status = $statusCode >= 400 && $statusCode < 600 ? $statusCode : 500;

        if ($status < 500) {
            return [$status, ['code' => 'REQUEST_INVALID', 'message' => 'That request could not be processed.']];
        }

        // In development the real message is far more useful than a placeholder,
        // and it never reaches a customer.
        $message = ENVIRONMENT === 'development'
            ? $exception->getMessage()
            : 'Something went wrong at our end. Please try again.';

        return [500, ['code' => 'SERVER_ERROR', 'message' => $message]];
    }

    /** The browser's X-Request-Id when it is a usable reference, else a fresh one. */
    public static function requestId(RequestInterface $request): string
    {
        $sent = trim($request->getHeaderLine('X-Request-Id'));
        if (preg_match('/^[A-Za-z0-9._-]{6,64}$/', $sent) === 1) {
            return $sent;
        }

        return bin2hex(random_bytes(16));
    }
}
