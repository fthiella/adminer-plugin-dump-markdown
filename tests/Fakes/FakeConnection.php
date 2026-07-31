<?php

declare(strict_types=1);

namespace Tests\Fakes;

/**
 * Stands in for the connection object Adminer\connection() normally returns.
 * Configure it with either a FakeResult (success) or a null result plus an
 * error string (failure), then hand it to AdminerDumpMarkdown::dumpData().
 */
class FakeConnection
{
    public ?string $error = null;
    private FakeResult|false $result;

    public function __construct(FakeResult|false $result, ?string $error = null)
    {
        $this->result = $result;
        $this->error = $error;
    }

    public function query(string $query, int $unbuffered = 0): FakeResult|false
    {
        return $this->result;
    }
}
