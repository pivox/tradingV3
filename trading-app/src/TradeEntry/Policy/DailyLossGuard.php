<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Config\TradeEntryConfig;
use App\Contract\Provider\MainProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DailyLossGuard
 *
 * Blocks new trades once the daily loss limit (absolute USDT) is reached.
 * Uses account equity when available, falls back to available balance.
 * Persists per-day baseline and lock state under var/lock/daily_loss_guard.json.
 */
final class DailyLossGuard
{
    private string $statePath;
    private float $limitUsdt;
    private bool $countUnrealized;

    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TradeEntryConfig $config,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {
        // Default settings
        $risk = $this->config->getRisk();
        $this->limitUsdt = isset($risk['daily_max_loss_usdt']) ? (float)$risk['daily_max_loss_usdt'] : 0.0;
        if ($this->limitUsdt <= 0.0) {
            // Backward compatible: if only percent exists, disable absolute guard by default
            $this->limitUsdt = 0.0;
        }
        // Count unrealized through equity by default (safer for futures)
        $this->countUnrealized = (bool)($risk['daily_loss_count_unrealized'] ?? true);

        $root = \dirname(__DIR__, 3); // src/TradeEntry/Policy -> src/TradeEntry -> src -> project root
        $lockDir = $root . '/var/lock';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $this->statePath = $lockDir . '/daily_loss_guard.json';
    }

    /**
     * Check current state and update lock if limit hit.
     *
     * @return array{date:string, start_measure:float, measure:string, measure_value:float, pnl_today:float, limit_usdt:float, locked:bool, locked_at?:string}
     */
    public function checkAndMaybeLock(): array
    {
        // If not configured, do nothing
        if ($this->limitUsdt <= 0.0) {
            return [
                'date' => $this->today(),
                'start_measure' => 0.0,
                'measure' => 'disabled',
                'measure_value' => 0.0,
                'pnl_today' => 0.0,
                'limit_usdt' => 0.0,
                'locked' => false,
            ];
        }

        $state = $this->loadState();

        // Measure equity/available
        $account = $this->providers->getAccountProvider()->getAccountInfo();
        $equity = null;
        $available = null;
        if ($account !== null) {
            try { $equity = $account->equity->toScale(8, 3)->toFloat(); } catch (\Throwable) { $equity = null; }
            try { $available = $account->availableBalance->toScale(8, 3)->toFloat(); } catch (\Throwable) { $available = null; }
        }

        $measureName = $this->countUnrealized && $equity !== null ? 'equity' : 'available';
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
                'limit_usdt' => $this->limitUsdt,
                'locked' => false,
            ];
            $this->saveState($state);
        }

        // Compute PnL from baseline
        $pnlToday = $measureValue - (float)$state['start_measure'];
        $loss = -min($pnlToday, 0.0);

        // Lock if limit exceeded
        if (!$state['locked'] && $loss >= $this->limitUsdt) {
            $state['locked'] = true;
            $state['locked_at'] = gmdate('c');
            $this->positionsLogger->warning('daily_loss_guard.locked', [
                'date' => $today,
                'measure' => $measureName,
                'start' => (float)$state['start_measure'],
                'current' => $measureValue,
                'pnl_today' => $pnlToday,
                'loss' => $loss,
                'limit_usdt' => $this->limitUsdt,
            ]);
        }

        // Keep state updated with latest measure and pnl
        $state['measure'] = $measureName;
        $state['measure_value'] = $measureValue;
        $state['pnl_today'] = $pnlToday;
        $state['limit_usdt'] = $this->limitUsdt;
        $this->saveState($state);

        return $state;
    }

    public function isLocked(): bool
    {
        $state = $this->loadState();
        // If day changed, treat as unlocked until checkAndMaybeLock() is called
        return ($state['date'] ?? '') === $this->today() && (bool)($state['locked'] ?? false);
    }

    private function today(): string
    {
        // Always use UTC for consistency
        return gmdate('Y-m-d');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(): array
    {
        if (is_file($this->statePath)) {
            try {
                $content = file_get_contents($this->statePath);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (\is_array($data)) {
                        return $data;
                    }
                }
            } catch (\Throwable) {}
        }

        return [
            'date' => $this->today(),
            'start_measure' => null,
            'measure' => 'unknown',
            'measure_value' => 0.0,
            'pnl_today' => 0.0,
            'limit_usdt' => $this->limitUsdt,
            'locked' => false,
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveState(array $state): void
    {
        try {
            file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // ignore
        }
    }
}

