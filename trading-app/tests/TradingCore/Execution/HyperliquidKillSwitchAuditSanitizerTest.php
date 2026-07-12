<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchAuditSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidKillSwitchAuditSanitizer::class)]
final class HyperliquidKillSwitchAuditSanitizerTest extends TestCase
{
    public function testRecursivelyRedactsSensitiveKeysAndValuesAndBoundsOutput(): void
    {
        $privateKey = '0x' . str_repeat('a', 64);
        $unprefixedPrivateKey = str_repeat('b', 64);
        $bearer = 'Bearer header.payload.signature';
        $opaqueToken = 'sk-test_' . str_repeat('z', 32);
        $assignment = 'token=plain-text-sensitive-value';
        $sanitizer = new HyperliquidKillSwitchAuditSanitizer();

        $context = $sanitizer->sanitizeContext([
            'private_key' => 'hidden',
            'note' => $privateKey,
            'summary' => $unprefixedPrivateKey,
            'detail' => $bearer,
            'diagnostic' => $opaqueToken,
            'message' => $assignment,
            'nested' => ['description' => 'Authorization: ' . $bearer],
            'correlation_id' => str_repeat('c', 500),
        ]);
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('hidden', $encoded);
        self::assertStringNotContainsString($privateKey, $encoded);
        self::assertStringNotContainsString($unprefixedPrivateKey, $encoded);
        self::assertStringNotContainsString($bearer, $encoded);
        self::assertStringNotContainsString($opaqueToken, $encoded);
        self::assertStringNotContainsString($assignment, $encoded);
        self::assertSame(128, strlen((string) $context['correlation_id']));
        self::assertLessThanOrEqual(4_096, strlen($encoded));
    }

    public function testReasonRejectsSensitiveValuePatterns(): void
    {
        $sanitizer = new HyperliquidKillSwitchAuditSanitizer();

        self::assertSame(
            'hyperliquid_kill_switch_tripped',
            $sanitizer->sanitizeReason('0x' . str_repeat('a', 64)),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveAssignments(): iterable
    {
        foreach ([
            'api_key=hidden',
            'api-key: hidden',
            'secret=hidden',
            'token=hidden',
            'private_key=hidden',
            'private-key: hidden',
            'passphrase=hidden',
            'password=hidden',
            'authorization=hidden',
            'cookie=hidden',
            'signature=hidden',
            'credential=hidden',
            'credentials=hidden',
            'memo=hidden',
        ] as $assignment) {
            yield $assignment => [$assignment];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveAssignments')]
    public function testRedactsFullSensitiveAssignmentVocabularyUnderBenignKeys(string $assignment): void
    {
        $context = (new HyperliquidKillSwitchAuditSanitizer())->sanitizeContext(['message' => $assignment]);

        self::assertSame('[redacted]', $context['message'] ?? null);
    }
}
