<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

use App\Indicator\ConditionLoader\Cards\AbstractCard;

class TimeframeValidation extends AbstractCard
{
    const TF_4H  = '4h';
    const TF_1H  = '1h';
    const TF_15M = '15m';
    const TF_5M  = '5m';
    const TF_1M  = '1m';

    private string $timeframe = self::TF_4H;
    private ?Side $long = null;
    private ?Side $short = null;

    public function fill(string|array $data): static
    {
        if (isset($data[Side::LONG])) {
            $this->long = (new Side())
                ->withSide(Side::LONG)
                ->fill($data[Side::LONG]);
        }

        if (isset($data[Side::SHORT])) {
            $this->short = (new Side())
                ->withSide(Side::SHORT)
                ->fill($data[Side::SHORT]);
        }

        return $this;
    }

    public function withTimeframe(string $tf): static
    {
        $this->timeframe = $tf;
        return $this;
    }

    public function evaluate(array $context): array
    {
        $payload = $context;
        $payload['__timeframe'] = $this->timeframe;

        $result = [
            'timeframe' => $this->timeframe,
        ];

        if ($this->long instanceof Side) {
            $longResult = $this->long->evaluate($payload);
            $result[Side::LONG] = $longResult;
            $result['passed'][Side::LONG] = $this->long->isValid();
        }

        if ($this->short instanceof Side) {
            $shortResult = $this->short->evaluate($payload);
            $result[Side::SHORT] = $shortResult;
            $result['passed'][Side::SHORT] = $this->short->isValid();
        }

        if (!isset($result['passed'])) {
            $result['passed'] = [];
        }

        return $result;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function getLong(): ?Side
    {
        return $this->long;
    }

    public function getShort(): ?Side
    {
        return $this->short;
    }
}
