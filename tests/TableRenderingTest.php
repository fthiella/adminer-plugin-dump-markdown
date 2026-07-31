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
-- | -----
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
| -- | ----- |
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
        $this->assertSame('-: | :---- | :----:', $lines[1]);
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
| -: | :---- | :----: |
|  1 | Alice |  Yes   |

MD;

        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'markdownTable', [$rows, $widths, $aligns]));
    }

    public function testEmptyRowsProducesEmptyStringInsteadOfErroring(): void
    {
        // Regression test: rowSampleLimit = 0 (or a table with zero rows) used
        // to reach into $rows[0] for the header even when $rows was empty.
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame('', $this->callPrivateMethod($plugin, 'markdownTable', [[], [], []]));
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
