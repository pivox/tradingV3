<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

/**
 * Optionnel — non utilisé dans la logique actuelle.
 *
 * Contexte:
 * - Le module Execution utilise `Execution\TpSlAttacher::presetInSubmitPayload()` pour
 *   pré-attacher TP/SL au moment de la création de l'ordre (mode "preset").
 * - Par conséquent, aucun attachement séparé n'est nécessaire et cette classe n'est
 *   pas appelée par `Service\TradeEntryService`.
 *
 * Utilité future (mode non-preset):
 * - Si l'exchange ne supporte pas les presets, ou si l'on souhaite attacher TP/SL
 *   après le fill, implémenter ici une orchestration dédiée (création d'ordres TP/SL,
 *   éventuellement OCO, après vérification de l'état de l'ordre principal).
 * - Cette implémentation dépendra du `OrderProviderInterface` (API exchange) et
 *   d'un mécanisme d'attente/observation du fill (worker, event, ou boucle de polling).
 *
 * Remarque: laisser cette classe vide en attendant un besoin concret.
 */
final class AttachTpSl
{
}
