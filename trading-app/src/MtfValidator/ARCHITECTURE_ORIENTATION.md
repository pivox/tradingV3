# Architecture MtfValidator - Orientation et Principes

## Vue d'ensemble

L'architecture MtfValidator suit les principes des **Symfony Contracts** avec une séparation stricte entre contrats publics et logique interne.

## Principes Fondamentaux

### 1. **Séparation Contrats/Implémentations**
- **Contrats publics** : `Contract/MtfValidator/` - Interfaces et DTOs pour l'API externe
- **Logique interne** : `MtfValidator/Service/` - Implémentations et DTOs internes

### 2. **DTOs à Deux Niveaux**
- **DTOs publics** : Communication avec l'extérieur (API, CLI, etc.)
- **DTOs internes** : Logique métier et traitement interne

### 3. **Auto-wiring comme Provider/**
- Utilisation d'`#[AsAlias]` et `#[Autoconfigure]`
- Priorités définies pour les services
- Configuration similaire à l'architecture Provider Bitmart

### 4. **Breaking Changes Acceptés**
- Refactorisation complète pour améliorer l'architecture
- Interface publique complètement refactorisée
- Migration guidée pour les utilisateurs existants

### 5. **Gestion des Exceptions**
- Les exceptions remontent naturellement
- Pas de gestion d'erreur dans les contrats
- Logging au niveau des services

## Structure de l'Architecture

```
Contract/MtfValidator/                    # CONTRATS PUBLICS
├── MtfValidatorInterface.php             # Interface principale
├── TimeframeProcessorInterface.php      # Interface timeframe
└── Dto/                                 # DTOs publics
    ├── MtfRunRequestDto.php
    ├── MtfRunResponseDto.php
    ├── TimeframeResultDto.php
    └── ValidationContextDto.php

MtfValidator/Service/                     # LOGIQUE INTERNE
├── MtfRunService.php                    # Implémente MtfValidatorInterface
├── Timeframe/                          # Services timeframe
│   ├── BaseTimeframeService.php         # Implémente TimeframeProcessorInterface
│   ├── Timeframe1mService.php
│   ├── Timeframe5mService.php
│   ├── Timeframe15mService.php
│   ├── Timeframe1hService.php
│   └── Timeframe4hService.php
└── Dto/                                # DTOs internes
    ├── InternalMtfRunDto.php
    ├── InternalTimeframeResultDto.php
    └── ProcessingContextDto.php
```

## Patterns d'Utilisation

### 1. **Injection de Dépendances**
```php
// Auto-wiring avec alias
#[AsAlias(id: MtfValidatorInterface::class)]
class MtfRunService implements MtfValidatorInterface

// Priorités pour les services timeframe
#[AsAlias(id: TimeframeProcessorInterface::class, priority: 1)]
class Timeframe1mService extends BaseTimeframeService
```

### 2. **Conversion DTOs**
```php
// Conversion public -> interne
$internalRequest = InternalMtfRunDto::fromContractRequest($runId, $request);

// Conversion interne -> public
return $internalResult->toContractDto();
```

### 3. **Interface Contract**
```php
// Service principal
public function run(MtfRunRequestDto $request): MtfRunResponseDto

// Processeur timeframe
public function processTimeframe(string $symbol, ValidationContextDto $context): TimeframeResultDto
```

## Avantages de cette Architecture

### 1. **Isolation des Modules**
- Les contrats définissent l'API publique
- Les implémentations peuvent changer sans affecter les clients
- Testabilité améliorée avec des interfaces mockables

### 2. **Séparation des Responsabilités**
- Contrats = interfaces publiques
- Services = logique métier
- DTOs = transfert de données

### 3. **Extensibilité**
- Ajout facile de nouveaux processeurs timeframe
- Remplacement des implémentations sans impact
- Configuration flexible via auto-wiring

### 4. **Maintenabilité**
- Code organisé par responsabilité
- Interfaces claires et documentées
- Tests unitaires complets

## Migration et Compatibilité

### Breaking Changes
- Interface `MtfRunInterface` remplacée par `MtfValidatorInterface`
- DTOs `MtfRunDto` remplacés par `MtfRunRequestDto`/`MtfRunResponseDto`
- Services timeframe refactorisés avec contrats

### Guide de Migration
1. Remplacer les injections par les nouveaux contrats
2. Adapter les DTOs selon la nouvelle structure
3. Mettre à jour les tests avec les nouvelles interfaces
4. Utiliser les exemples dans `MtfValidator/Example/`

## Exemples d'Utilisation

Voir `MtfValidator/Example/MtfValidatorUsageExample.php` pour des exemples complets d'utilisation des nouveaux contrats.

## Tests

Les tests unitaires sont disponibles dans `tests/Contract/MtfValidator/` et couvrent :
- Interfaces et contrats
- DTOs publics et internes
- Méthodes de conversion
- Gestion des statuts et erreurs
