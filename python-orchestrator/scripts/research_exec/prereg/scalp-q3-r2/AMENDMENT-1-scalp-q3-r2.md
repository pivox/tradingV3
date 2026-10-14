# Amendement 1 — scalp-q3-r2 (2026-10-14, avant tout résultat)

Écrit et haché **avant** l'exécution de la préparation complète et avant tout calcul de portefeuille
(aucun résultat de performance de scalp-q3-r2 n'existe ; seul un essai de durée sur une série,
BTCUSDT février, a été lancé : 28 s, métadonnées de comptage lues, aucun PnL lu).

1. **Graines du contrôle à décalage d'épisodes au niveau portefeuille : 1 000 au lieu de 200**
   (PREREGISTRATION §7). Raison purement technique : l'implémentation calcule le résultat d'ordre de
   chaque seconde de chaque série une seule fois (table complète, ≈ 20 s par série), de sorte que
   chaque graine de contrôle n'est plus qu'une relecture de cette table ; la contrainte de calcul qui
   justifiait 200 n'existe plus. Résolution de p au niveau portefeuille : 1/1 001. Rien d'autre ne
   change (critères, seuils, variantes, données).
2. Précision d'exécution, sans effet sur les critères : le seuil de 1 000 graines du niveau signal et
   les 1 000 graines du niveau portefeuille utilisent les mêmes tirages d'épisodes (graine k identique
   aux deux niveaux).
3. Erratum de forme : l'en-tête du pré-enregistrement indique « ≈ 12:15Z » ; le gel a eu lieu à
   12:05:40Z (fichier `.sha256`). Aucun effet.
