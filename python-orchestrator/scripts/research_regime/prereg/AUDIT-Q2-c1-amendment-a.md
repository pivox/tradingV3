# regime-r2 — amendement a de la correction c1 (avant tout rejeu c1)

Rédigé le 2026-10-14 après l'écriture des tests de c1 et AVANT tout rejeu c1 (aucun résultat c1
n'existe). Un test synthétique (long seul, prix −99,99 % en 40 jours, sans frais) montre que la règle
c1 §1 (« notionnel = équité de t−1 / 14 ») n'est pas autofinancée : les tranches déjà ouvertes gardent
le notionnel fixé quand l'équité était plus haute, l'exposition brute peut dépasser l'équité et
l'équité devient négative (−5,4 %) sans aucun levier déclaré.

Amendement (remplace c1 §1, première phrase) : le notionnel de la tranche du jour t est
`min(équité(t−1) / 14, max(0, équité(t−1) − exposition brute(t−1)))`, où l'exposition brute(t−1) est
la valeur, au cours de clôture de t−1, de toutes les positions encore ouvertes (|valeur| pour les
shorts). L'exposition brute ne peut donc pas dépasser l'équité au moment d'ouvrir une tranche. Tout
le reste de c1 est inchangé.
