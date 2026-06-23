<?php

declare(strict_types=1);

namespace App\Tests\Trading\Service;

use App\Trading\Service\RunCorrelationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OBS-003 — Vecteurs PARTAGÉS de l'identifiant de corrélation canonique.
 *
 * Lit `tests/fixtures/run_correlation_vectors.json` (racine du dépôt), le MÊME fichier
 * que le test Python `test_correlation.py` : PHP et Python doivent produire exactement
 * le même résultat pour chaque vecteur. Garantit notamment qu'aucune collision de
 * préfixe n'est possible (pas de troncature `substr($id, 0, 64)`).
 */
#[CoversClass(RunCorrelationId::class)]
final class RunCorrelationIdTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        // trading-app/tests/Trading/Service/ -> racine du dépôt -> tests/fixtures/...
        $path = \dirname(__DIR__, 4) . '/tests/fixtures/run_correlation_vectors.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'shared correlation fixture must be readable');

        /** @var array<string,mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testFixtureIsPresentAndShared(): void
    {
        $data = self::fixture();
        self::assertSame(RunCorrelationId::MAX_LENGTH, $data['max_len']);
        self::assertTrue($data['empty_must_raise']);
        self::assertNotEmpty($data['vectors']);
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>}>
     */
    public static function vectorProvider(): iterable
    {
        foreach (self::fixture()['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * @param array<string,mixed> $vector
     */
    #[DataProvider('vectorProvider')]
    public function testCanonicalMatchesSharedVectors(array $vector): void
    {
        $result = RunCorrelationId::canonical($vector['input']);
        self::assertSame($vector['expected'], $result);

        if ($vector['transform'] === 'identity' || $vector['transform'] === 'trim') {
            // identity : conservé tel quel ; trim : conservé après trim des espaces.
            self::assertLessThanOrEqual(RunCorrelationId::MAX_LENGTH, mb_strlen($result));
            if ($vector['transform'] === 'identity') {
                self::assertSame($vector['input'], $result);
            } else {
                self::assertSame(trim($vector['input']), $result);
            }
        } else {
            self::assertSame('sha256', $vector['transform']);
            // Hash hex de 64 caractères, jamais une troncature du préfixe.
            self::assertSame(64, mb_strlen($result));
            self::assertNotSame(mb_substr($vector['input'], 0, 64), $result);
        }
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RunCorrelationId::canonical('   ');
    }

    public function testCanonicalOrNullFallsBackForBlankInput(): void
    {
        self::assertNull(RunCorrelationId::canonicalOrNull(null));
        self::assertNull(RunCorrelationId::canonicalOrNull(''));
        self::assertNull(RunCorrelationId::canonicalOrNull('   '));
        self::assertSame('run_abc', RunCorrelationId::canonicalOrNull('run_abc'));
    }

    public function testPrefixCollisionsDoNotCollide(): void
    {
        $byName = [];
        foreach (self::fixture()['vectors'] as $vector) {
            $byName[$vector['name']] = $vector;
        }
        $a = $byName['prefix_collision_a']['input'];
        $b = $byName['prefix_collision_b']['input'];

        self::assertSame(mb_substr($a, 0, 64), mb_substr($b, 0, 64));
        self::assertNotSame(RunCorrelationId::canonical($a), RunCorrelationId::canonical($b));
    }
}
