<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'blacklisted_contracts')]
#[ORM\UniqueConstraint(name: 'uniq_blacklist_symbol', columns: ['symbol'])]
class BlacklistedContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 50)]
    private string $symbol; // ex: BTCUSDT

    #[ORM\Column(type: 'string', length: 20)]
    private string $reason; // ex: 'delist', 'recent', 'illiquid', 'manual'

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;
    // optionnel : permet un blacklist temporaire

    // --- NOUVEAUX CHAMPS ---
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $noResponseStreak = 0;  // nb de fois (consécutives) sans réponse

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastNoResponseAt = null; // horodatage du dernier "no response"


    public function __construct(string $symbol, string $reason, ?DateTimeImmutable $expiresAt = null)
    {
        $this->symbol = strtoupper($symbol);
        $this->reason = $reason;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    // ---- Getters ----
    public function getId(): int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    // ---- Helpers ----
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable();
    }

    /** Appelé quand on constate un “no response ≥ 10 min” pour ce symbole. */
    public function registerNoResponse(): void
    {
        $this->noResponseStreak++;
        $this->lastNoResponseAt = new DateTimeImmutable();
        // si la raison n'est pas déjà "no_response", on la met (optionnel)
        if ($this->reason !== 'no_response') {
            $this->reason = 'no_response';
        }
    }

    /** Appelé dès qu’on reçoit une réponse valide pour ce symbole. */
    public function resetNoResponse(): void
    {
        $this->noResponseStreak = 0;
        // on conserve lastNoResponseAt en historique ; sinon mettre à null si tu préfères
    }

    /** Devient “à ignorer” si 6 échecs “no response” consécutifs. */
    public function shouldIgnoreFromNow(): bool
    {
        return $this->noResponseStreak >= 6;
    }
}
