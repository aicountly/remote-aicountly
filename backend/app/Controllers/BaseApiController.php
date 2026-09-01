<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Support\ApiException;
use App\Domain\Support\RequestContext;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Shared plumbing for the Remote API.
 *
 * Controllers here do four things and nothing else: read input, ask a domain
 * service to do the work, format the answer, and choose a status code. No
 * controller decides a permission, opens a transaction or writes a table —
 * that all belongs to the services, so it can be tested without HTTP and cannot
 * be forgotten on one route out of twenty.
 */
abstract class BaseApiController extends Controller
{
    protected function context(): RequestContext
    {
        return Services::requestContext();
    }

    protected function identity(): RemoteIdentity
    {
        return $this->context()->identity();
    }

    /**
     * The effective policy for a scope the caller asked about.
     *
     * Note the argument order: the scope comes from the *route or the verified
     * context*, never from a body field the browser filled in. A client cannot
     * ask to be evaluated against a company it does not belong to — the
     * resolver refuses, which is the isolation boundary (§77).
     */
    protected function policyFor(string $scopeType, ?int $companyId): EffectivePolicy
    {
        return Services::policyResolver()->resolve($this->identity(), $scopeType, $companyId);
    }

    /**
     * @param  array<string, mixed>|list<mixed> $data
     * @param  array<string, mixed>             $meta
     */
    protected function ok(array $data, array $meta = [], int $status = 200): ResponseInterface
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON($payload);
    }

    /** @param array<string, mixed> $data */
    protected function created(array $data): ResponseInterface
    {
        return $this->ok($data, [], 201);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setHeader('Cache-Control', 'no-store')->setBody('');
    }

    /**
     * The decoded JSON body, or an empty array.
     *
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json) ? $json : [];
    }

    /**
     * Validate the body against CI4's rules and return it, or throw a 400 whose
     * `details` name the offending fields.
     *
     * @param  array<string, string> $rules
     * @param  array<string, mixed>  $messages
     * @return array<string, mixed>
     */
    protected function validated(array $rules, array $messages = []): array
    {
        $data = $this->body();

        $validation = service('validation');
        $validation->setRules($rules, $messages);

        if (! $validation->run($data)) {
            throw ApiException::badRequest(
                'VALIDATION_FAILED',
                'Some of the details sent were not valid.',
                ['fields' => $validation->getErrors()],
            );
        }

        return $data;
    }

    /** A required, bounded string from the body. */
    protected function requiredString(array $data, string $key, int $maxLength = 255): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => [$key => 'This is required.'],
            ]);
        }

        return mb_substr(trim($value), 0, $maxLength);
    }

    protected function optionalString(array $data, string $key, int $maxLength = 255): ?string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    protected function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function boolean(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return $default;
    }

    /**
     * One of a fixed set, or a 400 that says which values are acceptable.
     *
     * @param list<string> $allowed
     */
    protected function enum(array $data, string $key, array $allowed, string $default): string
    {
        $value = $data[$key] ?? $default;
        if (! is_string($value)) {
            $value = $default;
        }

        $value = strtoupper($value);
        if (! in_array($value, $allowed, true)) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => [$key => 'Must be one of: ' . implode(', ', $allowed)],
            ]);
        }

        return $value;
    }

    /** The caller's IP, when it is a real one — used only for the audit trail. */
    protected function clientIp(): ?string
    {
        $ip = $this->request->getIPAddress();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    protected function userAgent(): ?string
    {
        $agent = $this->request->getUserAgent()->getAgentString();

        return $agent === '' ? null : mb_substr($agent, 0, 500);
    }
}
