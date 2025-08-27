<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Neox\FireGeolocatorBundle\Entity\IpAttempt;

/**
 * @extends ServiceEntityRepository<IpAttempt>
 */
class IpAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpAttempt::class);
    }

    public function findOneByIp(string $ip): ?IpAttempt
    {
        return $this->findOneBy(['ip' => $ip]);
    }

    public function increment(string $ip, int $by = 1): IpAttempt
    {
        $em      = $this->getEntityManager();
        $attempt = $this->findOneByIp($ip) ?? new IpAttempt($ip);
        $attempt->incrementAttempts($by);
        $em->persist($attempt);
        $em->flush();

        return $attempt;
    }

    public function reset(string $ip): void
    {
        $em      = $this->getEntityManager();
        $attempt = $this->findOneByIp($ip);
        if ($attempt) {
            $em->remove($attempt);
            $em->flush();
        }
    }
}
