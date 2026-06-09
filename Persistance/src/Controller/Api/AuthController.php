<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

final class AuthController extends AbstractController
{
    #[OA\Tag(name: 'Auth')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['email', 'password'],
        properties: [
            new OA\Property(property: 'email', type: 'string', example: 'yann@example.com'),
            new OA\Property(property: 'password', type: 'string', example: 'secret123'),
        ],
    ))]
    #[OA\Response(response: 200, description: 'JWT', content: new OA\JsonContent(
        properties: [new OA\Property(property: 'token', type: 'string')],
    ))]
    #[OA\Response(response: 401, description: 'Identifiants invalides')]
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('Cette route est gérée par le firewall json_login.');
    }
}
