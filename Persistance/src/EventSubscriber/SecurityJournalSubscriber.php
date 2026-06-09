<?php

namespace App\EventSubscriber;

use App\Journal\Journal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SecurityJournalSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $this->journal->record(Journal::AUTH_SUCCESS, 'Connexion réussie', [
            'username' => $event->getUser()->getUserIdentifier(),
        ]);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $this->journal->record(Journal::AUTH_FAILURE, 'Échec de connexion', [
            'reason' => $event->getException()->getMessage(),
        ], 'warning');
    }
}
