<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'batch_run')]
#[ORM\UniqueConstraint(name: 'uniq_run_key', columns: ['run_key'])]
class BatchRun
{
    public const STATUS_RUNNING  = 'RUNNING';
    public const STATUS_COMPLETE = 'COMPLETE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $runKey; // ex: "15m@2025-09-25T13:30:00Z"

    #[ORM\Column(type: 'string', length: 8)]
    private string $timeframe; // '4h','1h','15m','5m'

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endAtUtc; // cutoff aligné

    #[ORM\Column(type: 'integer')]
    private int $expectedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default'=>0])]
    private int $persistedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default'=>0])]
    private int $validatedCount = 0;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    // getters/setters ...
}
