<?php

namespace App\Command;

use App\Service\Pipeline\MtfPipelineViewService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:monitor:contract-pipeline',
    description: 'Affiche les pipelines MTF (tf_eligibility/latest_signal) et détecte les changements'
)]
class MonitorContractPipelineCommand extends Command
{
    public function __construct(private readonly MtfPipelineViewService $pipelines)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Intervalle de refresh (secondes)', '2')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre max de lignes affichées (0 = toutes)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $limit    = max(0, (int)$input->getOption('limit'));

        $prevSnapshot = [];
        $prevValues   = [];
        $section = $output->section();

        while (true) {
            $rows = $this->fetchRows($limit);
            $changed = [];
            $nowSnapshot = [];
            foreach ($rows as $row) {
                $key = $this->rowKey($row);
                $hash = $this->rowHash($row);
                $nowSnapshot[$key] = $hash;
                if (!isset($prevSnapshot[$key]) || $prevSnapshot[$key] !== $hash) {
                    $changed[$key] = true;
                }
            }

            $buf = new BufferedOutput();
            $buf->writeln(sprintf(
                "<info>mtf_pipelines</info> | %d lignes | %d modifiée(s) | refresh %ds | %s",
                count($rows), count($changed), $interval, (new DateTimeImmutable())->format('H:i:s')
            ));

            $table = new Table($buf);
            $table->setHeaders(['symbol', 'tf', 'card', 'retries', 'lock', 'updated']);
            $table->setStyle('box');

            $nextPrev = [];
            foreach ($rows as $row) {
                $key = $this->rowKey($row);
                $prevRetries = $prevValues[$key]['retries'] ?? null;
                $delta = $prevRetries === null ? ''
                    : ((int)$row['retries'] > (int)$prevRetries ? ' ▲'
                        : ((int)$row['retries'] < (int)$prevRetries ? ' ▼' : ''));

                $card = match ($row['card_status']) {
                    'completed' => '<fg=green>completed</>',
                    'failed'    => '<fg=red>failed</>',
                    default     => '<fg=yellow>progress</>',
                };
                $lock = $row['locked'] ? '<fg=red>locked</>' : '<fg=green>open</>';
                $tableRow = [
                    $row['symbol'],
                    $row['current_timeframe'],
                    $card,
                    sprintf('%d/%d%s', $row['retries'], $row['max_retries'], $delta),
                    $lock,
                    $row['updated_at'] ?? '-',
                ];
                if (isset($changed[$key])) {
                    $tableRow = array_map(static fn($v) => "<bg=yellow;fg=black;options=bold> $v </>", $tableRow);
                }
                $table->addRow($tableRow);
                $nextPrev[$key] = [
                    'retries' => $row['retries'],
                    'max_retries' => $row['max_retries'],
                    'card_status' => $row['card_status'],
                ];
            }

            $table->render();
            $section->overwrite($buf->fetch());

            $prevSnapshot = $nowSnapshot;
            $prevValues = $nextPrev;

            sleep($interval);
        }

        // unreachable
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRows(int $limit): array
    {
        $rows = $this->pipelines->list();
        usort($rows, static function (array $a, array $b) {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        return array_map(fn(array $row) => [
            'symbol' => $row['symbol'],
            'current_timeframe' => $row['current_timeframe'],
            'retries' => (int)($row['retries_current'] ?? 0),
            'max_retries' => (int)($row['max_retries'] ?? 0),
            'card_status' => $row['card_status'],
            'locked' => $this->isLocked($row),
            'updated_at' => $row['updated_at'],
        ], $rows);
    }

    private function rowKey(array $row): string
    {
        return $row['symbol'].'|'.$row['current_timeframe'];
    }

    private function rowHash(array $row): string
    {
        return sha1(json_encode([
            $row['retries'],
            $row['max_retries'],
            $row['card_status'],
            $row['locked'],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function isLocked(array $pipeline): bool
    {
        foreach ($pipeline['eligibility'] ?? [] as $row) {
            $status = strtoupper((string)($row['status'] ?? ''));
            if (in_array($status, ['LOCKED_POSITION','LOCKED_ORDER'], true)) {
                return true;
            }
        }
        return false;
    }
}
