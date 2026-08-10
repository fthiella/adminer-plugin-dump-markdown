<?php

declare(strict_types=1);

/*
 * AdminerDumpMarkdown is written to run inside Adminer, so it calls several
 * free functions (Adminer\fields(), Adminer\connection(), Adminer\indexes(),
 * Adminer\foreign_keys(), Adminer\tables_list()) that only exist when
 * Adminer itself is loaded. These stubs let the class run standalone in
 * tests. Each test controls their behaviour through the corresponding
 * $GLOBALS['__adminer_test_*'] entry.
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

    function indexes(string $table): array
    {
        return $GLOBALS['__adminer_test_indexes'][$table] ?? [];
    }

    function foreign_keys(string $table): array
    {
        return $GLOBALS['__adminer_test_foreign_keys'][$table] ?? [];
    }

    function tables_list(): array
    {
        return $GLOBALS['__adminer_test_tables_list'] ?? [];
    }
}

namespace {
    require __DIR__ . '/../dump-markdown.php';
    require __DIR__ . '/Fakes/FakeResult.php';
    require __DIR__ . '/Fakes/FakeConnection.php';
    require __DIR__ . '/Support/InvokesPrivateMembers.php';
}
