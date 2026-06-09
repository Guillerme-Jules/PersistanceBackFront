<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;

#[Route('/api/register')]
final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[OA\Tag(name: 'Auth')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['email', 'password'],
        properties: [
            new OA\Property(property: 'email', type: 'string', example: 'yann@example.com'),
            new OA\Property(property: 'password', type: 'string', example: 'secret123'),
        ],
    ))]
    #[OA\Response(response: 201, description: 'Compte créé')]
    #[OA\Response(response: 409, description: 'Email déjà utilisé')]
    #[Route('', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = \is_array($data) ? trim((string) ($data['email'] ?? '')) : '';
        $password = \is_array($data) ? (string) ($data['password'] ?? '') : '';

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL) || \strlen($password) < 6) {
            return $this->json(
                ['error' => 'Email invalide ou mot de passe trop court (min. 6 caractères).'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            return $this->json(['error' => 'Cet email est déjà utilisé.'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $this->json(['id' => $user->getId(), 'email' => $user->getEmail()], Response::HTTP_CREATED);
    }
}
