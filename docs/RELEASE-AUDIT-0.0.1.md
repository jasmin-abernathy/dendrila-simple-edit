# Dendrila Simple Edit — état des contrôles (0.0.1)

Date : 22 novembre 2026. Version en laboratoire (pas encore publiée sur WordPress.org).

## Source

Le ZIP de test contient les 29 fichiers du dossier `dendrila-simple-edit/` ; son contenu correspond aux fichiers de la branche de livraison, sans tests ni documentation de développement dans l’archive.

## Fonctionnalités

- Assistante de configuration initiale, permissions WordPress par rôle, édition limitée des pages et articles.
- WooCommerce : types de produits, catégories, stocks, validation/publication selon les droits.
- Newsletters : quatre vraies structures, palettes enregistrées, blocs repliables, responsive.
- FluentSMTP **requis seulement pour activer et envoyer du mailing** : autres modules utilisables sans lui.
- Désinscriptions persistantes et migration des anciennes tables ; blocage en cas de migration incomplète, contrôle des exclusions au moment de l’envoi.
- Traductions `en_US` et `fr_FR` embarquées, éditeur JavaScript localisé. **Couverture PHP partielle** : plusieurs messages encore codés en français.

## Résultats connus

- Plugin Check du 22/11/2026 à 16:11 : 0 ERROR / 298 WARNING sur l’archive précédente.
- Répartition des warnings relevés : 190 accès directs à la base SQL, 96 vérifications de nonce, 9 entrées à traiter, 3 requêtes lentes.
- Les requêtes directes sont majoritairement des lectures/écritures d’état de campagne, listes et désinscriptions ; elles ne doivent pas être mises en cache sans étude de cohérence.
- Les changements supplémentaires de traduction présents ici n’ont pas fait l’objet d’un **nouveau Plugin Check réel** : ne pas annoncer 0 avertissement.
- Contrôle syntaxique local : tous les PHP et JS ont été vérifiés avec PHP 8.4 et Node.js 22.
- Tests isolés : permissions, édition de pages, décisions de validation de newsletter, structure des quatre emails et palettes, marqueur de désinscription.

## Avant soumission à WordPress.org

1. Faire une sauvegarde SQL du labo avant mise à jour.
2. Installer l’archive et confirmer la migration des adresses désabonnées.
3. Tester la configuration FluentSMTP (actif, inactif, sans connexion).
4. Tester la création et l’édition des brouillons sur mobile, WooCommerce simple/variable, et les rôles WordPress.
5. Rejouer Plugin Check sur **ce ZIP exact** et analyser les messages résiduels.
6. Contrôler le compte WordPress.org dans `Contributors:` de `readme.txt` avant dépôt.
7. Tester français et anglais dans des installations WordPress réelles ; certaines traductions restent à finaliser.

Aucune publication sur WordPress.org n’est impliquée par ce commit GitHub.
