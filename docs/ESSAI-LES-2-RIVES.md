# Essai sur Les 2 Rives — site WordPress actif

**Site visé :** https://www.les2rives.eu/ — **date de préparation :** 10 octobre 2026.

**Statut :** site public consulté ; aucune connexion à son administration, aucun plugin installé, aucune sauvegarde confirmée, aucun essai WordPress réel effectué.

**Attention aux versions :** ce protocole de pilote concerne le socle éditorial 0.1.0. Les nouveaux modules 0.2.0 sont encore en validation et ne sont pas à activer sur ce site en production pour un premier essai.

## Informations disponibles et limites

- La page publique fonctionne et présente les activités de l’association.
- Sa politique de cookies déclare l’utilisation de **WordPress et Elementor**. La présence actuelle, les versions et les modules Elementor actifs restent à vérifier dans l’administration ; une politique de cookies ancienne peut être incomplète.
- Les mentions légales mentionnent **OVHcloud** comme hébergeur ; confirmer l’hébergement depuis l'espace OVH ou une fiche client avant tout geste sur serveur.
- Le site a déjà été envisagé comme pilote d'un plugin RGPD du Potager : relever les extensions RGPD et de cache réellement actives, sans présumer de leur installation.
- Aucun dépôt GitHub spécifiquement identifié et confirmé pour la production Les 2 Rives. **Ne pas appliquer par hypothèse** les commandes SSH, chemins o2switch ni processus de déploiement d’autres sites.
- Versions WordPress, PHP, thème, Elementor, ACF, sauvegardes et droits sur le site : **non vérifiés**.

## Étape 0 — éléments à relever avant installation

Depuis l’administration du WordPress existant :

1. **Outils → Santé du site → Informations** : noter version WordPress, version PHP, thème actif et environnement serveur.
2. **Extensions** : vérifier Elementor et sa version, ACF éventuel, plugins de sécurité, cache et consentement ; noter tout problème préalable sans désactiver ces extensions.
3. Depuis l’espace OVHcloud associé au bon hébergement, vérifier les **sauvegardes de l’espace web ET de la base de données**, leur date, leur restauration et les accès de secours (FTP/SFTP, logs). Si une sauvegarde manuelle est nécessaire, la réaliser avant l’essai. Une sauvegarde du code seul ne suffit pas.
4. Noter l’état public d’au moins l’accueil et d’une page interne ; observer d’éventuelles erreurs ou anomalies déjà présentes.
5. Confirmer le droit d’effectuer cet essai sur le site de l’association et choisir un moment à faible activité.

**Condition bloquante :** sans sauvegarde restaurable et possibilité de désactivation par fichiers en cas de problème, ne pas activer le plugin sur ce site.

## Étape 1 — archive validée, activation sans contenu public

Archive issue du workflow GitHub Actions validé : https://github.com/jasmin-abernathy/dendrila-simple-edit/actions/runs/38047382773. Dans **Artifacts**, récupérer `dendrila-simple-edit-installation`, puis extraire l’archive intérieure `dendrila-simple-edit-0.1.0.zip`.

Dans **Extensions → Ajouter → Téléverser une extension**, installer le ZIP intérieur et activer.

- Vérifier que **Modifier mon site** apparaît pour l'administrateur.
- Autoriser seulement la zone **Présentation** et enregistrer un texte d'essai sans shortcode public ; aucune page ne doit changer.
- Créer un compte **temporaire** avec uniquement le rôle **Responsable du site**. Tester son menu et l'absence de privilèges de création de pages, gestion des extensions ou modification du thème.
- Ne pas attribuer le rôle expérimental à un administrateur existant ; les autres rôles peuvent conserver des permissions supplémentaires.
- Ne pas renseigner de clés ou de mots de passe dans des tickets, captures ou échanges.

## Étape 2 — essai dans un brouillon non publié

1. En tant qu'administrateur, créer une **page brouillon** intitulée par exemple « Essai Dendrila – privé ».
2. Y placer un bloc **Code court** WordPress avec `[dendrila_simple_edit field="presentation"]`.
3. Prévisualiser la page sans la publier.
4. Depuis le compte Responsable du site, modifier la présentation ; revenir à la prévisualisation pour vérifier la mise à jour.
5. Tester la restauration de la valeur via l'historique des zones, puis une nouvelle prévisualisation.
6. Tester une zone non autorisée et vérifier qu'elle ne peut pas être éditée. Vérifier les menus et l'absence d'accès aux éditeurs natifs.

**Précision :** le diagnostic d'emplacements du plugin cible les contenus publiés, pas les brouillons. L'absence de ce brouillon dans sa liste de repérage est donc normale.

## Étape 3 — Elementor (sans modifier les pages en service)

Si Elementor est bien actif, créer au besoin **un autre brouillon** et y insérer le widget officiel **Shortcode**, contenant le même shortcode. Tester sa prévisualisation, sans remplacer de texte existant ni modifier `_elementor_data` d'une page publiée.

Ne pas tester pour l'instant les champs ACF existants, ni l'insertion dans les modèles globaux ou éléments partagés du site ; ces chemins peuvent affecter immédiatement des pages publiques.

## Retour arrière

- Administration accessible : désactiver **Dendrila Simple Edit** dans Extensions, et supprimer les pages de démonstration devenues inutiles.
- Administration inaccessible : renommer le dossier `wp-content/plugins/dendrila-simple-edit` par FTP/SFTP à partir **du bon hébergement OVH**, puis examiner les logs et la page publique.
- Une simple désactivation **ne supprime pas** les options, le rôle et le compte temporaire ; retirer le compte temporaire après essai. La remise à l’état exactement initial nécessite de disposer de la bonne sauvegarde et de tenir compte des éventuelles autres modifications survenues entre-temps.
- Ne pas laisser un shortcode d'essai dans un contenu publié : sans l'extension il peut apparaître en texte.

## Critères de décision

Pour poursuivre sur un contenu réel, confirmer : installation sans erreur, privilèges limités, rendu en brouillon via shortcode, restauration réussie, absence de régression sur les pages publiques, fonctionnement Elementor et prise en compte du cache si applicable.

Le passage sur un emplacement **publié** exige un accord distinct après ces vérifications. Pas de fusion, de déploiement automatique ou de modification des modules Elementor existants à ce stade.
