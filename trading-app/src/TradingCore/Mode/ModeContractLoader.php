<?php

declare(strict_types=1);

namespace App\TradingCore\Mode;

use App\TradingCore\Mode\Exception\ModeContractException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ModeContractLoader
{
    public function __construct(
        private ?string $contractRoot = null,
        private ?ModeContractValidator $validator = null,
    ) {
    }

    public function load(string $modeId, string $modeVersion): ModeContract
    {
        if (!in_array($modeId, ModeContractValidator::MODE_IDS, true)) {
            throw new ModeContractException(sprintf('Unknown modern mode id "%s".', $modeId));
        }
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $modeVersion) !== 1) {
            throw new ModeContractException(sprintf('Invalid semantic mode version "%s".', $modeVersion));
        }

        $path = $this->pathFor($modeId, $modeVersion);
        if (!is_file($path)) {
            throw new ModeContractException(sprintf('Unknown version "%s" for modern mode "%s"; no fallback is allowed.', $modeVersion, $modeId));
        }

        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new ModeContractException(sprintf('Mode contract could not be parsed: %s', $path), previous: $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new ModeContractException('Mode contract must contain a YAML mapping.');
        }

        /** @var array<string, mixed> $document */
        if (
            isset($document['mode_id'], $document['mode_version'])
            && is_string($document['mode_id'])
            && is_string($document['mode_version'])
            && ($document['mode_id'] !== $modeId || $document['mode_version'] !== $modeVersion)
        ) {
            throw new ModeContractException('Contract identity does not match requested mode/version.');
        }
        return ModeContract::fromDocument($document, $this->validator);
    }

    public function pathFor(string $modeId, string $modeVersion): string
    {
        return $this->root() . '/' . $modeId . '/' . $modeVersion . '.yaml';
    }

    private function root(): string
    {
        return rtrim($this->contractRoot ?? dirname(__DIR__, 3) . '/config/trading/mode_contract', '/');
    }
}
