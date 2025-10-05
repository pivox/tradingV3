<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration utilisateur pour les paramètres de trading
 */
#[ORM\Entity(repositoryClass: \App\Repository\UserConfigRepository::class)]
#[ORM\Table(name: 'user_config')]
class UserConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $configKey = 'default';

    // ===== High Conviction Strategy =====

    /**
     * Pourcentage du solde disponible à utiliser pour les positions High Conviction (0.0 à 1.0)
     */
    #[ORM\Column(type: 'float')]
    private float $hcMarginPct = 0.5;

    /**
     * Risque maximum par trade High Conviction (en pourcentage, 0.0 à 1.0)
     */
    #[ORM\Column(type: 'float')]
    private float $hcRiskMaxPct = 0.07;

    /**
     * Multiple de risque/récompense pour High Conviction
     */
    #[ORM\Column(type: 'float')]
    private float $hcRMultiple = 2.0;

    /**
     * Délai d'expiration des ordres High Conviction (en secondes)
     */
    #[ORM\Column(type: 'integer')]
    private int $hcExpireAfterSec = 120;

    // ===== Scalping Strategy =====

    /**
     * Marge en USDT pour les positions Scalping
     */
    #[ORM\Column(type: 'float')]
    private float $scalpMarginUsdt = 150.0;

    /**
     * Risque maximum par trade Scalping (en pourcentage, 0.0 à 1.0)
     */
    #[ORM\Column(type: 'float')]
    private float $scalpRiskMaxPct = 0.07;

    /**
     * Multiple de risque/récompense pour Scalping
     */
    #[ORM\Column(type: 'float')]
    private float $scalpRMultiple = 2.0;

    // ===== Métadonnées =====

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): self
    {
        $this->configKey = $configKey;
        $this->touch();
        return $this;
    }

    // ===== High Conviction Getters/Setters =====

    public function getHcMarginPct(): float
    {
        return $this->hcMarginPct;
    }

    public function setHcMarginPct(float $hcMarginPct): self
    {
        if ($hcMarginPct < 0.0 || $hcMarginPct > 1.0) {
            throw new \InvalidArgumentException('hcMarginPct must be between 0.0 and 1.0');
        }
        $this->hcMarginPct = $hcMarginPct;
        $this->touch();
        return $this;
    }

    public function getHcRiskMaxPct(): float
    {
        return $this->hcRiskMaxPct;
    }

    public function setHcRiskMaxPct(float $hcRiskMaxPct): self
    {
        if ($hcRiskMaxPct < 0.0 || $hcRiskMaxPct > 1.0) {
            throw new \InvalidArgumentException('hcRiskMaxPct must be between 0.0 and 1.0');
        }
        $this->hcRiskMaxPct = $hcRiskMaxPct;
        $this->touch();
        return $this;
    }

    public function getHcRMultiple(): float
    {
        return $this->hcRMultiple;
    }

    public function setHcRMultiple(float $hcRMultiple): self
    {
        if ($hcRMultiple <= 0.0) {
            throw new \InvalidArgumentException('hcRMultiple must be greater than 0');
        }
        $this->hcRMultiple = $hcRMultiple;
        $this->touch();
        return $this;
    }

    public function getHcExpireAfterSec(): int
    {
        return $this->hcExpireAfterSec;
    }

    public function setHcExpireAfterSec(int $hcExpireAfterSec): self
    {
        if ($hcExpireAfterSec <= 0) {
            throw new \InvalidArgumentException('hcExpireAfterSec must be greater than 0');
        }
        $this->hcExpireAfterSec = $hcExpireAfterSec;
        $this->touch();
        return $this;
    }

    // ===== Scalping Getters/Setters =====

    public function getScalpMarginUsdt(): float
    {
        return $this->scalpMarginUsdt;
    }

    public function setScalpMarginUsdt(float $scalpMarginUsdt): self
    {
        if ($scalpMarginUsdt < 0.0) {
            throw new \InvalidArgumentException('scalpMarginUsdt must be greater than or equal to 0');
        }
        $this->scalpMarginUsdt = $scalpMarginUsdt;
        $this->touch();
        return $this;
    }

    public function getScalpRiskMaxPct(): float
    {
        return $this->scalpRiskMaxPct;
    }

    public function setScalpRiskMaxPct(float $scalpRiskMaxPct): self
    {
        if ($scalpRiskMaxPct < 0.0 || $scalpRiskMaxPct > 1.0) {
            throw new \InvalidArgumentException('scalpRiskMaxPct must be between 0.0 and 1.0');
        }
        $this->scalpRiskMaxPct = $scalpRiskMaxPct;
        $this->touch();
        return $this;
    }

    public function getScalpRMultiple(): float
    {
        return $this->scalpRMultiple;
    }

    public function setScalpRMultiple(float $scalpRMultiple): self
    {
        if ($scalpRMultiple <= 0.0) {
            throw new \InvalidArgumentException('scalpRMultiple must be greater than 0');
        }
        $this->scalpRMultiple = $scalpRMultiple;
        $this->touch();
        return $this;
    }

    // ===== Métadonnées =====

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
