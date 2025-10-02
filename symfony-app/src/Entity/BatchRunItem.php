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
#[ORM\Table(name: 'batch_run_item')]
#[ORM\UniqueConstraint(name: 'uniq_run_symbol', columns: [ 'run_key', 'symbol'])]
class BatchRunItem
{
    public const S_PENDING   = 'PENDING';
    public const S_PERSISTED = 'PERSISTED';
    public const S_VALIDATED = 'VALIDATED';
    public const S_FAILED    = 'FAILED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $runKey;

    #[ORM\Column(type: 'string', length: 64)]
    private string $symbol;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::S_PENDING;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null; // logs, counts…

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $persistedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    // getters/setters ...
}
