# Vue Fonctionnelle

Le coeur fonctionnel suit un cycle simple: choisir des symboles, evaluer un contexte multi-timeframe, decider si un setup est exploitable, puis construire et executer un plan d'ordre.

## Cycle principal

1. Un run est declenche par `POST /api/mtf/run`, `bin/console mtf:run` ou Temporal.
2. Le runner choisit les symboles actifs et retire ceux deja occupes par une position, un ordre ou un lock.
3. Le validateur MTF recupere les indicateurs et applique les regles du profil.
4. Si le resultat est tradable, une decision est envoyee vers TradeEntry.
5. TradeEntry calcule prix, zone, risque, levier, stop-loss et take-profit.
6. Les ordres et protections sont persistes et surveilles par les workers.

## Profils

| Profil | Usage |
| --- | --- |
| `regular` | Runs moins frequents, validation plus large. |
| `scalper` | Execution rapide, entry zone et risque adaptes au court terme. |
| `scalper_micro` | Profil minute, sensible aux locks, zones et filtres fins. |
| `crash` | Profil defensif ou opportuniste selon configuration YAML. |

## Etats fonctionnels attendus

| Etat | Signification |
| --- | --- |
| `READY` | Le symbole peut etre transmis a TradeEntry. |
| `NO_LONG_NO_SHORT` | Aucun cote ne passe les validations. |
| `LONG_AND_SHORT` | Les deux cotes passent, situation ambigue a bloquer. |
| `INVALID` | Donnees, contexte ou contraintes insuffisants. |
| `skipped_out_of_zone` | La zone d'entree n'est plus executable au prix courant. |
