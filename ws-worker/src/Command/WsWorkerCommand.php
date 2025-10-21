<?php
namespace App\Command;

use App\Infra\HttpControlServer;
use App\Worker\MainWorker;
use React\EventLoop\Loop;
use App\Logging\HttpMessengerLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ws:run', description: 'Lance le worker WS BitMart (ordres, positions, klines)')]
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
            $out->writeln('<comment>Attention: Clés API BitMart non configurées. Les canaux privés (ordres, positions) ne fonctionneront pas.</comment>');
            $out->writeln('<comment>Variables requises: BITMART_API_KEY, BITMART_API_SECRET, BITMART_API_MEMO</comment>');
        }

        // Créer le logger distant (PSR-3) → trading-app (Messenger/Redis)
        $logEndpoint = $_ENV['TRADING_LOG_ENDPOINT'] ?? 'http://localhost:8082/internal/log';
        $logger = new HttpMessengerLogger($logEndpoint, app: 'ws-worker', channel: 'ws');

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
            $logger
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
