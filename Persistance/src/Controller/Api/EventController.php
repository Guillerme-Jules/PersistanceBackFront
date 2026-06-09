<?php

namespace App\Controller\Api;

use App\Journal\Journal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

#[Route('/api/events')]
final class EventController extends AbstractController
{

    private const ALLOWED = [
        Journal::CLIENT_OFFLINE_USAGE,
        Journal::CLIENT_DISCONNECT,
        Journal::CLIENT_RECONNECT,
    ];

    public function __construct(private readonly Journal $journal)
    {
    }

    #[OA\Tag(name: 'Journal')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'events', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'action', type: 'string', example: 'client.offline_usage'),
                    new OA\Property(property: 'message', type: 'string', example: 'Consultation hors-ligne'),
                    new OA\Property(property: 'context', type: 'object'),
                ],
            )),
        ],
    ))]
    #[OA\Response(response: 202, description: 'Événements acceptés')]
    #[Route('', methods: ['POST'])]
    public function collect(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Corps JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $events = isset($data['events']) && \is_array($data['events']) ? $data['events'] : [$data];

        $accepted = 0;
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $action = (string) ($event['action'] ?? '');
            if (!\in_array($action, self::ALLOWED, true)) {
                continue;
            }

            $context = \is_array($event['context'] ?? null) ? $event['context'] : [];
            $context['clientReported'] = true;
            if (isset($event['at'])) {
                $context['clientTime'] = (string) $event['at'];
            }

            $this->journal->record(
                $action,
                (string) ($event['message'] ?? $action),
                $context,
                $action === Journal::CLIENT_DISCONNECT ? 'warning' : 'info',
            );
            ++$accepted;
        }

        return $this->json(['accepted' => $accepted], Response::HTTP_ACCEPTED);
    }
}
