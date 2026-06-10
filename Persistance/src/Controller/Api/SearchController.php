<?php

namespace App\Controller\Api;

use App\CQRS\Command\RunSearch;
use App\Entity\Search;
use App\Entity\User;
use App\Enum\SearchType;
use App\Journal\Journal;
use App\Repository\SearchRepository;
use App\Serializer\SearchNormalizer;
use App\SolarWind\Search\PaginableExecutorInterface;
use App\SolarWind\Search\SearchCriteria;
use App\SolarWind\Search\SearchExecutorRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Recherche')]
#[Route('/api/search')]
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchRepository $searches,
        private readonly SearchNormalizer $normalizer,
        private readonly MessageBusInterface $commandBus,
        private readonly Journal $journal,
        private readonly SearchExecutorRegistry $executors,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function history(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $items = array_map(
            fn (Search $s) => $this->normalizer->toArray($s),
            $this->searches->findHistory($user, $limit, $offset),
        );

        return $this->json(['items' => $items, 'limit' => $limit, 'offset' => $offset]);
    }

    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type'],
        properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Optionnel (idempotence offline)'),
            new OA\Property(property: 'type', type: 'string', enum: ['average_metric_on_day', 'threshold_crossing', 'bucket_average', 'raw_range']),
            new OA\Property(property: 'metric', type: 'string', enum: ['speed', 'density', 'bt', 'bz']),
            new OA\Property(property: 'from', type: 'string', example: '2024-06-09'),
            new OA\Property(property: 'to', type: 'string', example: '2024-06-30'),
            new OA\Property(property: 'operator', type: 'string', enum: ['<', '<=', '>', '>=', '=']),
            new OA\Property(property: 'threshold', type: 'number', example: -40),
            new OA\Property(property: 'bucketHours', type: 'integer', example: 12),
            new OA\Property(property: 'createdOffline', type: 'boolean'),
        ],
        example: ['type' => 'average_metric_on_day', 'metric' => 'bz', 'from' => '2024-06-09'],
    ))]
    #[OA\Response(response: 202, description: 'Recherche acceptée (exécution asynchrone)')]
    #[Route('', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $payload = $this->decode($request);

        $id = isset($payload['id']) ? Uuid::fromString((string) $payload['id']) : Uuid::v7();

        $existing = $this->searches->findOneForUser($id, $user);
        if ($existing !== null) {
            return $this->json($this->normalizer->toArray($existing), Response::HTTP_OK);
        }

        try {
            $criteria = SearchCriteria::fromArray($this->criteriaPayload($payload));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $search = new Search($id, $user, $criteria->type, $criteria->toArray());
        $search->setCreatedOffline((bool) ($payload['createdOffline'] ?? false));
        $search->setLabel($payload['label'] ?? $this->autoLabel($criteria));
        $this->searches->save($search);

        $this->journal->record(Journal::SEARCH_CREATED, 'Recherche créée', [
            'searchId' => (string) $id,
            'type' => $criteria->type->value,
            'createdOffline' => $search->isCreatedOffline(),
        ]);

        $this->commandBus->dispatch(new RunSearch((string) $id));

        return $this->json($this->normalizer->toArray($search), Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(#[CurrentUser] User $user, string $id): JsonResponse
    {
        $search = $this->find($user, $id);

        return $this->json($this->normalizer->toArray($search));
    }

    /**
     * Renvoie une page du jeu de résultats complet en ré-interrogeant ClickHouse.
     * Utile pour les recherches dont l'aperçu (20 lignes) est tronqué.
     */
    #[OA\Response(response: 200, description: 'Page de lignes du résultat')]
    #[Route('/{id}/rows', methods: ['GET'])]
    public function rows(#[CurrentUser] User $user, string $id, Request $request): JsonResponse
    {
        $search = $this->find($user, $id);

        $executor = $this->executors->get($search->getType());
        if (!$executor instanceof PaginableExecutorInterface) {
            return $this->json(
                ['error' => 'Ce type de recherche ne produit pas de lignes paginables.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $limit = min(500, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        try {
            $criteria = SearchCriteria::fromArray($search->getParams());
            // Total coûteux à calculer : uniquement sur la première page.
            // Pour les suivantes, le client réutilise le total déjà connu.
            $page = $executor->paginate($criteria, $limit, $offset, withTotal: $offset === 0);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => 'Résultats indisponibles (ClickHouse injoignable ?).'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return $this->json([
            'columns' => $page['columns'],
            'rows' => $page['rows'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Route('/{id}/replay', methods: ['POST'])]
    public function replay(#[CurrentUser] User $user, string $id, Request $request): JsonResponse
    {
        $source = $this->find($user, $id);
        $payload = $this->decode($request, allowEmpty: true);

        $newId = isset($payload['id']) ? Uuid::fromString((string) $payload['id']) : Uuid::v7();
        if (($existing = $this->searches->findOneForUser($newId, $user)) !== null) {
            return $this->json($this->normalizer->toArray($existing), Response::HTTP_OK);
        }

        $replay = new Search($newId, $user, $source->getType(), $source->getParams());
        $replay->setLabel($source->getLabel());
        $this->searches->save($replay);

        $this->journal->record(Journal::SEARCH_REPLAYED, 'Recherche relancée', [
            'searchId' => (string) $newId,
            'sourceId' => (string) $source->getId(),
        ]);

        $this->commandBus->dispatch(new RunSearch((string) $newId));

        return $this->json($this->normalizer->toArray($replay), Response::HTTP_ACCEPTED);
    }

    private function find(User $user, string $id): Search
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Recherche introuvable.');
        }

        return $this->searches->findOneForUser($uuid, $user)
            ?? throw $this->createNotFoundException('Recherche introuvable.');
    }

    private function decode(Request $request, bool $allowEmpty = false): array
    {
        $content = $request->getContent();
        if ($content === '' && $allowEmpty) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Corps JSON invalide.');
        }

        return $data;
    }

    private function criteriaPayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'type', 'metric', 'from', 'to', 'threshold', 'operator', 'bucketHours',
        ]));
    }

    private function autoLabel(SearchCriteria $c): string
    {
        return match ($c->type) {
            SearchType::AverageMetricOnDay => \sprintf('%s moyen le %s', strtoupper($c->metric?->value ?? ''), $c->from?->format('Y-m-d') ?? '?'),
            SearchType::ThresholdCrossing => \sprintf('%s %s %s', strtoupper($c->metric?->value ?? ''), $c->operator ?? '<', $c->threshold ?? '?'),
            SearchType::BucketAverage => \sprintf('%s moyen par %dh', strtoupper($c->metric?->value ?? ''), $c->bucketHours ?? 12),
            SearchType::RawRange => \sprintf('Données brutes %s → %s', $c->from?->format('Y-m-d H:i') ?? '?', $c->to?->format('Y-m-d H:i') ?? '?'),
        };
    }
}
