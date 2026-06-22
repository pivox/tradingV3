<?php

declare(strict_types=1);

namespace App\Trading\Service;

/**
 * OBS-003 — Identifiant de corrélation canonique run d'orchestration ↔ trades.
 *
 * Un run d'orchestration porte un `run_id` d'origine (jusqu'à 255 caractères côté
 * orchestrateur, éventuellement haché et préfixé `run_`). Les trades sont rattachés via
 * `trade_lifecycle_event.run_id`, un VARCHAR(64). Pour relier les deux SANS collision
 * (deux identifiants longs partageant les mêmes 64 premiers caractères ne doivent pas se
 * confondre), on dérive un identifiant de corrélation canonique déterministe, IDENTIQUE
 * à l'implémentation Python (`app/services/correlation.py`).
 *
 * Règle (cf. `tests/fixtures/run_correlation_vectors.json`, partagé Python ↔ PHP) :
 *  1. chaîne vide refusée ({@see \InvalidArgumentException}) — un run a toujours un id ;
 *  2. si le `run_id` respecte `^[A-Za-z0-9._:-]+$` ET `len <= 64` : conservé tel quel ;
 *  3. sinon : `sha256(run_id)` en hexadécimal minuscule (exactement 64 caractères).
 *
 * Interdits : aucune troncature silencieuse (`substr($id, 0, 64)`), aucun algorithme
 * divergent entre Python et PHP.
 */
final class RunCorrelationId
{
    /** Largeur de `trade_lifecycle_event.run_id` (== `position_trade_analysis.run_id`). */
    public const MAX_LENGTH = 64;

    /** Caractères « sûrs » d'un identifiant conservé tel quel (miroir exact du Python). */
    private const SAFE_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    /**
     * Dérive l'identifiant de corrélation canonique (≤ 64 caractères) d'un run.
     *
     * Déterministe et sans collision : deux `run_id` distincts produisent toujours deux
     * identifiants distincts (les longs/non-sûrs passent par sha256, jamais par une
     * troncature). Une valeur entourée d'espaces est d'abord *trim*.
     *
     * @throws \InvalidArgumentException si le `run_id` est vide (après trim).
     */
    public static function canonical(string $runId): string
    {
        $runId = trim($runId);
        if ($runId === '') {
            throw new \InvalidArgumentException('run_id must be a non-empty string');
        }

        if (mb_strlen($runId) <= self::MAX_LENGTH && preg_match(self::SAFE_PATTERN, $runId) === 1) {
            return $runId;
        }

        return hash('sha256', $runId);
    }

    /**
     * Variante tolérante : renvoie `null` au lieu de lever pour une valeur vide/nulle.
     *
     * Utilisée sur le chemin de propagation (CLI / appel direct sans en-tête) où
     * l'absence de `run_id` est légitime et doit retomber sur le comportement
     * historique (UUID généré en aval), sans erreur.
     */
    public static function canonicalOrNull(?string $runId): ?string
    {
        if ($runId === null || trim($runId) === '') {
            return null;
        }

        return self::canonical($runId);
    }
}
