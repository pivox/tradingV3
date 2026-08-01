<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class SetupContractLoader
{
    public function __construct(private ?string $contractRoot = null, private ?SetupContractValidator $validator = null)
    {
    }

    public function load(string $setupId, string $setupVersion): SetupContract
    {
        if (!in_array($setupId, SetupContractValidator::SETUP_IDS, true)) {
            throw new SetupContractException(sprintf('Unknown canonical setup id "%s".', $setupId));
        }
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $setupVersion) !== 1) {
            throw new SetupContractException(sprintf('Invalid semantic setup version "%s".', $setupVersion));
        }
        $path = $this->root() . '/' . $setupId . '/' . $setupVersion . '.yaml';
        if (!is_file($path)) {
            throw new SetupContractException(sprintf('Unknown version "%s" for setup "%s"; no alias or fallback is allowed.', $setupVersion, $setupId));
        }
        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new SetupContractException(sprintf('Setup contract could not be parsed: %s', $path), previous: $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new SetupContractException('Setup contract must contain a YAML mapping.');
        }
        if (($document['setup_id'] ?? null) !== $setupId || ($document['setup_version'] ?? null) !== $setupVersion) {
            throw new SetupContractException('Contract identity does not match requested setup/version.');
        }

        /** @var array<string, mixed> $document */
        return SetupContract::fromDocument($document, $this->validator);
    }

    private function root(): string
    {
        return rtrim($this->contractRoot ?? dirname(__DIR__, 3) . '/config/trading/setup_contract', '/');
    }
}
