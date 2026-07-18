<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakePrivateWsScenario
{
    /**
     * @param list<FakePrivateWsDelivery> $deliveries
     */
    public function __construct(
        public string $scenarioId,
        public array $deliveries,
    ) {
        if (trim($this->scenarioId) === '') {
            throw new \InvalidArgumentException('fake_private_ws_scenario_id_invalid');
        }
        if (!array_is_list($this->deliveries)) {
            throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
        }
        foreach ($this->deliveries as $delivery) {
            if (!$delivery instanceof FakePrivateWsDelivery) {
                throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
            }
        }
    }

    /**
     * @param list<FakeExchangeEvent> $events
     */
    public static function fromEvents(string $scenarioId, array $events): self
    {
        if (!array_is_list($events)) {
            throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
        }

        $deliveries = [];
        foreach ($events as $index => $event) {
            if (!$event instanceof FakeExchangeEvent) {
                throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
            }
            $deliveries[] = FakePrivateWsDelivery::fromEvent(
                sprintf('%s-%04d', $scenarioId, $index + 1),
                $event,
            );
        }

        return new self($scenarioId, $deliveries);
    }

    /**
     * @return array{
     *     scenario_id:string,
     *     deliveries:list<array{
     *         fixture_entry_id:string,
     *         sequence:string,
     *         event:array{type:string,symbol:string,occurred_at:string,payload:array<string,mixed>},
     *         fingerprint:string
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'scenario_id' => $this->scenarioId,
            'deliveries' => array_map(
                static fn (FakePrivateWsDelivery $delivery): array => $delivery->toArray(),
                $this->deliveries,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $scenarioId = $payload['scenario_id'] ?? null;
        $deliveriesPayload = $payload['deliveries'] ?? null;
        if (
            !\is_string($scenarioId)
            || !\is_array($deliveriesPayload)
            || !array_is_list($deliveriesPayload)
        ) {
            throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
        }

        $deliveries = [];
        foreach ($deliveriesPayload as $deliveryPayload) {
            if (!\is_array($deliveryPayload)) {
                throw new \InvalidArgumentException('fake_private_ws_scenario_deliveries_invalid');
            }
            $deliveries[] = FakePrivateWsDelivery::fromArray($deliveryPayload);
        }

        return new self($scenarioId, $deliveries);
    }
}
