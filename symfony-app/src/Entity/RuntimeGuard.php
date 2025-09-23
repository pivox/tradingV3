<?php
// src/Entity/RuntimeGuard.php
namespace App\Entity;

use App\Repository\RuntimeGuardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RuntimeGuardRepository::class)]
#[ORM\Table(name: 'runtime_guard')]
class RuntimeGuard
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $guard = 'trading'; // clé unique fonctionnelle

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $paused = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $guard = 'trading')
    {
        $this->guard = $guard;
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function getGuard(): string { return $this->guard; }

    public function isPaused(): bool { return $this->paused; }

    public function getReason(): ?string { return $this->reason; }

    public function pause(?string $reason = null): void
    {
        $this->paused = true;
        $this->reason = $reason;
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function resume(): void
    {
        $this->paused = false;
        $this->reason = null;
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
