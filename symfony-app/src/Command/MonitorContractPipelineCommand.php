<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Cursor;

#[AsCommand(
    name: 'app:monitor:contract-pipeline',
    description: 'Affiche en continu contract_pipeline et remonte les lignes modifiées'
)]
class MonitorContractPipelineCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Intervalle de refresh (secondes)', '2')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre max de lignes affichées (0 = toutes)', '0')
            ->addOption('order-by', null, InputOption::VALUE_OPTIONAL, 'Ordre de base quand aucune ligne ne change', 'contract_symbol ASC, current_timeframe ASC');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval')); // ex: --interval=2
        $limit    = max(0, (int)$input->getOption('limit'));     // ex: --limit=50, 0=toutes
        $orderBy  = (string)$input->getOption('order-by');       // ex: --order-by="contract_symbol ASC"

        // Snapshots pour détecter les changements
        $prevSnapshot = [];   // key => hash des valeurs surveillées
        $prevValues   = [];   // key => valeurs brutes (ex: retries) pour calculer delta ▲▼

        // Section réinscrite à chaque tick (pas de scroll)
        $section = $output->section();

        while (true) {
            // 1) Lecture DB
            $rows = $this->fetchRows($orderBy, $limit);

            // 2) Détection des changements
            $changed = [];   // key => true si la ligne a changé depuis le tick précédent
            $nowSnapshot = [];

            foreach ($rows as $r) {
                $key  = $this->rowKey($r);     // ex: symbol|timeframe
                $hash = $this->rowHash($r);    // ex: sha1([retries, max_retries, status])
                $nowSnapshot[$key] = $hash;
                if (!isset($prevSnapshot[$key]) || $prevSnapshot[$key] !== $hash) {
                    $changed[$key] = true;
                }
            }

            // 3) Tri : lignes modifiées en tête, puis ordre secondaire stable
            usort($rows, function (array $a, array $b) use ($changed) {
                $ka = $this->rowKey($a);
                $kb = $this->rowKey($b);
                $ca = isset($changed[$ka]);
                $cb = isset($changed[$kb]);
                if ($ca !== $cb) {
                    return $ca ? -1 : 1; // changées d'abord
                }
                // tri secondaire stable
                return strcmp($a['contract_symbol'], $b['contract_symbol'])
                    ?: strcmp($a['current_timeframe'], $b['current_timeframe']);
            });

            // 4) Rendu dans un buffer, puis overwrite de la section
            $buf = new BufferedOutput();
            $buf->writeln(sprintf(
                "<info>contract_pipeline</info> | %d lignes | %d modifiée(s) | refresh %ds | %s",
                count($rows), count($changed), $interval, (new \DateTimeImmutable())->format('H:i:s')
            ));

            $table = new Table($buf);
            $table->setHeaders(['contract_symbol', 'current_timeframe', 'retries', 'max_retries', 'status']);
            $table->setStyle('box'); // compact | box | box-double | borderless

            $nextPrev = []; // valeurs brutes pour le prochain tick (delta)

            foreach ($rows as $r) {
                $key       = $this->rowKey($r);
                $isChanged = isset($changed[$key]);

                // Alignement chiffres + delta ▲▼
                $prevRetries = $prevValues[$key]['retries'] ?? null;
                $delta = $prevRetries === null ? ''
                    : ((int)$r['retries'] > (int)$prevRetries ? ' ▲'
                        : ((int)$r['retries'] < (int)$prevRetries ? ' ▼' : ''));

                $colRetries    = sprintf('%3d', (int)$r['retries']) . $delta;
                $colMaxRetries = sprintf('%3d', (int)$r['max_retries']);

                // Couleur du status
                $statusLabel = strtolower((string)$r['status']);
                $statusColored = match ($statusLabel) {
                    'done', 'ok', 'success'   => '<fg=green>done</>',
                    'running','processing'    => '<fg=blue>running</>',
                    'error','failed'          => '<fg=red>error</>',
                    default                   => '<fg=yellow>pending</>',
                };

                // Ligne prête
                $row = [
                    (string)$r['contract_symbol'],
                    (string)$r['current_timeframe'],
                    $colRetries,
                    $colMaxRetries,
                    $statusColored,
                ];

                // Surligner toute la ligne UNE FOIS quand un changement est détecté
                if ($isChanged) {
                    $row = array_map(
                        static fn($v) => "<bg=yellow;fg=black;options=bold> $v </>",
                        $row
                    );
                }

                $table->addRow($row);

                // Mémoriser valeurs brutes pour le prochain tick (calcul delta)
                $nextPrev[$key]['retries']     = (int)$r['retries'];
                $nextPrev[$key]['max_retries'] = (int)$r['max_retries'];
                $nextPrev[$key]['status']      = (string)$r['status'];
            }

            $table->render();
            $section->overwrite($buf->fetch());

            // 5) Mettre à jour les snapshots pour la prochaine itération
            $prevSnapshot = $nowSnapshot;
            $prevValues   = $nextPrev;

            // 6) Pause
            sleep($interval);
        }

        // (on ne sort normalement jamais ; si besoin, captez SIGINT pour un retour propre)
        // return Command::SUCCESS;
    }

    private function fetchRows(string $orderBy, int $limit): array
    {
        $sql = "SELECT contract_symbol, current_timeframe, retries, max_retries, status
                FROM contract_pipeline
                WHERE current_timeframe in ('1m', '5m', '15m')
                ORDER BY $orderBy";
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        return $this->db->fetchAllAssociative($sql);
    }

    private function rowKey(array $r): string
    {
        return $r['contract_symbol'].'|'.$r['current_timeframe'];
    }

    private function rowHash(array $r): string
    {
        // hache uniquement les champs qui nous intéressent
        return sha1(json_encode([
            $r['retries'], $r['max_retries'], $r['status']
        ], JSON_UNESCAPED_UNICODE));
    }
}
