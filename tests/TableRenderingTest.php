<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMembers;

final class TableRenderingTest extends TestCase
{
    use InvokesPrivateMembers;

    public function testSimpleTableWithoutPipesOrAlignment(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $rows = [
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ];
        $widths = ['id' => 2, 'name' => 5];

        $expected = <<<'MD'
id | name 
---|------
1  | Alice
2  | Bob  

MD;

        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, []]));
    }

    public function testTableWithLeadingAndTrailingPipes(): void
    {
        $plugin = new AdminerDumpMarkdown(['tablePipes' => true]);
        $rows = [['id' => '1', 'name' => 'Alice']];
        $widths = ['id' => 2, 'name' => 5];

        $expected = <<<'MD'
| id | name  |
|----|-------|
| 1  | Alice |

MD;

        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, []]));
    }

    public function testTableWithAlignmentMarkersInSeparatorRow(): void
    {
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true]);
        $rows = [['id' => '1', 'name' => 'Alice', 'active' => 'Yes']];
        $widths = ['id' => 2, 'name' => 5, 'active' => 6];
        $aligns = ['id' => 'right', 'name' => 'left', 'active' => 'center'];

        $result = $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, $aligns]);
        $lines = explode("\n", $result);

        $this->assertSame('id | name  | active', $lines[0]);
        // right: dashes then colon; left: colon then dashes; center: colon-dashes-colon
        $this->assertSame('--:|:------|:-----:', $lines[1]);
    }

    public function testTableWithPipesAndAlignmentCombined(): void
    {
        // tablePipes and tableAlign are independent config flags that were
        // only ever tested in isolation from each other; this locks in the
        // combined output exactly.
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true, 'tablePipes' => true]);
        $rows = [['id' => '1', 'name' => 'Alice', 'active' => 'Yes']];
        $widths = ['id' => 2, 'name' => 5, 'active' => 6];
        $aligns = ['id' => 'right', 'name' => 'left', 'active' => 'center'];

        $expected = <<<'MD'
| id | name  | active |
|---:|:------|:------:|
|  1 | Alice |  Yes   |

MD;

        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, $aligns]));
    }

    public function testSeparatorPipesLineUpVerticallyWithHeaderPipes(): void
    {
        // The property that actually matters: dashes fill the space that
        // would otherwise be blank padding, but the pipe *positions* in the
        // separator row must still land exactly where they do in the header
        // row above it, whatever the column count or tablePipes/tableAlign
        // combination. Checking character offsets directly, rather than a
        // fixed string, makes this resilient to width/column changes.
        $plugin = new AdminerDumpMarkdown(['tableAlign' => true, 'tablePipes' => true]);
        $rows = [['a' => '1', 'bb' => '22', 'ccc' => '333', 'dddd' => '4444']];
        $widths = ['a' => 4, 'bb' => 9, 'ccc' => 2, 'dddd' => 15];
        $aligns = ['a' => 'left', 'bb' => 'right', 'ccc' => 'center', 'dddd' => 'left'];

        $result = $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, $aligns]);
        [$headerLine, $sepLine] = explode("\n", $result, 3);

        $pipePositions = function (string $line): array {
            $positions = [];
            foreach (str_split($line) as $i => $char) {
                if ($char === '|') {
                    $positions[] = $i;
                }
            }
            return $positions;
        };

        $this->assertSame($pipePositions($headerLine), $pipePositions($sepLine));
    }

    public function testSeparatorPipesLineUpVerticallyWithoutTablePipes(): void
    {
        // Same property, but for the no-outer-wrap case, where only the
        // interior " | " joins need compensating, not the (nonexistent)
        // leading/trailing wrap.
        $plugin = new AdminerDumpMarkdown(['tableAlign' => false, 'tablePipes' => false]);
        $rows = [['a' => '1', 'bb' => '22', 'ccc' => '333']];
        $widths = ['a' => 4, 'bb' => 9, 'ccc' => 2];

        $result = $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, []]);
        [$headerLine, $sepLine] = explode("\n", $result, 3);

        $pipePositions = function (string $line): array {
            $positions = [];
            foreach (str_split($line) as $i => $char) {
                if ($char === '|') {
                    $positions[] = $i;
                }
            }
            return $positions;
        };

        $this->assertSame($pipePositions($headerLine), $pipePositions($sepLine));
    }

    public function testEmptyRowsProducesEmptyStringInsteadOfErroring(): void
    {
        // Regression test: rowSampleLimit = 0 (or a table with zero rows) used
        // to reach into $rows[0] for the header even when $rows was empty.
        $plugin = new AdminerDumpMarkdown();
        $expected = <<<'MD'
> No data found in table.

MD;
        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'markdownTable', [[], [], []]));
    }

    public function testMarkdownRowEscapesAndPadsEachCellIndependently(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $row = ['a' => 'x', 'b' => 'yy'];
        $widths = ['a' => 3, 'b' => 4];

        $this->assertSame('x   | yy  ', $this->callPrivateMethod(
            $plugin,
            'markdownRow',
            [$row, $widths, [], ' | ', ' ']
        ));
    }

    public function testMapHeaderEscapesColumnNamesLikeAnyOtherValue(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $header = $this->callPrivateMethod($plugin, 'mapHeader', [['weird*col' => 'x']]);
        $this->assertSame(['weird*col' => 'weird\\*col'], $header);
    }
}
