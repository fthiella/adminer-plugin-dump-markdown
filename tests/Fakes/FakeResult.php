<?php

declare(strict_types=1);

namespace Tests\Fakes;

/**
 * Stands in for the mysqli_result-like object Adminer's connection->query()
 * normally returns, so tests don't need a real database.
 */
class FakeResult
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;
    private int $cursor = 0;

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetch_assoc(): ?array
    {
        if (!isset($this->rows[$this->cursor])) {
            return null;
        }
        return $this->rows[$this->cursor++];
    }
}
