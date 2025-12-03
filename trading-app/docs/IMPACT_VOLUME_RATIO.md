# Ajustement volume_ratio_ok et zone max deviation

- 2025-12-02: Seuil par défaut `volume_ratio_ok` abaissé de 1.4 à 0.9 dans `VolumeRatioOkCondition`. Objectif : laisser passer davantage de contextes à faible volume tout en surveillant la qualité des signaux.
- Mode `scalper_micro`: `entry.entry_zone.max_deviation_pct` élargi de 0.02 à 0.035 pour compenser les rejets out-of-zone quand le volume est faible.
- À surveiller lors des prochains runs :
  - Nombre de rejets `volume_ratio_ok` (logs MTF).
  - Nouvelles entrées `trade_zone_events` (skipped_out_of_zone) liées à l'élargissement de la zone.
  - PnL/qualité des signaux, en particulier sur les symboles les plus touchés (HYPEUSDT, XMRUSDT, ZECUSDT, BTC/ETH/BNB).
