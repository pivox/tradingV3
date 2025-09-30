<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\DataProvider\KlineProvider;
use App\Service\Exchange\Bitmart\Dto\KlineDto;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/klines'
        ),
        new Get(
            uriTemplate: '/klines/{id}',
        ),
        new Post(
            uriTemplate: '/klines',
        )
    ],
    provider: KlineProvider::class
)]
#[ApiFilter(SearchFilter::class, properties: [
    'contract.symbol' => 'exact',
    'step' => 'exact',
    'contract.exchange.name' => 'exact',
])]
#[ORM\UniqueConstraint(
    name: 'uniq_kline_contract_step_ts',
    columns: ['contract_id', 'step', 'timestamp']
)]
class Kline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $timestamp;

    #[ORM\Column(type: 'float')]
    private float $open;

    #[ORM\Column(type: 'float')]
    private float $close;

    #[ORM\Column(type: 'float')]
    private float $high;

    #[ORM\Column(type: 'float')]
    private float $low;

    #[ORM\Column(type: 'float')]
    private float $volume;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(referencedColumnName: "symbol", nullable: false)]
    private Contract $contract;

    #[ORM\Column(type: 'integer', options: ['default' => '900'])]
    private int $step = 900;

    public function getContract(): Contract { return $this->contract; }
    public function setContract(Contract $contract): self { $this->contract = $contract; return $this; }

    public function fillFromDto(KlineDto $dto, Contract $contract, int $step): self
    {
        $this->timestamp = (new \DateTimeImmutable())->setTimestamp($dto->timestamp);
        $this->open = $dto->open;
        $this->close = $dto->close;
        $this->high = $dto->high;
        $this->low = $dto->low;
        $this->volume = $dto->volume;
        $this->contract = $contract;
        $this->step = $step;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTimestamp(): \DateTimeInterface
    {
        return $this->timestamp;
    }

    public function getOpen(): float
    {
        return $this->open;
    }

    public function getClose(): float
    {
        return $this->close;
    }

    public function getHigh(): float
    {
        return $this->high;
    }

    public function getLow(): float
    {
        return $this->low;
    }

    public function getVolume(): float
    {
        return $this->volume;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'timestamp' => $this->timestamp->getTimestamp(), // ou format('Y-m-d H:i:s')
            'open'      => $this->open,
            'close'     => $this->close,
            'high'      => $this->high,
            'low'       => $this->low,
            'volume'    => $this->volume,
            'step'      => $this->step,
            'contract'  => $this->contract->getSymbol(),
        ];
    }


}
