# Dendrila Simple Edit pour WordPress

Dendrila Simple Edit donne à chaque rôle WordPress un espace de travail pour modifier les contenus qui lui sont confiés. L’administrateur choisit les types de contenus, les modules et les actions autorisées. La version de laboratoire est **0.0.3**, pour WordPress 6.5 ou ultérieur et PHP 7.4 ou ultérieur.

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
- **Accès aux plugins** — dans **Autorisations → Accès aux plugins**, cocher les extensions actives à ouvrir aux comptes **Édition simple**. Le choix est général : Calendar, un outil de réservation ou toute autre extension dont les écrans peuvent être identifiés. Décocher un plugin retire son accès dès la prochaine action.

## Déléguer l’administration d’une extension

La liste indique les menus détectés pour chaque plugin. L’autorisation s’applique à l’ensemble du rôle **Édition simple**, sans attribuer le rôle administrateur et sans enregistrer de droit global `manage_options` sur les comptes. Les menus autorisés restent disponibles dans la barre latérale, y compris les sous-menus placés sous un menu WordPress comme **Réglages** ou **Outils**.

Les vérifications de permission propres au plugin, ses contenus personnalisés, taxonomies et formulaires Settings API dédiés sont pris en charge. Les appels AJAX et REST implémentés par ce plugin utilisent la même sélection. L’origine est établie à partir du code actif et des callbacks enregistrés ; un paramètre `page` ou un référent HTTP ne suffit jamais à donner un droit.

L’accès porte sur les fonctions du plugin choisi : une extension de gestion des utilisateurs, de sauvegarde ou d’exécution de code peut elle-même proposer des actions sensibles. Ce mécanisme ne constitue pas un cloisonnement des fonctions internes de ces extensions. Les écrans WordPress non autorisés et l’administration de Simple Edit conservent leurs restrictions.

Certaines extensions nécessitent une adaptation : contrôles directs du rôle administrateur, initialisation conditionnelle avant le chargement de Simple Edit, permissions internes supplémentaires non représentées dans leurs menus, formulaires partageant un groupe de réglages WordPress ou service externe. Une extension complémentaire peut nécessiter de sélectionner aussi son plugin parent. Les réglages partagés restent réservés à l’administration ; aucune permission générale n’est ajoutée pour contourner ces cas.

Le [descriptif livré avec l’extension](readme.txt) détaille les prérequis, les données stockées et les limites. Le [bilan de la version 0.0.1](docs/RELEASE-AUDIT-0.0.1.md) et le [protocole pour un site pilote](docs/ESSAI-SITE-LIVE.md) distinguent les contrôles isolés des essais avec les extensions d’un site réel.

[Présentation du projet](https://dendrila.lepotager.org/projets/simple-edit/) · [Signaler un problème](https://github.com/jasmin-abernathy/dendrila-simple-edit/issues) · [Licence GPL v2 ou ultérieure](LICENSE.txt)
