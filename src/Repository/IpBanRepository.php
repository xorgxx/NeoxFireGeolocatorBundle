<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Neox\FireGeolocatorBundle\Entity\IpBan;

/**
 * @extends ServiceEntityRepository<IpBan>
 */
class IpBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpBan::class);
    }

    public function findOneByIp(string $ip): ?IpBan
    {
        return $this->findOneBy(['ip' => $ip]);
    }

    public function ban(string $ip, ?\DateTimeImmutable $expiresAt, ?string $reason = null, ?string $source = null): IpBan
    {
        $em  = $this->getEntityManager();
        $ban = $this->findOneByIp($ip) ?? new IpBan($ip);

        $ban->setExpiresAt($expiresAt)
            ->setReason($reason)
            ->setSource($source);

        $em->persist($ban);
        $em->flush();

        return $ban;
    }

    public function banFor(string $ip, int $seconds, ?string $reason = null, ?string $source = null): IpBan
    {
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(0, $seconds) . 'S'));

        return $this->ban($ip, $expiresAt, $reason, $source);
    }

    public function incrementHits(string $ip, int $by = 1): IpBan
    {
        $em  = $this->getEntityManager();
        $ban = $this->findOneByIp($ip) ?? new IpBan($ip);
        $ban->incrementHits($by);
        $em->persist($ban);
        $em->flush();

        return $ban;
    }

    public function isBanned(string $ip, ?\DateTimeImmutable $ref = null): bool
    {
        $ban = $this->findOneByIp($ip);

        return $ban ? $ban->isActive($ref) : false;
    }

    public function unban(string $ip): void
    {
        $em  = $this->getEntityManager();
        $ban = $this->findOneByIp($ip);
        if ($ban) {
            $em->remove($ban);
            $em->flush();
        }
    }
}
