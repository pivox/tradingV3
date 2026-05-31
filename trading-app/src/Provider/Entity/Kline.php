<?php

declare(strict_types=1);

namespace App\Provider\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Repository\KlineRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KlineRepository::class)]
#[ORM\Table(name: 'klines')]
#[ORM\Index(name: 'idx_klines_symbol_tf', columns: ['exchange', 'market_type', 'symbol', 'timeframe'])]
#[ORM\Index(name: 'idx_klines_open_time', columns: ['open_time'])]
#[ORM\UniqueConstraint(name: 'ux_klines_exchange_market_symbol_tf_open', columns: ['exchange', 'market_type', 'symbol', 'timeframe', 'open_time'])]
class Kline implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: \App\Common\Enum\Timeframe::class)]
    private \App\Common\Enum\Timeframe $timeframe;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $openTime;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $openPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $highPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $lowPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $closePrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $volume = null;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'REST'])]
    private string $source = 'REST';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->insertedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(Exchange|string $exchange): static
    {
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower($exchange);
        return $this;
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function setMarketType(MarketType|string $marketType): static
    {
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower($marketType);
        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function getTimeframe(): \App\Common\Enum\Timeframe
    {
        return $this->timeframe;
    }

    public function setTimeframe(\App\Common\Enum\Timeframe $timeframe): static
    {
        $this->timeframe = $timeframe;
        return $this;
    }

    public function getOpenTime(): \DateTimeImmutable
    {
        return $this->openTime;
    }

    public function setOpenTime(\DateTimeImmutable $openTime): static
    {
        $this->openTime = $openTime;
        return $this;
    }

    public function getOpenPrice(): BigDecimal
    {
        return BigDecimal::of($this->openPrice);
    }

    public function setOpenPrice(BigDecimal $openPrice): static
    {
        $this->openPrice = $openPrice->toScale(12)->__toString();
        return $this;
    }

    public function getHighPrice(): BigDecimal
    {
        return BigDecimal::of($this->highPrice);
    }

    public function setHighPrice(BigDecimal $highPrice): static
    {
        $this->highPrice = $highPrice->toScale(12)->__toString();
        return $this;
    }

    public function getLowPrice(): BigDecimal
    {
        return BigDecimal::of($this->lowPrice);
    }

    public function setLowPrice(BigDecimal $lowPrice): static
    {
        $this->lowPrice = $lowPrice->toScale(12)->__toString();
        return $this;
    }

    public function getClosePrice(): BigDecimal
    {
        return BigDecimal::of($this->closePrice);
    }

    public function setClosePrice(BigDecimal $closePrice): static
    {
        $this->closePrice = $closePrice->toScale(12)->__toString();
        return $this;
    }

    public function getVolume(): ?BigDecimal
    {
        return $this->volume ? BigDecimal::of($this->volume) : null;
    }

    public function setVolume(?BigDecimal $volume): static
    {
        $this->volume = $volume?->toScale(12)->__toString();
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getInsertedAt(): \DateTimeImmutable
    {
        return $this->insertedAt;
    }

    public function setInsertedAt(\DateTimeImmutable $insertedAt): static
    {
        $this->insertedAt = $insertedAt;
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

    public function isBullish(): bool
    {
        return $this->getClosePrice()->isGreaterThan($this->getOpenPrice());
    }

    public function isBearish(): bool
    {
        return $this->getClosePrice()->isLessThan($this->getOpenPrice());
    }

    public function getBodySize(): BigDecimal
    {
        return $this->getClosePrice()->minus($this->getOpenPrice())->abs();
    }

    public function getWickSize(): BigDecimal
    {
        return $this->getHighPrice()->minus($this->getLowPrice())->minus($this->getBodySize());
    }

    public function getBodyPercentage(): float
    {
        $totalRange = $this->getHighPrice()->minus($this->getLowPrice());
        if ($totalRange->isZero()) {
            return 0.0;
        }

        return $this->getBodySize()->dividedBy($totalRange, 12, \Brick\Math\RoundingMode::HALF_UP)->toFloat();
    }

    public function getChangePercentage(): float
    {
        $openPrice = $this->getOpenPrice();
        $closePrice = $this->getClosePrice();

        if ($openPrice->isZero()) {
            return 0.0;
        }

        $change = $closePrice->minus($openPrice);
        $percentage = $change->dividedBy($openPrice, 12, \Brick\Math\RoundingMode::HALF_UP)->multipliedBy(100);

        return $percentage->toFloat();
    }

    // Méthodes pour l'affichage dans Twig
    public function getOpenPriceFloat(): float
    {
        return $this->getOpenPrice()->toFloat();
    }

    public function getHighPriceFloat(): float
    {
        return $this->getHighPrice()->toFloat();
    }

    public function getLowPriceFloat(): float
    {
        return $this->getLowPrice()->toFloat();
    }

    public function getClosePriceFloat(): float
    {
        return $this->getClosePrice()->toFloat();
    }

    public function getVolumeFloat(): ?float
    {
        return $this->getVolume()?->toFloat();
    }

    public function jsonSerialize(): mixed
    {
        return [
            'exchange' => $this->exchange,
            'marketType' => $this->marketType,
            'timeframe' => $this->timeframe->value,
            'openTime' => $this->openTime->format(DATE_RFC3339),
            'openPrice' => $this->openPrice,
            'highPrice' => $this->highPrice,
            'lowPrice' => $this->lowPrice,
            'closePrice' => $this->closePrice,
            'volume' => $this->volume
        ];
    }
}
