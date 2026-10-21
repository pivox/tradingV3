# Procédure de lecture du verdict fwd-okx-r1 (lignée Q3 long)

**Statut : procédure préparée le 2026-10-21, NON déclenchée.** Aucune lecture automatique n'est
programmée. Ce texte a été écrit sans lire aucune donnée ni aucun résultat de fwd-okx-r1 : il reprend
seulement le runbook publié (`docs/handbook/runbooks/research-forward-okx.md`) et le rapport publié
(`docs/handbook/reports/research-forward-okx-2026-10.md`). Le pré-enregistrement figé du 2026-10-13
fait foi en cas d'écart.

## 1. Quand

- Pas avant le **2026-10-31 00:02 UTC**. W2 couvre le 2026-10-26 → 30, et le dernier jour est
  finalisé 90 s après minuit.
- Seulement sur un **feu vert écrit du propriétaire**, daté.
- Jusque-là, personne ne lit `screen-validation.json`, `verdict-validation.json`, `screen-holdout.json`,
  les journaux de `evaluate.sh` ni les fichiers de bande, même si la boucle d'évaluation a déjà jugé W1.

## 2. Vérifications avant lecture

Ces contrôles portent sur des métadonnées, jamais sur des rendements.

1. **Pré-enregistrement intact.** Le sha256 de `PREREGISTRATION-forward-okx-r1.md` est égal à celui
   du fichier `.sha256` voisin.
2. **Code intact.** Le commit du worktree `.worktrees/research-forward-okx` est celui du gel. Aucune
   modification locale n'est présente (`git status` propre).
3. **Fin des boucles.** `systemctl --user status fwd-okx-capture fwd-okx-evaluate` montre que
   l'évaluation s'est arrêtée après W2, comme le prévoit le runbook.
4. **Jours finaux.** Dans `tape/manifest-forward.json`, les cinq jours de W1 et de W2 des vingt paires
   sont `partial: false`. Les secondes manquantes et les reprises (`resumed_rows`) sont relevées sans
   rien corriger.
5. **Fichiers attendus.** Les sorties de `screen` et de `verdict` existent pour W1 et W2, avec leur
   date d'écriture.

Si un jour reste partiel, la fenêtre n'est pas jugeable selon le runbook. On le consigne. On ne relance
rien et on ne complète aucune donnée. Le propriétaire décide de la suite.

## 3. Lecture

Une seule lecture, dans cet ordre, sans relancer aucun calcul :

1. Verdict de W1 (validation), puis verdict de W2 (holdout).
2. Les cinq contrôles de chaque fenêtre :
   - au moins 100 trades ;
   - net moyen > 0 ;
   - profit factor ≥ 1,05 ;
   - net moyen au-dessus de la médiane du contrôle apparié au régime ;
   - au moins 50 % des vingt paires positives.
3. La sensibilité aux frais × 1,5 est rapportée, non décisive.

**Règle.** Le candidat passe seulement si W1 **et** W2 passent. Moins de 100 trades est un échec de
contrôle, pas un succès. Aucune fenêtre, aucun seuil, aucune paire ne peut être modifié après la
lecture.

## 4. Inscription

- Ajouter au registre des campagnes la ligne fwd-okx-r1 avec :
  - la date et l'heure de lecture ;
  - les sha256 des fichiers de verdict ;
  - le résultat par contrôle.
- Marquer W1 et W2 `consumed`. Ces fenêtres ne seront jamais recyclées.
- Mettre à jour la section Résultats de `research-forward-okx-2026-10.md`.

## 5. Interprétation, fixée d'avance

- **Les deux fenêtres passent.** C'est une étape nécessaire, pas une validation de stratégie. Le
  produit testé, OKX USDT-SWAP, n'est pas accessible depuis un compte français. Le transfert vers les
  X-Perps reste **NON PROUVÉ**, car le carnet, le funding et la liste de paires diffèrent. Toute suite
  exige un nouveau protocole gelé avant lecture.
- **Une fenêtre échoue.** La lignée Q3 long est close. Aucune variante n'est rouverte sur ces données.
- **Fenêtre non jugeable.** On consigne le motif. On ne relance rien, sauf décision écrite du
  propriétaire.

## 6. Après la lecture

- Arrêter proprement les services avec `systemctl --user stop fwd-okx-capture fwd-okx-evaluate`, s'ils
  tournent encore. Conserver le worktree et la bande.
- Le prérequis **P-G5-2** de G5, le verdict fwd-okx-r1 rendu, est alors levé. La récupération scellée
  du funding de G5 (**P-G5-3**) doit avoir lieu le jour même du verdict, et seulement sur décision
  datée du propriétaire.
