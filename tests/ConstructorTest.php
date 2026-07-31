<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMembers;

final class ConstructorTest extends TestCase
{
    use InvokesPrivateMembers;

    public function testDefaultsAreAppliedWhenNoConfigGiven(): void
    {
        $plugin = new AdminerDumpMarkdown();

        $this->assertSame(100, $this->getPrivateProperty($plugin, 'rowSampleLimit'));
        $this->assertSame('N/D', $this->getPrivateProperty($plugin, 'nullValue'));
        $this->assertFalse($this->getPrivateProperty($plugin, 'tableAlign'));
        $this->assertFalse($this->getPrivateProperty($plugin, 'tablePipes'));
        $this->assertSame(
            ['number' => 'right', 'bool' => 'center', 'default' => 'left'],
            $this->getPrivateProperty($plugin, 'typeAlign')
        );
    }

    public function testConfigOverridesAreApplied(): void
    {
        $plugin = new AdminerDumpMarkdown([
            'rowSampleLimit' => 5,
            'nullValue' => 'NULL',
            'tableAlign' => true,
            'tablePipes' => true,
            'columnAlign' => ['id' => 'right'],
        ]);

        $this->assertSame(5, $this->getPrivateProperty($plugin, 'rowSampleLimit'));
        $this->assertSame('NULL', $this->getPrivateProperty($plugin, 'nullValue'));
        $this->assertTrue($this->getPrivateProperty($plugin, 'tableAlign'));
        $this->assertTrue($this->getPrivateProperty($plugin, 'tablePipes'));
        $this->assertSame(['id' => 'right'], $this->getPrivateProperty($plugin, 'columnAlign'));
    }

    public function testDumpFormatReportsMarkdownType(): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame(['markdown' => 'Markdown'], $plugin->dumpFormat());
    }
}
