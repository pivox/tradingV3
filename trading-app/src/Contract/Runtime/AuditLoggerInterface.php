<?php

declare(strict_types=1);

namespace App\Contract\Runtime;

use App\Contract\Runtime\Dto\AuditEventDto;

/**
 * Interface pour le logging d'audit
 * Inspiré de Symfony Contracts pour la traçabilité
 */
interface AuditLoggerInterface
{
    /**
     * Enregistre une action d'audit
     */
    public function logAction(
        string $action,
        string $entity,
        mixed $entityId,
        array $data = [],
        ?string $userId = null,
        ?string $ipAddress = null
    ): void;

    /**
     * Enregistre une création d'entité
     */
    public function logCreate(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void;

    /**
     * Enregistre une mise à jour d'entité
     */
    public function logUpdate(string $entity, mixed $entityId, array $oldData, array $newData, ?string $userId = null): void;

    /**
     * Enregistre une suppression d'entité
     */
    public function logDelete(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void;

    /**
     * Enregistre une lecture d'entité sensible
     */
    public function logRead(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void;

    /**
     * Enregistre une action de trading
     */
    public function logTradingAction(
        string $action,
        string $symbol,
        float $quantity,
        float $price,
        ?string $orderId = null,
        ?string $userId = null
    ): void;

    /**
     * Enregistre une erreur critique
     */
    public function logError(string $error, array $context = [], ?string $userId = null): void;

    /**
     * Enregistre un accès utilisateur
     */
    public function logUserAccess(string $action, ?string $userId = null, ?string $ipAddress = null): void;

    /**
     * Enregistre une modification de configuration
     */
    public function logConfigChange(string $configKey, mixed $oldValue, mixed $newValue, ?string $userId = null): void;

    /**
     * Enregistre une action de sécurité
     */
    public function logSecurityEvent(string $event, array $data = [], ?string $userId = null): void;

    /**
     * Récupère les logs d'audit pour une entité
     */
    public function getAuditLogs(string $entity, mixed $entityId, int $limit = 100): array;

    /**
     * Récupère les statistiques d'audit
     */
    public function getAuditStats(): array;
}
