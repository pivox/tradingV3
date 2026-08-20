<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use App\TradingCore\Config\Audit\EffectiveConfigRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveConfigRedactor::class)]
final class EffectiveConfigRedactorTest extends TestCase
{
    public function testItRedactsSensitiveValuesRecursivelyAndReportsStablePaths(): void
    {
        $result = (new EffectiveConfigRedactor())->redact([
            'Api-Key' => 'top-secret',
            'nested' => ['privateKey' => 'pem', 'safe_token_budget' => 12],
            'dsn' => 'postgresql://alice:password@db.example/app',
            'items' => [['walletSigner' => 'key'], ['public_endpoint' => 'https://example.test']],
        ]);

        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['Api-Key']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['nested']['privateKey']);
        self::assertSame(12, $result->document['nested']['safe_token_budget']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['dsn']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['items'][0]['walletSigner']);
        self::assertSame('https://example.test', $result->document['items'][1]['public_endpoint']);
        self::assertSame(['Api-Key', 'dsn', 'items.0.walletSigner', 'nested.privateKey'], $result->redactedPaths);
    }

    public function testItRecognizesNormalizedVariantsWithoutRedactingBenignWords(): void
    {
        $result = (new EffectiveConfigRedactor())->redact([
            'exchangeAPISecret' => 'secret',
            'access.token' => 'token',
            'passwords' => ['one'],
            'condition_catalog_hash' => 'sha256:' . str_repeat('a', 64),
            'token_budget' => 100,
            'uri' => 'redis://cache.example/0',
        ]);

        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['exchangeAPISecret']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['access.token']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $result->document['passwords']);
        self::assertSame('sha256:' . str_repeat('a', 64), $result->document['condition_catalog_hash']);
        self::assertSame(100, $result->document['token_budget']);
        self::assertSame('redis://cache.example/0', $result->document['uri']);
    }
}
