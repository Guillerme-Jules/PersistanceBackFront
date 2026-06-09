<?php

namespace App\Repository;

use App\Entity\Search;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Search::class);
    }

    public function save(Search $search, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($search);
        if ($flush) {
            $em->flush();
        }
    }

    public function findOneForUser(Uuid $id, User $user): ?Search
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function findHistory(User $user, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
