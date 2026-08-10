<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeConnection;
use Tests\Fakes\FakeResult;

final class DumpMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $GLOBALS['__adminer_test_fields'] = [];
        $GLOBALS['__adminer_test_connection'] = null;
        $GLOBALS['__adminer_test_indexes'] = [];
        $GLOBALS['__adminer_test_foreign_keys'] = [];
        $GLOBALS['__adminer_test_tables_list'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $GLOBALS['__adminer_test_fields'] = [];
        $GLOBALS['__adminer_test_connection'] = null;
        $GLOBALS['__adminer_test_indexes'] = [];
        $GLOBALS['__adminer_test_foreign_keys'] = [];
        $GLOBALS['__adminer_test_tables_list'] = [];
    }

    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }

    public function testDumpMethodsAreNoOpsWhenFormatDoesNotMatch(): void
    {
        $_POST['format'] = 'sql';
        $plugin = new AdminerDumpMarkdown();

        $output = $this->captureOutput(fn() => $plugin->dumpDatabase('mydb'));
        $this->assertSame('', $output);
        $this->assertNull($plugin->dumpDatabase('mydb'));
    }

    public function testDumpDatabasePrintsEscapedH1(): void
    {
        $_POST['format'] = 'markdown';
        $plugin = new AdminerDumpMarkdown();

        $output = $this->captureOutput(fn() => $plugin->dumpDatabase('my_db'));
        $this->assertSame("# my\\_db\n\n", $output);
    }

    public function testDumpTablePrintsHeadingAndStructureTable(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['users'] = [
            [
                'field' => 'id',
                'type' => 'int',
                'full_type' => 'int(11)',
                'comment' => '',
                'null' => false,
                'auto_increment' => true,
            ],
            [
                'field' => 'name',
                'type' => 'varchar',
                'full_type' => 'varchar(255)',
                'comment' => 'Full name',
                'null' => true,
                'auto_increment' => false,
            ],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('users', 'CREATE'));

        $this->assertStringContainsString('## users', $output);
        $this->assertStringContainsString('### table structure', $output);
        $this->assertStringContainsString('Column name', $output);
        $this->assertStringContainsString('id', $output);
        $this->assertStringContainsString('name', $output);
        $this->assertStringContainsString('Full name', $output);
    }

    public function testDumpTableSkipsStructureSectionWhenStyleIsFalse(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['users'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => true],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('users', ''));

        $this->assertStringContainsString('## users', $output);
        $this->assertStringNotContainsString('### table structure', $output);
    }

    public function testDumpTableAcceptsIntIsViewWithoutTypeError(): void
    {
        // Regression coverage: Adminer core's own dump.inc.php calls
        // dumpTable($name, $style, (is_view($status) ? 2 : 0)) -- an int,
        // never a bool. A bool-typed $is_view parameter would throw a
        // TypeError under strict_types the moment real Adminer calls this.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['users'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => true],
        ];

        $plugin = new AdminerDumpMarkdown();
        // Should not throw.
        $result = null;
        $this->captureOutput(function () use ($plugin, &$result) {
            $result = $plugin->dumpTable('users', 'CREATE', 0);
        });
        $this->assertTrue($result);
    }

    public function testDumpTableRendersIndexesWhenPresent(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['users'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => true],
        ];
        $GLOBALS['__adminer_test_indexes']['users'] = [
            ['type' => 'PRIMARY', 'columns' => ['id'], 'lengths' => [null], 'descs' => [null]],
            ['type' => 'UNIQUE', 'columns' => ['email'], 'lengths' => [null], 'descs' => [null]],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('users', 'CREATE', 0));

        $this->assertStringContainsString('### indexes', $output);
        $this->assertStringContainsString('PRIMARY', $output);
        $this->assertStringContainsString('UNIQUE', $output);
        $this->assertStringContainsString('email', $output);
    }

    public function testDumpTableIndexColumnsIncludePrefixLengthAndDescMarkers(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['articles'] = [
            ['field' => 'title', 'type' => 'varchar', 'full_type' => 'varchar(255)', 'comment' => '', 'null' => false, 'auto_increment' => false],
        ];
        $GLOBALS['__adminer_test_indexes']['articles'] = [
            ['type' => 'INDEX', 'columns' => ['title'], 'lengths' => ['20'], 'descs' => [true]],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('articles', 'CREATE', 0));

        $this->assertStringContainsString('title\\(20\\) DESC', $output);
    }

    public function testDumpTableSkipsIndexesSectionWhenNoneDefined(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['users'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => true],
        ];
        $GLOBALS['__adminer_test_indexes']['users'] = [];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('users', 'CREATE', 0));

        $this->assertStringNotContainsString('### indexes', $output);
    }

    public function testDumpTableRendersForeignKeys(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['orders'] = [
            ['field' => 'customer_id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => false],
        ];
        $GLOBALS['__adminer_test_foreign_keys']['orders'] = [
            [
                'table' => 'customers',
                'source' => ['customer_id'],
                'target' => ['id'],
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
            ],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('orders', 'CREATE', 0));

        $this->assertStringContainsString('### foreign keys', $output);
        $this->assertStringContainsString('customer\\_id', $output);
        $this->assertStringContainsString('customers\\(id\\)', $output);
        $this->assertStringContainsString('CASCADE', $output);
        $this->assertStringContainsString('RESTRICT', $output);
    }

    public function testDumpTableForeignKeyTargetFallsBackToSourceColumnNameWhenNull(): void
    {
        // Adminer's ForeignKey shape allows null target entries, conventionally
        // meaning "same column name as the source side".
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['orders'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => false],
        ];
        $GLOBALS['__adminer_test_foreign_keys']['orders'] = [
            [
                'table' => 'legacy_orders',
                'source' => ['id'],
                'target' => [null],
                'on_delete' => 'RESTRICT',
            ],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('orders', 'CREATE', 0));

        $this->assertStringContainsString('legacy\\_orders\\(id\\)', $output);
    }

    public function testDumpTableSkipsIndexesAndForeignKeysForViews(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_fields']['user_summary'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int(11)', 'comment' => '', 'null' => false, 'auto_increment' => false],
        ];
        // Even if the stubs would return data, views shouldn't trigger these
        // sections -- indexes()/foreign_keys() aren't meaningful for views
        // and some drivers may not support calling them on one.
        $GLOBALS['__adminer_test_indexes']['user_summary'] = [
            ['type' => 'PRIMARY', 'columns' => ['id'], 'lengths' => [null], 'descs' => [null]],
        ];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpTable('user_summary', 'CREATE', 1));

        $this->assertStringNotContainsString('### indexes', $output);
        $this->assertStringNotContainsString('### foreign keys', $output);
    }

    public function testDumpDatabaseRendersTableOfContentsWhenNoSelectionPost(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_tables_list'] = ['users' => 'table', 'orders' => 'table'];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpDatabase('shop'));

        $this->assertStringContainsString('## Table of contents', $output);
        $this->assertStringContainsString('[users](#users)', $output);
        $this->assertStringContainsString('[orders](#orders)', $output);
    }

    public function testDumpDatabaseTocRespectsSelectedTablesFromPost(): void
    {
        // Mirrors Adminer core: only tables checked in the "tables" or "data"
        // export checkboxes should appear, not every table in the database.
        $_POST['format'] = 'markdown';
        $_POST['tables'] = ['users'];
        $GLOBALS['__adminer_test_tables_list'] = ['users' => 'table', 'orders' => 'table', 'logs' => 'table'];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpDatabase('shop'));

        $this->assertStringContainsString('[users](#users)', $output);
        $this->assertStringNotContainsString('[orders]', $output);
        $this->assertStringNotContainsString('[logs]', $output);
    }

    public function testDumpDatabaseSkipsTocWhenNoTablesExist(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_tables_list'] = [];

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpDatabase('empty_db'));

        $this->assertStringNotContainsString('## Table of contents', $output);
    }

    public function testDumpDataRendersSampledRowsAsATable(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ]));

        $plugin = new AdminerDumpMarkdown(['rowSampleLimit' => 100]);
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertStringContainsString('### Table Data', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('Bob', $output);
    }

    public function testDumpDataStreamsRowsBeyondTheSampleLimitWithoutErroring(): void
    {
        // Regression coverage: rows at/after the sample limit boundary used to
        // rely on a non-empty $sampleRows array that might not exist yet.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([
            ['id' => '1'],
            ['id' => '2'],
            ['id' => '3'],
        ]));

        $plugin = new AdminerDumpMarkdown(['rowSampleLimit' => 2]);
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('3', $output);
    }

    public function testDumpDataWithZeroRowSampleLimitDoesNotError(): void
    {
        // rowSampleLimit = 0 used to crash markdownTable() by indexing into
        // an empty $rows array. It should now degrade gracefully.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([
            ['id' => '1'],
        ]));

        $plugin = new AdminerDumpMarkdown(['rowSampleLimit' => 0]);
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertStringContainsString('### Table Data', $output);
    }

    public function testDumpDataWithRowCountExactlyEqualToSampleLimit(): void
    {
        // Boundary case distinct from testDumpDataStreamsRowsBeyondTheSampleLimit:
        // here rn never exceeds rowSampleLimit, only reaches it on the very
        // last row, so the "flush the buffered table" branch after the loop
        // (rn <= rowSampleLimit) is what has to fire, not the streaming branch.
        // Exactly one table (one header, one separator line) should be printed.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([
            ['id' => '1'],
            ['id' => '2'],
            ['id' => '3'],
        ]));

        $plugin = new AdminerDumpMarkdown(['rowSampleLimit' => 3]);
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertSame("### Table Data\n\nid\n--\n1 \n2 \n3 \n\n", $output);
    }

    public function testDumpDataRendersNullValuesUsingTheConfiguredPlaceholder(): void
    {
        // Unlike testProcessValueReturnsConfiguredNullPlaceholder() (unit-level,
        // in EscapingTest), this confirms NULL survives the full path: DB
        // result -> row processing -> column width calc -> rendered table.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([
            ['id' => '1', 'nickname' => null],
            ['id' => '2', 'nickname' => 'Bob'],
        ]));

        $plugin = new AdminerDumpMarkdown(['nullValue' => 'NULL']);
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertStringContainsString('NULL', $output);
        $this->assertStringContainsString('Bob', $output);
    }

    public function testDumpDataWithNoRowsProducesNoTable(): void
    {
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(new FakeResult([]));

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FROM users'));

        $this->assertStringContainsString('### Table Data', $output);
        $this->assertStringNotContainsString('|', $output);
    }

    public function testDumpDataReportsQueryFailureInsteadOfSilentlySkipping(): void
    {
        // Regression coverage: a failed query used to produce no output and
        // no indication anything had gone wrong.
        $_POST['format'] = 'markdown';
        $GLOBALS['__adminer_test_connection'] = new FakeConnection(false, 'syntax error near SELECT');

        $plugin = new AdminerDumpMarkdown();
        $output = $this->captureOutput(fn() => $plugin->dumpData('users', 'INSERT', 'SELECT * FRM users'));

        $this->assertStringContainsString('ERROR', $output);
        $this->assertStringContainsString('syntax error near SELECT', $output);
    }

    public function testDumpHeadersSendsMarkdownContentTypeAndReturnsExtension(): void
    {
        // header() is a no-op under the CLI SAPI, so we go through a test
        // subclass that overrides the protected sendHeader() seam instead
        // of trying to observe header() via headers_list().
        $_POST['format'] = 'markdown';
        $plugin = new class extends AdminerDumpMarkdown {
            public ?string $capturedHeader = null;

            protected function sendHeader(string $header): void
            {
                $this->capturedHeader = $header;
            }
        };

        $extension = $plugin->dumpHeaders('some-db');

        $this->assertSame('md', $extension);
        $this->assertSame('Content-Type: text/markdown; charset=utf-8', $plugin->capturedHeader);
    }

    public function testDumpHeadersReturnsNullWhenFormatDoesNotMatch(): void
    {
        $_POST['format'] = 'sql';
        $plugin = new AdminerDumpMarkdown();

        $this->assertNull($plugin->dumpHeaders('some-db'));
    }
}
