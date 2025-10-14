<?php

namespace App\Entity;

use App\Repository\BlacklistedContractRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlacklistedContractRepository::class)]
class BlacklistedContract
{
    const REASON_MANUAL = 'manual';
    const REASON_DESLIST = 'deslist';
    const REASON_ILLIQUID = 'illiquid';
    const REASON_POSITION_OPENED = 'position_opened';
    const REASON_POSITION_RECENTLY_CLOSED = 'recently_closed';
    const REASON_TOO_MANY_ERRORS = 'too_many_errors';
    const REASON_NO_RESPONSE = 'no_response';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $symbol = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(string $symbol, string $reason, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->symbol = strtoupper($symbol);
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(?string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
