<?php

declare(strict_types=1);

namespace App\Command\Positions;

use App\Entity\Position;
use App\Repository\PositionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:positions:repair-from-meta',
    description: 'Synchronize stored positions with the values saved inside the meta payload.'
)]
final class RepairFromMetaCommand extends Command
{
    private const QTY_KEYS = [
        'qty_contract', 'size', 'current_amount', 'position_amount', 'hold_volume',
        'position_volume', 'open_size', 'available',
    ];

    private const ENTRY_KEYS = ['entry_price', 'avg_entry_price', 'average_price', 'open_avg_price', 'avg_price'];
    private const LEVERAGE_KEYS = ['leverage', 'position_leverage', 'open_leverage'];
    private const STOP_LOSS_KEYS = ['stop_loss', 'sl_price', 'preset_stop_loss_price'];
    private const TAKE_PROFIT_KEYS = ['take_profit', 'tp_price', 'preset_take_profit_price'];
    private const PNL_KEYS = [
        'realised_pnl', 'realisedProfit', 'realised_profit', 'realized_pnl', 'realizedPnl', 'realized_profit',
        'realized_value', 'realised_value', 'pnl', 'unrealised_pnl', 'unrealised_profit', 'unrealisedProfit',
        'unrealized_pnl', 'unrealized_profit', 'unrealizedProfit',
    ];
    private const OPEN_TIME_KEYS = ['open_time', 'created_at', 'createdTime', 'open_timestamp'];
    private const CLOSE_TIME_KEYS = ['close_time', 'updated_at', 'closedTime', 'close_timestamp'];
    private const STATUS_KEYS = ['status', 'state', 'position_status'];
    private const TIME_IN_FORCE_KEYS = ['time_in_force', 'timeInForce'];
    private const EXTERNAL_STATUS_KEYS = ['external_status', 'state'];
    private const EXTERNAL_ORDER_KEYS = ['order_id', 'clOrdId', 'client_oid', 'clientOrderId'];
    private const AMOUNT_KEYS = ['position_value', 'current_value', 'mark_value', 'initial_margin'];

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Restrict the repair to a single position id')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Restrict the repair to a contract symbol (ex: BTCUSDT)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the changes without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $positionId = $input->getOption('id');
        $symbol = $input->getOption('symbol');

        $positions = $this->loadPositions($positionId, $symbol);
        if ($positions === []) {
            $io->warning('No position matched the provided filters.');
            return Command::SUCCESS;
        }

        $total = 0;
        $updated = 0;
        $now = new DateTimeImmutable();

        foreach ($positions as $position) {
            ++$total;

            $meta = $position->getMeta();
            if (!is_array($meta) || $meta === []) {
                continue;
            }

            $changes = $this->applyMeta($position, $meta, $now);
            if ($changes === []) {
                continue;
            }

            ++$updated;
            $io->writeln(sprintf(
                '#%d %s %s -> %s',
                $position->getId(),
                $position->getContract()->getSymbol(),
                implode(', ', array_map(
                    static fn(string $field, array $diff): string => sprintf(
                        '%s: %s -> %s',
                        $field,
                        $diff['old'] ?? 'null',
                        $diff['new']
                    ),
                    array_keys($changes),
                    $changes
                )),
                $dryRun ? 'dry-run' : 'updated'
            ));
        }

        if (!$dryRun && $updated > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('%d positions inspected, %d updated%s.', $total, $updated, $dryRun ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }

    /**
     * @return list<Position>
     */
    private function loadPositions(null|string $idOption, null|string $symbolOption): array
    {
        if (is_string($idOption) && $idOption !== '') {
            $position = $this->positions->find((int) $idOption);
            return $position ? [$position] : [];
        }

        $qb = $this->positions->createQueryBuilder('p')
            ->innerJoin('p.contract', 'c')->addSelect('c')
            ->andWhere('p.meta IS NOT NULL');

        if (is_string($symbolOption) && $symbolOption !== '') {
            $qb->andWhere('c.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbolOption));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function applyMeta(Position $position, array $meta, DateTimeImmutable $now): array
    {
        $changes = [];

        if ($side = $this->extractSide($meta)) {
            $current = strtoupper($position->getSide());
            if ($side !== $current) {
                $changes['side'] = ['old' => $current, 'new' => $side];
                $position->setSide($side);
            }
        }

        if ($status = $this->extractStatus($meta, $position)) {
            $current = strtoupper($position->getStatus());
            if ($status !== $current) {
                $changes['status'] = ['old' => $current, 'new' => $status];
                $position->setStatus($status);
            }
        }

        if ($qty = $this->extractNumericString($meta, self::QTY_KEYS)) {
            $current = $position->getQtyContract();
            if ($this->differentNumeric($current, $qty)) {
                $changes['qty_contract'] = ['old' => $current, 'new' => $qty];
                $position->setQtyContract($qty);
            }
        }

        if ($entry = $this->extractNumericString($meta, self::ENTRY_KEYS)) {
            $current = $position->getEntryPrice();
            if ($this->differentNumeric($current, $entry)) {
                $changes['entry_price'] = ['old' => $current, 'new' => $entry];
                $position->setEntryPrice($entry);
            }
        }

        if ($leverage = $this->extractNumericString($meta, self::LEVERAGE_KEYS)) {
            $current = $position->getLeverage();
            if ($this->differentNumeric($current, $leverage)) {
                $changes['leverage'] = ['old' => $current, 'new' => $leverage];
                $position->setLeverage($leverage);
            }
        }

        if ($stopLoss = $this->extractNumericString($meta, self::STOP_LOSS_KEYS)) {
            $current = $position->getStopLoss();
            if ($this->differentNumeric($current, $stopLoss)) {
                $changes['stop_loss'] = ['old' => $current, 'new' => $stopLoss];
                $position->setStopLoss($stopLoss);
            }
        }

        if ($takeProfit = $this->extractNumericString($meta, self::TAKE_PROFIT_KEYS)) {
            $current = $position->getTakeProfit();
            if ($this->differentNumeric($current, $takeProfit)) {
                $changes['take_profit'] = ['old' => $current, 'new' => $takeProfit];
                $position->setTakeProfit($takeProfit);
            }
        }

        if ($pnl = $this->extractNumericString($meta, self::PNL_KEYS)) {
            $current = $position->getPnlUsdt();
            if ($this->differentNumeric($current, $pnl)) {
                $changes['pnl_usdt'] = ['old' => $current, 'new' => $pnl];
                $position->setPnlUsdt($pnl);
            }
        }

        if ($amount = $this->extractNumericString($meta, self::AMOUNT_KEYS)) {
            $current = $position->getAmountUsdt();
            if ($this->differentNumeric($current, $amount)) {
                $changes['amount_usdt'] = ['old' => $current, 'new' => $amount];
                $position->setAmountUsdt($amount);
            }
        }

        if ($openedAt = $this->extractDate($meta, self::OPEN_TIME_KEYS)) {
            $current = $position->getOpenedAt();
            if (!$current || $openedAt->getTimestamp() !== $current->getTimestamp()) {
                $changes['opened_at'] = ['old' => $current?->format(DateTimeImmutable::ATOM), 'new' => $openedAt->format(DateTimeImmutable::ATOM)];
                $position->setOpenedAt($openedAt);
            }
        }

        if ($closedAt = $this->extractDate($meta, self::CLOSE_TIME_KEYS)) {
            $current = $position->getClosedAt();
            if (!$current || $closedAt->getTimestamp() !== $current->getTimestamp()) {
                $changes['closed_at'] = ['old' => $current?->format(DateTimeImmutable::ATOM), 'new' => $closedAt->format(DateTimeImmutable::ATOM)];
                $position->setClosedAt($closedAt);
            }
        }

        if ($timeInForce = $this->extractString($meta, self::TIME_IN_FORCE_KEYS)) {
            $timeInForce = strtoupper($timeInForce);
            if ($timeInForce !== $position->getTimeInForce()) {
                $changes['time_in_force'] = ['old' => $position->getTimeInForce(), 'new' => $timeInForce];
                $position->setTimeInForce($timeInForce);
            }
        }

        if ($externalStatus = $this->extractString($meta, self::EXTERNAL_STATUS_KEYS)) {
            $externalStatus = strtoupper($externalStatus);
            if ($externalStatus !== ($position->getExternalStatus() ?? null)) {
                $changes['external_status'] = ['old' => $position->getExternalStatus(), 'new' => $externalStatus];
                $position->setExternalStatus($externalStatus);
            }
        }

        if ($externalOrderId = $this->extractString($meta, self::EXTERNAL_ORDER_KEYS)) {
            if ($externalOrderId !== ($position->getExternalOrderId() ?? null)) {
                $changes['external_order_id'] = ['old' => $position->getExternalOrderId(), 'new' => $externalOrderId];
                $position->setExternalOrderId($externalOrderId);
            }
        }

        if ($changes !== []) {
            $position->setLastSyncAt($now);
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function extractSide(array $meta): ?string
    {
        $raw = $meta['side'] ?? $meta['hold_side'] ?? $meta['position_side'] ?? $meta['holdSide'] ?? null;
        if ($raw === null) {
            return null;
        }

        if (is_numeric($raw)) {
            $num = (int) $raw;
            return match ($num) {
                1 => Position::SIDE_LONG,
                2, -1 => Position::SIDE_SHORT,
                default => null,
            };
        }

        $normalized = strtoupper(trim((string) $raw));

        return match ($normalized) {
            'LONG', 'BUY', 'BID', 'OPEN_LONG', 'HOLD_LONG' => Position::SIDE_LONG,
            'SHORT', 'SELL', 'ASK', 'OPEN_SHORT', 'HOLD_SHORT' => Position::SIDE_SHORT,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function extractStatus(array $meta, Position $position): ?string
    {
        if ($status = $this->extractString($meta, self::STATUS_KEYS)) {
            $status = strtoupper($status);
        } else {
            $qty = $this->extractNumericString($meta, self::QTY_KEYS);
            if ($qty !== null) {
                $isZero = function_exists('bccomp')
                    ? bccomp($qty, '0', 8) === 0
                    : abs((float) $qty) < 1e-8;
                if ($isZero) {
                    $status = Position::STATUS_CLOSED;
                }
            }
        }

        if (!$status) {
            return null;
        }

        return match ($status) {
            'OPEN', 'NORMAL' => Position::STATUS_OPEN,
            'CLOSED', 'FILLED', 'FINISHED' => Position::STATUS_CLOSED,
            'PENDING', 'NEW', 'SUBMITTED' => Position::STATUS_PENDING,
            'CANCELLED', 'CANCELED' => Position::STATUS_CANCELLED,
            'EXPIRED' => Position::STATUS_EXPIRED,
            'REJECTED' => Position::STATUS_REJECTED,
            default => $position->getStatus(),
        };
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<int, string>   $keys
     */
    private function extractNumericString(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if (is_numeric($value)) {
                $normalized = (string) $value;
                if (str_contains($normalized, 'e') || str_contains($normalized, 'E')) {
                    $normalized = sprintf('%.12F', (float) $normalized);
                }
                if (str_contains($normalized, '.')) {
                    $normalized = rtrim(rtrim($normalized, '0'), '.');
                }
                if ($normalized === '-0') {
                    $normalized = '0';
                }
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<int, string>   $keys
     */
    private function extractDate(array $meta, array $keys): ?DateTimeImmutable
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $timestamp = (float) $value;
                if ($timestamp > 10_000_000_000) {
                    $timestamp /= 1000;
                }
                return (new DateTimeImmutable())->setTimestamp((int) $timestamp);
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if (ctype_digit($trimmed)) {
                    $timestamp = (float) $trimmed;
                    if ($timestamp > 10_000_000_000) {
                        $timestamp /= 1000;
                    }
                    return (new DateTimeImmutable())->setTimestamp((int) $timestamp);
                }

                try {
                    return new DateTimeImmutable($trimmed);
                } catch (\Exception) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<int, string>   $keys
     */
    private function extractString(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];
            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function differentNumeric(?string $current, string $candidate): bool
    {
        if ($current === null) {
            return true;
        }

        if (function_exists('bccomp')) {
            return bccomp($current, $candidate, 12) !== 0;
        }

        return ((float) $current) - ((float) $candidate) !== 0.0;
    }
}
