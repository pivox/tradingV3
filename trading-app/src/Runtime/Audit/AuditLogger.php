<?php

declare(strict_types=1);

namespace App\Runtime\Audit;

use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\Dto\AuditEventDto;
use App\Runtime\Audit\Dto\AuditContextDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Service d'audit pour tracer les actions importantes
 * Enregistre les événements critiques pour la traçabilité
 */
#[AsAlias(id: AuditLoggerInterface::class)]
class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

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
    ): void {
        $auditData = [
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'data' => $data
        ];

        $this->logger->info("Action d'audit", $auditData);
    }

    /**
     * Enregistre une création d'entité
     */
    public function logCreate(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void
    {
        $this->logAction('CREATE', $entity, $entityId, $data, $userId);
    }

    /**
     * Enregistre une mise à jour d'entité
     */
    public function logUpdate(string $entity, mixed $entityId, array $oldData, array $newData, ?string $userId = null): void
    {
        $this->logAction('UPDATE', $entity, $entityId, [
            'old_data' => $oldData,
            'new_data' => $newData
        ], $userId);
    }

    /**
     * Enregistre une suppression d'entité
     */
    public function logDelete(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void
    {
        $this->logAction('DELETE', $entity, $entityId, $data, $userId);
    }

    /**
     * Enregistre une lecture d'entité sensible
     */
    public function logRead(string $entity, mixed $entityId, array $data = [], ?string $userId = null): void
    {
        $this->logAction('READ', $entity, $entityId, $data, $userId);
    }

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
    ): void {
        $this->logAction($action, 'TRADING', $orderId, [
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $price
        ], $userId);
    }

    /**
     * Enregistre une erreur critique
     */
    public function logError(string $error, array $context = [], ?string $userId = null): void
    {
        $this->logAction('ERROR', 'SYSTEM', null, [
            'error' => $error,
            'context' => $context
        ], $userId);
    }

    /**
     * Enregistre un accès utilisateur
     */
    public function logUserAccess(string $action, ?string $userId = null, ?string $ipAddress = null): void
    {
        $this->logAction($action, 'USER_ACCESS', $userId, [], $userId, $ipAddress);
    }

    /**
     * Enregistre une modification de configuration
     */
    public function logConfigChange(string $configKey, mixed $oldValue, mixed $newValue, ?string $userId = null): void
    {
        $this->logAction('CONFIG_CHANGE', 'CONFIGURATION', $configKey, [
            'old_value' => $oldValue,
            'new_value' => $newValue
        ], $userId);
    }

    /**
     * Enregistre une action de sécurité
     */
    public function logSecurityEvent(string $event, array $data = [], ?string $userId = null): void
    {
        $this->logAction('SECURITY', 'SECURITY_EVENT', null, [
            'event' => $event,
            'data' => $data
        ], $userId);
    }

    /**
     * Récupère les logs d'audit pour une entité
     */
    public function getAuditLogs(string $entity, mixed $entityId, int $limit = 100): array
    {
        // Cette méthode devrait être implémentée avec un repository
        // pour récupérer les logs depuis la base de données
        return [];
    }

    /**
     * Récupère les statistiques d'audit
     */
    public function getAuditStats(): array
    {
        // Cette méthode devrait être implémentée avec un repository
        // pour calculer les statistiques depuis la base de données
        return [
            'total_actions' => 0,
            'actions_by_type' => [],
            'actions_by_entity' => [],
            'recent_actions' => []
        ];
    }
}
