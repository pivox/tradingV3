<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\MtfPlanRepository::class)]
#[ORM\Table(name: 'mtf_contract_plan')]
class MtfPlan
{
    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $symbol;

    #[ORM\Column(type: 'boolean')] private bool $enabled4h = true;
    #[ORM\Column(type: 'boolean')] private bool $enabled1h = true;
    #[ORM\Column(type: 'boolean')] private bool $enabled15m = true;
    #[ORM\Column(type: 'boolean')] private bool $enabled5m = true;
    #[ORM\Column(type: 'boolean')] private bool $enabled1m = true;

    #[ORM\Column(type: 'boolean')] private bool $cascadeParents = true;

    #[ORM\Column(length: 255, nullable: true)] private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $updatedAt;

    public function __construct(string $symbol)
    {
        $this->symbol = $symbol;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }

    // getters/setters...
}
