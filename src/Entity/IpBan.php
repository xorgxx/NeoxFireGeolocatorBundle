<?php

// File: src/Entity/IpBan.php
declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Neox\FireGeolocatorBundle\Repository\IpBanRepository;

#[ORM\Entity(repositoryClass: IpBanRepository::class)]
#[ORM\Table(name: 'geo_ip_ban')]
#[ORM\UniqueConstraint(name: 'uniq_geo_ban_ip', columns: ['ip'])]
#[ORM\Index(name: 'idx_geo_ban_ip', columns: ['ip'])]
#[ORM\HasLifecycleCallbacks]
class IpBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // IPv4/IPv6 compatible
    #[ORM\Column(type: 'string', length: 45)]
    private string $ip;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $hits = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $ip)
    {
        $this->ip        = $ip;
        $now             = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
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

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function setHits(int $hits): self
    {
        $this->hits = max(0, $hits);

        return $this;
    }

    public function incrementHits(int $by = 1): self
    {
        $this->hits += max(1, $by);

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

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

    public function isActive(?\DateTimeImmutable $ref = null): bool
    {
        $ref ??= new \DateTimeImmutable();

        return $this->expiresAt === null || $this->expiresAt > $ref;
    }
}
