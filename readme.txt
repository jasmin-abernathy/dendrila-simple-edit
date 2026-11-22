=== Dendrila Simple Edit ===
Tags: editor, permissions, newsletter, content
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Proposez un espace d’édition guidée pour les pages, articles, événements, produits et newsletters que vous confiez à votre équipe.

== Description ==

L’administrateur configure les contenus accessibles depuis « Modifier mon site » et peut attribuer le rôle « Édition simple ». Les pages et articles compatibles disposent d’un éditeur visuel. Les contenus utilisant une mise en page complexe doivent être modifiés dans leur éditeur d’origine.

La médiathèque permet de choisir une image visuellement. Les images des utilisateurs du rôle Édition simple sont partagées par défaut entre collègues. L’administrateur peut désactiver le partage, choisir des images individuellement ou ouvrir toute la médiathèque.

Les traductions de l’interface principale sont incluses dans languages/ (français et anglais).

Les intégrations facultatives concernent Advanced Custom Fields pour les champs autorisés, The Events Calendar pour les événements compatibles et WooCommerce pour les produits simples. Les droits sur les produits distinguent notamment le contenu, les images, les catégories, le prix et le stock.

Les modifications peuvent être soumises à validation avant publication, avec des rôles supplémentaires choisis par l’administration.

Le module de newsletters regroupe la rédaction par blocs, les listes, les contacts et les campagnes. Il utilise WP-Cron et la messagerie WordPress. Une extension SMTP configurée sur le site peut prendre en charge le transport des messages.

== Installation ==

1. Téléversez l’archive dans Extensions > Ajouter une extension, puis activez Dendrila Simple Edit.
2. Ouvrez « Modifier mon site » avec un compte administrateur et choisissez les contenus et modules à déléguer.
3. Attribuez le rôle « Édition simple » à un compte distinct et vérifiez son espace de travail.
4. Pour tester les newsletters, configurez un transport de capture sur votre site de test avant d’importer des adresses fictives.

== Frequently Asked Questions ==

= Quelles données sont utilisées pour les newsletters ? =
Le site conserve notamment les adresses, les appartenances aux listes, les statuts d’inscription, les informations de consentement et le suivi des campagnes. Lors d’un envoi, WordPress transmet le message et l’adresse au transport de messagerie configuré par l’exploitant. Le lien personnel de désinscription retire le contact des newsletters du site.

= Dois-je installer WooCommerce ou une extension SMTP ? =
WooCommerce sert aux fonctions boutique. Vous pouvez utiliser les autres rubriques indépendamment. Les newsletters appellent wp_mail() et utilisent le transport configuré sur votre installation.

= Comment insérer les informations et les formulaires ? =
Insérez [dse_text field="phone"] dans un bloc Code court pour un texte partagé, ou [dse_newsletter_inscription liste="1"] pour une liste de newsletter autorisée.

= Puis-je modifier les mises en page Divi ? =
Cette version protège les contenus reconnus comme utilisant le constructeur. Elle ne propose pas d’édition native des mises en page Divi.

== Changelog ==

= 0.0.1 =
* Espace d’édition guidée, rôle dédié, médiathèque partagée et autorisations de contenu.
* Modules facultatifs pour les événements, les produits et les newsletters.
