# Dendrila Simple Edit pour WordPress

Dendrila Simple Edit donne à chaque rôle WordPress un espace de travail pour modifier les contenus qui lui sont confiés. L’administrateur choisit les types de contenus, les modules et les actions autorisées. La version de laboratoire est **0.0.1**, pour WordPress 6.5 ou ultérieur et PHP 7.4 ou ultérieur.

## Installation et premier essai

1. Sur un site de test, installer le ZIP **dendrila-simple-edit-installation** fourni par le [workflow de vérification](https://github.com/jasmin-abernathy/dendrila-simple-edit/actions/workflows/check.yml). Télécharger l’artefact d’une exécution réussie et en extraire le ZIP installable.
2. Dans **Extensions → Ajouter une extension → Téléverser**, choisir ce ZIP et activer l’extension. Avant de remplacer une version existante, sauvegarder les fichiers et la base, notamment si des listes de diffusion existent.
3. Ouvrir **Modifier mon site** et suivre les cinq étapes de configuration. Activer les modules utiles et attribuer les actions aux rôles concernés. Une mise à jour conserve les réglages. L’assistant peut être rouvert depuis les réglages des modules.
4. Avec un compte de test du rôle choisi, préparer une modification et vérifier le circuit de validation ainsi que le résultat sur le site.

## Parcours disponibles

- **Contenus** — édition guidée des pages, articles et événements autorisés, images approuvées et champs ACF compatibles. Les mises en page complexes créées avec un constructeur doivent être vérifiées avec les extensions du site.
- **Navigation** — organisation du menu principal à la souris, au toucher ou avec les commandes clavier. Une confirmation est demandée avant de convertir une liste automatique de pages en menu manuel.
- **Boutique** — WooCommerce apporte les produits. Les actions sur leur contenu, leurs variations, le stock et la publication se délèguent séparément. Les commandes et les paiements restent dans WooCommerce.
- **Newsletters** — préparation et aperçu des campagnes, listes, file d’envoi et désinscription. L’envoi nécessite FluentSMTP actif avec une connexion configurée. Pour essayer le module, utiliser une liste de test.
- **Champs reliés** — placer par exemple `[dse_text field="phone"]` dans un bloc Code court. Un cache de page peut retarder l’affichage d’une modification.

Le [descriptif livré avec l’extension](readme.txt) détaille les prérequis, les données stockées et les limites. Le [bilan de la version 0.0.1](docs/RELEASE-AUDIT-0.0.1.md) et le [protocole pour un site pilote](docs/ESSAI-SITE-LIVE.md) distinguent les contrôles isolés des essais avec les extensions d’un site réel.

[Présentation du projet](https://dendrila.lepotager.org/projets/simple-edit/) · [Signaler un problème](https://github.com/jasmin-abernathy/dendrila-simple-edit/issues) · [Licence GPL v2 ou ultérieure](LICENSE.txt)
