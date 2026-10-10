# Plan de développement — Dendrila Simple Edit

10 octobre 2026 — référentiel autonome WordPress.

## Objectif
Permettre à un **Responsable du site** de modifier les données autorisées sans accéder aux outils de mise en page. Le plugin ne nécessite ni Dendrila CMS ni abonnement. WordPress demeure la source de vérité.

## Contrat de sécurité
- Rôle technique : dendrila_site_manager, créé à l’activation, jamais attribué automatiquement.
- Capacités : read, dse_access, dse_edit, dse_publish.
- Aucune capacité générale d’édition de pages/articles, de styles, de plugins, d’options, d’utilisateurs ou de fichiers.
- Les administrateurs configurent la liste autorisée. Les droits sont revérifiés sur chaque POST, avec nonce et contrôle des champs un par un.
- Les comptes cumulant plusieurs rôles conservent leurs autres droits WordPress : ne pas promettre un confinement si d’autres rôles les élargissent.
- Une mise à jour périmée doit être refusée, y compris par appel direct.
- Les raccourcis du CMS de référence ne doivent pas introduire sa dépendance PHP 8.5 dans WordPress.

## Lot 0 — analyse du CMS ✅
- [x] Lire les règles du dépôt et repo-factory.
- [x] Examiner le contrat des modèles de contenus, rôles, révisions, projections du CMS.
- [x] Décision de réutilisation : **principes uniquement**, pas de copie du code PHP 8.5 ni de dépendance aux schémas SQL.
- [x] Choisir WordPress 6.5+, PHP 7.4+ pour la première matrice. Tests PHP 7.4 et 8.4.
- [ ] Choisir un site WordPress pilote isolé (ne pas tester sur la production).
- [ ] Valider la compatibilité de licences si du code CMS est repris à l’avenir.

## Lot 1 — socle utilisable dans les zones reliées
- [x] Fichier plugin installable et styles admin limités à son écran.
- [x] Création du rôle à permissions minimales.
- [x] Écran « Modifier mon site » + configuration des champs pour l’administrateur.
- [x] Première édition de téléphone, horaires, adresse, présentation avec liste blanche et assainissement côté serveur.
- [x] Ajout par l’administrateur de 20 zones textuelles personnalisées au maximum, avec slug généré et activation immédiate.
- [x] Shortcode explicite par champ pour les pages/modèles compatibles.
- [x] Repérage limité des pages/articles publiés contenant littéralement les shortcodes.
- [x] Révision globale, restauration limitée aux champs actuels, comparaison atomique contre l’écrasement concurrent.
- [x] Tests de contrat sans WP + syntaxe PHP CI (à confirmer par l'exécution de la CI).
- [ ] Valider les chemins admin sur une instance WordPress réelle, avec et sans rôle supplémentaire.
- [ ] Tester désactivation/réactivation sans perdre les données.

## Lot 2 — contenu réel de pages
- [ ] Configuration par un administrateur de pages/zones Gutenberg précises.
- [ ] Adapter titre, paragraphe et image par identifiant stable ; conserver intact le reste du document.
- [ ] Bloquer la lecture/écriture d’un bloc non déclaré, d’un bloc synchronisé ou d’un format inconnu.
- [ ] Prévisualisation réelle, révisions natives, restauration sur objets WordPress et gestion de validation.
- [ ] Test de diff du rendu public, des caches, de la conservation de la structure.
- [ ] Raccorder la purge des caches pleine page/CDN du site pilote à l'événement dse_fields_updated.
- [x] Sélection d'une photo parmi des images WordPress préalablement approuvées par l'administration.
- [ ] Import d’images autorisé par zone : MIME, taille, dimensions, réencodage et droits à définir et tester avant activation.

## Lot 3 — intégrations et pilote
- [ ] Adaptateur Divi, puis Elementor, uniquement à partir de formats vérifiés.
- [x] Premier adaptateur ACF : clé exacte, post public, types textuels simples, autorisation et révocation explicites.
- [x] Découverte des champs ACF simples déjà renseignés sur un contenu choisi depuis l'administration (alternative à la saisie manuelle de clé).
- [x] Diagnostic des widgets Elementor Shortcode sur les pages publiées.
- [ ] Adapter les champs ACF complexes après tests dédiés (répéteurs, galeries, relation, flexibles).
- [ ] Adapter directement Elementor et Divi 4/5 uniquement avec fixtures de versions et tests non destructifs.
- [ ] Tester mobile, clavier, lecteur d’écran et une personne non technique.
- [ ] Former l’administrateur à attribuer le rôle sans accorder de droits d’édition natifs.
- [ ] Documenter site pilote, installation, configuration, révisions et comportement des constructeurs.
- [ ] Préparer le ZIP + Plugin Check + publication WordPress.org après validation.

## Critères d'acceptation du premier lot
1. Un Responsable du site ne peut pas accéder à l’éditeur WordPress de pages via les seules capacités accordées par le plugin.
2. Un champ désactivé est refusé même en falsifiant le POST.
3. La saisie est assainie puis échappée à l'affichage public.
4. Une version dépassée ne remplace pas un changement plus récent.
5. La restauration ne réécrit aucun champ devenu interdit.
6. Une donnée ne devient visible que si l'administrateur relie explicitement son shortcode au site.
7. Une modification en base n'est **pas** une validation du rendu public : le pilote doit confirmer la propagation réelle.

## Limites explicites
- Aucun remplacement automatique d’occurrences textuelles dispersées ; seuls les emplacements avec shortcode changent.
- Aucun adaptateur Divi/Elementor, Gutenberg structuré, workflow de validation, aperçu réel, publication différée ni import média à ce stade.
- Les champs du plugin ne sont pas automatiquement connectés à un thème ; ils ne sont publiés que là où un shortcode est effectivement placé.
- Le rôle n’empêche pas un autre plugin ou un autre rôle d'accorder des capacités plus étendues.
- La suppression de l’extension n’efface pas les valeurs ; prévoir un assistant de retrait/réattribution avant nettoyage des rôles.
