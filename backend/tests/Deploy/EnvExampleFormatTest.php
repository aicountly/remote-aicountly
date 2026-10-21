<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * CodeIgniter's .env parser throws on an unquoted value containing a space, and that exception takes
 * the whole API down. Every value an operator might copy out of .env.example must parse as written:
 * lists comma-separated with no spaces, anything with a space quoted.
 *
 * @internal
 */
final class EnvExampleFormatTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function copyableLines(): iterable
    {
        foreach (file(dirname(__DIR__, 2) . '/.env.example') ?: [] as $n => $line) {
            if (preg_match('/^#?\s*([A-Z][A-Z0-9_]*|[a-z]+\.[A-Za-z.]+) = (.*)$/', rtrim($line), $m) === 1 && trim($m[2]) !== '') {
                yield ".env.example:{$n} {$m[1]}" => [trim($m[2])];
            }
        }
    }

    /** @dataProvider copyableLines */
    public function testTheValueParsesAsCodeIgniterReadsIt(string $value): void
    {
        if (in_array($value[0], ['"', "'"], true)) {
            $this->assertSame($value[0], substr(rtrim(explode(' #', $value)[0]), -1), 'A quoted value must close its quote.');

            return;
        }

        // An inline comment (" #") ends the value, exactly as DotEnv::sanitizeValue() reads it.
        $this->assertDoesNotMatchRegularExpression('/\s/', trim(explode(' #', $value, 2)[0]));
    }
}
