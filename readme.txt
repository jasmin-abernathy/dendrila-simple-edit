=== Dendrila Simple Edit ===
Contributors: juliane16
Tags: editor, content, access-control, role, simple-editing
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Éditez des informations explicitement autorisées sans toucher à la mise en page WordPress.

== Description ==

Dendrila Simple Edit propose un espace « Modifier mon site » pour les personnes qui doivent tenir à jour quelques informations sans accéder aux éditeurs techniques.

Cette première version de travail permet :
* la création d’un rôle « Édition simple » aux permissions minimales ;
* le choix des champs délégués par un administrateur ;
* la création de 20 zones de texte personnalisées au maximum, affichées par shortcode ;
* une connexion facultative à Advanced Custom Fields (ACF) pour modifier uniquement des champs texte, zone de texte, URL ou e-mail explicitement autorisés par un administrateur ;
* un diagnostic des widgets Shortcode dans le contenu Elementor publié, sans altérer les structures du constructeur ;
* le droit d’importer ses images et d’utiliser celles des autres éditeurs simples par défaut (partage désactivable par l’administrateur), plus des images explicitement autorisées ou un accès à toute la médiathèque ;
* la modification du téléphone, des horaires, de l’adresse et d’un court texte de présentation ;
* l’insertion volontaire de ces données par les shortcodes prévus ;
* l’historique limité, la restauration autorisée et le refus d’écrasement de changements concurrents.

Depuis la version 0.2.0 de développement, un administrateur peut activer séparément les modules Newsletters et WooCommerce, puis attribuer des droits précis à chaque compte. Newsletters permet uniquement de préparer, prévisualiser et valider des brouillons privés sans aucun envoi ni accès aux contacts. WooCommerce permet de créer des produits simples en brouillon et de modifier les champs autorisés (contenu, image, catégories, prix, stock) sans accès aux commandes ou paiements. L'édition des produits publiés nécessite une seconde autorisation. La publication de produits et les variantes ne sont pas encore disponibles.

Aucune donnée n’est transmise à un service externe par Dendrila Simple Edit. Ce plugin ne remplace pas automatiquement des textes existants et n’édite pas encore les zones Gutenberg, Divi ou Elementor.

== Installation ==

Installer sur une instance de test, activer, configurer les champs depuis « Modifier mon site », puis insérer les shortcodes affichés à l’administrateur.

== Frequently Asked Questions ==

= Le plugin modifie-t-il automatiquement les pages existantes ? =
Non. Seuls les emplacements reliés par un shortcode sont mis à jour.

= Le rôle Édition simple permet-il de modifier un thème ? =
Non, pas avec les seules permissions accordées par ce plugin. Un autre rôle ou plugin peut cependant lui attribuer des droits supplémentaires.

== Changelog ==

= 0.2.1-beta4 (rôle Édition simple et imports d’images) =
* Nouveau rôle « Édition simple », attribuable depuis Utilisateurs et limité au plugin ; migration des comptes de l’ancien rôle.
* Accès au plugin juste sous Tableau de bord dans le menu d’administration.
* Les utilisateurs de ce rôle peuvent importer leurs images sans autorisation préalable et utiliser celles des autres utilisateurs « Édition simple » par défaut. Une case permet de désactiver ce partage.
* Option administrateur « Accès à toute la médiathèque », désactivée par défaut.
* Sans accès complet : la bibliothèque est limitée aux images de l’équipe (si le partage est actif), aux images personnelles et aux images explicitement autorisées, avec contrôles côté serveur.
* Un sélecteur natif permet de choisir une image pour un contenu sans saisir d’identifiants.


= 0.2.1-beta3 (médiathèque et autorisations) =
* Choix multiple directement dans la médiathèque WordPress, avec miniatures, retrait individuel et sélection limitée à 60 images.
* Les identifiants des médias restent enregistrés dans la même option ; l’édition manuelle est réservée au mode avancé ou à l’absence de JavaScript.
* Le retrait d’une autorisation ne supprime pas les fichiers ni les images déjà affichées.
* Libellés de l’onglet « Autorisations » rédigés pour l’administrateur, sans désigner tous les utilisateurs autorisés comme « responsables ».

= 0.2.1-beta2 (allègement de l’en-tête) =
* Suppression de la phrase introductive redondante sous « Modifier mon site ».

= 0.2.1-beta1 (tests, interface inspirée de Dendrila Privacy) =
* Onglets adaptés aux petits écrans : textes, newsletters, boutique, historique, autorisations et aide.
* Présentation simplifiée et codes ACF manuels rangés dans les options avancées.
* Les onglets non autorisés sont masqués au rôle délégué ; navigation fonctionnelle sans JavaScript.
* Un champ ACF ne peut plus être autorisé manuellement si son groupe n’est pas applicable au contenu.
* Tests isolés des onglets, du rôle et des règles de localisation ACF.


= 0.2.0 (développement) =
* Modules optionnels par compte : brouillons de newsletters sans envoi et édition de produits WooCommerce simples à droits réduits.

= 0.1.0 =
* Premier socle : champs reliés, rôle dédié, historique et vérification des versions.

= 0.0.1 — éditeur commun et mailing (laboratoire uniquement) =
* Éditeur à blocs visuels pour newsletters et pour pages/articles explicitement autorisés. Le titre principal d'une newsletter ne peut être ajouté qu'une fois.
* Pastilles de couleurs visibles, couleurs personnalisées contrôlées côté serveur et aperçu par élément. Boutons d'ajout illustrés, utilisables par clic et clavier.
* Les pages/articles Gutenberg, Divi, les constructeurs et les contenus incompatibles ne sont jamais convertis automatiquement. L'accès aux pages et articles est interdit tant que l'administrateur ne les a pas sélectionnés.
* Newsletters : brouillons et approbation, listes, import avec consentement déclaré, formulaire public à double confirmation par liste, liens de désinscription, prévisualisation et campagnes préparées sans envoi.
* Seul un administrateur peut choisir les destinataires, programmer, lancer, suspendre ou tester un envoi. L'envoi passe par wp_mail(), avec le transport FluentSMTP 2.4.5 lorsqu'une connexion est configurée ; aucun mot de passe SMTP n'est conservé par Dendrila.
* File d'envoi par lots de cinq via WP-Cron. Échec ou interruption : vérification manuelle ; les envois incertains ne sont pas rejoués automatiquement. Un envoi accepté n'est pas une preuve de remise en boîte.
* VERSION EXPÉRIMENTALE : aucune campagne réelle ni interface de gestion en production n'ont été validées. À tester sur wordpress.lepotager.org avant diffusion publique.
