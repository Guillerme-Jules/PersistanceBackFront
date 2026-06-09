<?php

namespace App\SolarWind\Search;

use App\Enum\SearchType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.search_executor')]
interface SearchExecutorInterface
{
    public function type(): SearchType;

    public function execute(SearchCriteria $criteria): SearchResult;
}
