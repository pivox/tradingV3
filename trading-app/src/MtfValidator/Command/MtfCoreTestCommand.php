<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mtf:core:test')]
class MtfCoreTestCommand extends Command
{
    public function __construct(
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly TradeDecisionDispatcherInterface $tradeDecisionDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Version minimale : un seul symbole, dry_run = true
        $request = new MtfRunRequestDto(
            symbols: ['BTCUSDT'],
            dryRun: true,
        );

        $response = $this->mtfValidator->run($request);
        $this->tradeDecisionDispatcher->dispatchFromResponse($request, $response);


        return Command::SUCCESS;
    }
}
