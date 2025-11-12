<?php
namespace App\WebSocket\Command;

use App\WebSocket\Service\WsDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContext;

#[AsCommand(name: 'ws:unsubscribe', description: 'Désabonne un symbole des TF')]
final class WsUnsubscribeCommand extends Command
{
    public function __construct(private WsDispatcher $ws) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'ex: BTCUSDT')
            ->addArgument('tfs', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'TF list', ['1m','5m','15m','1h','4h'])
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Identifiant de l\'exchange (ex: bitmart)')
            ->addOption('market-type', null, InputOption::VALUE_OPTIONAL, 'Type de marché (perpetual|spot)');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $symbol = (string)$in->getArgument('symbol');
        $tfs = $in->getArgument('tfs');
        // Resolve context (defaults Bitmart/Perpetual)
        $exchangeOpt = $in->getOption('exchange');
        $marketTypeOpt = $in->getOption('market-type');
        $exchange = Exchange::BITMART;
        if (is_string($exchangeOpt) && $exchangeOpt !== '') {
            $exchange = match (strtolower(trim($exchangeOpt))) {
                'bitmart' => Exchange::BITMART,
                default => Exchange::BITMART,
            };
        }
        $marketType = MarketType::PERPETUAL;
        if (is_string($marketTypeOpt) && $marketTypeOpt !== '') {
            $marketType = match (strtolower(trim($marketTypeOpt))) {
                'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
                'spot' => MarketType::SPOT,
                default => MarketType::PERPETUAL,
            };
        }
        $context = new ExchangeContext($exchange, $marketType);

        $this->ws->unsubscribe($symbol, $tfs, $context);
        $out->writeln("<info>OK</info> $symbol → ".implode(',', $tfs));
        return Command::SUCCESS;
    }
}





