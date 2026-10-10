=== Dendrila Simple Edit ===
Contributors: juliane16
Tags: editor, content, access-control, role, simple-editing
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Éditez des informations explicitement autorisées sans toucher à la mise en page WordPress.

== Description ==

Dendrila Simple Edit propose un espace « Modifier mon site » pour les personnes qui doivent tenir à jour quelques informations sans accéder aux éditeurs techniques.

Cette première version de travail permet :
* la création d’un rôle « Responsable du site » aux permissions minimales ;
* le choix des champs délégués par un administrateur ;
* la création de 20 zones de texte personnalisées au maximum, affichées par shortcode ;
* une connexion facultative à Advanced Custom Fields (ACF) pour modifier uniquement des champs texte, zone de texte, URL ou e-mail explicitement autorisés par un administrateur ;
* un diagnostic des widgets Shortcode dans le contenu Elementor publié, sans altérer les structures du constructeur ;
* le choix d’une photo parmi des images préalablement approuvées par l’administrateur, sans droit de téléverser ou de parcourir toute la médiathèque ;
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

= Un Responsable du site peut-il modifier un thème ? =
Non, pas avec les seules permissions accordées par ce plugin. Un autre rôle ou plugin peut cependant lui attribuer des droits supplémentaires.

== Changelog ==

= 0.2.0 (développement) =
* Modules optionnels par compte : brouillons de newsletters sans envoi et édition de produits WooCommerce simples à droits réduits.

= 0.1.0 =
* Premier socle : champs reliés, rôle dédié, historique et vérification des versions.
