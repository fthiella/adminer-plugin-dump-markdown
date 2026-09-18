<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\TestCase;

final class EdgeCaseTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = ['format' => 'markdown'];
        $GLOBALS['__adminer_test_fields'] = [];
        $GLOBALS['__adminer_test_connection'] = null;
        $GLOBALS['__adminer_test_indexes'] = [];
        $GLOBALS['__adminer_test_foreign_keys'] = [];
        $GLOBALS['__adminer_test_tables_list'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean() ?: '';
    }

    public function testDuplicateColumnsWithCollision(): void
    {
        $GLOBALS['__adminer_test_fields']['test'] = [
            ['field' => 'id', 'type' => 'int', 'full_type' => 'int'],
        ];

        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private bool $done = false;
                    public function fetch_fields(): array {
                        return array_map(function ($name) {
                            $o = new \stdClass();
                            $o->name = $name;
                            return $o;
                        }, ['id', 'id', 'id_2']);
                    }
                    public function fetch_row(): ?array {
                        if ($this->done) return null;
                        $this->done = true;
                        return ['10', '20', '99'];
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown(['tablePipes' => true]);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('id | id_3 | id_2', $out);
    }

    public function testMultilineStringsNormalization(): void
    {
        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private bool $done = false;
                    public function fetch_assoc(): ?array {
                        if ($this->done) return null;
                        $this->done = true;
                        return ['text' => "Line 1\r\nLine 2\nLine 3\rLine 4"];
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown(['tablePipes' => true]);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('Line 1 Line 2 Line 3 Line 4', $out);
        $this->assertStringNotContainsString("\nLine 2", $out);
    }

    public function testPipesAndBackslashesEscapedInValues(): void
    {
        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private bool $done = false;
                    public function fetch_assoc(): ?array {
                        if ($this->done) return null;
                        $this->done = true;
                        return ['val' => 'foo|bar\\baz'];
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown(['tablePipes' => true]);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('foo\\|bar\\\\baz', $out);
    }

    public function testNullValueRendering(): void
    {
        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private bool $done = false;
                    public function fetch_assoc(): ?array {
                        if ($this->done) return null;
                        $this->done = true;
                        return ['val' => null];
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown(['nullValue' => '_NULL_']);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('_NULL_', $out);
    }

    public function testTruncationMarkerAppliedOnStreamingRows(): void
    {
        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private int $i = 0;
                    private array $data = [
                        ['c' => 'abc'],
                        ['c' => 'abcdefgh'],
                    ];
                    public function fetch_assoc(): ?array {
                        return $this->data[$this->i++] ?? null;
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown([
            'rowSampleLimit' => 1,
            'truncationMarker' => '~',
        ]);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('ab~', $out);
    }

    public function testCompactModeEmitsMinimalSeparators(): void
    {
        $GLOBALS['__adminer_test_fields']['test'] = [
            ['field' => 'num', 'type' => 'int', 'full_type' => 'int'],
            ['field' => 'str', 'type' => 'varchar', 'full_type' => 'varchar(50)'],
        ];

        $GLOBALS['__adminer_test_connection'] = new class {
            public $error = null;
            public function query($q, $mode = 1) {
                return new class {
                    private bool $done = false;
                    public function fetch_assoc(): ?array {
                        if ($this->done) return null;
                        $this->done = true;
                        return ['num' => '42', 'str' => 'test'];
                    }
                };
            }
        };

        $p = new AdminerDumpMarkdown(['compact' => true, 'tableAlign' => true, 'tablePipes' => true]);
        $out = $this->capture(fn() => $p->dumpData('test', '1', 'SELECT...'));

        $this->assertStringContainsString('| --: | :-- |', $out);
    }

    public function testHttpHeaderCharsetMatchesDisableUtf8Mode(): void
    {
        $pLegacy = new class(['disableUTF8' => true]) extends AdminerDumpMarkdown {
            public string $capturedHeader = '';
            protected function sendHeader(string $h): void {
                $this->capturedHeader = $h;
            }
        };
        $pLegacy->dumpHeaders('test');

        $this->assertStringContainsString('charset=iso-8859-1', $pLegacy->capturedHeader);
    }

    public function testTableOfContentsDeduplicatesCollidingSlugs(): void
    {
        $GLOBALS['__adminer_test_tables_list'] = [
            'users' => 'table',
            'users!' => 'table',
        ];

        $p = new AdminerDumpMarkdown();
        $out = $this->capture(fn() => $p->dumpDatabase('test_db'));

        $this->assertStringContainsString('(#' . 'users)', $out);
        $this->assertStringContainsString('(#' . 'users-2)', $out);
    }
}