<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Neox\FireGeolocatorBundle\Repository\IpAttemptRepository;

#[ORM\Entity(repositoryClass: IpAttemptRepository::class)]
#[ORM\Table(name: 'geo_ip_attempt')]
#[ORM\Index(name: 'idx_geo_attempt_ip', columns: ['ip'])]
#[ORM\HasLifecycleCallbacks]
class IpAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 45)]
    private string $ip;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastAttemptAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $ip)
    {
        $this->ip            = $ip;
        $now                 = new \DateTimeImmutable();
        $this->lastAttemptAt = $now;
        $this->createdAt     = $now;
        $this->updatedAt     = $now;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt     ??= $now;
        $this->updatedAt     ??= $now;
        $this->lastAttemptAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = max(0, $attempts);

        return $this;
    }

    public function incrementAttempts(int $by = 1): self
    {
        $this->attempts += max(1, $by);
        $this->lastAttemptAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastAttemptAt(): \DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(\DateTimeImmutable $at): self
    {
        $this->lastAttemptAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
