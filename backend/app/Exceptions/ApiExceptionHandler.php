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
        [$status, $payload] = $this->describe($exception, $statusCode);

        if ($status >= 500) {
            log_message('critical', "Remote API: {message}\n{trace}", [
                'message' => $exception->getMessage(),
                'trace'   => $exception->getTraceAsString(),
            ]);
        }

        $response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('application/json')
            ->setBody((string) json_encode(['error' => $payload], JSON_UNESCAPED_SLASHES))
            ->send();

        exit($exitCode);
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
        $message = ENVIRONMENT === 'production'
            ? 'Something went wrong at our end. Please try again.'
            : $exception->getMessage();

        return [500, ['code' => 'SERVER_ERROR', 'message' => $message]];
    }
}
