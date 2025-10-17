<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContractDispatcher
{
    /** @var string[] */
    private array $workers;

    public function __construct(
        private AssignmentStorageInterface $storage,
        private HttpClientInterface $http,
        string $workersCsv
    ) {
        $this->workers = array_values(array_filter(array_map('trim', explode(',', $workersCsv))));
        if (!$this->workers) {
            throw new \RuntimeException('No WS workers configured');
        }
    }

    /** Dispatch via hashing cohérent (stable) */
    public function dispatchByHash(array $symbols, bool $live = false, array $tfs = ['1m','5m','15m','1h','4h']): array
    {
        $assign = [];
        $n = count($this->workers);
        
        foreach ($symbols as $s) {
            $w = $this->workers[crc32($s) % $n];
            $assign[$s] = $w;
            if ($live) {
                $this->postSubscribe($w, $s, $tfs);
            }
        }
        
        $this->storage->save($assign);
        return $assign;
    }

    /** Dispatch équilibré (capacité fixe par worker) */
    public function dispatchLeastLoaded(array $symbols, int $capacity = 20, bool $live = false, array $tfs = ['1m','5m','15m','1h','4h']): array
    {
        $assign = [];
        $loads = array_fill_keys($this->workers, 0);

        foreach ($symbols as $s) {
            $target = $this->pickLeastLoaded($loads, $capacity);
            if ($target === null) {
                throw new \RuntimeException('All workers are at capacity');
            }
            $assign[$s] = $target;
            $loads[$target]++;

            if ($live) {
                $this->postSubscribe($target, $s, $tfs);
            }
        }
        
        $this->storage->save($assign);
        return $assign;
    }

    public function rebalance(array $symbols, array $currentAssign, bool $live = false, array $tfs = ['1m','5m','15m','1h','4h']): array
    {
        // Recalcul hash (ou least-loaded) puis ne bouger que les symboles qui changent de worker
        $target = $this->dispatchByHash($symbols, false, $tfs); // ou dispatchLeastLoaded(...)
        $moves = [];
        
        foreach ($symbols as $s) {
            $old = $currentAssign[$s] ?? null;
            $new = $target[$s];
            if ($old && $old !== $new) {
                $moves[$s] = [$old, $new];
                if ($live) {
                    $this->postUnsubscribe($old, $s, $tfs);
                    $this->postSubscribe($new, $s, $tfs);
                }
            } elseif (!$old && $live) {
                $this->postSubscribe($new, $s, $tfs);
            }
        }
        
        $this->storage->save($target);
        return $moves; // utile pour logguer ce qui a bougé
    }

    /** Dispatch vers un worker spécifique */
    public function dispatchToWorker(array $symbols, string $worker, bool $live = false, array $tfs = ['1m','5m','15m','1h','4h']): array
    {
        if (!in_array($worker, $this->workers)) {
            throw new \RuntimeException("Worker '{$worker}' not found in configured workers");
        }

        $assign = [];
        foreach ($symbols as $s) {
            $assign[$s] = $worker;
            if ($live) {
                $this->postSubscribe($worker, $s, $tfs);
            }
        }
        
        $this->storage->save($assign);
        return $assign;
    }

    /** Helpers HTTP */
    public function postSubscribe(string $worker, string $symbol, array $tfs): void
    {
        try {
            $this->http->request('POST', "http://{$worker}/subscribe", [
                'json' => compact('symbol', 'tfs'),
                'timeout' => 5
            ]);
        } catch (\Exception $e) {
            error_log("Failed to subscribe {$symbol} to {$worker}: " . $e->getMessage());
            throw $e;
        }
    }

    public function postUnsubscribe(string $worker, string $symbol, array $tfs): void
    {
        try {
            $this->http->request('POST', "http://{$worker}/unsubscribe", [
                'json' => compact('symbol', 'tfs'),
                'timeout' => 5
            ]);
        } catch (\Exception $e) {
            error_log("Failed to unsubscribe {$symbol} from {$worker}: " . $e->getMessage());
            throw $e;
        }
    }

    /** picks */
    private function pickLeastLoaded(array &$loads, int $capacity): ?string
    {
        asort($loads);
        foreach ($loads as $w => $cnt) {
            if ($cnt < $capacity) {
                return $w;
            }
        }
        return null;
    }

    /** Obtenir la liste des workers */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /** Obtenir les assignations actuelles */
    public function getCurrentAssignments(): array
    {
        return $this->storage->load();
    }

    /** Sauvegarder les assignations */
    public function saveAssignments(array $assignments): void
    {
        $this->storage->save($assignments);
    }
}
