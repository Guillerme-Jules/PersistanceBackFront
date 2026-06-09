<?php

namespace App\CQRS\Command;

final readonly class RunSearch
{
    public function __construct(
        public string $searchId,
    ) {
    }
}
