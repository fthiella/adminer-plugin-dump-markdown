<?php

declare(strict_types=1);

namespace Tests;

use AdminerDumpMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvokesPrivateMembers;

final class SlugifyTest extends TestCase
{
    use InvokesPrivateMembers;

    #[DataProvider('slugProvider')]
    public function testSlugify(string $input, string $expected): void
    {
        $plugin = new AdminerDumpMarkdown();
        $this->assertSame($expected, $this->callPrivateMethod($plugin, 'slugify', [$input]));
    }

    public static function slugProvider(): array
    {
        return [
            'simple lowercase' => ['users', 'users'],
            'already has underscore' => ['user_accounts', 'user_accounts'],
            'uppercase gets lowered' => ['Users', 'users'],
            'spaces become hyphens' => ['order items', 'order-items'],
            'punctuation is stripped' => ['users (archived)', 'users-archived'],
            'existing hyphen kept' => ['user-accounts', 'user-accounts'],
        ];
    }
}
