<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\ApiExceptionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Exceptions;
use Config\Services;
use RuntimeException;

/**
 * An uncaught failure answers JSON with a reference (no blank 500), and the log line carries the same
 * reference with the exception's class, message, file:line and the route — what the find-ref workflow
 * searches for. The exception's text never reaches a 5xx body.
 *
 * @internal
 */
final class ApiExceptionHandlerTest extends CIUnitTestCase
{
    public function testA5xxCarriesTheBrowsersReferenceAndLogsItWithTheException(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setHeader('X-Request-Id', 'abcdef0123456789abcdef0123456789');

        [$status, $body, $rid] = (new ApiExceptionHandler(new Exceptions()))
            ->answer(new RuntimeException('SQLSTATE[42P01]: relation "secret_table" does not exist'), $request, 500);

        $this->assertSame(500, $status);
        $this->assertSame('abcdef0123456789abcdef0123456789', $rid);
        $this->assertSame($rid, $body['request_id']);
        $this->assertSame($rid, $body['error']['request_id']);
        $this->assertSame('SERVER_ERROR', $body['error']['code']);
        $this->assertStringNotContainsString('secret_table', (string) json_encode($body));
        $this->assertLogContains('critical', 'Remote API uncaught RuntimeException [abcdef0123456789abcdef0123456789]');
        $this->assertLogContains('critical', 'secret_table');
    }

    public function testAReferenceThatIsNotUsableIsReplacedWithAFreshOne(): void
    {
        foreach (['', 'abc', 'has spaces in it', str_repeat('a', 65), '<script>'] as $sent) {
            $request = Services::incomingrequest(null, false);
            $request->setHeader('X-Request-Id', $sent);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ApiExceptionHandler::requestId($request), $sent);
        }
        $request = Services::incomingrequest(null, false);
        $request->setHeader('X-Request-Id', 'web-1.ab_CD');
        $this->assertSame('web-1.ab_CD', ApiExceptionHandler::requestId($request));
    }

    public function testA4xxKeepsItsOwnShapeWithTheReference(): void
    {
        [$status, $body] = (new ApiExceptionHandler(new Exceptions()))
            ->answer(\CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(), Services::incomingrequest(null, false), 404);

        $this->assertSame(404, $status);
        $this->assertSame('NOT_FOUND', $body['error']['code']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $body['request_id']);
    }
}
