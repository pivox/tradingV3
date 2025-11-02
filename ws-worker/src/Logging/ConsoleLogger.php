<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Simple logger qui écrit dans stdout avec formatage.
 */
final class ConsoleLogger extends AbstractLogger
{
    private const COLORS = [
        LogLevel::EMERGENCY => "\033[41m", // Rouge bg
        LogLevel::ALERT     => "\033[41m",
        LogLevel::CRITICAL  => "\033[41m",
        LogLevel::ERROR     => "\033[31m", // Rouge
        LogLevel::WARNING   => "\033[33m", // Jaune
        LogLevel::NOTICE    => "\033[36m", // Cyan
        LogLevel::INFO      => "\033[32m", // Vert
        LogLevel::DEBUG     => "\033[37m", // Blanc
    ];

    private const RESET = "\033[0m";

    public function __construct(
        private readonly string $appName = 'app',
        private readonly bool $useColors = true,
    ) {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        
        // Formater le niveau avec couleur
        if ($this->useColors && isset(self::COLORS[$level])) {
            $coloredLevel = self::COLORS[$level] . str_pad($levelUpper, 8) . self::RESET;
        } else {
            $coloredLevel = str_pad($levelUpper, 8);
        }

        // Extraire le channel du contexte si présent
        $channel = $context['channel'] ?? 'app';
        unset($context['channel']);

        // Construire le message
        $output = sprintf(
            '[%s] %s [%s.%s] %s',
            $timestamp,
            $coloredLevel,
            $this->appName,
            $channel,
            $message
        );

        // Ajouter le contexte si présent
        if (!empty($context)) {
            $output .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        echo $output . PHP_EOL;
    }
}

