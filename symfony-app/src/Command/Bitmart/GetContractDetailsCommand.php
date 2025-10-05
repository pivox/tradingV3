<?php
// src/Command/Bitmart/GetContractDetailsCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Dto\ContractDetailsDto;
use App\Dto\ContractDetailsCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-contract-details',
    description: 'Affiche les détails de contrat Futures V2 (BitMart) — optionnellement pour un symbole donné',
)]
final class GetContractDetailsCommand extends Command
{
    public function __construct(
        private readonly BitmartHttpClientPublic $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole ex: BTCUSDT')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie JSON brute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $symbol = $input->getArgument('symbol');
        $asJson = (bool) $input->getOption('json');
        $tz     = new \DateTimeZone('Europe/Paris');

        try {
            /** @var ContractDetailsCollection $details */
            $details = $this->client->getContractDetails($symbol ?: null);

            if ($details->isEmpty()) {
                $io->warning('Aucun contrat trouvé.');
                return Command::SUCCESS;
            }

            if ($asJson) {
                $payload = array_map(
                    static fn(ContractDetailsDto $d) => [
                        'symbol'                  => $d->symbol,
                        'product_type'            => $d->productType,
                        'open_timestamp_ms'       => $d->openTimestampMs,
                        'expire_timestamp_ms'     => $d->expireTimestampMs,
                        'settle_timestamp_ms'     => $d->settleTimestampMs,
                        'base_currency'           => $d->baseCurrency,
                        'quote_currency'          => $d->quoteCurrency,
                        'last_price'              => $d->lastPrice,
                        'volume_24h'              => $d->volume24h,
                        'turnover_24h'            => $d->turnover24h,
                        'index_price'             => $d->indexPrice,
                        'index_name'              => $d->indexName,
                        'contract_size'           => $d->contractSize,
                        'min_leverage'            => $d->minLeverage,
                        'max_leverage'            => $d->maxLeverage,
                        'price_precision'         => $d->pricePrecision,
                        'vol_precision'           => $d->volPrecision,
                        'max_volume'              => $d->maxVolume,
                        'min_volume'              => $d->minVolume,
                        'market_max_volume'       => $d->marketMaxVolume,
                        'funding_rate'            => $d->fundingRate,
                        'expected_funding_rate'   => $d->expectedFundingRate,
                        'open_interest'           => $d->openInterest,
                        'open_interest_value'     => $d->openInterestValue,
                        'high_24h'                => $d->high24h,
                        'low_24h'                 => $d->low24h,
                        'change_24h'              => $d->change24h,
                        'funding_interval_hours'  => $d->fundingIntervalHours,
                        'status'                  => $d->status,
                        'delist_time_sec'         => $d->delistTimeSec,
                    ],
                    $details->all()
                );
                $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            $io->title(sprintf(
                'Détails contrat(s) %s',
                $symbol ? "pour $symbol" : '(tous)'
            ));

            foreach ($details as $d) {
                // helpers d’affichage (Europe/Paris)
                $fmtMs = function (?int $ms) use ($tz): string {
                    if (!$ms) return '-';
                    $dt = (new \DateTimeImmutable('@'.(int) \floor($ms / 1000)))->setTimezone($tz);
                    return $dt->format('Y-m-d H:i:s');
                };
                $fmtSec = function (?int $s) use ($tz): string {
                    if (!$s) return '-';
                    $dt = (new \DateTimeImmutable('@'.$s))->setTimezone($tz);
                    return $dt->format('Y-m-d H:i:s');
                };

                $io->section($d->symbol);
                $io->listing([
                    sprintf('Base / Quote            : %s / %s', $d->baseCurrency ?? '-', $d->quoteCurrency ?? '-'),
                    sprintf('Précision du prix       : %s', $d->pricePrecision ?? '-'),
                    sprintf('Précision du volume     : %s', $d->volPrecision ?? '-'),
                    sprintf('Contract size           : %s', $d->contractSize ?? '-'),
                    sprintf('Levier Min / Max        : %s / %s', $d->minLeverage ?? '-', $d->maxLeverage ?? '-'),
                    sprintf('Min / Max volume        : %s / %s', $d->minVolume ?? '-', $d->maxVolume ?? '-'),
                    sprintf('Market max volume       : %s', $d->marketMaxVolume ?? '-'),
                    sprintf('Funding interval (h)    : %s', $d->fundingIntervalHours ?? '-'),
                    sprintf('Status                  : %s', $d->status ?? '-'),
                    sprintf('Open time               : %s', $fmtMs($d->openTimestampMs)),
                    sprintf('Delist time             : %s', $fmtSec($d->delistTimeSec ?? null)),
                ]);
            }

            $io->success(sprintf('Total: %d contrat(s)', $details->count()));
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
