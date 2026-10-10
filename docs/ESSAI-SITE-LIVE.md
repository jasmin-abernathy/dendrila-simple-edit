# Essai contrôlé sur un WordPress actif

Version candidate : **Dendrila Simple Edit 0.1.0**. Ce premier essai valide les réglages, l'interface et le rendu d'une zone isolée ; il ne valide pas encore l'édition native des modules Divi, Elementor ou Gutenberg.

## Prérequis incontournables

1. Choisir un site WordPress dont l'administrateur accepte ce test. Privilégier un site peu sensible ; ne pas commencer sur une boutique ou un site à trafic critique.
2. Confirmer WordPress 6.5+ et PHP 7.4+ ; relever les versions du thème et, si présents, de Divi, Elementor et ACF.
3. Créer une **sauvegarde complète des fichiers ET de la base de données**, indépendante du ZIP du plugin. Vérifier les outils de sauvegarde **réellement disponibles chez l’hébergeur du site** (OVHcloud, o2switch ou autre) et la procédure de restauration des fichiers et de la base ; ne pas supposer qu’un point de restauration existe.
4. Relever l'état initial : fonctionnement de la page d'accueil et d'une page de contenu, erreurs PHP connues, présence éventuelle d'un cache/CDN. Prévoir l'accès au gestionnaire de fichiers ou à SFTP en cas d'écran blanc.
5. Ne transmettre aucun identifiant ni mot de passe à un tiers ; utiliser les accès déjà détenus par le propriétaire du site.

## Obtenir un ZIP installable

Le workflow `PHP quick checks` fournit, après réussite des tests PHP 7.4 et 8.4, un artefact **dendrila-simple-edit-installation**. Télécharger l'artefact dans les résultats GitHub Actions de la branche de travail et **extraire le ZIP intérieur** `dendrila-simple-edit-0.1.0.zip`. C'est ce ZIP intérieur qu'il faut installer dans WordPress.

La fonctionnalité d'archivage est réservée à la distribution de test : l'artefact n'implique ni publication WordPress.org, ni fusion, ni déploiement automatique.

## Phase A : aucune modification publique

1. Dans WordPress, **Extensions → Ajouter → Téléverser une extension**, sélectionner `dendrila-simple-edit-0.1.0.zip`, installer puis activer.
2. Vérifier l'absence d'erreur fatale et la présence de **Modifier mon site** dans l'administration. L'activation crée un rôle mais **ne l'attribue à aucun compte**.
3. Dans l'écran de l'administrateur, cocher uniquement **Présentation** ou ajouter une zone personnalisée de démonstration ; ne pas associer d'autres contenus réels.
4. Créer un **nouveau compte temporaire** avec le seul rôle **Responsable du site** et un mot de passe robuste. Ne pas modifier le rôle d'un compte administrateur : des permissions préexistantes resteraient actives.
5. Avec ce compte, vérifier qu'il peut modifier la zone autorisée, et qu'il ne peut pas accéder aux éditeurs de pages/articles, au thème, aux utilisateurs ou aux extensions.
6. Vérifier qu'aucun contenu public n'a changé. Les données saisies dans ce lot ne s'affichent qu'à l'emplacement d'un shortcode placé explicitement.

## Phase B : vérification du rendu sans exposition publique

1. Avec l'administrateur, créer une **nouvelle page en brouillon**, par exemple « Essai zone de contenu », et y placer un bloc **Code court** contenant : `[dendrila_simple_edit field="presentation"]`.
2. Prévisualiser la page avec le compte administrateur : la valeur autorisée doit apparaître, le reste de la page doit rester inchangé.
3. Modifier uniquement **Présentation** depuis le compte Responsable du site, puis recharger l'aperçu administrateur. Vérifier le nouveau texte, puis une restauration depuis l'historique.
4. Tester un champ non autorisé : il ne doit pas être proposé au Responsable du site. Toute tentative de modification directe hors liste doit être refusée.
5. Ne **pas publier** cette page de test, ne pas modifier de contenu ACF existant, ni injecter un shortcode dans les modèles Divi/Elementor lors de ce premier passage.
6. Documenter toute différence entre la sauvegarde dans WordPress et l'aperçu, surtout si un cache est actif.

## Phase C : publication limitée (approbation distincte)

Après validation des phases A et B, choisir volontairement un emplacement public mineur où afficher une zone. Le responsable pourra y éditer la valeur sans accéder au constructeur. Examiner rendu sur mobile, accessibilité et cache. Ne pas utiliser le plugin pour modifier directement des modules Divi/Elementor ; cette fonction n'est pas disponible.

Les champs ACF simples ont leur propre adaptateur, mais leur édition écrit dans les données existantes. Attendre un essai dédié et une sauvegarde vérifiée avant de les tester en production.

## Retour arrière

- Si l'administration reste accessible : **Extensions → Désactiver Dendrila Simple Edit**. Avant une désactivation définitive, retirer les shortcodes de démonstration : sinon WordPress peut afficher leur texte littéral.
- Si l'administration ne répond plus : utiliser le gestionnaire de fichiers o2switch/SFTP pour renommer `wp-content/plugins/dendrila-simple-edit` en `dendrila-simple-edit-disabled` ; cette manœuvre empêche WordPress de charger le plugin. Vérifier ensuite le site et les journaux d'erreur.
- Désactivation et suppression des fichiers **n'effacent pas automatiquement** les options, les valeurs ni le rôle créé dans la base. Réattribuer ou supprimer explicitement le compte temporaire, et restaurer la sauvegarde complète si une remise à l'état exact est nécessaire.
- Ne restaurer **fichiers et base** qu'après avoir évalué les autres modifications effectuées sur le site depuis la sauvegarde.

## À relever dans le compte rendu

URL du site pilote (sans secrets), versions WordPress/PHP, constructeur et versions, thème, ACF, cache, succès/échec de l'activation, résultat compte délégué, aperçu shortcode, restauration, éventuels messages PHP et capture du comportement inattendu.

**Statut** : tests PHP automatisés et contrôles de contrat ≠ essai sur WordPress réel. Les phases A, B et C doivent être déclarées séparément.
