<?php
// src/Service/Temporal/Dto/SignalPayload.php
namespace App\Service\Temporal\Dto;

/** Charge utile d’un signal (encapsulée/serializable JSON) */
final class SignalPayload
{
    public function __construct(
        public readonly string $signalName, // ex: "submit"
        public readonly array $data         // ex: enveloppe { url_type, url_callback, base_url, payload, ... }
    ) {}
}
