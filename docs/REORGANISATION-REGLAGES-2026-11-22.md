# Dendrila Simple Edit 0.0.1 — Administration, première installation

## Comportement de l’assistant

- À **la première activation après une première installation**, et uniquement si l’option de configuration n’existe pas, `on_activate()` crée la progression initiale et un marqueur de redirection. L’administrateur/gestionnaire autorisé est dirigé vers les cinq étapes.
- Une **mise à jour**, un remplacement de ZIP ou une **réactivation ultérieure** ne réinitialise ni la progression ni l’état « configuration terminée ». Les listes, contacts, désinscriptions, rôles et consentements restent intacts.
- Depuis **Réglages → Modules**, un bouton explicite rouvre manuellement l’assistant. Ce mode « révision » ne réinitialise pas l’option de configuration initiale. Il peut être quitté via « Quitter l’assistant ».
- Un **désinstaller/réinstaller** ne garantit pas le retour du parcours initial si les options WordPress persistent ; la révision manuelle est donc l’option recommandée pour une installation déjà configurée.

## Nouvelle navigation de réglages

1. **Modules** : activation/désactivation autonome d’Édition de contenu, Newsletters et Produits WooCommerce. Une connexion FluentSMTP configurée est exigée pour activer Newsletters. Changer un état de module ne remplace pas les permissions par rôle.
2. **Contenu** : sous-rubriques Pages, articles et événements (choix des **types**, pas des éléments individuellement), Images, Champs complémentaires (ACF), Textes et coordonnées. L’ancienne sous-rubrique purement informative « Événements » est supprimée, mais l’éditeur d’événements reste accessible comme outil métier.
3. **Rôles et permissions** : sous-panneau par module et accordéon par rôle, fermés par défaut, avec sélection/désélection groupée ; les administrateurs conservent leurs droits natifs. Enregistrer des droits n’active/désactive plus un module en parallèle.
4. **Validation** : droits/traitement des demandes de publication.

Les anciennes URLs de réglage sont redirigées logiquement vers les nouvelles rubriques dans le routeur de l’administration ; le numéro de version reste **0.0.1**. Les assets CSS utilisent déjà `filemtime` dans leur versionnement pour éviter le cache des anciens styles.

## Contrôles

- 18 fichiers PHP vérifiés avec `php -l` ; deux scripts JS vérifiés par `node --check`.
- `tests/first-install.php` : première installation, mise à jour/réactivation, poursuite après interruption et réouverture manuelle.
- `tests/admin-settings.php` : rendu des 3 choix d’activation, FluentSMTP apparent, sous-panneaux par rôle, impossibilité d’activer un module depuis le formulaire des permissions.
- `tests/relais-regressions.php` : 125 brouillons, droits par type, contenu Gutenberg protégé, campagnes par auteur, consentement WooCommerce et nonce.
- **Contrôle visuel/live non effectué** : la tentative d’ouverture locale par Chromium en mode headless a expiré. Le CSS et l’HTML ont été relus, mais la géométrie réelle sur WordPress doit être vérifiée en mobile et desktop.
- Le nouveau rapport Plugin Check doit être établi **sur le ZIP exact** avant toute affirmation de conformité WordPress.org. Les avertissements SQL antérieurs sont conservés ; pas de cache introduit sur les désinscriptions.
