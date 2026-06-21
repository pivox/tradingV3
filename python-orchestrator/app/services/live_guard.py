"""Couche **unique** de garde-fous live de l'orchestrateur (SAFE-003).

Avant SAFE-003, la politique « ce set peut-il s'exécuter en live ? » était
éparpillée et dupliquée entre ``app/schemas.py`` (persistance) et
``app/routers/orchestrator.py`` (runner). Ce module centralise toute la décision
en **un seul point fail-closed** :

- bannissements **permanents** OKX/Hyperliquid (jamais relâchés, même
  interrupteur activé) ;
- interrupteur d'activation explicite ``ORCHESTRATION_LIVE_ENABLED`` (défaut
  OFF) + allow-list ``ORCHESTRATION_LIVE_EXCHANGES`` (défaut vide), portés par
  ``app/settings.py`` ;
- prérequis runtime (snapshot d'état ouvert) laissés au runner, mais dont le
  message canonique est défini ici pour rester stable dans l'historique.

``schemas.assert_set_persistable``, ``schemas.assert_live_allowed`` et le runner
``_dispatch_set`` **délèguent** ici : il n'existe plus de logique live dupliquée.
Politique de référence : ``docs/handbook/technical/exchange-schedule-policy.md``.

Invariant fondamental : **fail-closed**. Tout doute ⇒ pas de live. Aucune valeur
par défaut n'active le live silencieusement (interrupteur OFF + allow-list vide).
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import TYPE_CHECKING, Optional

if TYPE_CHECKING:  # pragma: no cover - import de typage seul (évite tout cycle)
    from app.settings import Settings


# Exchanges dont le live est **interdit en permanence** (garde jamais relâchée,
# même interrupteur activé + exchange allow-listé). Stockés en chaînes normalisées
# (minuscules) : une ligne ORM écrite hors API avec ``OKX`` ou `` hyperliquid ``
# doit fail-closer comme ``okx``/``hyperliquid``. Source **unique** : ``schemas``
# en dérive son ``LIVE_FORBIDDEN_EXCHANGES`` (enums) et ``assert_live_allowed`` le
# consomme via ``is_permanently_forbidden``.
PERMANENT_LIVE_FORBIDDEN_EXCHANGES = frozenset({"okx", "hyperliquid"})


# Codes de décision stables (``LiveDecision.code``) — réutilisés pour l'historique
# ``RunSet.error`` / ``last_json`` et les assertions de test. Distincts par cause
# pour une histoire de run exploitable.
LIVE_OK = "live_ok"  # set live autorisé (interrupteur ON + allow-list + exchange OK)
DRY_RUN = "dry_run"  # pas une requête live (dry-run) : autorisé trivialement
LIVE_FORBIDDEN_EXCHANGE = "live_forbidden_exchange"  # OKX/Hyperliquid (permanent)
LIVE_NOT_ENABLED = "live_not_enabled"  # interrupteur global OFF
LIVE_EXCHANGE_NOT_ALLOWLISTED = "live_exchange_not_allowlisted"  # hors allow-list
# Prérequis runtime appliqué côté runner (le snapshot n'existe pas à la
# persistance) ; le message canonique vit ici pour rester stable.
OPEN_STATE_UNAVAILABLE = "open_state_unavailable"

# Message canonique du skip runtime « snapshot indisponible » (réutilisé par le
# runner). Conservé tel quel pour la compatibilité de l'historique.
OPEN_STATE_UNAVAILABLE_REASON = (
    "open_state_snapshot unavailable: live set skipped (fail-closed)"
)


@dataclass(frozen=True)
class LiveDecision:
    """Décision **unique** « ce set peut-il s'exécuter en live ? ».

    - ``allowed`` : True si l'exécution demandée est permise (dry-run trivialement
      autorisé ; live autorisé seulement si toute la politique passe) ;
    - ``reason`` : message stable explicitant un refus (``None`` si autorisé), versé
      tel quel dans ``RunSet.error`` / ``last_json`` ;
    - ``code`` : code machine stable de la cause (voir constantes ci-dessus).
    """

    allowed: bool
    reason: Optional[str]
    code: str


def _normalize_exchange(exchange: object) -> str:
    """Normalise un exchange (enum ou chaîne) en minuscules sans espaces.

    Une ligne ORM écrite hors API peut porter une casse/des espaces non
    normalisés (``OKX``, `` bitmart ``) : on aligne sur la forme canonique pour
    que les comparaisons de politique soient fail-closed.
    """
    raw = getattr(exchange, "value", exchange)
    return raw.strip().lower() if isinstance(raw, str) else str(raw)


def is_permanently_forbidden(exchange: object) -> bool:
    """Indique si l'exchange est interdit live **en permanence** (OKX/Hyperliquid).

    Source unique consommée par ``schemas.assert_live_allowed`` (OrchestratorSet en
    mémoire) ET par ``assess_live`` (persistance + runner) : aucune duplication de
    la liste des exchanges bannis.
    """
    return _normalize_exchange(exchange) in PERMANENT_LIVE_FORBIDDEN_EXCHANGES


def assess_live(
    *,
    exchange: object,
    market_type: object = None,
    environment: object = None,
    dry_run: bool,
    settings: "Settings",
) -> LiveDecision:
    """Décision **unique** et fail-closed d'autorisation live d'un set.

    Hiérarchie des décisions (l'ordre est sécuritaire et ne doit pas changer) :

    1. ``dry_run`` (état EFFECTIF, override run-level déjà appliqué par l'appelant)
       ⇒ pas de live ⇒ autorisé (``DRY_RUN``).
    2. Exchange **permanently forbidden** (OKX/Hyperliquid) ⇒ refusé, **même
       interrupteur ON + exchange allow-listé** (``LIVE_FORBIDDEN_EXCHANGE``).
       Vérifié AVANT l'interrupteur pour ne jamais être relâché.
    3. Interrupteur global OFF (``ORCHESTRATION_LIVE_ENABLED=false``, défaut)
       ⇒ refusé (``LIVE_NOT_ENABLED``) : comportement identique à aujourd'hui.
    4. Exchange hors allow-list (``ORCHESTRATION_LIVE_EXCHANGES``, défaut vide)
       ⇒ refusé (``LIVE_EXCHANGE_NOT_ALLOWLISTED``).
    5. Sinon ⇒ autorisé (``LIVE_OK``).

    Le prérequis runtime « snapshot d'état ouvert présent » N'est PAS évalué ici
    (le snapshot n'existe pas à la persistance) : le runner l'applique en plus,
    APRÈS une décision ``allowed=True``, via ``OPEN_STATE_UNAVAILABLE_REASON``.

    ``market_type`` et ``environment`` sont acceptés (signature cible figée) mais
    non encore branchés dans la politique : l'activation est portée par
    l'interrupteur + l'allow-list (décision produit SAFE-003). Ils restent
    disponibles pour un futur gate par environnement sans changer la signature.
    """
    # 1. Dry-run : aucune exécution live, autorisé trivialement (l'override
    #    run-level `{"dry_run": true}` est déjà reflété dans `dry_run` par l'appelant).
    if dry_run is not False:
        return LiveDecision(allowed=True, reason=None, code=DRY_RUN)

    raw_exchange = getattr(exchange, "value", exchange)
    normalized = _normalize_exchange(exchange)

    # 2. Bannissement permanent (OKX/Hyperliquid) : prééminent sur l'interrupteur.
    #    Jamais relâché, même interrupteur ON + exchange dans l'allow-list.
    if normalized in PERMANENT_LIVE_FORBIDDEN_EXCHANGES:
        return LiveDecision(
            allowed=False,
            reason=f"live forbidden for exchange '{raw_exchange}': set skipped (fail-closed)",
            code=LIVE_FORBIDDEN_EXCHANGE,
        )

    # 3. Interrupteur global : OFF par défaut ⇒ tout set live skippé (fail-closed),
    #    message conservé/compatibilisé avec la phase pré-SAFE-003.
    if not settings.live_enabled:
        return LiveDecision(
            allowed=False,
            reason="live execution not yet enabled: live set skipped (fail-closed)",
            code=LIVE_NOT_ENABLED,
        )

    # 4. Allow-list : même interrupteur ON, seuls les exchanges explicitement listés
    #    (au plus `bitmart`, + `fake` en simulation) peuvent passer live.
    if normalized not in settings.live_exchanges:
        return LiveDecision(
            allowed=False,
            reason=f"live not allow-listed for exchange '{raw_exchange}': set skipped (fail-closed)",
            code=LIVE_EXCHANGE_NOT_ALLOWLISTED,
        )

    # 5. Toutes les gardes de politique passent : live autorisé (le runner exige
    #    encore le snapshot d'état ouvert avant de dispatcher réellement).
    return LiveDecision(allowed=True, reason=None, code=LIVE_OK)
