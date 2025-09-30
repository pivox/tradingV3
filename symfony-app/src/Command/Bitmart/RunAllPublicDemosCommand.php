<?php
// src/Command/Bitmart/RunAllPublicDemosCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:run-all-public-demos',
    description: 'Exécute en séquence les commandes BitMart de démo avec des constantes.'
)]
final class RunAllPublicDemosCommand extends Command
{
    // 🔧 Constantes (ajuste-les ici)
    private const SYMBOL             = 'BTCUSDT';
    private const TIMEFRAME          = '15m';
    private const KLINES_LIMIT       = 10; // 10 dernières bougies
    private const ORDERBOOK_LEVELS   = 5;
    private const TRADES_LIMIT       = 5;
    private const DETAILS_AS_JSON    = false; // mettre true pour sortie JSON des contract details

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $app = $this->getApplication();
        if (!$app) {
            $io->error('Application Console introuvable.');
            return Command::FAILURE;
        }

        $io->title('BitMart • Run All Demos');
        $io->text(sprintf('SYMBOL=%s | TF=%s', self::SYMBOL, self::TIMEFRAME));
        $io->newLine();

        $ok = true;

        // 1) Contract details
        $ok = $ok && $this->runSubCommand(
                $app,
                'bitmart:get-contract-details',
                self::DETAILS_AS_JSON
                    ? ['symbol' => self::SYMBOL, '--json' => true]
                    : ['symbol' => self::SYMBOL],
                $io,
                '1) Contract Details'
            );

        // 2) Futures Klines (10 dernières bougies)
        $ok = $ok && $this->runSubCommand(
                $app,
                'bitmart:get-futures-klines',
                [
                    'symbol'      => self::SYMBOL,
                    'granularity' => self::TIMEFRAME,
                    'limit'       => (string) self::KLINES_LIMIT,
                ],
                $io,
                '2) Futures Klines (10 dernières)'
            );

        // 3) Futures Klines Window (split 4 / 6)
        $ok = $ok && $this->runSubCommand(
                $app,
                'bitmart:get-futures-klines-window',
                [
                    'symbol'    => self::SYMBOL,
                    'timeframe' => self::TIMEFRAME,
                ],
                $io,
                '3) Futures Klines (fenêtres: 4 puis 5–10)'
            );

        // 4) System time (si tu as la commande)
        if ($app->has('bitmart:get-system-time')) {
            $ok = $ok && $this->runSubCommand(
                    $app,
                    'bitmart:get-system-time',
                    [],
                    $io,
                    '4) System Time'
                );
        }

        // 5) Order book (si tu as la commande dédiée)
        if ($app->has('bitmart:get-order-book')) {
            $ok = $ok && $this->runSubCommand(
                    $app,
                    'bitmart:get-order-book',
                    [
                        'symbol' => self::SYMBOL,
                        'limit'  => (string) self::ORDERBOOK_LEVELS,
                    ],
                    $io,
                    '5) Order Book'
                );
        }

        // 6) Recent trades (si tu as la commande dédiée)
        if ($app->has('bitmart:get-recent-trades')) {
            $ok = $ok && $this->runSubCommand(
                    $app,
                    'bitmart:get-recent-trades',
                    [
                        'symbol' => self::SYMBOL,
                        'limit'  => (string) self::TRADES_LIMIT,
                    ],
                    $io,
                    '6) Recent Trades'
                );
        }

        // 7) Test public (ancienne commande fourre-tout — optionnelle)
        if ($app->has('bitmart:test-public')) {
            $ok = $ok && $this->runSubCommand(
                    $app,
                    'bitmart:test-public',
                    [],
                    $io,
                    '7) Test Public (agrégé)'
                );
        }

        if ($ok) {
            $io->success('Toutes les démos ont été exécutées avec succès ✅');
            return Command::SUCCESS;
        }

        $io->warning('Certaines sous-commandes ont échoué. Consulte la sortie ci-dessus.');
        return Command::FAILURE;
    }

    private function runSubCommand(
        \Symfony\Component\Console\Application $app,
        string $name,
        array $args,
        SymfonyStyle $io,
        string $label
    ): bool {
        $io->section($label.' → '.$name);
        if (!$app->has($name)) {
            $io->warning(sprintf('Sous-commande introuvable: %s', $name));
            return false;
        }

        $command = $app->find($name);
        $input   = new ArrayInput(array_merge(['command' => $name], $args));
        $buffer  = new BufferedOutput();

        $code = $command->run($input, $buffer);

        // Print buffered output
        $out = $buffer->fetch();
        if ($out !== '') {
            $io->writeln($out);
        }

        if ($code !== Command::SUCCESS) {
            $io->error(sprintf('"%s" a échoué (code=%d).', $name, $code));
            return false;
        }

        $io->success(sprintf('"%s" OK', $name));
        return true;
    }
}
