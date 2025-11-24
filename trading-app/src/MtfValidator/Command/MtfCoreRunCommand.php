<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Provider\Repository\ContractRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'mtf:core:run',
    description: 'Exécute un cycle MTF core pour une liste de symboles (profil regular/scalper).',
)]
final class MtfCoreRunCommand extends Command
{
    public function __construct(
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly ContractRepository $contractRepository,
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'symbols',
                null,
                InputOption::VALUE_OPTIONAL,
                'Liste de symboles séparés par des virgules (ex: BTCUSDT,ETHUSDT). Si vide, on utilise les contrats actifs.'
            )
            ->addOption(
                'symbol-limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limite max de symboles à traiter (si non indiqué, on prend tous les symboles actifs compatibles profil).',
                null
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'N’exécute aucune action de trade, uniquement la validation MTF.'
            )
            ->addOption(
                'force-run',
                null,
                InputOption::VALUE_NONE,
                'Force l’exécution même si certaines gardes-fous (lock, last_run, etc.) sont actives.'
            )
            ->addOption(
                'tf',
                null,
                InputOption::VALUE_OPTIONAL,
                'Timeframe courant (ex: 4h,1h,15m) pour le contexte d’exécution.',
                '15m'
            )
            ->addOption(
                'skip-context',
                null,
                InputOption::VALUE_NONE,
                'Si défini, on saute certaines validations de contexte (à utiliser avec prudence).'
            )
            ->addOption(
                'user-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Identifiant utilisateur (pour audit / multi-compte).',
                null
            )
            ->addOption(
                'ip-address',
                null,
                InputOption::VALUE_OPTIONAL,
                'Adresse IP de la requête (pour audit / rate-limiting externe).',
                null
            )
            ->addOption(
                'exchange',
                null,
                InputOption::VALUE_OPTIONAL,
                'Exchange ciblé (ex: bitmart).',
                'bitmart'
            )
            ->addOption(
                'market-type',
                null,
                InputOption::VALUE_OPTIONAL,
                'Type de marché (ex: futures, spot).',
                'futures'
            )
            ->addOption(
                'mtf-profile',
                null,
                InputOption::VALUE_OPTIONAL,
                'Profil MTF à utiliser (regular|scalper|permissive|... selon ta config).',
                'scalper'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $symbolsOption    = \trim((string) $input->getOption('symbols'));
        $symbolLimitInput = $input->getOption('symbol-limit');
        $dryRun           = (bool) $input->getOption('dry-run');
        $forceRun         = (bool) $input->getOption('force-run');
        $currentTf        = (string) $input->getOption('tf');
        $skipContext      = (bool) $input->getOption('skip-context');
        $userId           = $input->getOption('user-id');
        $ipAddress        = $input->getOption('ip-address');
        $exchange         = (string) $input->getOption('exchange');
        $marketType       = (string) $input->getOption('market-type');
        $mtfProfile       = (string) $input->getOption('mtf-profile');

        $symbolLimit = null;
        if ($symbolLimitInput !== null && $symbolLimitInput !== '') {
            $symbolLimit = \max(1, (int) $symbolLimitInput);
        }

        // 1) Récupération des symboles
        $symbols = $this->resolveSymbolsList($symbolsOption, $mtfProfile, $symbolLimit, $io);
        if ($symbols === []) {
            $io->warning('Aucun symbole à traiter (liste vide).');
            return Command::SUCCESS;
        }

        $runId = Uuid::uuid4()->toString();

        $io->title('MTF Core Run');
        $io->writeln(sprintf('Run ID       : <comment>%s</comment>', $runId));
        $io->writeln(sprintf('Profil MTF   : <info>%s</info>', $mtfProfile));
        $io->writeln(sprintf('Timeframe    : <info>%s</info>', $currentTf));
        $io->writeln(sprintf('Mode dry-run : <info>%s</info>', $dryRun ? 'OUI' : 'NON'));
        $io->writeln(sprintf('Force run    : <info>%s</info>', $forceRun ? 'OUI' : 'NON'));
        $io->writeln(sprintf('Exchange     : <info>%s</info>', $exchange));
        $io->writeln(sprintf('Market type  : <info>%s</info>', $marketType));
        $io->writeln(sprintf('Nb symboles  : <info>%d</info>', \count($symbols)));
        $io->newLine();

        // 2) Construction de la requête
        // NB: adapter les paramètres du constructeur de MtfRunRequestDto à ta définition réelle.
        $requestDto = new MtfRunRequestDto(
            symbols: $symbols,
            dryRun: $dryRun,
            forceRun: $forceRun,
            skipContextValidation: $skipContext,
            userId: $userId,
            ipAddress: $ipAddress,
            marketType: MarketType::tryFrom($marketType),
        );

        // 3) Exécution du validateur MTF
        try {
            /** @var MtfRunResponseDto $response */
            $response = $this->mtfValidator->run($requestDto);
        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur lors de l’exécution du validateur MTF: %s', $e->getMessage()));
            $this->logger->error('mtf:core:run failed', [
                'exception' => $e,
                'run_id'    => $runId,
            ]);

            return Command::FAILURE;
        }

        // 4) Affichage des résultats détaillés + catégorisation des statuts
        $io->section('Résultats par symbole');

        $stats = [
            'total'   => 0,
            'ready'   => 0,
            'invalid' => 0,
            'ignored' => 0,
            'error'   => 0,
        ];

        $results = $response->results ?? [];

        foreach ($results as $entry) {
            $stats['total']++;

            $symbol    = (string) ($entry['symbol'] ?? 'UNKNOWN');
            $mtfResult = $entry['result'] ?? null;

            // Cas de résultat déjà pré-normalisé (tableau simple)
            if ($mtfResult === null || \is_array($mtfResult)) {
                $status = $entry['status'] ?? '-';
                $reason = $entry['reason'] ?? null;

                $normalized = $this->normalizeSimpleStatus((string) $status);
                $stats[$normalized['bucket']]++;

                $io->writeln(sprintf(
                    '<info>%s</info> - statut: <comment>%s</comment>',
                    $symbol,
                    $normalized['status'],
                ));
                if ($reason !== null && $reason !== '') {
                    $io->writeln(sprintf('  Raison: %s', (string) $reason));
                }
                $io->newLine();
                continue;
            }

            // Cas objet MtfRunResultDto-like : on suppose propriétés publiques/readonly
            $isTradable      = (bool) ($mtfResult->isTradable ?? false);
            $executionTf     = $mtfResult->executionTimeframe ?? null;
            $side            = $mtfResult->side ?? null;
            $finalReason     = $mtfResult->finalReason ?? null;
            $normalizedFinal = $this->normalizeFinalReason($finalReason);

            $statusInfo = $this->computeStatusFromResult(
                isTradable: $isTradable,
                executionTimeframe: $executionTf,
                side: $side,
                reasonCategory: $normalizedFinal['category'],
            );

            $stats[$statusInfo['bucket']]++;

            $io->writeln(sprintf(
                '<info>%s</info> - statut: <comment>%s</comment>',
                $symbol,
                $statusInfo['status'],
            ));

            if ($isTradable && $executionTf !== null && $side !== null) {
                $io->writeln(sprintf(
                    '  Décision finale : %s sur %s',
                    \strtoupper((string) $side),
                    (string) $executionTf,
                ));
            }

            if ($finalReason !== null && $finalReason !== '') {
                $io->writeln(sprintf(
                    '  Raison normalisée : %s',
                    $normalizedFinal['category'] ?? '(inconnue)',
                ));
                $io->writeln(sprintf(
                    '  Détail : %s',
                    (string) $finalReason,
                ));
            }

            $io->newLine();
        }

        // 5) Résumé global
        $elapsed = microtime(true) - $startTime;

        $io->section('Résumé global');

        $successCount = $stats['ready'];
        $failureCount = $stats['invalid'] + $stats['error'];
        $ignoredCount = $stats['ignored'];
        $total        = $stats['total'] > 0 ? $stats['total'] : 1;

        $successRate = $successCount > 0
            ? \round(($successCount / $total) * 100, 2)
            : 0.0;

        $io->writeln(sprintf('Run ID         : <comment>%s</comment>', $response->runId ?? $runId));
        $io->writeln(sprintf('Profil         : <info>%s</info>', $response->profile ?? $mtfProfile));
        $io->writeln(sprintf('Mode           : <info>%s</info>', $response->mode ?? 'core'));
        $io->writeln(sprintf('Symboles       : <info>%d</info>', $stats['total']));
        $io->writeln(sprintf('READY          : <info>%d</info>', $stats['ready']));
        $io->writeln(sprintf('INVALID        : <comment>%d</comment>', $stats['invalid']));
        $io->writeln(sprintf('IGNORED        : <comment>%d</comment>', $stats['ignored']));
        $io->writeln(sprintf('ERROR          : <error>%d</error>', $stats['error']));
        $io->writeln(sprintf('Taux de succès : <info>%.2f %%</info>', $successRate));
        $io->writeln(sprintf('Durée          : <info>%.3f s</info>', $elapsed));

        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * Résout la liste des symboles à partir de l’option CLI ou du repository.
     *
     * @return string[]
     */
    private function resolveSymbolsList(
        string $symbolsOption,
        string $mtfProfile,
        ?int $limit,
        SymfonyStyle $io
    ): array {
        if ($symbolsOption !== '') {
            $symbols = \array_filter(
                \array_map('trim', \explode(',', $symbolsOption)),
                static fn (string $s): bool => $s !== ''
            );

            $io->writeln(sprintf(
                'Symboles fournis en CLI (%d): %s',
                \count($symbols),
                \implode(', ', $symbols),
            ));

            if ($limit !== null && \count($symbols) > $limit) {
                $symbols = \array_slice($symbols, 0, $limit);
                $io->writeln(sprintf(
                    '<comment>Limite appliquée: %d symboles seront traités.</comment>',
                    \count($symbols),
                ));
            }

            $io->newLine();

            return \array_values($symbols);
        }

        // Sinon on va chercher les contrats actifs compatibles avec le profil MTF
        $io->writeln('Aucun symbole fourni, récupération des contrats actifs via ContractRepository...');

        $contracts = $this->contractRepository->findActiveContracts($mtfProfile, $limit);

        $symbols = [];
        foreach ($contracts as $contract) {
            $symbols[] = $contract->getSymbol();
        }

        $io->writeln(sprintf(
            'Symboles récupérés (%d): %s',
            \count($symbols),
            $symbols !== [] ? \implode(', ', $symbols) : '(aucun)',
        ));
        $io->newLine();

        return $symbols;
    }

    /**
     * Normalise un statut simple (déjà fourni) vers un bucket pour les stats.
     *
     * @return array{status: string, bucket: string}
     */
    private function normalizeSimpleStatus(string $status): array
    {
        $normalized = \strtoupper($status);

        return match ($normalized) {
            'READY'       => ['status' => 'READY', 'bucket' => 'ready'],
            'INVALID'     => ['status' => 'INVALID', 'bucket' => 'invalid'],
            'IGNORED'     => ['status' => 'IGNORED', 'bucket' => 'ignored'],
            'ERROR'       => ['status' => 'ERROR', 'bucket' => 'error'],
            default       => ['status' => $normalized, 'bucket' => 'ignored'],
        };
    }

    /**
     * Découpe le finalReason en catégorie + détail.
     *
     * Exemple: "filters_mandatory_failed:rsi_lt_70" =>
     *   category="filters_mandatory_failed", detail="rsi_lt_70"
     *
     * @return array{category: ?string, detail: ?string}
     */
    private function normalizeFinalReason(?string $finalReason): array
    {
        if ($finalReason === null || $finalReason === '') {
            return ['category' => null, 'detail' => null];
        }

        $parts = \explode(':', $finalReason, 2);

        return [
            'category' => $parts[0] ?? null,
            'detail'   => $parts[1] ?? null,
        ];
    }

    /**
     * Calcule un statut normalisé à partir du résultat MTF.
     *
     * @return array{status: string, bucket: string}
     */
    private function computeStatusFromResult(
        bool $isTradable,
        ?string $executionTimeframe,
        ?string $side,
        ?string $reasonCategory
    ): array {
        if ($isTradable === true && $executionTimeframe !== null && $side !== null) {
            return [
                'status' => 'READY',
                'bucket' => 'ready',
            ];
        }

        // Si non tradable, on catégorise selon la catégorie de raison
        $category = $reasonCategory !== null ? \strtolower($reasonCategory) : null;

        return match ($category) {
            'filters_mandatory_failed' => [
                'status' => 'FILTERS_MANDATORY_FAILED',
                'bucket' => 'invalid',
            ],
            'context_invalid' => [
                'status' => 'CONTEXT_INVALID',
                'bucket' => 'invalid',
            ],
            'no_executable_timeframe' => [
                'status' => 'NO_EXECUTION_TF',
                'bucket' => 'ignored',
            ],
            'execution_forbidden' => [
                'status' => 'EXECUTION_FORBIDDEN',
                'bucket' => 'invalid',
            ],
            'internal_error',
            'error' => [
                'status' => 'ERROR',
                'bucket' => 'error',
            ],
            default => [
                // Par défaut on garde la catégorie en majuscules si présente,
                // sinon un statut neutre (IGNORED) pour éviter les '-'.
                'status' => $category !== null ? \strtoupper($category) : 'IGNORED',
                'bucket' => 'ignored',
            ],
        };
    }
}
