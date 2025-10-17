<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\KlineRepository;
use App\Repository\MtfAuditRepository;
use App\Repository\MtfStateRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\OrderPlanRepository;
use App\Repository\SignalRepository;
use App\Repository\ValidationCacheRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-database',
    description: 'Teste la base de données et les repositories'
)]
class TestDatabaseCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KlineRepository $klineRepository,
        private readonly SignalRepository $signalRepository,
        private readonly ValidationCacheRepository $validationCacheRepository,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly OrderPlanRepository $orderPlanRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('create-test-data', 'c', InputOption::VALUE_NONE, 'Créer des données de test')
            ->addOption('cleanup', 'l', InputOption::VALUE_NONE, 'Nettoyer les données de test')
            ->setHelp('
Cette commande teste la base de données et les repositories.

Exemples:
  php bin/console app:test-database                    # Test de base
  php bin/console app:test-database --create-test-data # Test avec données
  php bin/console app:test-database --cleanup         # Nettoyage
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $createTestData = $input->getOption('create-test-data');
        $cleanup = $input->getOption('cleanup');

        $io->title('Test de la base de données');

        try {
            // Test de connexion
            $io->section('Test de connexion');
            $result = $this->connection->executeQuery('SELECT 1 as test')->fetchAssociative();
            
            if ($result['test'] === 1) {
                $io->success('Connexion à la base de données réussie');
            } else {
                $io->error('Erreur de connexion à la base de données');
                return Command::FAILURE;
            }

            // Test des tables
            $io->section('Test des tables');
            $tables = [
                'klines' => $this->klineRepository,
                'signals' => $this->signalRepository,
                'validation_cache' => $this->validationCacheRepository,
                'mtf_audit' => $this->mtfAuditRepository,
                'mtf_state' => $this->mtfStateRepository,
                'mtf_switch' => $this->mtfSwitchRepository,
                'order_plan' => $this->orderPlanRepository
            ];
            
            $tableResults = [];
            foreach ($tables as $tableName => $repository) {
                try {
                    $count = $repository->count([]);
                    $tableResults[] = [$tableName, 'OK', $count];
                } catch (\Exception $e) {
                    $tableResults[] = [$tableName, 'ERREUR', $e->getMessage()];
                }
            }
            
            $io->table(
                ['Table', 'Statut', 'Count/Erreur'],
                $tableResults
            );

            // Test des kill switches
            $io->section('Test des kill switches');
            $globalSwitch = $this->mtfSwitchRepository->isGlobalSwitchOn();
            $btcSwitch = $this->mtfSwitchRepository->isSymbolSwitchOn('BTCUSDT');
            
            $io->table(
                ['Switch', 'État'],
                [
                    ['Global', $globalSwitch ? 'ON' : 'OFF'],
                    ['BTCUSDT', $btcSwitch ? 'ON' : 'OFF']
                ]
            );

            // Test des états MTF
            $io->section('Test des états MTF');
            $states = $this->mtfStateRepository->findAll();
            
            if (empty($states)) {
                $io->text('Aucun état MTF trouvé');
            } else {
                $stateResults = [];
                foreach ($states as $state) {
                    $stateResults[] = [
                        $state->getSymbol(),
                        $state->is4hValidated() ? 'OUI' : 'NON',
                        $state->is1hValidated() ? 'OUI' : 'NON',
                        $state->is15mValidated() ? 'OUI' : 'NON',
                        $state->hasConsistentSides() ? 'OUI' : 'NON'
                    ];
                }
                
                $io->table(
                    ['Symbole', '4h', '1h', '15m', 'Cohérent'],
                    $stateResults
                );
            }

            // Test des order plans
            $io->section('Test des order plans');
            $orderPlans = $this->orderPlanRepository->findAll();
            
            if (empty($orderPlans)) {
                $io->text('Aucun order plan trouvé');
            } else {
                $planResults = [];
                foreach ($orderPlans as $plan) {
                    $planResults[] = [
                        $plan->getId(),
                        $plan->getSymbol(),
                        $plan->getSide()->value,
                        $plan->getStatus(),
                        $plan->getPlanTime()->format('Y-m-d H:i:s')
                    ];
                }
                
                $io->table(
                    ['ID', 'Symbole', 'Côté', 'Statut', 'Date'],
                    $planResults
                );
            }

            // Test des audits
            $io->section('Test des audits');
            $audits = $this->mtfAuditRepository->findBy([], ['createdAt' => 'DESC'], 10);
            
            if (empty($audits)) {
                $io->text('Aucun audit trouvé');
            } else {
                $auditResults = [];
                foreach ($audits as $audit) {
                    $auditResults[] = [
                        $audit->getId(),
                        $audit->getSymbol(),
                        $audit->getStep(),
                        $audit->getTimeframe()?->value ?? 'N/A',
                        $audit->getCreatedAt()->format('Y-m-d H:i:s')
                    ];
                }
                
                $io->table(
                    ['ID', 'Symbole', 'Étape', 'Timeframe', 'Date'],
                    $auditResults
                );
            }

            // Création de données de test
            if ($createTestData) {
                $io->section('Création de données de test');
                $this->createTestData($io);
            }

            // Nettoyage
            if ($cleanup) {
                $io->section('Nettoyage des données de test');
                $this->cleanupTestData($io);
            }

            $io->success('Tous les tests de base de données ont réussi');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            $this->logger->error('[Test Database] Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function createTestData(SymfonyStyle $io): void
    {
        // Créer un état MTF de test
        $state = $this->mtfStateRepository->getOrCreateForSymbol('TESTUSDT');
        $state->setK4hTime($this->clock->now());
        $state->setK1hTime($this->clock->now());
        $state->setK15mTime($this->clock->now());
        $state->set4hSide('LONG');
        $state->set1hSide('LONG');
        $state->set15mSide('LONG');
        $this->mtfStateRepository->getEntityManager()->flush();
        
        $io->text('État MTF de test créé pour TESTUSDT');
        
        // Créer un order plan de test
        $orderPlan = new \App\Entity\OrderPlan();
        $orderPlan->setSymbol('TESTUSDT');
        $orderPlan->setSide(\App\Domain\Common\Enum\SignalSide::LONG);
        $orderPlan->setContextJson(['test' => true]);
        $orderPlan->setRiskJson(['leverage' => 10]);
        $orderPlan->setExecJson(['dry_run' => true]);
        $this->orderPlanRepository->getEntityManager()->persist($orderPlan);
        $this->orderPlanRepository->getEntityManager()->flush();
        
        $io->text('Order plan de test créé');
        
        // Créer un audit de test
        $audit = new \App\Entity\MtfAudit();
        $audit->setRunId(\Ramsey\Uuid\Uuid::uuid4());
        $audit->setSymbol('TESTUSDT');
        $audit->setStep('TEST_STEP');
        $audit->setCause('Test de création de données');
        $audit->setDetails(['test' => true]);
        $this->mtfAuditRepository->getEntityManager()->persist($audit);
        $this->mtfAuditRepository->getEntityManager()->flush();
        
        $io->text('Audit de test créé');
    }

    private function cleanupTestData(SymfonyStyle $io): void
    {
        // Nettoyer les données de test
        $this->connection->executeStatement("DELETE FROM mtf_audit WHERE symbol = 'TESTUSDT'");
        $this->connection->executeStatement("DELETE FROM order_plan WHERE symbol = 'TESTUSDT'");
        $this->connection->executeStatement("DELETE FROM mtf_state WHERE symbol = 'TESTUSDT'");
        
        $io->text('Données de test nettoyées');
    }

    private function testMtfState(): void
    {
        $state = new MtfState();
        $state->setSymbol('BTCUSDT');
        $state->setK4hTime($this->clock->now());
        $state->setK1hTime($this->clock->now());
        $state->setK15mTime($this->clock->now());
        $this->em->persist($state);
        $this->em->flush();
    }
}


