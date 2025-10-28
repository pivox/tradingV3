<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Bitmart;

use App\Command\Provider\FetchContractsCommand;
use App\Infrastructure\Http\BitmartRestClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FetchContractsCommandTest extends TestCase
{
    public function testFetchContractsCommand(): void
    {
        // Mock du client BitMart
        $bitmartClient = $this->createMock(BitmartRestClient::class);

        $mockContracts = [
            [
                'symbol' => 'BTCUSDT',
                'name' => 'Bitcoin/USDT',
                'contract_type' => 'perpetual',
                'min_size' => '0.001',
                'max_size' => '1000',
                'tick_size' => '0.1',
                'status' => 'trading'
            ]
        ];

        $bitmartClient->expects($this->once())
            ->method('fetchContracts')
            ->willReturn($mockContracts);

        // Création de la commande
        $command = new FetchContractsCommand($bitmartClient);

        // Configuration de l'input
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        // Exécution de la commande
        $result = $command->run($input, $output);

        // Vérifications
        $this->assertEquals(0, $result);
        $this->assertStringContainsString('Récupéré 1 contrat(s)', $output->fetch());
        $this->assertStringContainsString('BTCUSDT', $output->fetch());
    }

    public function testFetchSpecificContract(): void
    {
        // Mock du client BitMart
        $bitmartClient = $this->createMock(BitmartRestClient::class);

        $mockContract = [
            'symbol' => 'BTCUSDT',
            'name' => 'Bitcoin/USDT',
            'contract_type' => 'perpetual',
            'min_size' => '0.001',
            'max_size' => '1000',
            'tick_size' => '0.1',
            'status' => 'trading'
        ];

        $bitmartClient->expects($this->once())
            ->method('fetchContractDetails')
            ->with('BTCUSDT')
            ->willReturn($mockContract);

        // Création de la commande
        $command = new FetchContractsCommand($bitmartClient);

        // Configuration de l'input avec symbole spécifique
        $input = new ArrayInput(['--symbol' => 'BTCUSDT']);
        $output = new BufferedOutput();

        // Exécution de la commande
        $result = $command->run($input, $output);

        // Vérifications
        $this->assertEquals(0, $result);
        $this->assertStringContainsString('Récupéré 1 contrat(s)', $output->fetch());
    }
}




