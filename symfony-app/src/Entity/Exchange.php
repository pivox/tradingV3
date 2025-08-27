<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ApiResource]
class Exchange
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    #[ApiProperty(identifier: true)] // ✅ Dit à API Platform d'utiliser ce champ dans les URLs
    private string $name;

    #[ORM\OneToMany(mappedBy: 'exchange', targetEntity: Contract::class)]
    private Collection $contracts;

    public function __construct()
    {
        $this->contracts = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getContracts(): Collection { return $this->contracts; }

    public function __toString(): string
    {
        return $this->name;
    }
}
