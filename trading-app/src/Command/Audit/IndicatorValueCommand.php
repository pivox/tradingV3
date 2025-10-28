<?php

declare(strict_types=1);

namespace App\Command\Audit;

use App\Common\Enum\Timeframe;
use App\Repository\IndicatorSnapshotRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'indicator:value',
    description: 'Retourne la valeur de l\'indicateur demandé pour une kline donnée.'
)]
class IndicatorValueCommand extends Command
{
    public function __construct(
        private readonly IndicatorSnapshotRepository $snapshotRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('indicator', InputArgument::REQUIRED, 'Nom de l\'indicateur (ex: ema20, macd_signal, rsi)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1m, 5m, 15m, 1h, 4h)')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole (ex: BTCUSDT)')
            ->addArgument('currentDate', InputArgument::REQUIRED, 'Date d\'ouverture de la kline (ISO8601 ou Y-m-d H:i:s)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $indicatorInput = (string) $input->getArgument('indicator');
        $timeframeInput = (string) $input->getArgument('timeframe');
        $symbolInput = (string) $input->getArgument('symbol');
        $dateInput = (string) $input->getArgument('currentDate');

        try {
            $timeframe = Timeframe::from($timeframeInput);
        } catch (\ValueError $e) {
            $io->error(sprintf(
                'Timeframe "%s" invalide. Valeurs possibles: %s',
                $timeframeInput,
                implode(', ', array_map(static fn(Timeframe $tf): string => $tf->value, Timeframe::cases()))
            ));
            return Command::FAILURE;
        }

        try {
            $klineTime = new \DateTimeImmutable($dateInput);
        } catch (\Exception $e) {
            $io->error(sprintf('Date "%s" invalide: %s', $dateInput, $e->getMessage()));
            return Command::FAILURE;
        }

        $normalizedSymbol = strtoupper($symbolInput);
        $klineTimeUtc = $klineTime->setTimezone(new \DateTimeZone('UTC'));

        $snapshot = $this->snapshotRepository->findOneBy([
            'symbol' => $normalizedSymbol,
            'timeframe' => $timeframe,
            'klineTime' => $klineTimeUtc,
        ]);

        if (null === $snapshot) {
            $io->error(sprintf(
                'Aucun snapshot trouvé pour %s %s à %s',
                $normalizedSymbol,
                $timeframe->value,
                $klineTimeUtc->format('Y-m-d H:i:sP')
            ));
            return Command::FAILURE;
        }

        $values = $snapshot->getValues();
        $result = $this->resolveIndicatorValue($values, $indicatorInput);

        if (false === $result['found']) {
            $io->error(sprintf(
                'Indicateur "%s" introuvable pour ce snapshot. Indicateurs disponibles: %s',
                $indicatorInput,
                implode(', ', $this->formatAvailableIndicators($values))
            ));
            return Command::FAILURE;
        }

        $value = $result['value'];

        if (\is_array($value)) {
            $io->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
        } elseif (null === $value) {
            $io->writeln('null');
        } else {
            $io->writeln((string) $value);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $values
     * @return array{found: bool, value: mixed}
     */
    private function resolveIndicatorValue(array $values, string $indicator): array
    {
        $parts = array_filter(
            preg_split('/[.>]/', $indicator) ?: [],
            static fn(string $part): bool => $part !== ''
        );

        if (empty($parts)) {
            return ['found' => false, 'value' => null];
        }

        $current = $values;
        foreach ($parts as $part) {
            if (!\is_array($current)) {
                return ['found' => false, 'value' => null];
            }

            $matchedKey = $this->findMatchingKey($current, $part);
            if (null === $matchedKey) {
                return ['found' => false, 'value' => null];
            }

            $current = $current[$matchedKey];
        }

        return ['found' => true, 'value' => $current];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function findMatchingKey(array $values, string $candidate): ?string
    {
        $normalizedCandidate = $this->normalizeKey($candidate);

        foreach ($values as $key => $_value) {
            if ($this->normalizeKey((string) $key) === $normalizedCandidate) {
                return (string) $key;
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/', '', $key));
    }

    /**
     * @param array<string, mixed> $values
     * @return string[]
     */
    private function formatAvailableIndicators(array $values): array
    {
        return $this->flattenKeys($values);
    }

    /**
     * @param array<string, mixed> $values
     * @return string[]
     */
    private function flattenKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            $keys[] = $fullKey;

            if (\is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            }
        }

        return $keys;
    }
}

