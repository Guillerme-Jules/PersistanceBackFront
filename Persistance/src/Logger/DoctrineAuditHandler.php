<?php

namespace App\Logger;

use App\Audit\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DoctrineAuditHandler extends AbstractProcessingHandler
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.audit_entity_manager')]
        private readonly EntityManagerInterface $auditEm,
    ) {
        parent::__construct(Level::Debug, true);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        try {
            $entry = new AuditLog(
                action: (string) ($context['action'] ?? 'system.log'),
                message: $record->message,
                context: $this->normalizeContext($context),
                level: $record->level->getName(),
            );
            $entry->setChannel($record->channel);
            $entry->setUsername(isset($context['username']) ? (string) $context['username'] : null);
            $entry->setIp(isset($context['ip']) ? (string) $context['ip'] : null);
            $entry->setSearchId(isset($context['searchId']) ? (string) $context['searchId'] : null);

            $this->auditEm->persist($entry);
            $this->auditEm->flush();
        } catch (\Throwable $e) {

            error_log(\sprintf('[audit] échec de persistance: %s | %s', $e->getMessage(), $record->message));
        }
    }

    private function normalizeContext(array $context): array
    {
        unset($context['username'], $context['ip']);

        return array_map(
            static fn ($v) => $v instanceof \Throwable ? $v->getMessage() : $v,
            $context,
        );
    }
}
