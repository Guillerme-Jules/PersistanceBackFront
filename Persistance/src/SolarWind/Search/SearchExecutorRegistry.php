<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SearchExecutorRegistry
{

    private array $executors = [];

    public function __construct(
        #[AutowireIterator('app.search_executor')]
        iterable $executors,
    ) {
        foreach ($executors as $executor) {
            $this->executors[$executor->type()->value] = $executor;
        }
    }

    public function get(SearchType $type): SearchExecutorInterface
    {
        return $this->executors[$type->value]
            ?? throw new \RuntimeException(\sprintf('Aucun exécuteur pour le type "%s".', $type->value));
    }

    public function execute(SearchCriteria $criteria): SearchResult
    {
        return $this->get($criteria->type)->execute($criteria);
    }
}
