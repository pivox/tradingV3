# Annexe B (regime-r2) — table `symbol → asset_id`, figée avant toute lecture de prix

Méthode : (1) inventaire des fichiers mensuels `1d` par symbole (data.binance.vision, noms de fichiers
seulement) : 9 symboles ont au moins un mois sans fichier ; (2) réutilisations et migrations connues
de l'auteur, sans consultation de prix. Toute identité non certaine est exclue sur la période
concernée, sans consulter sa performance. Par défaut, asset_id = symbole sur toute sa durée ; les
interruptions de cotation > 7 jours (détectées sur les bougies journalières elles-mêmes, sans
regarder les prix) découpent seulement des segments du même actif (§3).

| Symbole | Période | asset_id | Statut | Motif / source |
|---|---|---|---|---|
| LUNAUSDT | début → 2022-05-31 | LUNA_TERRA_CLASSIC | inclus | Terra d'origine (effondrement de mai 2022 conservé) ; Binance, annonces de mai 2022 |
| LUNAUSDT | 2022-06-01 → fin | — | **exclu** | date exacte de bascule vers Terra 2.0 et renommage en LUNC non certifiée sans lecture de prix |
| LUNCUSDT | toute la durée | LUNC | inclus | Terra Classic après renommage (série propre, nouvelle) |
| USDSUSDT | 2020-06 → 2026-04 (trou) | — | exclu (A1) | symbole réutilisé (Stably USD puis Sky USDS) ; stablecoin de toute façon |
| VENUSDT | début → 2018-07-31 | VEN | inclus | VeChain avant migration vers VET |
| VENUSDT | 2018-08-01 → fin | — | **exclu** | reprise après trou, identité incertaine (migration VEN → VET) |
| FTTUSDT | toute la durée | FTT | inclus, 2 segments | même actif ; trou 2022-11 → 2023-09 (faillite FTX) = nouveau segment |
| CVCUSDT | toute la durée | CVC | inclus, 2 segments | même actif ; trou 2022-12 → 2023-05 = nouveau segment |
| SUNUSDT | toute la durée | — | **exclu** | redénomination/échange de jeton (2021) non datée de façon certaine |
| COCOSUSDT | toute la durée | — | **exclu** | redénomination (2021) non datée de façon certaine |
| DREPUSDT | toute la durée | — | **exclu** | redénomination/échange (2021) non datée de façon certaine |
| BCCUSDT, BCHABCUSDT, BCHUSDT, BCHSVUSDT | chacun sa durée | symbole | inclus | renommages successifs de Bitcoin Cash et scission BSV : actifs distincts par symbole, sans raccord |
| BCHUPUSDT, BCHDOWNUSDT, TUSDUSDT, USDCUSDT, USDPUSDT | — | — | exclus (A1/A4) | trous sans objet : déjà exclus |

Les autres symboles (ex. MATICUSDT → POLUSDT, RNDRUSDT → RENDERUSDT, FTMUSDT → SUSDT, ERDUSDT →
EGLDUSDT, NPXSUSDT → PUNDIXUSDT, BTTUSDT → BTTCUSDT) changent de **symbole** lors d'une migration :
chaque symbole reste un actif distinct, sans raccord de séries, ce qui n'introduit aucun biais de
survivance (l'ancien symbole reste dans les archives jusqu'à sa date de retrait).
