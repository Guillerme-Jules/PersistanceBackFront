<?php

namespace App\Mercure;

use App\Entity\Search;
use App\Serializer\SearchNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SearchPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly SearchNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function userTopic(int $userId): string
    {
        return \sprintf('/users/%d/searches', $userId);
    }

    public static function searchTopic(string $searchId): string
    {
        return \sprintf('/searches/%s', $searchId);
    }

    public function publish(Search $search): void
    {
        try {
            $data = json_encode($this->normalizer->toArray($search), \JSON_THROW_ON_ERROR);

            $this->hub->publish(new Update(
                [
                    self::userTopic($search->getUser()->getId()),
                    self::searchTopic((string) $search->getId()),
                ],
                $data,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Publication Mercure échouée (le résultat reste disponible via l\'API).', [
                'searchId' => (string) $search->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
