<?php

declare(strict_types=1);

namespace App\MtfValidator\Entity;

use App\MtfValidator\Repository\MtfRunSymbolRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MtfRunSymbolRepository::class)]
#[ORM\Table(name: 'mtf_run_symbol')]
#[ORM\UniqueConstraint(name: 'uniq_mtf_run_symbol', columns: ['run_id', 'symbol'])]
#[ORM\Index(name: 'idx_mtf_run_symbol_run_id', columns: ['run_id'])]
#[ORM\Index(name: 'idx_mtf_run_symbol_symbol', columns: ['symbol'])]
#[ORM\Index(name: 'idx_mtf_run_symbol_status', columns: ['status'])]
#[ORM\Index(name: 'idx_mtf_run_symbol_blocking_tf', columns: ['blocking_tf'])]
#[ORM\Index(name: 'idx_mtf_run_symbol_exec_tf', columns: ['execution_tf'])]
class MtfRunSymbol
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MtfRun::class)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private MtfRun $run;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $status;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $executionTf = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $blockingTf = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    private ?string $signalSide = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $currentPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $atr = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $validationModeUsed = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    private ?string $tradeEntryModeUsed = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tradingDecision = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $error = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(MtfRun $run, string $symbol)
    {
        $this->run = $run;
        $this->symbol = $symbol;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = 'UNKNOWN';
    }

    public function setFromArray(array $arr): self
    {
        $this->status = (string)($arr['status'] ?? 'UNKNOWN');
        $this->executionTf = isset($arr['execution_tf']) ? (string)$arr['execution_tf'] : null;
        $this->blockingTf = isset($arr['blocking_tf']) ? (string)$arr['blocking_tf'] : null;
        $this->signalSide = isset($arr['signal_side']) ? (string)$arr['signal_side'] : null;
        $this->currentPrice = isset($arr['current_price']) ? (string)$arr['current_price'] : null;
        $this->atr = isset($arr['atr']) ? (string)$arr['atr'] : null;
        $this->validationModeUsed = isset($arr['validation_mode_used']) ? (string)$arr['validation_mode_used'] : null;
        $this->tradeEntryModeUsed = isset($arr['trade_entry_mode_used']) ? (string)$arr['trade_entry_mode_used'] : null;
        $this->tradingDecision = isset($arr['trading_decision']) && is_array($arr['trading_decision']) ? $arr['trading_decision'] : null;
        $this->error = isset($arr['error']) && is_array($arr['error']) ? $arr['error'] : null;
        $this->context = isset($arr['context']) && is_array($arr['context']) ? $arr['context'] : null;
        return $this;
    }

    public function getRun(): MtfRun { return $this->run; }
    public function getSymbol(): string { return $this->symbol; }
    public function getStatus(): string { return $this->status; }
    public function getExecutionTf(): ?string { return $this->executionTf; }
    public function getBlockingTf(): ?string { return $this->blockingTf; }
    public function getSignalSide(): ?string { return $this->signalSide; }
    public function getCurrentPrice(): ?string { return $this->currentPrice; }
    public function getAtr(): ?string { return $this->atr; }
    public function getValidationModeUsed(): ?string { return $this->validationModeUsed; }
    public function getTradeEntryModeUsed(): ?string { return $this->tradeEntryModeUsed; }
    public function getTradingDecision(): ?array { return $this->tradingDecision; }
    public function getError(): ?array { return $this->error; }
    public function getContext(): ?array { return $this->context; }
}
