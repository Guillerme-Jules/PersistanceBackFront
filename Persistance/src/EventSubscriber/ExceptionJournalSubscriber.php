<?php

namespace App\EventSubscriber;

use App\Journal\Journal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionJournalSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 0]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        if ($statusCode < 500) {
            return;
        }

        $this->journal->error(Journal::SYSTEM_EXCEPTION, $e->getMessage(), [
            'exceptionClass' => $e::class,
            'path' => $event->getRequest()->getPathInfo(),
            'statusCode' => $statusCode,
        ]);
    }
}
