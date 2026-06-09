<?php

namespace App\Serializer;

use App\Entity\Search;

final class SearchNormalizer
{
    public function toArray(Search $search): array
    {
        return [
            'id' => (string) $search->getId(),
            'label' => $search->getLabel(),
            'type' => $search->getType()->value,
            'params' => $search->getParams(),
            'status' => $search->getStatus()->value,
            'createdOffline' => $search->isCreatedOffline(),
            'result' => $search->getStatus()->value === 'done' ? [
                'summary' => $search->getResultSummary(),
                'columns' => $search->getResultColumns(),
                'preview' => $search->getResultPreview(),
                'rowCount' => $search->getRowCount(),
                'truncated' => $search->isTruncated(),
                'durationMs' => $search->getDurationMs(),
            ] : null,
            'error' => $search->getErrorMessage(),
            'createdAt' => $search->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'executedAt' => $search->getExecutedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
