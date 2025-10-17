<?php
namespace App\Infra;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WsDispatcher
{
    public function __construct(private HttpClientInterface $http, private string $baseUrl) {}

    public function subscribe(string $symbol, array $tfs): void
    {
        $this->http->request('POST', rtrim($this->baseUrl,'/').'/subscribe', [
            'json' => ['symbol'=>$symbol, 'tfs'=>$tfs],
            'timeout' => 5,
        ]);
    }

    public function unsubscribe(string $symbol, array $tfs): void
    {
        $this->http->request('POST', rtrim($this->baseUrl,'/').'/unsubscribe', [
            'json' => ['symbol'=>$symbol, 'tfs'=>$tfs],
            'timeout' => 5,
        ]);
    }
}






