<?php

namespace App\SolarWind\Search;

/**
 * Implémentée par les exécuteurs dont le résultat est un ensemble de lignes
 * (et non un simple résumé). Permet de récupérer une page arbitraire du jeu
 * complet en ré-interrogeant ClickHouse, au-delà de l'aperçu des 20 premières.
 */
interface PaginableExecutorInterface
{
    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(SearchCriteria $criteria, int $limit, int $offset): array;
}
