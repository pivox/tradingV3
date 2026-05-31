<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ExchangeEventNormalizerRegistry
{
    /**
     * @param iterable<ExchangeEventNormalizerInterface> $normalizers
     */
    public function __construct(
        #[AutowireIterator('app.exchange_event_normalizer')]
        private iterable $normalizers,
    ) {
    }

    /**
     * @return ExchangeEventInterface[]
     */
    public function normalize(mixed $event): array
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($event)) {
                return $normalizer->normalize($event);
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'No exchange event normalizer supports "%s"',
            is_object($event) ? $event::class : get_debug_type($event),
        ));
    }
}
