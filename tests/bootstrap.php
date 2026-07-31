<?php

declare(strict_types=1);

/*
 * AdminerDumpMarkdown is written to run inside Adminer, so it calls two
 * free functions, Adminer\fields() and Adminer\connection(), that only
 * exist when Adminer itself is loaded. These stubs let the class run
 * standalone in tests. Each test controls their behaviour through
 * $GLOBALS['__adminer_test_fields'] / $GLOBALS['__adminer_test_connection'].
 */
namespace Adminer {
    function fields(string $table): array
    {
        return $GLOBALS['__adminer_test_fields'][$table] ?? [];
    }

    function connection(): mixed
    {
        return $GLOBALS['__adminer_test_connection'] ?? null;
    }
}

namespace {
    require __DIR__ . '/../dump-markdown.php';
    require __DIR__ . '/Fakes/FakeResult.php';
    require __DIR__ . '/Fakes/FakeConnection.php';
    require __DIR__ . '/Support/InvokesPrivateMembers.php';
}
