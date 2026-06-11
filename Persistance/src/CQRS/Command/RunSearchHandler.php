<?php

namespace App\CQRS\Command;

use App\Entity\Search;
use App\Journal\Journal;
use App\Mercure\SearchPublisher;
use App\Repository\SearchRepository;
use App\SolarWind\Search\PaginableExecutorInterface;
use App\SolarWind\Search\ResultRowCache;
use App\SolarWind\Search\SearchCriteria;
use App\SolarWind\Search\SearchExecutorRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class RunSearchHandler
{
    public function __construct(
        private readonly SearchRepository $searches,
        private readonly SearchExecutorRegistry $registry,
        private readonly SearchPublisher $publisher,
        private readonly Journal $journal,
        private readonly EntityManagerInterface $em,
        private readonly ResultRowCache $resultCache,
    ) {
    }

    public function __invoke(RunSearch $command): void
    {
        $search = $this->searches->find(Uuid::fromString($command->searchId));
        if (!$search instanceof Search) {
            return;
        }

        $search->markRunning();
        $this->em->flush();
        $this->journal->record(Journal::SEARCH_STARTED, 'Recherche démarrée', [
            'searchId' => (string) $search->getId(),
            'type' => $search->getType()->value,
            'username' => $search->getUser()->getUserIdentifier(),
        ]);
        $this->publisher->publish($search);

        try {
            $criteria = SearchCriteria::fromArray($search->getParams());
            $result = $this->registry->execute($criteria);

            $executor = $this->registry->get($criteria->type);
            if ($executor instanceof PaginableExecutorInterface
                && $result->rowCount > 0
                && !$this->resultCache->has((string) $search->getId())
            ) {
                try {
                    $this->resultCache->store((string) $search->getId(), $executor->materializePayloadSql($criteria));
                } catch (\Throwable $e) {
                    $this->journal->error(Journal::SEARCH_FAILED, 'Matérialisation du cache de pagination échouée', [
                        'searchId' => (string) $search->getId(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $search->markDone(
                summary: $result->summary,
                columns: $result->columns,
                preview: $result->rows,
                rowCount: $result->rowCount,
                truncated: $result->isTruncated(),
                durationMs: $result->durationMs,
            );
            $this->em->flush();

            $this->journal->record(Journal::SEARCH_COMPLETED, 'Recherche terminée', [
                'searchId' => (string) $search->getId(),
                'rowCount' => $result->rowCount,
                'durationMs' => $result->durationMs,
                'username' => $search->getUser()->getUserIdentifier(),
            ]);
        } catch (\Throwable $e) {
            $search->markFailed($e->getMessage());
            $this->em->flush();

            $this->journal->error(Journal::SEARCH_FAILED, 'Échec de la recherche', [
                'searchId' => (string) $search->getId(),
                'exception' => $e->getMessage(),
                'username' => $search->getUser()->getUserIdentifier(),
            ]);
        }

        $this->publisher->publish($search);
    }
}
