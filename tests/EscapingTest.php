<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMembers;

final class EscapingTest extends TestCase
{
    use InvokesPrivateMembers;

    #[DataProvider('specialCharacterProvider')]
    public function testEachSpecialCharacterIsBackslashEscaped(string $input, string $expected): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'escapeMarkdown', [$input]));
    }

    public static function specialCharacterProvider(): array
    {
        return [
            'asterisk' => ['a*b', 'a\\*b'],
            'underscore' => ['a_b', 'a\\_b'],
            'brackets' => ['[link]', '\\[link\\]'],
            'parens' => ['(x)', '\\(x\\)'],
            'braces' => ['{x}', '\\{x\\}'],
            'pipe' => ['a|b', 'a\\|b'],
            'hash' => ['#heading', '\\#heading'],
            'backslash itself' => ['a\\b', 'a\\\\b'],
            'plain text is untouched' => ['hello world', 'hello world'],
        ];
    }

    public function testEscapingIsAppliedPerCharacterNotAsAWholeStringMatch(): void
    {
        $plugin = new AdminerDumpMarkdown();
        // Every special char in one string, not just the first one found.
        $this->assertSame('\\*\\_\\[\\]', $this->callPrivateMethod($plugin, 'escapeMarkdown', ['*_[]']));
    }

    public function testProcessValueReturnsConfiguredNullPlaceholder(): void
    {
        $plugin = new AdminerDumpMarkdown(['nullValue' => 'NULL']);
        $this->assertSame('NULL', $this->callPrivateMethod($plugin, 'processValue', [null]));
    }

    public function testProcessValueUsesDefaultNullPlaceholder(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('N/D', $this->callPrivateMethod($plugin, 'processValue', [null]));
    }

    #[DataProvider('newlineProvider')]
    public function testProcessValueCollapsesAllLineEndingsToASpace(string $input, string $expected): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'processValue', [$input]));
    }

    public static function newlineProvider(): array
    {
        return [
            'unix newline' => ["line1\nline2", 'line1 line2'],
            'windows newline' => ["line1\r\nline2", 'line1 line2'],
            'old mac newline' => ["line1\rline2", 'line1 line2'],
        ];
    }

    public function testProcessValueEscapesAfterNormalizingNewlines(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('a\\*b c', $this->callPrivateMethod($plugin, 'processValue', ["a*b\nc"]));
    }

    public function testDisableUtf8KeepsCharactersRepresentableInLatin1(): void
    {
        $plugin = new AdminerDumpMarkdown(['disableUTF8' => true]);
        // 'é' is representable directly in ISO-8859-1, so no transliteration
        // is needed; the string just becomes single-byte (4 bytes vs 5 in UTF-8).
        $result = $this->callPrivateMethod($plugin, 'escapeMarkdown', ['café']);
        $this->assertSame(4, strlen($result));
    }

    public function testDisableUtf8HandlesCharactersOutsideLatin1WithoutErroring(): void
    {
        // iconv's TRANSLIT//IGNORE behavior for characters with no Latin-1
        // representation is implementation-specific: glibc (Linux) commonly
        // substitutes '?', while the libiconv build bundled with Windows PHP
        // can drop them to an empty result instead. Either is an acceptable
        // "best effort" degradation for a legacy single-byte fallback, so
        // this only asserts the call is safe, not a byte-for-byte fixed output.
        $plugin = new AdminerDumpMarkdown(['disableUTF8' => true]);
        $result = $this->callPrivateMethod($plugin, 'escapeMarkdown', ['日本語']);
        $this->assertIsString($result);
        $this->assertLessThanOrEqual(3, strlen($result));
    }

    public function testUtf8MultibyteCharactersAreHandledAsSingleCharactersByDefault(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame(4, $this->callPrivateMethod($plugin, 'getStringLength', ['café']));
    }
}
