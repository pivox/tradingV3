<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Entity\MtfAudit;
use App\Entity\MtfSignal;
use App\Entity\MtfState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MtfValidatorFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MtfValidatorInterface $mtfValidator;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var MtfValidatorInterface $mtfValidator */
        $this->mtfValidator = self::getContainer()->get(MtfValidatorInterface::class);
    }

    public function testMtfValidatorPragmaticContextInvalidProducesAuditAndState(): void
    {
        $symbol = 'BTCUSDT';

        // 1) Nettoyer les données précédentes pour ce symbole (optionnel mais utile en test)
        $this->clearExistingDataForSymbol($symbol);

        // 2) Construire la requête MTF (même logique que mtf:core:run)
        $request = new MtfRunRequestDto(
            symbols: [$symbol],
            dryRun: false,
            forceRun: false,
            currentTf: null,
            forceTimeframeCheck: false,
            skipContextValidation: false,
            lockPerSymbol: true,
            skipOpenStateFilter: false,
            userId: null,
            ipAddress: null,
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
        );

        // 3) Exécuter le core
        $response = $this->mtfValidator->run($request);

        // 4) Assertions sur la réponse
        self::assertSame('success', $response->status, 'Le run MTF doit être en statut success (technique).');
        self::assertSame(1, $response->symbolsRequested);
        self::assertSame(1, $response->symbolsProcessed);

        // On vérifie que le résultat du symbole est présent
        $resultEntry = null;
        foreach ($response->results as $entry) {
            if (($entry['symbol'] ?? null) === $symbol) {
                $resultEntry = $entry;
                break;
            }
        }
        self::assertNotNull($resultEntry, 'Le résultat pour le symbole doit être présent dans MtfRunResponseDto.');

        $resultData = (array)($resultEntry['result'] ?? []);
        $details = (array)($resultData['details'] ?? []);

        // 5) Assertions sur le détail : contexte pragmatique invalide (comme ton exemple)
        $this->assertFalse($details['is_tradable'] ?? true, 'Le symbole ne doit pas être tradable si le contexte est invalide.');
        $this->assertSame(
            'pragmatic_context_has_invalid_timeframes',
            $details['final_reason'] ?? null,
            'La raison finale doit indiquer que le contexte pragmatique est invalide.'
        );

        // 6) Vérifier la projection mtf_audit
        /** @var MtfAudit[] $audits */
        $audits = $this->em->getRepository(MtfAudit::class)->findBy(
            ['symbol' => $symbol],
            ['id' => 'DESC'],
            5
        );
        self::assertNotEmpty($audits, 'Un audit MTF_RESULT doit être créé.');

        $lastAudit = $audits[0];
        self::assertSame('MTF_RESULT', $lastAudit->getStep());
        self::assertSame('pragmatic_context_has_invalid_timeframes', $lastAudit->getCause());

        $auditDetails = $lastAudit->getDetails();
        self::assertIsArray($auditDetails);
        self::assertSame('pragmatic', $auditDetails['mode'] ?? null);
        self::assertSame(false, $auditDetails['is_tradable'] ?? null);

        // 7) Vérifier la projection mtf_state
        /** @var MtfState|null $state */
        $state = $this->em->getRepository(MtfState::class)->findOneBy(['symbol' => $symbol]);
        self::assertNotNull($state, 'Un mtf_state doit exister pour ce symbole.');
        self::assertNull($state->getDecisionKey() ?? null, 'En contexte invalide, il peut être normal de ne pas avoir de decisionKey (selon ton implémentation).');

        $sides = $state->getSides();
        self::assertIsArray($sides);
        // Adapté à ta config scalper : contexte sur 1h et 15m
        self::assertArrayHasKey('1h', $sides);
        self::assertArrayHasKey('15m', $sides);
        self::assertNull($sides['1h']);
        self::assertNull($sides['15m']);

        // 8) Vérifier qu'aucun signal n'est créé (is_tradable=false)
        $signals = $this->em->getRepository(MtfSignal::class)->findBy(['symbol' => $symbol]);
        self::assertCount(0, $signals, 'Aucun signal ne doit être inséré si is_tradable=false.');
    }

    /**
     * Helper pour nettoyer les données de test pour un symbole.
     */
    private function clearExistingDataForSymbol(string $symbol): void
    {
        // On efface d'abord les signaux, puis les audits, puis le state (ordre souvent plus sûr)
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM mtf_signal WHERE symbol = :symbol', ['symbol' => $symbol]);
        $conn->executeStatement('DELETE FROM mtf_audit WHERE symbol = :symbol', ['symbol' => $symbol]);
        $conn->executeStatement('DELETE FROM mtf_state WHERE symbol = :symbol', ['symbol' => $symbol]);
    }
}
