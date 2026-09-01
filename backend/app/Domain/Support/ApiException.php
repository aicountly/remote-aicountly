<?php

declare(strict_types=1);

namespace App\Domain\Support;

use CodeIgniter\HTTP\ResponsableInterface;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A failure the API is prepared to describe to a client.
 *
 * Every one carries a stable machine code alongside its HTTP status. The code
 * is the contract: the frontend maps it to a written explanation (§39), so an
 * ordinary user is never shown "403 permission denied" and the wording can
 * change without breaking anything.
 *
 * `$details` is for structured, non-sensitive context the UI can render — the
 * surface that was refused, the minutes until an invitation expires. Never put
 * a token, a secret or another tenant's data in it.
 *
 * It implements {@see ResponsableInterface} so CodeIgniter converts it inside
 * `CodeIgniter::run()` rather than letting it reach the global exception
 * handler. That matters for more than tidiness: it makes the behaviour
 * identical in production and under the feature tests, so a route's refusal is
 * asserted as the response the browser will actually receive.
 */
class ApiException extends RuntimeException implements ResponsableInterface
{
    /**
     * The exact JSON body the client receives.
     *
     * The same shape {@see \App\Exceptions\ApiExceptionHandler} produces for
     * everything else, so the frontend has one error contract to switch on.
     */
    public function getResponse(): ResponseInterface
    {
        $payload = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->details !== []) {
            $payload['details'] = $this->details;
        }

        return service('response')
            ->setStatusCode($this->status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['error' => $payload]);
    }

    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 400, $details);
    }

    public static function unauthenticated(string $message = 'Sign in to continue.'): self
    {
        return new self('UNAUTHENTICATED', $message, 401);
    }

    /** @param array<string, mixed> $details */
    public static function forbidden(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 403, $details);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        // Deliberately indistinguishable from "exists but is not yours": a
        // caller must not be able to probe for another tenant's session ids.
        return new self('NOT_FOUND', $message, 404);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function tooManyRequests(string $message = 'Too many attempts. Please wait and try again.'): self
    {
        return new self('RATE_LIMITED', $message, 429);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self($code, $message, 503);
    }
}
