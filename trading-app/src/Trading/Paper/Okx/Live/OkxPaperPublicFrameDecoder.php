<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final readonly class OkxPaperPublicFrameDecoder
{
    private const MAX_CONNECTION_ID_LENGTH = 64;
    private const MAX_REQUEST_ID_LENGTH = 32;

    public function __construct(private OkxPaperPublicSubscriptionSet $subscriptions)
    {
    }

    /** @return array<string, mixed> */
    public function decodePublic(#[\SensitiveParameter] string $frame): array
    {
        return $this->decodeForSocket($frame, false);
    }

    /** @return array<string, mixed> */
    public function decodeBusiness(#[\SensitiveParameter] string $frame): array
    {
        return $this->decodeForSocket($frame, true);
    }

    /** @return array<string, mixed> */
    private function decodeForSocket(#[\SensitiveParameter] string $frame, bool $business): array
    {
        if ($frame === '' || strlen($frame) > OkxPaperLivePolicy::MAX_FRAME_BYTES) {
            throw self::invalidMessage();
        }

        if ($frame === 'pong') {
            return ['event' => 'pong'];
        }

        try {
            $decoded = json_decode($frame, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalidMessage();
        }

        if (!$decoded instanceof \stdClass) {
            throw self::invalidMessage();
        }

        $message = get_object_vars($decoded);
        if (array_key_exists('event', $message)) {
            return $this->decodeControl($message, $business);
        }

        return $this->decodeData($message, $business);
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function decodeControl(#[\SensitiveParameter] array $message, bool $business): array
    {
        $event = $message['event'] ?? null;
        if ($event === 'error') {
            if (!self::hasRequiredAndOnlyKeys(
                $message,
                ['event', 'code', 'msg', 'connId'],
                ['id', 'arg'],
            ) || !is_string($message['code']) || !is_string($message['msg'])) {
                throw self::invalidMessage();
            }

            self::assertControlMetadata($message);
            if (array_key_exists('arg', $message)) {
                $arg = $message['arg'];
                if (!$arg instanceof \stdClass) {
                    throw self::invalidMessage();
                }
                $this->assertRequiredArgument(get_object_vars($arg), $business);
            }

            throw new OkxPaperLiveIntegrityException('okx_paper_public_protocol_error');
        }

        if ($event !== 'subscribe' || !self::hasRequiredAndOnlyKeys(
            $message,
            ['event', 'arg', 'connId'],
            ['id'],
        )) {
            throw self::invalidMessage();
        }

        self::assertControlMetadata($message);
        $arg = $message['arg'];
        if (!$arg instanceof \stdClass) {
            throw self::invalidMessage();
        }

        $normalizedArgument = $this->assertRequiredArgument(get_object_vars($arg), $business);

        return ['event' => 'subscribe', 'arg' => $normalizedArgument];
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function decodeData(#[\SensitiveParameter] array $message, bool $business): array
    {
        $arg = $message['arg'] ?? null;
        $data = $message['data'] ?? null;
        if (!$arg instanceof \stdClass || !is_array($data) || !array_is_list($data)) {
            throw self::invalidMessage();
        }

        $normalizedArgument = $this->assertRequiredArgument(get_object_vars($arg), $business);
        $channel = $normalizedArgument['channel'];
        if ($channel === 'books') {
            if (!self::hasExactKeys($message, ['arg', 'action', 'data'])
                || !in_array($message['action'], ['snapshot', 'update'], true)) {
                throw self::invalidMessage();
            }
        } elseif (!self::hasExactKeys($message, ['arg', 'data'])) {
            throw self::invalidMessage();
        }

        return self::normalizeMessage($message);
    }

    /**
     * @param array<array-key, mixed> $arg
     *
     * @return array{channel: string, instId: string}
     */
    private function assertRequiredArgument(#[\SensitiveParameter] array $arg, bool $business): array
    {
        if (!self::hasExactKeys($arg, ['channel', 'instId'])) {
            throw self::invalidMessage();
        }

        $channel = $arg['channel'];
        $instrumentId = $arg['instId'];
        if (!is_string($channel) || !is_string($instrumentId)) {
            throw self::invalidMessage();
        }

        $isRequired = $business
            ? $this->subscriptions->isBusinessRequired($channel, $instrumentId)
            : $this->subscriptions->isPublicRequired($channel, $instrumentId);
        if (!$isRequired) {
            throw self::invalidMessage();
        }

        return ['channel' => $channel, 'instId' => $instrumentId];
    }

    /** @param array<array-key, mixed> $message */
    private static function assertControlMetadata(#[\SensitiveParameter] array $message): void
    {
        $connectionId = $message['connId'];
        if (!is_string($connectionId)
            || preg_match('/\A[A-Za-z0-9]{1,'.self::MAX_CONNECTION_ID_LENGTH.'}\z/', $connectionId) !== 1) {
            throw self::invalidMessage();
        }

        if (!array_key_exists('id', $message)) {
            return;
        }

        $requestId = $message['id'];
        if (!is_string($requestId)
            || preg_match('/\A[A-Za-z0-9]{1,'.self::MAX_REQUEST_ID_LENGTH.'}\z/', $requestId) !== 1) {
            throw self::invalidMessage();
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $requiredKeys
     * @param list<string>            $optionalKeys
     */
    private static function hasRequiredAndOnlyKeys(
        #[\SensitiveParameter] array $value,
        array $requiredKeys,
        array $optionalKeys,
    ): bool
    {
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }

        $allowedKeys = array_fill_keys([...$requiredKeys, ...$optionalKeys], true);
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !isset($allowedKeys[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function hasExactKeys(#[\SensitiveParameter] array $value, array $keys): bool
    {
        if (count($value) !== count($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function invalidMessage(): OkxPaperLiveIntegrityException
    {
        return new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function normalizeMessage(#[\SensitiveParameter] array $message): array
    {
        foreach ($message as $key => $value) {
            $message[$key] = self::normalizeValue($value);
        }

        return $message;
    }

    private static function normalizeValue(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::normalizeMessage(get_object_vars($value));
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalizeValue($item);
            }
        }

        return $value;
    }
}
