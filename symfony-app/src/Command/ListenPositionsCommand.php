<?php

declare(strict_types=1);

namespace App\Command;

use App\Bitmart\Ws\PositionStream;
use App\Service\Trading\PositionTpSlEnforcer;
use App\Service\Pipeline\MtfStateService;
use App\Service\Pipeline\MtfPipelineViewService;
use App\Service\Trading\PositionStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'bitmart:listen-positions',
    description: 'Écoute les positions (WS) et affecte automatiquement SL/TP si manquants.'
)]
final class ListenPositionsCommand extends Command
{
    /** Anti-spam: min secondes entre deux enforcements pour un même symbole. */
    private const ENFORCE_COOLDOWN_SEC = 10;

    /** @var array<string,int> last enforcement timestamp per symbol */
    private array $lastEnforceAt = [];

    /** @var array<string,array{symbol:string,side:int,update_time_ms:int}> */
    private array $lastOpenByKey = [];

    public function __construct(
        private readonly PositionStream $positionStream,
        private readonly PositionTpSlEnforcer $enforcer,
        private readonly LoggerInterface $positionsLogger,
        private readonly MtfStateService $mtfState,
        private readonly MtfPipelineViewService $pipelineView,
        private readonly PositionStore $positionStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[BitMart]</info> Écoute des positions privées…');

        $this->positionStream->listen(function (array $positions) use ($output) {
            // Indexation des positions ouvertes courantes par (symbol#side)
            $currByKey = [];
            foreach ($positions as $p) {
                $symbol = strtoupper((string)($p['symbol'] ?? ''));
                $side   = (int)($p['side'] ?? 0);
                if ($symbol === '' || !\in_array($side, [1,2], true)) {
                    continue;
                }
                $key = $symbol . '#' . $side;
                $currByKey[$key] = [
                    'symbol' => $symbol,
                    'side'   => $side,
                    'update_time_ms' => (int)($p['update_time_ms'] ?? 0),
                    'avg_open_price' => isset($p['avg_open_price']) ? (float)$p['avg_open_price'] : null,
                    'hold_volume'    => isset($p['hold_volume']) ? (float)$p['hold_volume'] : null,
                ];
            }

            // Détecter OPEN: présents maintenant et pas avant
            foreach ($currByKey as $key => $info) {
                if (!isset($this->lastOpenByKey[$key])) {
                    $ts = $info['update_time_ms'] > 0 ? $info['update_time_ms'] : (int)(microtime(true) * 1000);
                    $eventId = sprintf('open:%s:%d', $key, $ts);
                    // Seed si pipeline inconnu
                    if (!$this->pipelineView->get($info['symbol'])) {
                        $this->mtfState->ensureSeeded($info['symbol']);
                    }
                    $tfs = $this->executionTimeframesForSymbol($info['symbol']);
                    $this->positionsLogger->info('[ListenPositionsCommand] position OPEN detected', ['key' => $key, 'event_id' => $eventId, 'tfs' => $tfs]);
                    $this->mtfState->applyPositionOpened($eventId, $info['symbol'], $tfs);
                    // Persistance DB
                    $this->positionStore->openOrUpdate(
                        $info['symbol'],
                        $info['side'],
                        $info['avg_open_price'],
                        $info['hold_volume'],
                        null
                    );
                }
            }

            // Détecter CLOSE: présents avant et plus maintenant
            foreach ($this->lastOpenByKey as $key => $prev) {
                if (!isset($currByKey[$key])) {
                    $ts = (int)($prev['update_time_ms'] ?? 0);
                    if ($ts <= 0) { $ts = (int)(microtime(true) * 1000); }
                    $eventId = sprintf('close:%s:%d', $key, $ts);
                    if (!$this->pipelineView->get($prev['symbol'])) {
                        $this->mtfState->ensureSeeded($prev['symbol']);
                    }
                    $tfs = $this->executionTimeframesForSymbol($prev['symbol']);
                    $this->positionsLogger->info('[ListenPositionsCommand] position CLOSE detected', ['key' => $key, 'event_id' => $eventId, 'tfs' => $tfs]);
                    $this->mtfState->applyPositionClosed($eventId, $prev['symbol'], $tfs);
                    // Persistance DB
                    $this->positionStore->close($prev['symbol'], $prev['side']);
                }
            }

            // Enforce SL/TP pour les positions courantes (throttle existant conservé)
            foreach ($positions as $p) {
                $symbol = strtoupper((string)($p['symbol'] ?? ''));
                $position = [
                    'symbol'          => $symbol,
                    'hold_volume'     => (float)($p['hold_volume'] ?? 0),
                    'side'            => (int)($p['side'] ?? ($p['position_type'] ?? 0)),
                    'avg_open_price'  => (float)($p['avg_open_price'] ?? $p['open_avg_price'] ?? 0.0),
                    'liq_price'       => isset($p['liq_price']) ? (float)$p['liq_price'] : null,
                    'position_mode'   => (string)($p['position_mode'] ?? 'one_way_mode'),
                ];

                if ($position['symbol'] === '' || $position['hold_volume'] <= 0 || $position['avg_open_price'] <= 0) {
                    continue;
                }
                if (!$this->shouldEnforce($position['symbol'])) {
                    continue;
                }

                $output->writeln(sprintf(
                    '→ Enforce SL/TP %s (side=%s, qty=%.6f, avg=%.2f)',
                    $position['symbol'],
                    $position['side'] === 1 ? 'LONG' : 'SHORT',
                    $position['hold_volume'],
                    $position['avg_open_price']
                ));

                try {
                    $payload = $this->mapWsToEnforcer($p);
                    if ($payload['symbol'] && $payload['side'] && $payload['entry_price'] > 0 && $payload['size'] > 0) {
                        $this->positionsLogger->info('[ListenPositionsCommand] enforcing TP/SL', $payload);
                        $this->enforcer->enforceAuto($payload); // ← méthode que tu as dans PositionOpener
                    }
                } catch (\Throwable $e) {
                    $this->positionsLogger->error('[ListenPositionsCommand] enforce failed', [
                        'symbol' => $position['symbol'] ?? null,
                        'error'  => $e->getMessage(),
                        'trace'  => $e->getTraceAsString(),   // 👈 ajoute ça
                    ]);
                }

            }

            // Mettre à jour l’état précédent
            $this->lastOpenByKey = $currByKey;
        });

        return Command::SUCCESS;
    }

    private function mapWsToEnforcer(array $p): array {
        $symbol = strtoupper((string)($p['symbol'] ?? ''));
        $sideId = (int)($p['side'] ?? ($p['position_type'] ?? 0));
        $side   = $sideId === 1 ? 'long' : ($sideId === 2 ? 'short' : null);

        return [
            'symbol'        => $symbol,
            'side'          => $side,
            'entry_price'   => (float)($p['avg_open_price'] ?? $p['open_avg_price'] ?? 0.0),
            'size'          => (int)max(1, (float)($p['hold_volume'] ?? 0.0)), // contrats
            'tp_price'      => $this->extractFloat($p, ['tp_price', 'take_profit', 'preset_take_profit_price']),
            'sl_price'      => $this->extractFloat($p, ['sl_price', 'stop_loss', 'preset_stop_loss_price']),
            'tp_order_id'   => $this->extractString($p, ['tp_order_id', 'take_profit_order_id', 'preset_take_profit_order_id']),
            'sl_order_id'   => $this->extractString($p, ['sl_order_id', 'stop_loss_order_id', 'preset_stop_loss_order_id']),
        ];
    }


    private function shouldEnforce(string $symbol): bool
    {
        $now = \time();
        $last = $this->lastEnforceAt[$symbol] ?? 0;
        if ($now - $last >= self::ENFORCE_COOLDOWN_SEC) {
            $this->lastEnforceAt[$symbol] = $now;
            return true;
        }
        return false;
    }

    private function executionTimeframesForSymbol(string $symbol): array
    {
        $pipeline = $this->pipelineView->get($symbol);
        if (!$pipeline) {
            return ['1m','5m'];
        }
        $tf = $pipeline['current_timeframe'] ?? '1m';
        return [$tf];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $keys
     */
    private function extractFloat(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $keys
     */
    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                $stringValue = (string) $value;
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        return null;
    }
}
