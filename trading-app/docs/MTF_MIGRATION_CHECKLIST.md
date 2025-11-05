# Checklist de migration MTF (Contrats 2024)

Cette checklist permet à l'équipe de valider la bascule vers le nouveau `MtfValidatorInterface`.

## 1. Contrats & dépendances
- [ ] Remplacer les injections de `MtfRunService` par `MtfValidatorInterface` côté CLI, contrôleurs et workers
- [ ] Propager les nouveaux champs `skip_context`, `lock_per_symbol`, `user_id`, `ip_address` dans les appels contractuels
- [ ] Vérifier que les intégrations tierces (Temporal, API REST) consomment `MtfRunRequestDto` / `MtfRunResponseDto`
- [ ] Mettre à jour les alias/arguments dans `services.yaml` pour garantir l'autowiring façade → orchestrateur → décision

## 2. Tests
- [ ] Exécuter `php bin/phpunit --testsuite=unit` pour valider les nouveaux tests DTO/stratégie/pipeline
- [ ] Exécuter `php bin/phpunit --testsuite=integration --filter=MtfValidatorAutowiring` pour vérifier l'autowiring complet
- [ ] Ajouter des cas de non-régression dans les suites métiers (Temporal / API) en utilisant la nouvelle réponse contractuelle

## 3. Monitoring & observabilité
- [ ] Créer/adapter les dashboards Kibana/Grafana pour les champs `decision_key`, `user_id`, `ip_address`
- [ ] Vérifier la présence des logs `order_journey.*` et `positions_flow` pour chaque run MTF
- [ ] Mettre à jour les alertes (PagerDuty, OpsGenie…) avec les nouveaux statuts (`global_switch_off`, `lock_acquisition_failed`, `no_active_symbols`)
- [ ] S'assurer que la désactivation auto des symboles (switch 15 minutes) est surveillée côté BDD

## 4. Opérations
- [ ] Documenter les nouveaux flags CLI (`--lock-per-symbol`, `--user-id`, `--ip-address`)
- [ ] Former l'équipe support sur le nouveau découpage Application / Infrastructure et les journaux associés
- [ ] Planifier un rollback (variables d'environnement, feature switches) en cas de régression
