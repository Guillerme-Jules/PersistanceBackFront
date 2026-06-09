<?php

namespace App\SolarWind\Search;

final readonly class SearchResult
{
    public const PREVIEW_SIZE = 20;

    public function __construct(
        public array $summary,
        public array $columns,
        public array $rows,
        public int $rowCount,
        public int $durationMs,
    ) {
    }

    public function isTruncated(): bool
    {
        return $this->rowCount > \count($this->rows);
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'columns' => $this->columns,
            'preview' => $this->rows,
            'rowCount' => $this->rowCount,
            'truncated' => $this->isTruncated(),
            'durationMs' => $this->durationMs,
        ];
    }
}
