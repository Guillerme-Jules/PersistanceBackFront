<?php

namespace App\Entity;

use App\Enum\SearchStatus;
use App\Enum\SearchType;
use App\Repository\SearchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SearchRepository::class)]
#[ORM\Table(name: 'search')]
#[ORM\Index(name: 'idx_search_user_created', columns: ['user_id', 'created_at'])]
class Search
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'string', enumType: SearchType::class)]
    private SearchType $type;

    #[ORM\Column(type: 'json')]
    private array $params = [];

    #[ORM\Column(type: 'string', enumType: SearchStatus::class)]
    private SearchStatus $status = SearchStatus::Pending;

    #[ORM\Column]
    private bool $createdOffline = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultSummary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultColumns = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $resultPreview = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowCount = null;

    #[ORM\Column(nullable: true)]
    private ?bool $truncated = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    public function __construct(Uuid $id, User $user, SearchType $type, array $params)
    {
        $this->id = $id;
        $this->user = $user;
        $this->type = $type;
        $this->params = $params;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): SearchType
    {
        return $this->type;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getStatus(): SearchStatus
    {
        return $this->status;
    }

    public function isCreatedOffline(): bool
    {
        return $this->createdOffline;
    }

    public function setCreatedOffline(bool $createdOffline): static
    {
        $this->createdOffline = $createdOffline;

        return $this;
    }

    public function markRunning(): static
    {
        $this->status = SearchStatus::Running;

        return $this;
    }

    public function markDone(array $summary, array $columns, array $preview, int $rowCount, bool $truncated, int $durationMs): static
    {
        $this->status = SearchStatus::Done;
        $this->resultSummary = $summary;
        $this->resultColumns = $columns;
        $this->resultPreview = $preview;
        $this->rowCount = $rowCount;
        $this->truncated = $truncated;
        $this->durationMs = $durationMs;
        $this->errorMessage = null;
        $this->executedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $message): static
    {
        $this->status = SearchStatus::Failed;
        $this->errorMessage = $message;
        $this->executedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResultSummary(): ?array
    {
        return $this->resultSummary;
    }

    public function getResultColumns(): ?array
    {
        return $this->resultColumns;
    }

    public function getResultPreview(): ?array
    {
        return $this->resultPreview;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function isTruncated(): ?bool
    {
        return $this->truncated;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }
}
