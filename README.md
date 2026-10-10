# Dendrila Simple Edit

Plugin WordPress autonome pour la modification guidée de contenus, inspiré de la séparation des rôles et des champs déclaratifs de Dendrila CMS.

**Version de travail : 0.1.0 — premier parcours de champs reliés.** Pas encore une prise en charge générale de l’édition des pages WordPress.

## Installation (environnement de test)
Copier le répertoire à partir de la racine de ce dépôt dans wp-content/plugins/dendrila-simple-edit/ puis activer l’extension. WordPress 6.5+ et PHP 7.4+ requis.

Un nouveau rôle **Responsable du site** est créé à l'activation, sans compte ni attribution automatique. Dans Utilisateurs, un administrateur peut attribuer ce rôle explicitement à un compte qui ne possède pas déjà des privilèges étendus.

Dans **Modifier mon site**, l'administrateur coche les informations qu'il souhaite déléguer. Il peut ensuite insérer les raccourcis dans les contenus et modèles compatibles :

- [dendrila_simple_edit field="phone"]
- [dendrila_simple_edit field="hours"]
- [dendrila_simple_edit field="address"]
- [dendrila_simple_edit field="presentation"]

Le rôle délégué n’a accès qu’aux champs cochés. Les pages ne sont **pas** inspectées ni modifiées automatiquement. Un champ absent du thème tant que son shortcode n’a pas été placé ne s’affichera nulle part. La modification alimente immédiatement les shortcodes reliés côté WordPress, mais un cache pleine page ou CDN peut retarder sa visibilité publique. Un événement dse_fields_updated permet de raccorder les purges de caches du site.

## Fonctionnalités du premier lot
- Formulaire lisible sur mobile, sauvegarde explicite, retours d’erreur et libellés accessibles.
- Création par l’administrateur de 20 zones de contenu personnalisées supplémentaires (titre ou texte multiligne), nommées automatiquement et accessibles par shortcode.
- Repérage partiel des pages/articles publics contenant les shortcodes, plus diagnostic dédié des widgets Elementor Shortcode sur des pages publiées (sans éditer leur JSON).
- Connexion optionnelle à Advanced Custom Fields : un administrateur peut rechercher les champs existants d’un contenu public puis autoriser un champ de texte, URL ou e-mail précis d’une page, d’un article ou d’un type de contenu public. Le responsable le modifie sans droits natifs d’édition des pages. Les autorisations sont révocables.
- Rôle dédié et contrôle de permissions côté serveur (incluant les POST directs).
- Liste blanche des champs. Aucun accès natif aux éditeurs Gutenberg / Divi / Elementor.
- Échappement public, assainissement des valeurs et stockage dans les options WordPress.
- Comparaison atomique de version, historique des 15 versions précédentes et restauration strictement limitée aux champs encore autorisés.
- Aucun compte créé, aucune connexion externe, aucun tracking.

## Limites et travaux suivants
C’est le premier adaptateur **champs globaux reliés**. Les zones Gutenberg sélectionnables, l'aperçu réel, les médias, le workflow de validation et les adaptateurs Divi/Elementor viendront ensuite. Les shortcodes doivent être ajoutés par un administrateur. Les champs cachés à l'éditeur peuvent rester affichés publiquement lorsque le shortcode correspondant est déjà utilisé.

Voir [le plan](docs/PLAN.md) et [l'architecture](docs/ARCHITECTURE.md). Ne pas installer directement en production sans essai complet du site concerné.

## Qualité
Exécuter php -l sur les fichiers PHP et php tests/smoke.php. Les tests livrés couvrent le contrat des champs et des autorisations avec des doubles de WordPress ; une installation réelle reste nécessaire pour vérifier les formulaires, rôles, caches et rendus.

## Licence
GPL-2.0-or-later pour ce plugin, entièrement écrit pour WordPress. Aucun code de Dendrila CMS n'a été copié.
