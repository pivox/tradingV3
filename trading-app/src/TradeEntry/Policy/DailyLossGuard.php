<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Config\TradeEntryConfigResolver;
use App\Contract\Provider\MainProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DailyLossGuard
 *
 * Blocks new trades once the daily loss limit (absolute USDT) is reached.
 * Uses account equity when available, falls back to available balance.
 * Persists per-day baseline and lock state per mode under var/lock/daily_loss_guard.<mode>.json.
 * Supports mode-based configuration (same as validations.{mode}.yaml).
 */
final class DailyLossGuard
{
    private string $lockDir;

    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TradeEntryConfigResolver $configResolver,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {
        $root = \dirname(__DIR__, 3); // src/TradeEntry/Policy -> src/TradeEntry -> src -> project root
        $lockDir = $root . '/var/lock';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $this->lockDir = $lockDir;
    }

    /**
     * Check current state and update lock if limit hit.
     * 
     * @param string|null $mode Mode de configuration (ex: 'regular', 'scalping'). Si null, utilise la config par défaut.
     * @return array{date:string, start_measure:float, measure:string, measure_value:float, pnl_today:float, limit_usdt:float, locked:bool, locked_at?:string}
     */
    public function checkAndMaybeLock(?string $mode = null): array
    {
        $config = $this->configResolver->resolve($mode);
        $modeUsed = $this->configResolver->resolveMode($mode);
        $risk = $config->getRisk();
        $limitUsdt = isset($risk['daily_max_loss_usdt']) ? (float)$risk['daily_max_loss_usdt'] : 0.0;
        if ($limitUsdt <= 0.0) {
            $limitUsdt = 0.0;
        }
        $countUnrealized = (bool)($risk['daily_loss_count_unrealized'] ?? true);

        if ($limitUsdt <= 0.0) {
            return [
                'date' => $this->today(),
                'start_measure' => 0.0,
                'measure' => 'disabled',
                'measure_value' => 0.0,
                'pnl_today' => 0.0,
                'limit_usdt' => 0.0,
                'locked' => false,
                'mode' => $modeUsed,
            ];
        }

        $state = $this->loadState($modeUsed, $limitUsdt);

        // Measure equity/available
        $account = $this->providers->getAccountProvider()->getAccountInfo();
        $equity = null;
        $available = null;
        if ($account !== null) {
            try { $equity = $account->equity->toScale(8, 3)->toFloat(); } catch (\Throwable) { $equity = null; }
            try { $available = $account->availableBalance->toScale(8, 3)->toFloat(); } catch (\Throwable) { $available = null; }
        }

        $measureName = $countUnrealized && $equity !== null ? 'equity' : 'available';
        $measureValue = $measureName === 'equity' ? (float)($equity ?? 0.0) : (float)($available ?? 0.0);

        // Reset baseline if missing or day changed
        $today = $this->today();
        if (($state['date'] ?? null) !== $today || !isset($state['start_measure'])) {
            $state = [
                'date' => $today,
                'start_measure' => $measureValue,
                'measure' => $measureName,
                'measure_value' => $measureValue,
                'pnl_today' => 0.0,
                'limit_usdt' => $limitUsdt,
                'locked' => false,
                'mode' => $modeUsed,
            ];
            $this->saveState($state, $modeUsed);
        }

        // Compute PnL from baseline
        $pnlToday = $measureValue - (float)$state['start_measure'];
        $loss = -min($pnlToday, 0.0);

        // Unlock if loss is now below the limit (e.g., limit was increased)
        if ($state['locked'] && $loss < $limitUsdt) {
            $state['locked'] = false;
            unset($state['locked_at']);
            $this->positionsLogger->info('daily_loss_guard.unlocked', [
                'date' => $today,
                'measure' => $measureName,
                'start' => (float)$state['start_measure'],
                'current' => $measureValue,
                'pnl_today' => $pnlToday,
                'loss' => $loss,
                'limit_usdt' => $limitUsdt,
                'mode' => $modeUsed,
                'reason' => 'loss_below_limit',
            ]);
        }

        // Lock if limit exceeded
        if (!$state['locked'] && $loss >= $limitUsdt) {
            $state['locked'] = true;
            $state['locked_at'] = gmdate('c');
            $this->positionsLogger->warning('daily_loss_guard.locked', [
                'date' => $today,
                'measure' => $measureName,
                'start' => (float)$state['start_measure'],
                'current' => $measureValue,
                'pnl_today' => $pnlToday,
                'loss' => $loss,
                'limit_usdt' => $limitUsdt,
                'mode' => $modeUsed,
            ]);
        }

        // Keep state updated with latest measure and pnl
        $state['measure'] = $measureName;
        $state['measure_value'] = $measureValue;
        $state['pnl_today'] = $pnlToday;
        $state['limit_usdt'] = $limitUsdt;
        $state['mode'] = $modeUsed;
        $this->saveState($state, $modeUsed);

        return $state;
    }

    public function isLocked(?string $mode = null): bool
    {
        $modeUsed = $this->configResolver->resolveMode($mode);
        $config = $this->configResolver->resolve($mode);
        $risk = $config->getRisk();
        $limitUsdt = isset($risk['daily_max_loss_usdt']) ? (float)$risk['daily_max_loss_usdt'] : 0.0;
        $state = $this->loadState($modeUsed, $limitUsdt);
        // If day changed, treat as unlocked until checkAndMaybeLock() is called
        return ($state['date'] ?? '') === $this->today() && (bool)($state['locked'] ?? false);
    }

    /**
     * Réinitialise le daily loss limit pour un mode donné.
     * Supprime le fichier de lock et réinitialise la baseline avec les valeurs actuelles du compte.
     * 
     * @param string|null $mode Mode de configuration (ex: 'regular', 'scalper'). Si null, utilise la config par défaut.
     * @return array{date:string, start_measure:float, measure:string, measure_value:float, pnl_today:float, limit_usdt:float, locked:bool, mode:string}
     */
    public function reset(?string $mode = null): array
    {
        $config = $this->configResolver->resolve($mode);
        $modeUsed = $this->configResolver->resolveMode($mode);
        $risk = $config->getRisk();
        $limitUsdt = isset($risk['daily_max_loss_usdt']) ? (float)$risk['daily_max_loss_usdt'] : 0.0;
        if ($limitUsdt <= 0.0) {
            $limitUsdt = 0.0;
        }
        $countUnrealized = (bool)($risk['daily_loss_count_unrealized'] ?? true);

        // Supprimer le fichier de lock existant
        $path = $this->getStatePath($modeUsed);
        if (is_file($path)) {
            @unlink($path);
        }
        // Supprimer aussi le fichier legacy si présent
        $legacyPath = $this->lockDir . '/daily_loss_guard.json';
        if (is_file($legacyPath) && $modeUsed === '') {
            @unlink($legacyPath);
        }

        // Récupérer les valeurs actuelles du compte
        $account = $this->providers->getAccountProvider()->getAccountInfo();
        $equity = null;
        $available = null;
        if ($account !== null) {
            try { $equity = $account->equity->toScale(8, 3)->toFloat(); } catch (\Throwable) { $equity = null; }
            try { $available = $account->availableBalance->toScale(8, 3)->toFloat(); } catch (\Throwable) { $available = null; }
        }

        $measureName = $countUnrealized && $equity !== null ? 'equity' : 'available';
        $measureValue = $measureName === 'equity' ? (float)($equity ?? 0.0) : (float)($available ?? 0.0);

        // Créer un nouvel état avec la baseline actuelle
        $today = $this->today();
        $state = [
            'date' => $today,
            'start_measure' => $measureValue,
            'measure' => $measureName,
            'measure_value' => $measureValue,
            'pnl_today' => 0.0,
            'limit_usdt' => $limitUsdt,
            'locked' => false,
            'mode' => $modeUsed,
        ];

        $this->saveState($state, $modeUsed);

        return $state;
    }

    private function today(): string
    {
        // Always use UTC for consistency
        return gmdate('Y-m-d');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(string $mode, float $defaultLimitUsdt): array
    {
        $path = $this->getStatePath($mode);
        if (!is_file($path)) {
            $legacy = $this->lockDir . '/daily_loss_guard.json';
            if (is_file($legacy)) {
                $path = $legacy;
            }
        }

        if (is_file($path)) {
            try {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (\is_array($data)) {
                        $data['mode'] = $mode;
                        return $data;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return [
            'date' => $this->today(),
            'start_measure' => null,
            'measure' => 'unknown',
            'measure_value' => 0.0,
            'pnl_today' => 0.0,
            'limit_usdt' => $defaultLimitUsdt,
            'locked' => false,
            'mode' => $mode,
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveState(array $state, string $mode): void
    {
        $path = $this->getStatePath($mode);
        try {
            file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // ignore
        }
    }

    private function getStatePath(string $mode): string
    {
        $suffix = $mode !== '' ? '.' . $this->normalizeMode($mode) : '';
        return sprintf('%s/daily_loss_guard%s.json', $this->lockDir, $suffix);
    }

    private function normalizeMode(string $mode): string
    {
        $clean = strtolower($mode);
        $clean = preg_replace('/[^a-z0-9_-]+/', '_', $clean);
        $clean = trim((string)$clean, '_');
        return $clean !== '' ? $clean : 'default';
    }
}
