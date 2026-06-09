<?php

namespace App\SolarWind\Search;

use App\Enum\Metric;
use App\Enum\SearchType;

final readonly class SearchCriteria
{
    public function __construct(
        public SearchType $type,
        public ?Metric $metric = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?float $threshold = null,
        public ?string $operator = null,
        public ?int $bucketHours = null,
    ) {
    }

    private const OPERATORS = ['<', '<=', '>', '>=', '='];

    public static function fromArray(array $data): self
    {
        $type = SearchType::from($data['type'] ?? throw new \InvalidArgumentException('type manquant'));

        $operator = $data['operator'] ?? null;
        if ($operator !== null && !\in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(\sprintf('Opérateur invalide: %s', $operator));
        }

        return new self(
            type: $type,
            metric: isset($data['metric']) ? Metric::from($data['metric']) : null,
            from: self::parseDate($data['from'] ?? null),
            to: self::parseDate($data['to'] ?? null),
            threshold: isset($data['threshold']) ? (float) $data['threshold'] : null,
            operator: $operator,
            bucketHours: isset($data['bucketHours']) ? (int) $data['bucketHours'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'metric' => $this->metric?->value,
            'from' => $this->from?->format(\DateTimeInterface::ATOM),
            'to' => $this->to?->format(\DateTimeInterface::ATOM),
            'threshold' => $this->threshold,
            'operator' => $this->operator,
            'bucketHours' => $this->bucketHours,
        ], static fn ($v) => $v !== null);
    }

    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(\sprintf('Date invalide: %s', $value), 0, $e);
        }
    }
}
