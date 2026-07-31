<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMembers;

final class FormattingTest extends TestCase
{
    use InvokesPrivateMembers;

    public function testFormatValueLeftAlignsByDefault(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('ab   ', $this->callPrivateMethod($plugin, 'formatValue', ['ab', 5, ' ', 'left']));
    }

    public function testFormatValueRightAligns(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('   ab', $this->callPrivateMethod($plugin, 'formatValue', ['ab', 5, ' ', 'right']));
    }

    public function testFormatValueCentersWithExtraPaddingOnTheRightForOddWidths(): void
    {
        $plugin = new AdminerDumpMarkdown();
        // width 5, content len 2 -> 3 padding chars, floor(3/2)=1 left, 2 right
        $this->assertSame(' ab  ', $this->callPrivateMethod($plugin, 'formatValue', ['ab', 5, ' ', 'center']));
    }

    public function testFormatValueTruncatesValuesLongerThanTheColumnWidth(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('abcde', $this->callPrivateMethod($plugin, 'formatValue', ['abcdefgh', 5, ' ', 'left']));
    }

    public function testFormatValueUsesTheGivenFillerCharacter(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('ab---', $this->callPrivateMethod($plugin, 'formatValue', ['ab', 5, '-', 'left']));
    }

    #[DataProvider('boolProvider')]
    public function testBoolConvertsToYesNo(mixed $input, string $expected): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'bool', [$input]));
    }

    public static function boolProvider(): array
    {
        return [
            'int 1' => [1, 'Yes'],
            'int 0' => [0, 'No'],
            'string "1"' => ['1', 'Yes'],
            'null' => [null, 'No'],
            'true' => [true, 'Yes'],
            'false' => [false, 'No'],
        ];
    }

    public function testTableAlignDisabledForcesLeftRegardlessOfType(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => false]);
        $this->assertSame('left', $this->callPrivateMethod($plugin, 'getAlign', ['id', '42']));
    }

    public function testNumericColumnTypeAlignsRightWhenTableAlignEnabled(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->setPrivateProperty($plugin, 'fields', [
            'id' => ['type' => 'int', 'full_type' => 'int(11)'],
        ]);
        $this->assertSame('right', $this->callPrivateMethod($plugin, 'getAlign', ['id', '42']));
    }

    public function testTinyint1ColumnIsTreatedAsBooleanAndCentered(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->setPrivateProperty($plugin, 'fields', [
            'active' => ['type' => 'tinyint', 'full_type' => 'tinyint(1)'],
        ]);
        $this->assertSame('center', $this->callPrivateMethod($plugin, 'getAlign', ['active', 'Yes']));
    }

    public function testTinyintWithoutOneWidthIsNotTreatedAsBoolean(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->setPrivateProperty($plugin, 'fields', [
            'count' => ['type' => 'tinyint', 'full_type' => 'tinyint(4)'],
        ]);
        // Falls through to the numeric rule instead of the boolean one.
        $this->assertSame('right', $this->callPrivateMethod($plugin, 'getAlign', ['count', '3']));
    }

    public function testExplicitColumnAlignOverridesInferredType(): void
    {
        $plugin = new AdminerDumpMarkdown([
            'tableAlign' => true,
            'columnAlign' => ['id' => 'center'],
        ]);
        $this->setPrivateProperty($plugin, 'fields', [
            'id' => ['type' => 'int', 'full_type' => 'int(11)'],
        ]);
        $this->assertSame('center', $this->callPrivateMethod($plugin, 'getAlign', ['id', '42']));
    }

    public function testYesNoValueWithUnknownColumnTypeAlignsAsBoolean(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->assertSame('center', $this->callPrivateMethod($plugin, 'getAlign', ['flag', 'Yes']));
    }

    public function testUnrecognizedColumnFallsBackToDefaultAlignment(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->assertSame('left', $this->callPrivateMethod($plugin, 'getAlign', ['name', 'Alice']));
    }

    public function testNegativeNumberIsRightAlignedLikeAnyOtherNumericValue(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $this->setPrivateProperty($plugin, 'fields', [
            'balance' => ['type' => 'decimal', 'full_type' => 'decimal(10,2)'],
        ]);
        $this->assertSame('right', $this->callPrivateMethod($plugin, 'getAlign', ['balance', '-42.50']));
    }

    public function testNegativeNumberIsPaddedOnTheLeftWhenRightAligned(): void
    {
        $plugin = new AdminerDumpMarkdown();
        // Right alignment pads with the filler character on the left, same
        // as any other value; the minus sign is just part of the string.
        $this->assertSame('  -42.50', $this->callPrivateMethod($plugin, 'formatValue', ['-42.50', 8, ' ', 'right']));
    }

    public function testDecimalNumberLongerThanColumnWidthIsTruncatedFromTheRight(): void
    {
        // formatValue() truncates by taking the leftmost N characters, so a
        // truncated decimal keeps its integer part and loses precision from
        // the right -- e.g. a width of 6 cuts "-42.567" down to "-42.5".
        // This documents existing (if perhaps surprising) behavior rather
        // than asserting an ideal one, so a future change to this behavior
        // fails a test on purpose instead of silently changing output.
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('-42.56', $this->callPrivateMethod($plugin, 'formatValue', ['-42.567', 6, ' ', 'right']));
    }

    public function testPositiveAndNegativeNumbersOfDifferingLengthAlignOnTheDecimalColumnVisually(): void
    {
        // Not a claim that decimal points line up character-for-character
        // (Markdown tables don't guarantee that across renderers), just that
        // right-alignment pads shorter numeric strings consistently.
        $plugin = new AdminerDumpMarkdown();
        $width = 7;
        $this->assertSame('   3.50', $this->callPrivateMethod($plugin, 'formatValue', ['3.50', $width, ' ', 'right']));
        $this->assertSame('-100.25', $this->callPrivateMethod($plugin, 'formatValue', ['-100.25', $width, ' ', 'right']));
    }
}
