<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MtfSwitchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MtfSwitchRepository::class)]
#[ORM\Table(name: 'mtf_switch')]
#[ORM\Index(name: 'idx_mtf_switch_key', columns: ['switch_key'])]
#[ORM\UniqueConstraint(name: 'ux_mtf_switch_key', columns: ['switch_key'])]
class MtfSwitch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $switchKey;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isOn = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'postgres_timestamp')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'postgres_timestamp')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'postgres_timestamp', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSwitchKey(): string
    {
        return $this->switchKey;
    }

    public function setSwitchKey(string $switchKey): static
    {
        $this->switchKey = $switchKey;
        return $this;
    }

    public function isOn(): bool
    {
        return $this->isOn;
    }

    public function setIsOn(bool $isOn): static
    {
        $this->isOn = $isOn;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function isOff(): bool
    {
        return !$this->isOn;
    }

    public function turnOn(): static
    {
        return $this->setIsOn(true);
    }

    public function turnOff(): static
    {
        return $this->setIsOn(false);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        
        return $this->expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function createGlobalSwitch(): self
    {
        $switch = new self();
        $switch->setSwitchKey('GLOBAL');
        $switch->setDescription('Kill switch global pour tout le système MTF');
        return $switch;
    }

    public static function createSymbolSwitch(string $symbol): self
    {
        $switch = new self();
        $switch->setSwitchKey("SYMBOL:{$symbol}");
        $switch->setDescription("Kill switch pour le symbole {$symbol}");
        return $switch;
    }

    public static function createSymbolTimeframeSwitch(string $symbol, string $timeframe): self
    {
        $switch = new self();
        $switch->setSwitchKey("SYMBOL_TF:{$symbol}:{$timeframe}");
        $switch->setDescription("Kill switch pour {$symbol} sur {$timeframe}");
        return $switch;
    }

    public function isGlobal(): bool
    {
        return $this->switchKey === 'GLOBAL';
    }

    public function isSymbolSwitch(): bool
    {
        return str_starts_with($this->switchKey, 'SYMBOL:') && !str_contains($this->switchKey, ':');
    }

    public function isSymbolTimeframeSwitch(): bool
    {
        return str_starts_with($this->switchKey, 'SYMBOL_TF:');
    }

    public function getSymbol(): ?string
    {
        if ($this->isSymbolSwitch()) {
            return substr($this->switchKey, 7); // Remove 'SYMBOL:'
        }
        
        if ($this->isSymbolTimeframeSwitch()) {
            $parts = explode(':', $this->switchKey);
            return $parts[1] ?? null;
        }
        
        return null;
    }

    public function getTimeframe(): ?string
    {
        if ($this->isSymbolTimeframeSwitch()) {
            $parts = explode(':', $this->switchKey);
            return $parts[2] ?? null;
        }
        
        return null;
    }
}
