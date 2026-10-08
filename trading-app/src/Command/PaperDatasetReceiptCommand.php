<?php

declare(strict_types=1);

namespace App\Command;

use App\Trading\Paper\Dataset\PaperDatasetVerificationReceipt;
use App\Trading\Paper\Replay\PaperReplayReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the one full baseline verification of a campaign dataset and writes its receipt.
 * The campaign runner starts it once per dataset, in its own process with the explicit
 * child memory limit, so the long-lived campaign process never holds verifier structures.
 */
#[AsCommand(
    name: 'app:paper-market:dataset-receipt',
    description: 'Verify one Paper dataset once (baseline) and write its campaign verification receipt.',
)]
final class PaperDatasetReceiptCommand extends Command implements SignalableCommandInterface
{
    public const RESULT_SCHEMA = 'paper-dataset-receipt-result-v1';

    public function __construct(
        private readonly PaperReplayReader $reader,
        private readonly PaperDatasetVerificationReceipt $receipts = new PaperDatasetVerificationReceipt(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dataset', null, InputOption::VALUE_REQUIRED, 'Absolute private dataset directory')
            ->addOption('receipt', null, InputOption::VALUE_REQUIRED, 'Absolute path of the receipt to create (must not exist)');
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return array_values(array_filter([
            \defined('SIGINT') ? \SIGINT : null,
            \defined('SIGTERM') ? \SIGTERM : null,
            \defined('SIGHUP') ? \SIGHUP : null,
            \defined('SIGQUIT') ? \SIGQUIT : null,
        ], static fn (?int $signal): bool => $signal !== null));
    }

    /**
     * A signalled verification exits with 128 + signal, never with success: the receipt is
     * only written after the whole verification passed, and an interrupted write cannot
     * validate (canonical encoding and self-digest).
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return 128 + $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $dataset = $this->requiredOption($input, 'dataset');
            $receipt = $this->requiredOption($input, 'receipt');
            $body = $this->receipts->issue($dataset, $this->reader->eventLimit(), $receipt);
            $manifest = \is_array($body['manifest'] ?? null) ? $body['manifest'] : [];
            $events = \is_array($body['events'] ?? null) ? $body['events'] : [];
            $payload = [
                'schema_version' => self::RESULT_SCHEMA,
                'issued' => true,
                'dataset_id' => $manifest['dataset_id'] ?? null,
                'event_count' => $manifest['event_count'] ?? null,
                'events_sha256' => $events['sha256'] ?? null,
                'code_tree_sha256' => $body['code_tree_sha256'] ?? null,
                'event_limit' => $body['event_limit'] ?? null,
            ];
            $status = Command::SUCCESS;
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $exception) {
            $blocker = $exception->getMessage();
            $payload = [
                'schema_version' => self::RESULT_SCHEMA,
                'issued' => false,
                'blocker' => preg_match('/\A[a-z0-9_]{3,96}\z/D', $blocker) === 1 ? $blocker : 'paper_dataset_receipt_failed',
            ];
            $status = Command::FAILURE;
        }
        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $status;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || trim($value) === '' || trim($value) !== $value) {
            throw new \InvalidArgumentException('paper_dataset_receipt_option_invalid');
        }

        return $value;
    }
}
