# Correction du menu — glisser-déposer et sous-menus

## Diagnostic

- `assets/admin.js` attachait `setPointerCapture` à la poignée **qui était ensuite déplacée dans le DOM**. Sur les navigateurs mobiles, cela peut annuler la capture du pointeur avant la fin du geste.
- `includes/class-dendrila-simple-edit-navigation.php` n'enregistrait que l'ordre des liens racines : aucune lecture/sauvegarde de `parent`, aucune conversion de `core/page-list`, aucun état initial mobile.

## Correction

- Capture sur la liste `<ol>` stable, glisser-déposer tactile et souris, défilement près des bords, annulation, statut accessible.
- Déposer sur la zone « Sous-menu ici » assigne le parent ; sélecteur « Sous-menu de » et boutons ↑/↓, flèches clavier fonctionnent comme alternatives. Les déplacements de groupes conservent leurs enfants.
- Validation serveur stricte de la permutation, de chaque parent, de l'absence de cycle/troisième niveau et d'un numéro de version du menu (contrôle optimiste).
- `core/page-list` : réordonnancement simple par `menu_order` conserve les pages automatiques ; création de groupe convertit **seulement la navigation** en blocs explicites `core/navigation-submenu` + `core/navigation-link`, après confirmation explicite. Les nouvelles pages cessent alors de s'ajouter automatiquement. Les URL, `post_parent` et contenus des pages ne changent pas.
- `wp_navigation` déjà explicite : réordonnancement et regroupement de liens, conservant leurs URL et autres attributs. Les navigations contenant des blocs non pris en charge ne sont pas réécrites.
- Menus classiques : mise à jour des positions et du champ `menu_item_parent`, avec état initial mobile stocké dans une option propre à Simple Edit.
- La préférence « Ouvert / Fermé par défaut sur mobile » est stockée sur le groupe et appliquée uniquement à ces groupes dans le menu mobile WordPress. Les navigations hors Simple Edit ne sont pas touchées.

## Tests

- `php tests/navigation-subgroups.php` : détection, construction des sous-menus WordPress, protection des URL, contrôle de conversion et rejets de structures invalides.
- `php tests/navigation-subgroups.php missing-confirmation` et `convert` : confirmation indispensable et conversion qui ne modifie pas `menu_order` des pages.
- `node tests/navigation-drag.js` : capture tactile sur une liste immobile, réordonnancement, dépôt sous le parent, actions de repli et déplacement clavier de groupe.
- `node tests/navigation-mobile.js` : état initial fermé, bascule et `aria-expanded`, absence d'effet sur les sous-menus non gérés.
- Les tests isolés ne remplacent **pas** une vérification visuelle du navigateur Android ni un essai WordPress avec un menu réel.
