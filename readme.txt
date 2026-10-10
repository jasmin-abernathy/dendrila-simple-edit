=== Dendrila Simple Edit ===
Contributors: juliane16
Tags: editor, content, access-control, role, simple-editing
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
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
* la modification du téléphone, des horaires, de l’adresse et d’un court texte de présentation ;
* l’insertion volontaire de ces données par les shortcodes prévus ;
* l’historique limité, la restauration autorisée et le refus d’écrasement de changements concurrents.

Aucune donnée n’est transmise à un service externe. Ce plugin ne remplace pas automatiquement des textes existants et n’édite pas encore les zones Gutenberg, Divi ou Elementor.

== Installation ==

Installer sur une instance de test, activer, configurer les champs depuis « Modifier mon site », puis insérer les shortcodes affichés à l’administrateur.

== Frequently Asked Questions ==

= Le plugin modifie-t-il automatiquement les pages existantes ? =
Non. Seuls les emplacements reliés par un shortcode sont mis à jour.

= Un Responsable du site peut-il modifier un thème ? =
Non, pas avec les seules permissions accordées par ce plugin. Un autre rôle ou plugin peut cependant lui attribuer des droits supplémentaires.

== Changelog ==

= 0.1.0 =
* Premier socle : champs reliés, rôle dédié, historique et vérification des versions.
