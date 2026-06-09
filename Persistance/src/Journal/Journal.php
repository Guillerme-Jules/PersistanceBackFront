<?php

namespace App\Journal;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

final class Journal
{

    public const SEARCH_CREATED = 'search.created';
    public const SEARCH_REPLAYED = 'search.replayed';
    public const SEARCH_STARTED = 'search.started';
    public const SEARCH_COMPLETED = 'search.completed';
    public const SEARCH_FAILED = 'search.failed';
    public const SYSTEM_EXCEPTION = 'system.exception';
    public const AUTH_SUCCESS = 'auth.success';
    public const AUTH_FAILURE = 'auth.failure';
    public const CLIENT_OFFLINE_USAGE = 'client.offline_usage';
    public const CLIENT_DISCONNECT = 'client.unexpected_disconnect';
    public const CLIENT_RECONNECT = 'client.reconnect';

    public function __construct(
        #[Autowire(service: 'monolog.logger.audit')]
        private readonly LoggerInterface $auditLogger,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function record(string $action, string $message, array $context = [], string $level = 'info'): void
    {
        $context['action'] = $action;
        $context += $this->ambientContext();

        $this->auditLogger->log($level, $message, $context);
    }

    public function error(string $action, string $message, array $context = []): void
    {
        $this->record($action, $message, $context, 'error');
    }

    private function ambientContext(): array
    {
        $context = [];

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $context['username'] = $user->getUserIdentifier();
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $context['ip'] = $request->getClientIp();
        }

        return $context;
    }
}
