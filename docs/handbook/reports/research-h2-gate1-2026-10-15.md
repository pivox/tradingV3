# H2 Gate 1 (étude d'effet, Amendement A1) : exécution interrompue par le garde-fou disque, pas de verdict

**Statut au 2026-10-15T16:24Z : pas de verdict.** L'étape `events` a été arrêtée par le garde-fou disque du protocole
avant d'écrire la moindre sortie. L'étape `outcomes` n'a pas commencé. Aucun événement, aucun z-score, aucun
résultat, aucun critère G1–G7 n'a été calculé ni lu. Le protocole autorise **une seule** reprise de `events` ; elle
n'a pas été utilisée.

**Étiquettes (protocole §2) :** développement ; archive OKX déjà lue au niveau bougies (test swing v2 OKX, PR #500,
pour les familles hors programme ; bases du programme lues sur Binance) ; **aucun résultat de Gate 1 ne peut compter
comme une validation indépendante**. Même si l'exécution allait à son terme, un PASS n'autoriserait qu'une
**proposition** de Gate 2, jamais du trading.

## 1. Références figées

| Élément | Valeur |
|---|---|
| Protocole | `docs/handbook/research/PREREGISTRATION-H2-GATE1.md`, `FROZEN 2026-10-15T15:57:04Z` (avec l'Amendement A1) |
| sha256 du corps | `0ec71c563a6247464765fd0696ceb193d60d980e97d93d6f53d0565940d8bf20` |
| Empreinte du code (`research_h2` + `research_swing2/okx.py` + `okx_universe.py`) | `e8c087a1a1ee105c81d2f9f06d79b966f1db52a43ffd293860e1a8f6c8c41904` |
| Environnement | `tradingV3-private/research/20261014T090000Z-swing-v2-runtime/bin/python`, Python 3.12.3 (main, Aug 31 2026, 10:18:26) [GCC 13.3.0], numpy 2.5.4 |
| Décisions enregistrées dans la claim | `g4_rule=point_estimate`, `c2_caliper=none`, `c2_candidates=first_crossing`, `c2_pool=three_months` |
| Feu vert du propriétaire (texte enregistré) | « owner go 2026-10-15: execute the H2 Gate 1 effect study once, exactly as frozen with Amendment A1 (FROZEN 2026-10-15T15:57:04Z); report the verdict even if negative; a PASS only authorises a Gate 2 proposal, never trading » |

Entrées vérifiées par la claim (égales aux constantes de `protocol.py`) : `manifest-h2-okx-candles.json`
`95794fdd…6f0d00` (1 016 zips H2, chacun conforme au manifeste), `manifest-candles.json` `516e7183…9c5d01`,
`daily-quote-volume.json` `7c262b44…beedd`, `eea-futures.json` `732044a8…c44f8d`, 9 665 fichiers npz swing v2
(empreinte de la liste `b99f42f4…acc22`).

## 2. Ce qui s'est passé (chaque chiffre vient d'un fichier haché)

Répertoire de sortie : `tradingV3-private/research/20261015T1200Z-h2-gate1/run/`.

| Heure (UTC) | Événement | Source |
|---|---|---|
| 15:57:04 | Gel du protocole (A1) | ligne `FROZEN` |
| 16:02:02 | Claim `events` écrite | `events-claim.json`, sha256 `7c612faab328a8c1d5c3f2881446639b45d554d8c2821b368074406da6f1c2cd` |
| ≈ 16:02:15 | Démarrage du processus `cli events` (`nice -n 15`, un processus) | 14:17.53 écoulés, fin à 16:16:33 |
| 16:03:55 | `consume()` : re-vérification du protocole, du code, de l'environnement et des entrées, puis création exclusive de l'enregistrement de consommation | `events-consumed.json` (`started_at`) |
| 16:16:33 | Arrêt par le garde-fou : `guard: available memory 8306 MB, free disk 18 GB`, code de sortie 3 | `events-consumed.json`, sha256 `fa028b43c7801ac80dcda08f66fb1de65933838586223d3e48f28b65302fd642` ; journal `events.log` sha256 `de2f69d0…290947` |

Enregistrement de consommation : `status: aborted_by_guard`, `aborted_at 2026-10-15T16:16:33Z`, aucune reprise
utilisée (0 sur `MAX_GUARD_RESUMPTIONS["events"] = 1`).

Mesures du processus (`/usr/bin/time`) : 733,84 s CPU utilisateur, 520 856 kB de mémoire résidente maximale,
24 blocs écrits au total. La mémoire n'était pas en cause (8 306 MB disponibles à l'arrêt, plancher 4 000 MB).

**Pourquoi rien n'a été vu.** Le garde-fou n'est appelé que dans le chargement des données (`universe.gather` et la
boucle de `events.load_closes`), avant `events.compute`. Aucun β, résidu, z-score, événement, appariement C2 ou tirage
C1 n'a été calculé. Le répertoire `run/` n'a pas été modifié après 16:03:55Z (horodatage du répertoire égal à la
création de l'enregistrement) : `h2-events.json`, `h2-outcomes.json` et `h2-gate1-result.json` n'existent pas.

**Le disque.** Le plancher du protocole est 20 Go, calculé comme `shutil.disk_usage().free // 1024**3`, donc
20 GiB = 20 480 MiB (≈ 21,5 Go décimaux). L'exécution elle-même n'a écrit que 24 blocs ; la baisse d'espace libre
vient d'un autre processus de la machine, non identifié (le contrôle des plus gros écrivains ne montrait que des
processus de bureau). Rien n'a été supprimé. Relevés de l'opérateur, non conservés dans un fichier (donc non
vérifiables par l'audit) : ≈ 51 Go libres au départ, 18 Go à l'arrêt, 17,5 Go à 16:17:08Z, 16,6 Go à 16:20:24Z.
Relevés pendant l'audit : 16 835 MiB vers 16:21Z, 18 875 MiB à 16:22:21Z. Relevé à l'écriture de ce rapport
(16:24:09Z) : 37,3 Go libres, soit 34 GiB selon le calcul du garde-fou. **L'espace libre fluctue fortement** ;
la reprise unique n'a pas été lancée.

## 3. Critère par critère

Le protocole (§8) exige que chaque critère soit rapporté avec sa valeur, et que le rapport dise si un FAIL est dû au
seul G4. Ici, aucun critère n'a de valeur.

| # | Critère (figé) | Valeur | Résultat | Échec dû au seul G4 ? |
|---|---|---|---|---|
| G1 | ≥ 200 événements, ≥ 15 bases, ≥ 24 mois ; sinon `INSUFFICIENT_EVIDENCE` | non calculée | — | sans objet |
| Taux d'appariement C2 | ≥ 80 % ; sinon `STATISTICAL_BLOCKER` | non calculé | — | sans objet |
| G2 | moyenne y > 0 et borne basse de l'IC > 0 | non calculée | — | sans objet |
| G3 | moyenne y ≥ 40 pb (avant funding) | non calculée | — | sans objet |
| G4 | S2 > 0 (estimation ponctuelle ; IC publié, non décisif) | non calculée | — | sans objet |
| G5 | S1 > 0 et borne basse de l'IC > 0 | non calculée | — | sans objet |
| G6 | moyenne y > 0 dans ≥ 5 des semestres à ≥ 10 événements | non calculée | — | sans objet |
| G7 | moyenne y > 0 sans les 5 % d'événements les plus forts, et sans la base de plus grande somme de y | non calculée | — | sans objet |
| Verdict | ordre G1 → match C2 → G2–G7 | **aucun** | — | — |

Côtés long et court (G2–G7 séparés) : non calculés.

## 4. Analyses rapportées, jamais décisives

Horizons 1 j et 5 j, univers secondaire (160 bases X-Perp), sensibilités k = 2,5 et marché = BTC, décomposition de
C2 : **aucune n'a été calculée**. Elles restent non décisives quand l'exécution ira à son terme ; elles ne pourront en
aucun cas transformer un FAIL en PASS.

## 5. Rappel : puissance du gate figé (synthétique, pour lire correctement un futur FAIL)

P(PASS) avec le pool de trois mois (Amendement A1), moyenne sur les scénarios, sd(y) 500 / 800 / 1 200 pb
(section « Amendment A1 » du protocole) :

| Effet vrai μ | VR 1,0 | VR 0,8 | Student t3 |
|---:|---|---|---|
| 0 pb | 0,00 / 0,00 / 0,00 | 0,00 / 0,00 / 0,00 [max 0,01] | 0,00 / 0,00 / 0,00 |
| 40 pb | 0,05 / 0,02 / 0,01 | 0,04 / 0,02 / 0,01 | 0,06 / 0,01 / 0,00 |
| 80 pb | 0,39 / 0,18 / 0,07 | 0,35 / 0,15 / 0,04 | 0,45 / 0,16 / 0,06 |
| 150 pb | 0,84 / 0,74 / 0,44 | 0,82 / 0,74 / 0,41 | 0,94 / 0,86 / 0,51 |
| 300 pb | 1,00 / 1,00 / 0,98 | 1,00 / 0,99 / 0,98 | 1,00 / 1,00 / 0,99 |

P(FAIL dû au seul G4) avec ce pool : au plus 0,11 en moyenne (0,21 dans un scénario) à 150 pb, 0,00–0,01 à 300 pb.
Sous l'hypothèse nulle, S2 est biaisé vers le bas (module : −25 ± 110 pb, P(S2 > 0) = 0,41), donc contre un PASS.

Lecture : le gate est valide (un PASS est informatif, P(PASS | μ = 0) ≤ 0,01), mais peu puissant sous 150 pb. **Un
FAIL se lirait « pas d'effet assez grand pour battre le contrôle à choc partagé et les gates de robustesse », pas
« pas d'effet ».** Limites : simulateur qui réimplémente les règles, β et σ vrais, pas de trous de barres ni de
radiations, effet constant, sd(y) supposée.

## 6. Ce qui suit, selon le protocole (§12)

Aucune modification du code, des critères, des seuils ou des données n'est nécessaire ni permise.

1. **Le propriétaire libère de l'espace disque** sur `/`, nettement au-dessus de 20 GiB, et identifie si possible ce
   qui le consomme : l'espace libre est tombé d'environ 33 Go pendant les 14 minutes de l'exécution sans que
   celle-ci écrive. L'étape `outcomes` n'a **aucune** reprise : l'espace doit rester au-dessus du plancher pendant
   les deux étapes.
2. **La reprise unique** de `events`, depuis `python-orchestrator/` du worktree :
   `nice -n 15 …/20261014T090000Z-swing-v2-runtime/bin/python -m scripts.research_h2.cli events --resume`.
   Elle sera rapportée comme telle. Si le garde-fou l'arrête à nouveau, l'étape est consommée et une nouvelle
   tentative exigerait un amendement écrit.
3. Puis `cli claim --stage outcomes --expected-sha256 0ec71c56…d8bf20 --owner-go "<même référence>"` et
   `cli outcomes`, sans autre décision entre les deux : rien dans `h2-events.json` ne peut servir à décider de
   continuer.
4. Le verdict (`PASS`, `FAIL`, `INSUFFICIENT_EVIDENCE` ou `STATISTICAL_BLOCKER`) sera alors rapporté même négatif,
   critère par critère, avec la colonne « échec dû au seul G4 ».

Le même feu vert du propriétaire couvre les deux étapes (§12). Ce rapport ne décide pas s'il reste valable après
l'interruption ; c'est au propriétaire de le confirmer.

## 7. Audit indépendant

Résultat : `integrity_ok: true`, `verdict_consistent: true` (le rapport « pas de verdict » correspond à l'état
enregistré).

- Hash du corps du protocole recalculé sans importer le paquet : égal à la ligne `FROZEN` et à la claim ; copie
  privée du protocole identique octet par octet.
- Empreinte du code recalculée : égale ; `git diff a4844111..HEAD` vide sur les fichiers concernés ; la fusion
  `669d5d93` (15:59:03Z, après le gel, avant la claim) ne touche ni `python-orchestrator/scripts` ni le protocole.
- Ordre vérifié : gel 15:57:04Z < création de `run/` 16:00:40Z < claim 16:02:02Z < enregistrement de consommation
  16:03:55Z (modifié à 16:16:33Z). Aucun fichier créé ou supprimé dans `run/` ensuite.
- `claim.consume()` accepterait exactement une `events --resume` (statut `aborted_by_guard`, pas de sortie,
  0 reprise sur 1).
- Non vérifiable depuis les fichiers : les relevés de disque et de mémoire pendant l'exécution, le niveau `nice`,
  et le fait que le feu vert vienne bien du propriétaire (seul son texte est enregistré).

## 8. Écarts et réserves

- **Écart de formulation (mineur) :** le rapport d'exécution donnait « démarrage à 16:03:55Z » ; c'est l'heure
  `started_at` de l'enregistrement, écrite après la re-vérification des entrées (≈ 100 s). Le processus a démarré
  vers 16:02:15Z, ce qui correspond aux 14 min 18 s mesurées.
- **Aucun écart au protocole** : pas de nouvelle exécution, pas de modification du code, des critères ou des
  données, pas de reprise.
- Le fichier de synthèse prescrit par le protocole (`h2-gate1-result.json`) n'existe pas. À sa place, l'état de
  l'exécution est consigné dans
  [`H2-GATE1-RUN-STATUS.json`](../research/H2-GATE1-RUN-STATUS.json) (hashs de la claim, de l'enregistrement de
  consommation et du journal ; tous les critères à `null`). Ce n'est pas un résultat.
