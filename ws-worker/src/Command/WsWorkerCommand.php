<?php
namespace App\Command;

use App\Infra\HttpControlServer;
use App\Worker\MainWorker;
use React\EventLoop\Loop;
use App\Order\OrderSignalDispatcher;
use App\Order\OrderSignalFactory;
use App\Balance\BalanceSignalDispatcher;
use App\Balance\BalanceSignalFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(name: 'ws:run', description: 'Lance le worker WS BitMart (ordres, positions, balance, klines)')]
final class WsWorkerCommand extends Command
{
    public function __construct() 
    { 
        parent::__construct(); 
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $out->writeln('<info>BitMart WebSocket worker démarré</info>');
        
        // Configuration depuis les variables d'environnement
        $publicWsUri = $_ENV['BITMART_PUBLIC_WS_URI'] ?? 'wss://openapi-ws-v2.bitmart.com/api?protocol=1.1';
        $privateWsUri = $_ENV['BITMART_PRIVATE_WS_URI'] ?? 'wss://openapi-ws-v2.bitmart.com/user?protocol=1.1';
        $ctrlAddress = $_ENV['CTRL_ADDR'] ?? '0.0.0.0:8089';
        $subscribeBatch = (int)($_ENV['SUBSCRIBE_BATCH'] ?? 20);
        $subscribeDelayMs = (int)($_ENV['SUBSCRIBE_DELAY_MS'] ?? 200);
        $pingIntervalS = (int)($_ENV['PING_INTERVAL_S'] ?? 15);
        $reconnectDelayS = (int)($_ENV['RECONNECT_DELAY_S'] ?? 5);

        // Clés API BitMart (optionnelles pour les canaux privés)
        $apiKey = $_ENV['BITMART_API_KEY'] ?? null;
        $apiSecret = $_ENV['BITMART_API_SECRET'] ?? null;
        $apiMemo = $_ENV['BITMART_API_MEMO'] ?? null;

        if (!$apiKey || !$apiSecret || !$apiMemo) {
            $out->writeln('<comment>Attention: Clés API BitMart non configurées. Les canaux privés (ordres, positions, balance) ne fonctionneront pas.</comment>');
            $out->writeln('<comment>Variables requises: BITMART_API_KEY, BITMART_API_SECRET, BITMART_API_MEMO</comment>');
        }

        // Créer le logger (PSR-3)
        // Pour un logger plus avancé, vous pouvez implémenter HttpMessengerLogger
        $logger = new NullLogger();

        // Configuration du signal trading-app
        $tradingAppBaseUri = rtrim((string)($_ENV['TRADING_APP_BASE_URI'] ?? ''), '/');
        $tradingAppPath = $_ENV['TRADING_APP_ORDER_SIGNAL_PATH'] ?? '/api/ws-worker/orders';
        $tradingAppSecret = $_ENV['TRADING_APP_SHARED_SECRET'] ?? null;
        $tradingAppTimeout = (float)($_ENV['TRADING_APP_REQUEST_TIMEOUT'] ?? 2.0);
        $tradingAppMaxRetries = (int)($_ENV['TRADING_APP_SIGNAL_MAX_RETRIES'] ?? 5);
        $failureLogPath = $_ENV['TRADING_APP_SIGNAL_FAILURE_LOG'] ?? dirname(__DIR__, 2) . '/var/order-signal-failures.log';

        $orderSignalDispatcher = null;
        if ($tradingAppBaseUri === '' || $tradingAppSecret === null || $tradingAppSecret === '') {
            $out->writeln('<comment>Attention: TRADING_APP_BASE_URI ou TRADING_APP_SHARED_SECRET non configuré. Synchronisation des ordres désactivée.</comment>');
        } else {
            $endpoint = rtrim($tradingAppBaseUri, '/') . '/' . ltrim($tradingAppPath, '/');
            $orderSignalDispatcher = new OrderSignalDispatcher(
                HttpClient::create(),
                $endpoint,
                $tradingAppSecret,
                $tradingAppTimeout,
                $tradingAppMaxRetries,
                backoffDelays: [0.0, 5.0, 15.0, 45.0, 120.0],
                logger: $logger,
                failureLogPath: $failureLogPath
            );
        }

        // Configuration du signal balance trading-app
        $tradingAppBalancePath = $_ENV['TRADING_APP_BALANCE_SIGNAL_PATH'] ?? '/api/ws-worker/balance';
        $balanceFailureLogPath = $_ENV['TRADING_APP_BALANCE_FAILURE_LOG'] ?? dirname(__DIR__, 2) . '/var/balance-signal-failures.log';

        $balanceSignalDispatcher = null;
        if ($tradingAppBaseUri === '' || $tradingAppSecret === null || $tradingAppSecret === '') {
            $out->writeln('<comment>Attention: TRADING_APP_BASE_URI ou TRADING_APP_SHARED_SECRET non configuré. Synchronisation du balance désactivée.</comment>');
        } else {
            $balanceEndpoint = rtrim($tradingAppBaseUri, '/') . '/' . ltrim($tradingAppBalancePath, '/');
            $balanceSignalDispatcher = new BalanceSignalDispatcher(
                HttpClient::create(),
                $balanceEndpoint,
                $tradingAppSecret,
                $tradingAppTimeout,
                $tradingAppMaxRetries,
                backoffDelays: [0.0, 5.0, 15.0, 45.0, 120.0],
                logger: $logger,
                failureLogPath: $balanceFailureLogPath
            );
        }

        // Créer le worker principal
        $mainWorker = new MainWorker(
            $publicWsUri,
            $privateWsUri,
            $apiKey,
            $apiSecret,
            $apiMemo,
            $subscribeBatch,
            $subscribeDelayMs,
            $pingIntervalS,
            $reconnectDelayS,
            $logger,
            $orderSignalDispatcher,
            new OrderSignalFactory(),
            $balanceSignalDispatcher,
            new BalanceSignalFactory()
        );

        // Créer le serveur HTTP de contrôle
        $httpServer = new HttpControlServer($mainWorker, $ctrlAddress, $logger);
        
        // Démarrer le worker
        $mainWorker->run();
        
        $out->writeln('<info>Worker démarré avec succès</info>');
        $out->writeln("<info>Serveur de contrôle: http://{$ctrlAddress}</info>");
        $out->writeln('<info>Utilisez GET /help pour voir les endpoints disponibles</info>');
        
        // Gestion des signaux pour arrêt propre
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function() use ($mainWorker, $out) {
                $out->writeln('<info>Signal SIGTERM reçu, arrêt du worker...</info>');
                $mainWorker->stop();
                Loop::stop();
            });
            
            pcntl_signal(SIGINT, function() use ($mainWorker, $out) {
                $out->writeln('<info>Signal SIGINT reçu, arrêt du worker...</info>');
                $mainWorker->stop();
                Loop::stop();
            });
        }

        // Démarrer la boucle d'événements
        Loop::run();
        
        return Command::SUCCESS;
    }
}
