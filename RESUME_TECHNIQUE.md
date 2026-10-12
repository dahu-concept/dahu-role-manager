# Dahu Role Manager — résumé technique

Version 3.0.0. Licence MIT. Hugo Vial-Jaime — Dahu-Concept.

Ce document est la note de travail du développeur : structure du code, choix
d'implémentation, pièges WordPress rencontrés, et traçabilité du portage.

---

## 1. Structure

```
dahu-role-manager.php          constantes, i18n, requires, écran Extensions
includes/
  discovery.php                découverte des menus et noeuds de barre d'admin
  hiding.php                   application du masquage + page d'atterrissage
  order-lock.php               verrou des commandes « Terminée » (3 niveaux)
  editable-accounts.php        périmètre des comptes gérables + capacités
  csv.php                      export / import CSV
  roles.php                    duplication, renommage, suppression + notices
  settings.php                 écran de réglages et enregistrement
uninstall.php                  suppression des options, JAMAIS des rôles
```

57 fonctions, toutes préfixées `dahu_role_manager_`. Aucune classe : le plugin
est un ensemble de hooks, et l'indirection d'un conteneur n'apporterait rien ici.

Deux options seulement :

| Option | Contenu |
|---|---|
| `dahu_role_manager_settings` | tous les réglages, indexés par slug de rôle |

---

## 2. Les pièges WordPress qui ont coûté du temps

### 2.1 Les sous-menus « porteurs » (`hiding.php`)

Certaines pages partagent le même `$_GET['page']` qu'un autre sous-menu et n'en
diffèrent que par des arguments supplémentaires. WooCommerce : « Accueil » a le
slug `wc-admin`, « Clients » et « Statistiques » ont des slugs
`wc-admin&path=/...`. À l'ouverture, WordPress voit `page=wc-admin` pour toutes.

Retirer l'entrée `wc-admin` avec `remove_submenu_page()` et
`get_admin_page_parent()` ne retrouve plus le parent, le hook calculé n'est plus
enregistré, et **toutes** ces pages répondent « Sorry, you are not allowed to
access this page ».

D'où la détection d'un sous-menu dont d'autres slugs dépendent : son entrée est
masquée **en CSS** au lieu d'être retirée. Et comme WordPress fait pointer le
lien du menu parent vers son **premier** sous-menu, l'entrée masquée est déplacée
en fin de liste — sinon le lien parent mènerait à une page cachée.

Le sélecteur CSS est scopé à `.wp-submenu` : le lien parent de premier niveau
porte le même `href` que son premier sous-menu, donc sans ce scope, masquer
« Accueil » masquait tout le menu WooCommerce.

### 2.2 `sanitize_key()` sur un slug de rôle : ne jamais le faire

`sanitize_key()` met en minuscules et supprime des caractères. Un rôle créé par
un éditeur de rôles tiers avec des majuscules dans son slug ne correspondait
alors plus à aucune clé réelle de `wp_roles()`. Conséquences observées :

- les réglages étaient enregistrés sous une clé fantôme,
- la page retombait silencieusement sur le rôle par défaut, rendant le rôle
  impossible à configurer,
- les lignes de ce rôle étaient perdues à l'import CSV,
- le périmètre de comptes éditables était systématiquement rejeté.

**La validation, c'est la comparaison stricte (`===`) avec les rôles réellement
enregistrés.** Une valeur qui correspond exactement à un rôle existant non exclu
est sûre par définition. Ce choix est commenté à chacun des quatre endroits
concernés, parce que le réflexe « sanitize tout ce qui vient du POST » conduit
droit à la régression.

### 2.3 Capacité « méta » vs « primitive » (`editable-accounts.php`)

Les capacités méta `delete_user` / `promote_user` (singulier) sont traduites par
le core en capacités primitives `delete_users` / `promote_users` (pluriel),
ensuite recherchées **directement** dans `WP_User::$allcaps`, sans repasser par
`map_meta_cap`. L'octroi via `map_meta_cap` (renvoyer `[]`) ne suffit donc que
pour les capacités demandées par leur nom primitif.

Pour que la suppression et le changement de rôle aboutissent réellement, la
primitive doit exister dans `allcaps` : c'est le rôle du filtre `user_has_cap`.
La restriction au périmètre reste assurée séparément, puisque `map_meta_cap`
ajoute `do_not_allow` pour un compte hors périmètre et que `do_not_allow` n'est
jamais présent dans `allcaps`.

### 2.4 `shop_manager` doit être borné aussi

WooCommerce ne borne que `edit_user` / `remove_user` / `promote_user`, **pas**
`delete_user`. Comme la primitive `delete_users` est accordée dès qu'un périmètre
est configuré, sans bornage explicite un gestionnaire de boutique pourrait
supprimer n'importe quel compte, administrateurs compris. Le `map_meta_cap`
maison couvre donc aussi `shop_manager` — redondant pour edit/promote, décisif
pour delete.

### 2.5 Le verrou des commandes : retirer le callback, pas filtrer les champs

`order-lock.php`, niveau 1 : le callback de sauvegarde du panneau
(`WC_Meta_Box_Order_Data::save`) est **retiré**, au lieu de neutraliser les clés
de `$_POST` une par une. Deux raisons :

1. un seul point de code couvre tous les champs du panneau, y compris ceux que
   WooCommerce ajoutera plus tard ;
2. surtout, `WC_Meta_Box_Order_Data::save()` interprète certaines clés absentes
   comme « vider la valeur » — filtrer le POST effacerait des données en croyant
   les protéger.

La priorité du callback natif est **récupérée à l'exécution** via `has_action()`,
jamais écrite en dur. Elle vaut 40 dans les versions actuelles, mais si
WooCommerce la change, un `remove_action()` codé sur 40 échouerait en silence et
le verrou deviendrait inopérant sans que rien ne le signale.

Trois niveaux volontairement redondants : le niveau 1 ne couvre que l'écran
d'édition, le niveau 2 (`woocommerce_before_order_object_save`) couvre tous les
autres chemins — actions rapides, actions groupées, HPOS comme legacy — et le
niveau 3 n'est que du confort visuel.

Le CSS du niveau 3 est restreint aux contrôles et aux crayons, **pas** appliqué
au panneau entier : `pointer-events: none` sur le conteneur rendrait aussi son
texte non sélectionnable.

### 2.6 `options.php` écrasait les sections absentes du POST

L'enregistrement passe par `admin-post.php` avec son propre nonce, et non par
`register_setting()` / `options.php` avec un groupe partagé : `options.php` traite
**tous** les réglages du groupe soumis et remet à `null` tout réglage absent du
POST. Les sections qui n'étaient pas à l'écran étaient silencieusement vidées.

---

## 3. Le rôle Administrateur

Jamais **restreint** : menus, sous-menus, page de démarrage et comptes éditables
restent forcés à vide pour lui, même via requête forgée — à l'enregistrement
comme à l'import CSV. La raison est concrète : masquer un menu à l'administrateur
pourrait lui cacher la page de réglages elle-même et le laisser enfermé.

Les sections qui ne retirent aucun accès lui sont **ouvertes** : barre d'admin
(toujours récupérable depuis le menu latéral, jamais touché pour lui), masquage
CSS, et verrou des commandes terminées — un verrou volontaire qu'il peut lever
depuis une page qu'il atteint toujours. L'écran l'avertit explicitement quand il
coche le verrou pour son propre rôle.

Le garde-fou est doublé : UI, handler d'enregistrement, et import CSV.

---

## 4. Désinstallation

`uninstall.php` supprime les options et les transients de notice. **Les rôles ne
sont jamais supprimés.** Un rôle créé via ce plugin est un rôle WordPress
ordinaire dès qu'il existe : des utilisateurs le portent, d'autres extensions lui
accordent des capacités. Le supprimer à la désinstallation retirerait leur rôle à
ces comptes et changerait silencieusement qui peut quoi.

Même raisonnement pour les capacités accordées via
`dahu_role_manager_extra_role_caps` : laissées en place.

Nettoyage multisite inclus, sur chaque site du réseau.

---

## 5. Portage depuis la version Annad

Ce plugin public dérive de `dahu-roles-interface` (version interne Annad, 3514
lignes, 83 fonctions). Le tableau des correspondances de slugs et d'identifiants
est maintenu dans `CORRESPONDANCE-ANNAD.md`, à la racine de `dahu-public/`.

### 5.1 Correspondance

| Version Annad | Version publique |
|---|---|
| `dahu-roles-interface` | `dahu-role-manager` |
| `DAHU_ROLES_*` | `DAHU_ROLE_MANAGER_*` |
| `dahu_roles_*` (fonctions) | `dahu_role_manager_*` |
| `dahu_roles_settings` | `dahu_role_manager_settings` |
| text domain `dahu-roles` | `dahu-role-manager` |
| identifiants DOM/CSS `dahu-roles-*` | `dahu-role-manager-*` |

Les références historiques à la version Annad ne sont jamais renommées dans la
documentation — c'est ce qui permet de relier les deux bases.

### 5.2 Sections retirées (751 lignes)

| Section | Raison |
|---|---|
| Migration de l'option legacy `dahu_roles_annad_settings` | spécifique à l'historique du site Annad ; aucun site public ne porte cette option |
| Numéro de commande copiable | fonctionnalité de confort liée au flux de travail Annad |
| Contenu personnalisé de l'e-mail de commande terminée | relève d'une extension d'e-mails, pas d'un gestionnaire de rôles |

Chaque référence pendante a été retirée chirurgicalement dans l'export CSV,
l'import CSV, le nettoyage à la suppression d'un rôle, l'enregistrement des
réglages par rôle, et le dispatch des onglets. Vérification : aucune fonction
appelée non définie, 57 définies / 57 référencées.

### 5.3 Dé-spécialisation

| Point | Avant | Après |
|---|---|---|
| Capacités Flamingo (Contact Form 7) accordées en dur au rôle `editor` | liste codée dans `discovery.php` | filtre `dahu_role_manager_extra_role_caps`, vide par défaut |
| Rôle `total_admin` dans la liste d'exclusion des comptes éditables | rôle custom propre à Annad | filtre `dahu_role_manager_excluded_editable_roles` ; défaut `administrator`, `shop_manager` |
| Bouton « Aide » WooCommerce masqué par `aria-label="Aide"` / `"Help"` | libellés français et anglais en dur, et lecture du text domain `woocommerce` | retiré ; le CSS de masquage de la barre passe par le filtre `dahu_role_manager_hidden_adminbar_css` |
| Mise en évidence des notes de commande manuscrites | module dupliqué de `dahu-notes`, 20 regex françaises en dur | **retiré** : doublon de `dahu-order-notes`, qui reconstruit ses motifs à partir des chaînes traduisibles de WooCommerce. Les deux filtraient `woocommerce_order_note_class` et se seraient disputé l'écran. |
| Commentaire renvoyant à `dahu-sav` | nom d'extension interne | formulation générique |
| Exemple de libellé de rôle « Professionnel Bronze » | nomenclature Annad | « Warehouse staff » |
| `error_log()` inconditionnel | écrivait en production | conditionné à `WP_DEBUG_LOG` |

### 5.4 Internationalisation

Aucune chaîne n'était internationalisée dans la version Annad. 45 chaînes PHP et
39 fragments HTML français ont été traduits en anglais et enveloppés dans
`__()` / `esc_html_e()` / `_n()`, avec commentaires `translators:` sur chaque
chaîne à substitution. Les chaînes du JavaScript inline passent par
`wp_json_encode()` depuis PHP plutôt que d'être écrites dans le script.

381 lignes de commentaires français ont été réécrites en anglais. Les numéros de
version internes (v1.7.2, v2.5.4...) ont été retirés du code : ils renvoyaient à
un historique que le plugin public ne porte pas. Le raisonnement qu'ils
accompagnaient a été conservé — c'est lui qui a de la valeur.

### 5.5 Restructuration

`order-lock.php` (612 lignes) mélangeait trois sujets. Découpé :

- le verrou des commandes reste dans `order-lock.php` ;
- le masquage CSS par sélecteur rejoint `hiding.php` ;
- la couche « comptes éditables » et capacités devient `editable-accounts.php`.

Vérification du découpage : 19 fonctions source, 19 retrouvées, aucune perdue,
aucune dupliquée.

### 5.6 Méthode de vérification

Chaque fichier traduit a été comparé à son état d'origine par le **tokenizer
PHP** : les appels de fonctions et les variables sont dénombrés avant et après,
et tout écart doit être justifié. Une première version de ce contrôle, à base
d'expressions régulières, se désynchronisait sur les apostrophes des commentaires
français (`l'import`) et sur `'php://output'` — d'où le passage au tokenizer, qui
ne peut pas se tromper sur les frontières de chaînes.

---

## 6. Retrouver le comportement Annad depuis un snippet

Les deux spécialisations retirées se redéclarent côté boutique. À mettre dans un
snippet ou le `functions.php` du thème enfant, **sur annad.fr uniquement** :

```php
// Capacités Flamingo (Contact Form 7) pour le rôle Editeur.
add_filter(
    'dahu_role_manager_extra_role_caps',
    static function ( $caps ) {
        $caps['editor'] = array(
            'flamingo_manage_inbound',
            'flamingo_edit_inbound',
            'flamingo_delete_inbound',
        );
        return $caps;
    }
);

// Le rôle total_admin n'est jamais gérable par un autre rôle.
add_filter(
    'dahu_role_manager_excluded_editable_roles',
    static function ( $roles ) {
        $roles[] = 'total_admin';
        return $roles;
    }
);
```

Et, si le bouton « Aide » de l'en-tête WooCommerce doit être masqué quand la
barre d'admin est cachée — comportement de la version Annad, retiré parce qu'il
dépendait du libellé traduit et donc de la langue du site :

```php
add_filter(
    'dahu_role_manager_hidden_adminbar_css',
    static function ( $rules ) {
        $rules['.woocommerce-layout__header .woocommerce-layout__header-meta-icon[aria-label="Aide"]']
            = 'display:none !important;';
        return $rules;
    }
);
```

---

## 7. Points à vérifier sur une vraie installation

Ce qui n'a pas pu être testé sans navigateur ni base de données, et qui mérite un
coup d'œil avant publication :

1. **Effet de bord de `dahu_role_manager_get_adminbar_map()`.** Pour lister les
   noeuds disponibles, la fonction instancie un `WP_Admin_Bar` jetable et appelle
   `initialize()`. Or `initialize()` a de vrais effets : elle accroche
   `wp_admin_bar_header` à `wp_head` et `admin_head`, met en file les ressources
   `admin-bar`, et déclenche `admin_bar_init`. Sur la page de réglages, ces
   effets se produisent donc une seconde fois. Le comportement est celui qui
   tourne sur annad.fr aujourd'hui, donc rien de nouveau, mais si une extension
   tierce réagit mal à un second `admin_bar_init`, c'est là qu'il faut regarder.
   Une alternative serait de ne pas appeler `initialize()` du tout — à tester,
   certains callbacks de core lisent `$wp_admin_bar->user`.

2. **Le sélecteur CSS `:has()`** masque le `<li>` entier des sous-menus
   « porteurs ». Non supporté par les navigateurs anciens, d'où la seconde règle
   qui masque au moins le lien. À regarder sur un Safari ou un Firefox d'une
   version précédente si le rendu compte.

3. **Le verrou de commande face à HPOS**, et face aux actions groupées de la
   liste des commandes. Les deux chemins sont couverts par le niveau 2 et testés
   au banc, mais sur des objets simulés.

4. **La traduction française.** Le plugin est désormais en anglais, sans fichier
   `.po`/`.mo`. Pour l'utiliser en français sur annad.fr il faut générer le
   catalogue (`wp i18n make-pot . languages/dahu-role-manager.pot`, puis
   traduire). Tant que le catalogue n'existe pas, l'interface est en anglais.
