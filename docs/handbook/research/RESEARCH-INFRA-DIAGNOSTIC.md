# Diagnostic infrastructure : chute du disque et swap plein pendant H2 Gate 1 (2026-10-15)

> **Version publique résumée.** Le dépôt est public. Ce document ne contient ni les noms des fichiers personnels, ni
> l'inventaire du répertoire personnel, ni la liste des applications personnelles, ni l'identifiant du support USB,
> ni le contenu d'une autre session. La version détaillée, avec les chemins exacts, est conservée hors du dépôt public.
>
> Le diagnostic Linux a été fait en lecture seule, le 2026-10-15 vers 19:00Z. Rien n'a été installé, supprimé, arrêté
> ou reconfiguré. Aucun seuil de garde-fou n'a changé.
>
> Toutes les heures sont en UTC (Paris = UTC+2). `journalctl` et `sar` affichent l'heure de Paris : elles ont été
> converties.

## 1. Contexte

- **Arrêt.** La première tentative de l'étape `events` de H2 Gate 1 a démarré vers 16:02Z ; l'enregistrement de
  consommation porte 16:03:55Z. Le garde-fou l'a arrêtée à 16:16:33Z avec le motif `guard: available memory 8306 MB,
  free disk 18 GB`.
- **Seuils.** Le seuil disque est `MIN_FREE_DISK_GB = 20`, comparé à `shutil.disk_usage(...).free // 1024**3`, donc en
  Gio. Le seuil mémoire est `MIN_AVAILABLE_MB = 4000` (`python-orchestrator/scripts/research_h2/protocol.py:130-131`,
  `cli.py:42-45`). **C'est le disque qui a déclenché l'arrêt, pas la mémoire.**
- **Relevés de l'orchestrateur** (disque libre, non conservés en fichier sauf la valeur du garde-fou) : environ 51 Go
  avant 16:02Z, 18 Go à 16:16Z, 16,6 Go à 16:20Z, 37,3 Go à 16:24Z, 54 Go à 16:26Z, 73 Go vers 16:30Z, 77 Go à
  partir de 16:39Z.
- **Suite de l'exécution.** L'unique reprise autorisée (16:40:49Z → 16:56:33Z), puis l'étape `outcomes`
  (16:57:19Z → 17:11:30Z, fin de la consommation ; le processus sort à 17:11:31Z), ont tourné avec 77 Go libres
  stables (`run/monitor-resume.log`).

## 2. Cause de la chute du disque

**Cause établie : une écriture d'environ 36 Gio liée à une activité personnelle, sans rapport avec la recherche, sur
le même disque, entre environ 15:58Z et 16:18Z.** Les horodatages des fichiers concernés et le débit d'écriture
mesuré par `sar -b` (environ 38 Go écrits entre 16:00 et 16:20Z, contre environ 0,5 Mo/s le reste du temps) concordent
avec la chute observée. Le détail (fichiers, support source, outil) est conservé dans la version privée.

- **Exclu : H2.** H2 n'a écrit que 24 blocs lors de la première tentative, et 3 656 blocs (environ 1,8 Mo) lors de la
  reprise.
- **Exclue : l'autre session Claude locale active pendant l'arrêt** (nettoyage de fichiers personnels). Elle n'a fait
  aucune copie ; ses suppressions ont commencé après l'arrêt.
- **NON PROUVÉ** : l'outil et l'auteur exacts de l'écriture. **INCONNU** : environ 1,5 Go d'écritures de la même
  fenêtre.

## 3. Remontée du disque (prouvée)

La session de nettoyage a supprimé environ 60 Gio de fichiers personnels en double entre 16:22Z et 16:29Z, ce qui
explique les 77 Go libres relevés à partir de 16:39Z. Elle n'a pas causé la chute, elle l'a compensée.

## 4. Autres faits de la fenêtre, non expliqués

- Un pic transitoire de mémoire réservée (`kbcommit` 43,9 Go à 16:00:02Z, contre environ 31 Go avant et après),
  d'origine **INCONNUE**.
- Des lectures massives entre 16:00 et 16:30Z (jusqu'à environ 315 Mo/s), partagées entre l'activité personnelle,
  la session de nettoyage et la première tentative `events` de H2 (environ 6,0 Go lus). Elles ont vidé le cache de
  pages et ont pu pousser quelques pages vers le swap (§5).

## 5. Swap : explication

**Configuration.** Fichier de swap de 4 Gio, swappiness 60, 15,6 Gio de RAM.

**Le swap est plein depuis des jours, pas à cause de H2.** Historique `sar -S` :

- le 10 oct., après le démarrage (12:26Z), il monte jusqu'à environ 50 % ;
- le 11 oct., il passe de 55 % à 87 % entre 16:00 et 16:10Z, puis atteint 98 % à 21:50Z ;
- le 12 oct., il atteint 100 % à 07:00Z, puis reste entre 80 et 100 % environ ;
- le 15 oct., il est à 98 % dès 12:30Z, avant toute exécution H2.

**OOM du 12 oct.** Le noyau a tué trois processus `python` de 6,4 à 9,7 Go de RAM, lancés depuis un terminal, à
01:35Z, 01:55Z et 08:27Z. Le swap était déjà à 87–98 % environ neuf heures avant le premier OOM. Ces arrêts ne sont
donc pas la cause du remplissage initial. Les heures de démarrage des processus tués sont **INCONNUES**.

**Activité réelle pendant la période étudiée :**

- Entre 15:50 et 16:20Z, pendant les lectures massives, des pages sont parties vers le swap (`pswpout` jusqu'à
  121 pages/s, soit environ 0,5 Mo/s).
- Pendant la reprise H2, le moniteur montre le swap libre passant de 39 Mo à 0 entre 16:40 et 16:46Z, alors que
  MemAvailable restait autour de 8 Go. Le noyau a sorti quelques pages inutilisées ; ce n'était pas un manque de RAM.
- Les valeurs « Swaps: 0 » affichées par GNU time ne signifient rien sous Linux.

**Qui occupe le swap** (relevé VmSwap à 19:03Z, 4 070 Mo au total) : des applications de bureau et des serveurs de
développement inactifs, dont certains tournent depuis le 10 oct. Les cinq premiers groupes de processus en détiennent
environ 2,3 Go. Le détail par processus figure dans la version privée.

**Conclusion : le swap plein est un reste ancien, pas une pression actuelle.** Mesures de 19:02Z :

- `vmstat` : `si`/`so` = 0 sur 5 s ;
- PSI mémoire à 0,00 sur 10, 60 et 300 s ;
- MemAvailable de 8,4 Go ;
- aucun OOM ni arrêt par systemd-oomd entre 15:30 et 17:10Z.

**Conséquence : il n'y a plus de marge de swap.** Deux situations peuvent mal finir :

- une hausse soudaine de 8 Go ou plus de mémoire réellement utilisée irait directement à l'OOM noyau, comme le
  12 oct. ;
- une pression mémoire supérieure à 50 % pendant 20 s sur `user@1000.service` déclencherait un arrêt par
  systemd-oomd. Ce cgroup contient les services `fwd-okx-*`, les terminaux et les sessions Claude.

systemd-oomd ne surveille aucun cgroup sur le swap : un swap à 100 % ne déclenche donc rien à lui seul.

## 6. État actuel, risques et mesures préventives

**État au 2026-10-15 vers 19:02Z :**

- **Disque.** `/dev/sda2` fait 469 G, avec 77 G disponibles (83 % utilisés, 13 % des inodes). C'est un seul disque,
  partagé par le système, Docker, le code, les données de recherche et les fichiers personnels. Les répertoires
  personnels représentent la plus grande part de l'occupation ; le détail est dans la version privée.
- **Docker** (`docker system df`) : images 8,812 Go, toutes utilisées ; volumes 9,768 Go, dont 3,942 Go récupérables ;
  cache de build 436 Mo. `/var/lib/docker` n'est pas lisible sans sudo.
- **Fichiers supprimés encore ouverts** (`lsof +L1`) : rien de notable sur le disque.
- **Unités `fwd-okx-capture` et `fwd-okx-evaluate`** : actives. Leur consommation disque n'a pas été mesurée, car leur
  répertoire est hors périmètre : **INCONNUE**.

**Peut-on poursuivre la recherche ?** Oui, sans risque excessif pour l'intégrité scientifique : le garde-fou a joué son
rôle, s'est arrêté avant toute sortie, et la reprise unique s'est faite proprement. Le risque est opérationnel :
d'autres arrêts sont possibles, ainsi qu'une perte d'heures de capture forward. Les risques principaux sont les
suivants :

1. **Opérations personnelles lourdes en parallèle.** Une seule copie de 36 Gio a suffi à faire passer le disque sous
   le garde-fou de 20 Gio. Rien ne prévient la recherche quand une copie, une archive ou un nettoyage démarre.
2. **Archives volumineuses de fichiers personnels.** Une archive de plusieurs dizaines de Go écrite sur le disque
   principal avant la suppression des originaux ferait passer le disque libre sous le garde-fou ; une compression
   maximale occuperait en plus les 4 CPU pendant des heures.
3. **Swap saturé en permanence** (§5). Une campagne gourmande en mémoire passerait directement à l'OOM ou à un arrêt
   par oomd. Un arrêt par oomd dans `user@1000.service` peut toucher une campagne ou le test forward `fwd-okx-r1`
   (fenêtres W1 du 19 au 23 oct. et W2 du 26 au 30 oct. ; rejeu de W0 annoncé vers le 17 oct. 00:00Z selon
   l'orchestrateur).
4. **Lectures massives pendant une campagne.** Elles vident le cache. L'effet sur H2 n'a pas été mesuré : la première
   tentative a obtenu 87 % de CPU, la reprise 98 %.
5. **Disque unique, sans alerte.** Aucune alerte n'existe en amont du garde-fou de campagne. La capture forward, qui
   écrit en continu, pourrait aussi échouer si le disque se remplit. Son rythme de croissance est **INCONNU**.

**Mesures préventives proposées.** Ce sont des décisions du propriétaire ; rien n'a été fait. **Aucun des seuils
ci-dessous n'est adopté.** Ils ne s'appliqueraient jamais à un protocole gelé ni à `fwd-okx-r1`, et ne modifient aucun
garde-fou.

1. **Vérification avant chaque campagne.** Contrôler le disque libre (garde-fou + besoin estimé + marge), MemAvailable,
   le swap et la PSI. Poser une question explicite au propriétaire : « une copie, une archive ou un nettoyage personnel
   est-il en cours ou prévu ? ». La vérification de 40 Go faite par l'orchestrateur avant la reprise H2
   (`monitor-resume.log`) peut servir de modèle ; ce n'est pas un seuil de protocole.
2. **Pas d'opération personnelle lourde pendant une campagne ou une fenêtre forward.** Écrire les archives sur le
   support externe, pas sur `/`. Sinon, les faire hors campagne, après avoir vérifié la place disponible.
3. **Vider le swap.** C'est un choix du propriétaire, avec sudo, à faire quand MemAvailable dépasse nettement le swap
   utilisé, et **après le 2026-10-31** (fin de `fwd-okx-r1`, dont la capture tourne en continu depuis le 13 oct.), sauf
   choix explicite contraire. Moyens possibles : `swapoff -a && swapon -a`, ou un redémarrage planifié. On peut aussi
   fermer les applications inactives qui occupent le swap. Agrandir le fichier de swap est un changement de réglage,
   laissé au propriétaire.
4. **Libérer de la place.** C'est au propriétaire de trancher ; aucune suppression n'est recommandée à sa place, et
   aucun agent ne doit en faire. Candidats évidents : les fichiers personnels récemment copiés (s'ils sont
   sauvegardés ailleurs), les caches et sauvegardes anciennes du répertoire personnel, et les volumes Docker récupérables
   (environ 3,9 Go).
5. **Historique disque dans sysstat** (sudo, propriétaire). Activer la collecte des systèmes de fichiers et des disques
   (`SADC_OPTIONS="-S XDISK"`), pour que `sar -F` et `sar -d` retracent l'occupation et l'activité par périphérique.
   Aujourd'hui, `sar -F` répond « Requested activities not available ».

## 7. Surveillance proposée (PROPOSITION SEULEMENT, non installée)

Un timer systemd utilisateur, peu coûteux, ajoute une ligne à un journal toutes les 5 minutes. Le script lit l'état du
système et n'écrit que son propre journal, qu'il fait tourner sur 14 jours. Il ne modifie aucun seuil de garde, ne
lance pas `du` sur de grandes arborescences et ne lit aucun répertoire de recherche.

**Script `~/.local/bin/infra-watch.sh`** (esquisse) :

```bash
#!/usr/bin/env bash
# Lit df, /proc/meminfo, /proc/vmstat, la PSI et /proc/<pid>/{status,io}.
# N'écrit que son propre journal (rotation sur 14 jours).
set -u
STATE=${XDG_STATE_HOME:-$HOME/.local/state}/infra-watch; mkdir -p "$STATE"
LOG="$STATE/infra-$(date -u +%Y%m%d).log"; PREV="$STATE/prev"
now=$(date -u +%FT%TZ)
free_gib=$(df -BG --output=avail / | tail -1 | tr -dc 0-9)
memavail_mb=$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo)
swapfree_mb=$(awk '/^SwapFree:/{print int($2/1024)}' /proc/meminfo)
pswpout=$(awk '/^pswpout/{print $2}' /proc/vmstat)
psi_mem=$(awk '/^some/{print $3}' /proc/pressure/memory)   # avg60
psi_io=$(awk '/^some/{print $3}' /proc/pressure/io)
if [ -f "$PREV" ]; then read -r p_free p_swp < "$PREV"; else p_free=$free_gib; p_swp=$pswpout; fi
echo "$free_gib $pswpout" > "$PREV"
drop=$(( p_free - free_gib )); swp_rate=$(( (pswpout - p_swp) / 300 ))
level=OK
[ "$free_gib" -lt 40 ] && level=WARN
[ "$drop" -ge 5 ] && level=WARN            # >= 5 Gio perdus en 5 min
[ "$memavail_mb" -lt 6000 ] && level=WARN
[ "$swp_rate" -gt 100 ] && level=WARN      # swap actif (pas seulement un reste)
[ "$free_gib" -lt 25 ] && level=ALERT      # alerte AVANT le garde de 20 Gio, qui ne change pas
echo "$now $level free=${free_gib}G drop5m=${drop}G memavail=${memavail_mb}M swapfree=${swapfree_mb}M pswpout/s=${swp_rate} psi_mem60=${psi_mem#avg60=} psi_io60=${psi_io#avg60=}" >> "$LOG"
if [ "$level" != OK ]; then
  # 5 plus gros (RSS + swap) parmi tous les processus lisibles
  for s in /proc/[0-9]*/status; do awk -v p="${s%/status}" '/^Name:/{n=$2}/^VmRSS:/{r=$2}/^VmSwap:/{w=$2}END{if(r!="")print r+w, r, w, n, p}' "$s" 2>/dev/null; done \
    | sort -rn | head -5 | sed "s/^/$now   mem /" >> "$LOG"
  # 5 plus gros écrivains (write_bytes cumulés depuis leur démarrage) ;
  # /proc/<pid>/io n'est lisible que pour les processus de l'utilisateur courant
  for d in /proc/[0-9]*; do b=$(awk '/^write_bytes/{print $2}' "$d/io" 2>/dev/null) && [ -n "$b" ] && echo "$b $(cat "$d/comm" 2>/dev/null) ${d#/proc/}"; done \
    | sort -rn | head -5 | sed "s/^/$now   wr  /" >> "$LOG"
  command -v notify-send >/dev/null && notify-send -u $([ "$level" = ALERT ] && echo critical || echo normal) "infra-watch $level" "disque libre ${free_gib}G, baisse ${drop}G/5min, MemAvailable ${memavail_mb}M"
fi
find "$STATE" -name 'infra-*.log' -mtime +14 -delete 2>/dev/null   # rotation de ses propres journaux
```

**Unités `~/.config/systemd/user/infra-watch.{service,timer}`** (esquisse) :

```ini
# infra-watch.service
[Unit]
Description=infra-watch: relevé disque/mémoire/swap
[Service]
Type=oneshot
ExecStart=%h/.local/bin/infra-watch.sh
Nice=19
IOSchedulingClass=idle

# infra-watch.timer
[Unit]
Description=infra-watch toutes les 5 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s
[Install]
WantedBy=timers.target
```

**Seuils proposés, d'information seulement.** Ils ne sont pas adoptés et ne remplacent aucun garde-fou.

| Indicateur | WARN | ALERT |
|---|---|---|
| Disque libre `/` | < 40 Gio | < 25 Gio |
| Baisse du disque libre | ≥ 5 Gio en 5 min | — |
| MemAvailable | < 6 000 Mo | — |
| Swap actif (`pswpout`) | > 100 pages/s en moyenne sur 5 min | — |

Un swap plein sans `pswpout` correspond à un reste ancien et ne déclenche pas d'alerte. Si le processus qui a copié les
fichiers le 15 oct. tournait sous l'utilisateur courant, la ligne `wr` l'aurait **probablement** fait apparaître dans
les 5 minutes. Ce n'est pas prouvé : `write_bytes` est cumulé depuis le démarrage, ce qui favorise les processus
anciens.

**Usage possible par l'orchestrateur, s'il est adopté :** lire la dernière ligne du journal du jour avant de lancer une
campagne, et signaler toute alerte dans le rapport de la campagne. La règle de non-lancement (pas de lancement en
`ALERT`, ni après un `WARN` de baisse de moins de 15 minutes) reste à décider par le propriétaire.

## 8. Ce qui reste NON PROUVÉ ou INCONNU

- **NON PROUVÉ** : l'outil et l'auteur exacts de l'écriture personnelle de 36 Gio.
- **INCONNU** : environ 1,5 Go des écritures de la fenêtre 15:50–16:20Z.
- **INCONNU** : l'origine du pic `kbcommit` de 16:00:02Z.
- **INCONNU** : la part de chaque processus dans les lectures massives entre 16:00 et 16:30Z.
- **INCONNU** : ce qui a rempli le swap le 11 oct. Les OOM du 12 oct. sont postérieurs au remplissage.
- **INCONNU** : la croissance disque de la capture forward `fwd-okx-r1` (non mesurée, hors périmètre).
- **INCONNU** : la taille réelle de `/var/lib/docker` (lecture refusée sans sudo).
