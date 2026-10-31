# Dahu Role Manager

Façonne l'administration de WordPress pour chaque rôle : quels menus et quels
éléments de la barre d'administration il voit, quels comptes il peut modifier,
où il arrive en se connectant. Duplique, renomme et supprime des rôles, et
déplace toute la configuration en CSV.

Tout ce que l'écran de réglages propose de masquer est découvert à l'exécution
sur l'installation réelle : quelles que soient les extensions d'un site, elles
apparaissent d'elles-mêmes dans la liste.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## Ce qu'il fait, rôle par rôle

| | |
|---|---|
| Menus et sous-menus | Masquer ce dont un rôle n'a pas l'usage |
| Barre d'administration | Masquer des éléments, ou la barre entière |
| Déconnexion | Ajouter une entrée de déconnexion au bas du menu |
| Page d'arrivée | Envoyer le rôle ailleurs que sur le tableau de bord |
| Masquage CSS | Masquer des éléments par sélecteur, pour les notices tierces |
| Comptes modifiables | Dire quels rôles de compte ce rôle peut gérer |
| Verrou de commande | Rendre une commande WooCommerce terminée non modifiable |

Plus un onglet Rôles qui duplique les capacités d'un rôle en bloc, renomme le
nom affiché d'un rôle, et supprime un rôle personnalisé qu'aucun utilisateur ne
porte — et un export/import CSV de toute la configuration.

## Masquer n'est pas interdire

Masquer un menu enlève de l'encombrement ; ça n'arrête personne qui connaît
l'URL. Si un rôle ne doit pas atteindre une page, retirez la capacité qui la
lui donne. Cette extension ne cherche pas à remplacer les capacités, et le dit à
l'écran plutôt que de laisser croire à une frontière de sécurité qu'elle
n'offre pas.

Les deux choses qu'elle fait réellement respecter — le périmètre des comptes
modifiables et le verrou des commandes terminées — sont vérifiées côté serveur.

## Prérequis

- WordPress 6.0 ou plus
- PHP 7.4 ou plus
- WooCommerce est facultatif : sans lui, tout fonctionne à l'identique sauf le
  verrou de commande et le volet WooCommerce du périmètre des comptes

## Installation

Copiez le dossier dans `wp-content/plugins/`, activez, et ouvrez
**Réglages → Gestion des rôles**.

## Points d'extension

| Hook | Type | Ce qu'il fait |
|---|---|---|
| `dahu_role_manager_extra_role_caps` | filtre | Capacités à ajouter, par slug de rôle. Vide par défaut ; n'ajoute jamais que. |
| `dahu_role_manager_excluded_editable_roles` | filtre | Rôles qu'aucun autre rôle ne peut gérer. `administrator` et `shop_manager` par défaut. |
| `dahu_role_manager_roles_barred_from_managing_accounts` | filtre | Rôles à qui on ne peut jamais donner un périmètre de comptes. |
| `dahu_role_manager_hidden_adminbar_css` | filtre | Le CSS appliqué quand la barre d'administration est masquée entièrement, sous la forme sélecteur => déclarations. |

## Désinstallation

Retire les réglages de cette extension et rien d'autre. **Les rôles ne sont
jamais supprimés** — des utilisateurs les portent et d'autres extensions peuvent
leur accorder des capacités, donc les retirer à la désinstallation changerait en
silence qui peut quoi. Supprimer un rôle reste un acte délibéré, fait depuis
l'onglet Rôles.

## Licence

MIT. Voir [LICENSE](LICENSE).

Hugo Vial-Jaime — Dahu-Concept
