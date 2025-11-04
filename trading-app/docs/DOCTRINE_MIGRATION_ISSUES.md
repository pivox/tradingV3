# Problèmes de Migration Doctrine - Explications

## 3. Valeurs par défaut des séquences

### Problème

Dans PostgreSQL, quand on utilise `BIGSERIAL` ou `SERIAL`, PostgreSQL crée automatiquement :
1. Une séquence (ex: `futures_order_id_seq`)
2. Une valeur par défaut sur la colonne `id` : `DEFAULT nextval('futures_order_id_seq')`

Cependant, Doctrine ORM **ne gère pas** les valeurs par défaut des séquences de cette manière. Doctrine utilise `#[ORM\GeneratedValue]` qui indique que la valeur sera générée automatiquement, mais il ne définit pas explicitement de `DEFAULT` dans la base de données.

### Pourquoi la différence ?

- **Migration manuelle** : Utilise `BIGSERIAL` qui crée automatiquement `DEFAULT nextval(...)`
- **Doctrine Entity** : Utilise `#[ORM\GeneratedValue]` sans `DEFAULT` explicite dans les options

### Solution appliquée

La migration générée par Doctrine supprime les valeurs par défaut (`ALTER id DROP DEFAULT`) car :
- Doctrine gère la génération des IDs au niveau ORM (pas au niveau SQL)
- Les valeurs par défaut SQL sont redondantes avec `#[ORM\GeneratedValue]`
- Cela évite les conflits entre la génération SQL et ORM

### Impact

**Aucun impact fonctionnel** : Les IDs continuent d'être générés automatiquement, mais via Doctrine ORM au lieu de PostgreSQL directement. C'est la méthode recommandée par Doctrine.

---

## Résumé des corrections appliquées

1. ✅ **Type postgres_timestamp** : Les entités utilisent déjà le bon type, la migration convertit TIMESTAMPTZ → TIMESTAMP
2. ✅ **Index uniques partiels** : Supprimé `unique: true` des colonnes, utilise uniquement les `UniqueConstraint` au niveau classe
3. ✅ **Valeurs par défaut JSONB** : Ajouté `'default' => '{}'` dans les options des colonnes JSONB
4. ✅ **Valeurs par défaut timestamps** : Migration convertit `now()` → `CURRENT_TIMESTAMP`
5. ✅ **Tables manquantes** : Migration crée `order_plan` et `order_lifecycle` avec `IF NOT EXISTS`
6. ✅ **Index renommés** : Doctrine renomme automatiquement selon ses conventions (normal)
7. ✅ **Contraintes FK** : Ajoutées avec vérification d'existence pour éviter les erreurs

