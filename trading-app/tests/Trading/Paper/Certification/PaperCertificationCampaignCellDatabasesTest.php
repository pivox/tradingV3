<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignCellDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCertificationCampaignCellDatabases::class)]
final class PaperCertificationCampaignCellDatabasesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper-cell-databases-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
    }

    public function testReadsOnePrivateDedicatedDatabasePerCellInOrder(): void
    {
        $path = $this->write(self::document(['secret-a', 'secret-b'], [5440, 5441]));

        $databases = PaperCertificationCampaignCellDatabases::fromFile($path);

        self::assertSame(2, $databases->count());
        self::assertStringContainsString('@127.0.0.1:5440/trading_paper', $databases->urlFor(0));
        self::assertStringContainsString('@127.0.0.1:5441/trading_paper', $databases->urlFor(1));
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $databases->fingerprint());
        self::assertSame(
            $databases->fingerprint(),
            PaperCertificationCampaignCellDatabases::fromDocument(self::document(['rotated-1', 'rotated-2'], [5440, 5441]))->fingerprint(),
            'The fingerprint names the instances, never their credentials.',
        );
        self::assertNotSame(
            $databases->fingerprint(),
            PaperCertificationCampaignCellDatabases::fromDocument(self::document(['secret-a', 'secret-b'], [5441, 5440]))->fingerprint(),
        );
    }

    public function testRejectsAnythingButPrivateDistinctLocalTradingPaperInstancesWithoutLeakingIt(): void
    {
        $base = self::document(['secret-a', 'secret-b'], [5440, 5441]);
        $cases = [
            'shared instance' => self::document(['secret-a', 'secret-b'], [5440, 5440]),
            'same instance by name' => ['schema_version' => $base['schema_version'], 'databases' => [
                $base['databases'][0],
                str_replace('127.0.0.1', 'localhost', $base['databases'][0]),
            ]],
            'remote host' => ['schema_version' => $base['schema_version'], 'databases' => [str_replace('127.0.0.1', '10.0.0.5', $base['databases'][0])]],
            'other database' => ['schema_version' => $base['schema_version'], 'databases' => [str_replace('/trading_paper', '/trading_app', $base['databases'][0])]],
            'no password' => ['schema_version' => $base['schema_version'], 'databases' => ['postgresql://postgres@127.0.0.1:5440/trading_paper']],
            'other schema' => ['schema_version' => 'paper-campaign-cell-databases-v0', 'databases' => $base['databases']],
            'extra field' => [...$base, 'comment' => 'x'],
            'empty' => ['schema_version' => $base['schema_version'], 'databases' => []],
        ];
        foreach ($cases as $label => $document) {
            try {
                PaperCertificationCampaignCellDatabases::fromDocument($document);
                self::fail('Accepted: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_campaign_cell_databases_invalid', $exception->getMessage(), $label);
            }
        }

        $readable = $this->write($base);
        chmod($readable, 0644);
        $link = $this->root . '/link.json';
        symlink($this->write($base, 'target.json'), $link);
        foreach (['world readable' => $readable, 'symlink' => $link, 'relative' => 'cell-databases.json'] as $label => $path) {
            try {
                PaperCertificationCampaignCellDatabases::fromFile($path);
                self::fail('Accepted: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_campaign_cell_databases_invalid', $exception->getMessage(), $label);
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    /**
     * @param list<string> $passwords
     * @param list<int> $ports
     * @return array{schema_version: string, databases: list<string>}
     */
    private static function document(array $passwords, array $ports): array
    {
        return [
            'schema_version' => PaperCertificationCampaignCellDatabases::SCHEMA_VERSION,
            'databases' => array_map(
                static fn (string $password, int $port): string => sprintf(
                    'postgresql://postgres:%s@127.0.0.1:%d/trading_paper?serverVersion=15&charset=utf8',
                    $password,
                    $port,
                ),
                $passwords,
                $ports,
            ),
        ];
    }

    /** @param array<string, mixed> $document */
    private function write(array $document, string $name = 'cell-databases.json'): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);

        return $path;
    }
}
