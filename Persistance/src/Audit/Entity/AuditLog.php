<?php

namespace App\Audit\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_username', columns: ['username'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $channel = 'audit';

    #[ORM\Column(length: 16)]
    private string $level = 'info';

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $searchId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $action, string $message, array $context = [], string $level = 'info')
    {
        $this->action = $action;
        $this->message = $message;
        $this->context = $context;
        $this->level = $level;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function setSearchId(?string $searchId): static
    {
        $this->searchId = $searchId;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
