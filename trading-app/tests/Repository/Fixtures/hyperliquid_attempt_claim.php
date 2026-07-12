<?php

declare(strict_types=1);

use App\Entity\HyperliquidTestnetExecutionAttempt;
use App\Repository\HyperliquidTestnetExecutionAttemptRepository;
use App\Tests\Support\SingleEntityManagerRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$dsn = (string) getenv('HL012_TEST_DSN');
$schema = (string) getenv('HL012_TEST_SCHEMA');
$key = (string) getenv('HL012_TEST_KEY');
$fingerprint = (string) getenv('HL012_TEST_FINGERPRINT');
$clientOrderId = (string) getenv('HL012_TEST_CLIENT_ORDER_ID');

$connection = DriverManager::getConnection(['url' => $dsn]);
$quotedSchema = $connection->getDatabasePlatform()->quoteSingleIdentifier($schema);
$connection->executeStatement('SET search_path TO ' . $quotedSchema);
$configuration = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true);
$entityManager = new EntityManager($connection, $configuration);
$repository = new HyperliquidTestnetExecutionAttemptRepository(
    new SingleEntityManagerRegistry($entityManager),
);

fwrite(STDERR, "ready\n");
$claim = $repository->claim($key, $fingerprint, $clientOrderId, 'corr-child');
fwrite(STDOUT, $claim->outcome);
$connection->close();
