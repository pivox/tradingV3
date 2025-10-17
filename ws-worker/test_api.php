<?php

// Test simple de l'API HTTP du worker
$url = 'http://localhost:8089/subscribe';
$data = [
    'symbol' => 'BTCUSDT',
    'tfs' => ['1m', '5m']
];

$options = [
    'http' => [
        'header' => "Content-type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === false) {
    echo "Erreur: Impossible de se connecter au serveur HTTP\n";
    echo "Assurez-vous que le worker est démarré avec: php bin/console ws:run\n";
} else {
    echo "Réponse du serveur: " . $result . "\n";
}






