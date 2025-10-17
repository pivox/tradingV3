<?php

require_once 'vendor/autoload.php';

use App\Logging\LogPublisher;
use App\Logging\TemporalHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Psr\Log\NullLogger;

// Créer le client HTTP
$httpClient = new NativeHttpClient();
$temporalClient = new TemporalHttpClient($httpClient, 'http://temporal:8080', 'default');

// Créer le publisher
$publisher = new LogPublisher($temporalClient, new NullLogger());

// Tester la publication
echo "Testing log publication...\n";
$publisher->publishLog('test', 'info', 'Test message', [], 'BTCUSDT', '1h', 'BUY');
echo "Log published successfully\n";

// Vérifier si le fichier a été créé
$logFile = '/var/log/symfony/test.log';
if (file_exists($logFile)) {
    echo "Log file created: $logFile\n";
    echo "Content:\n";
    echo file_get_contents($logFile);
} else {
    echo "Log file not created: $logFile\n";
}


