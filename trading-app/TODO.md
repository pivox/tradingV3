# TODO

- Synchroniser les clés/secret dans l'environnement (BITMART_API_KEY, BITMART_SECRET_KEY, BITMART_API_MEMO).
- Lancer `composer dump-autoload` puis `vendor/bin/phpunit` pour valider tout le flux localement.
- Ajouter des tests d'intégration autour de `MtfValidatorInterface::run` (descente complète jusqu'au 1m + déclenchement trading).
- Persister dans `trade_zone_events` (ou audit équivalent) la valeur de `volume_ratio` lorsqu'un rejet `volume_ratio_ok` survient afin d'analyser les seuils à ajuster.
