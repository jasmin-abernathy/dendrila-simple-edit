# Modules délégués — matrice des autorisations et étapes

Date : 10 octobre 2026. Source de vérité : branche de développement du plugin, et non installation en production.

## Périmètre et sécurité

- La page « Modifier mon site », les shortcodes, les champs ACF, les images autorisées et la révision des champs existants restent inchangés.
- Chaque module doit être **activé par l'administrateur**, puis chacune de ses actions attribuée **à un compte identifié** ayant déjà la capacité dendrila_site_manager correspondante (dse_access).
- Les autorisations de modules sont enregistrées dans l'option WordPress dse_modules, jamais dans les capacités natives du rôle. La modification de cette option exige manage_options et un nonce WordPress.
- Aucun droit natif WooCommerce ou MailPoet, aucun accès aux commandes, contacts, clients, abonnés, moyen de paiement ou fichier du site n'est ajouté au rôle.
- Le contrôleur de chaque action vérifie côté serveur le module, la permission individuelle, le nonce, le type d'objet, le statut et la version du contenu.
- Seules les **opérations explicitement implémentées** figurent dans le registre ; l'envoi et la programmation de campagnes ne peuvent pas être rendus accessibles en injectant des valeurs dans l'option de configuration.

## Matrice opérationnelle

| Module | Clé | Action | Statut |
| --- | --- | --- | --- |
| Newsletters | create | Créer un brouillon interne | Implémenté lot 1 |
| Newsletters | edit | Modifier un brouillon interne | Implémenté lot 1 |
| Newsletters | review | Soumettre un brouillon à validation | Implémenté lot 1 |
| Newsletters | approve | Valider un brouillon soumis (sans envoyer) | Implémenté lot 1 |
| Newsletters | schedule | Programmer dans MailPoet / autre prestataire | **Non disponible** |
| Newsletters | send | Envoyer une campagne | **Non disponible** |
| Newsletters | contacts | Consulter ou modifier des abonnés/listes | **Non disponible** |
| WooCommerce | create | Créer un produit simple brouillon | Implémenté lot 2 |
| WooCommerce | content | Modifier nom, descriptions | Implémenté lot 2 |
| WooCommerce | images | Sélectionner une image existante et approuvée | Implémenté lot 2 |
| WooCommerce | categories | Assigner des catégories existantes | Implémenté lot 2 |
| WooCommerce | prices | Modifier le prix régulier | Implémenté lot 2 |
| WooCommerce | stock | Modifier la quantité gérée | Implémenté lot 2 |
| WooCommerce | live | Débloquer l'édition d'un produit publié (en plus du droit par champ) | Implémenté lot 2 |
| WooCommerce | publish | Publier un nouveau produit | **Non disponible** |
| WooCommerce | variants | Modifier des variantes / prix par variante | **Non disponible** |
| WooCommerce | orders, customers, payments | Données commerciales sensibles | **Non disponible** |

Un droit « edit » pour les newsletters n'accorde pas « approve » ; un droit « prices » pour un produit n'accorde pas « stock », et « live » seul ne permet aucune modification de champ.

## Newsletter — lot 1

- Type de contenu interne WordPress dse_newsletter, non public, non exposé dans l'API REST et sans écran d'édition natif.
- Objet et corps en **texte simple**. Les brouillons sont conservés dans les tables standard WordPress ; ce ne sont pas des campagnes MailPoet.
- Fonctionnalités : créer, modifier, prévisualiser, demander une validation, approuver un contenu précis avec une empreinte, invalider l'approbation après édition.
- Le texte validé reste un document éditorial **sans destinataire et sans expédition**.
- Tout modèle HTML avancé, diffusion, analyse des destinataires et choix des listes requièrent une nouvelle phase de développement.
- Les personnes pouvant modifier les brouillons du module ont accès à l'ensemble des brouillons privés de **ce module**, pas aux listes MailPoet.

### Intégration MailPoet et autres solutions

MailPoet recommande son API publique v1 pour **listes, abonnés et données associées** et précise que les classes internes sont susceptibles de changer sans garantie de compatibilité. Son processus d'envoi est disponible dans son interface native, mais il n'est pas exposé comme API publique documentée pour créer et envoyer des newsletters.

**Décision** : ne jamais utiliser de classes internes MailPoet, de table SQL privée, de route interne ou d'élévation artificielle de droits. Le contenu préparé pourra être transféré par un administrateur vers MailPoet tant qu'un adaptateur officiellement pris en charge n'est pas identifié. Aucune donnée d'abonné n'est lue.

The Newsletter Plugin offre une API REST v2 avec certaines opérations de lecture des newsletters et des abonnés, mais la présence d'un endpoint de rédaction/envoi sûr, ses permissions granulaires et le besoin d'un addon doivent être validés avant intégration.

Brevo et d'autres prestataires disposent d'API distantes ; elles nécessitent une revue des jetons, du périmètre des listes et des fonctions de programmation/envoi, ainsi qu'une validation des flux RGPD.

### Étapes suivantes newsletters

1. Essai sur un WordPress isolé des rôles, du brouillon privé, de la validation et de l'absence d'accès à MailPoet.
2. Choisir un fournisseur qui expose **officiellement** une API stable de création/prévisualisation, de programmation et d'envoi avec portée limitée.
3. Ajouter des contrôles séparés schedule et send uniquement à cette étape, avec confirmation explicite, journal des actions et contrôle indépendant des destinataires par l'administrateur.
4. Tester un transport de préproduction désactivé (boîte factice) avant tout envoi réel.

## WooCommerce — étapes

Privilégier wc_get_products, wc_get_product et les objets WC_Product, jamais une écriture directe des tables/meta WooCommerce. Ne pas contourner les autorisations par manage_woocommerce. Tester les hooks de publication, de cache, de calcul de taxes et d'inventaire dans un véritable WooCommerce.

1. Créer et modifier des produits **simples** et brouillons via API CRUD ; initialement éviter les produits complexes.
2. Séparer images, catégories, tarifs et stocks, verrouiller les champs non autorisés et la mutation de produits déjà publiés.
3. Ajouter révision et validation avant publication si un workflow éditorial de produits existants est demandé.
4. Tester les variations, prix promotionnels, téléchargement, multidevise, gestion externe de stock et produits composites dans un lot séparé.
5. Vérifier que ni les commandes, ni les comptes clients, ni la configuration de paiement ne sont accessibles au rôle.

### Implémentation effective lot 2

- Actif uniquement si WooCommerce est chargé **et** si le module est activé. Aucun accès aux commandes, clients ou réglages WooCommerce n’est exposé.
- Les créations passent par WC_Product_Simple() + save() et restent toujours en statut draft. L'administrateur publie via l'interface WooCommerce habituelle.
- Les modifications se limitent aux produits de type exact simple, en statut draft/pending/publish, chargés par wc_get_product. Les fiches déjà publiées exigent le droit live **en plus du droit du champ**. Le contenu modifié d'un produit publié est visible immédiatement ; un workflow d'approbation est à construire.
- Les champs permis sont les noms, textes (texte simple), image choisie dans la liste Dendrila pré-approuvée, catégories déjà créées, prix régulier et quantité de stock. La liste de catégories est bornée dans l'interface. Une catégorie absente de l'écran peut toutefois être maintenue si elle n'est pas explicitement changée.
- Stock uniquement si manage_stock est **déjà actif** : aucune activation automatique, aucune modification des règles de commandes en attente, de taxes, promotions, attributs ou variations. Une variation n'est jamais modifiée.
- Prix régulier : uniquement si aucun prix promotionnel n'est défini ; l'objet WooCommerce met à jour le prix actif en même temps. Les promotions restent réservées à l'administration WooCommerce.
- Stocks : à la modification de la quantité, la disponibilité est synchronisée (0 sans commandes en attente = rupture ; 0 avec commandes en attente autorisées = commande en attente ; positif = disponible). Le mode et les autorisations de commandes en attente ne sont jamais modifiés.
- Catégories : si plus de 50 catégories existent ou si la liste échoue, le formulaire de catégorie est masqué pour éviter de perdre les catégories non affichées. L'administration WooCommerce reste utilisable.
- Les lectures des champs utilisent le contexte WooCommerce edit pour ne pas reprendre de valeurs filtrées destinées à l'affichage.
- Le formulaire porte une empreinte de la fiche ; si une modification est détectée au chargement, le POST est refusé. Cette vérification ne remplace pas un verrou atomique contre un éditeur WooCommerce concurrent.
- Les champs HTML anciens non touchés restent préservés à l'identique lorsqu'un autre champ est enregistré ; l'éditeur du module n'autorise pas à ajouter du HTML.
- Un champ dont la valeur est inchangée (y compris prix, stock, catégorie et image) n'est pas réécrit lors de l'enregistrement d'un autre champ. Cette idempotence évite de déclencher inutilement des hooks WooCommerce et protège les promotions et réglages tiers.

### Risques restant à tester dans un WooCommerce réel

Contrôle des hooks de recalcul de prix, état du stock après modification, catalogue et cache, compatibilité HPOS, extension de gestion de stocks, produits virtuels/téléchargeables, attributs complexes, multi-devises, produits composites et validation d'une modification publique.

## Références techniques

- https://github.com/mailpoet/mailpoet/blob/trunk/doc/Readme.md
- https://kb.mailpoet.com/article/344-create-a-standard-newsletter
- https://www.thenewsletterplugin.com/documentation/developers/newsletter-api-2/
- https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects
- https://developer.woocommerce.com/docs/features/products/wc-get-products/

## Contrôles attendus

- Par défaut, aucun module activé ; un rôle Responsable du site ne devient pas gestionnaire de newsletters ou produits sans attribution ciblée.
- Aucune combinaison d'actions ne donne un accès à send/schedule ou aux données personnelles des abonnés.
- Le contrôle d'autorisation s'applique à une requête POST directe, pas seulement à l'affichage du bouton.
- Une révision périmée ne doit jamais écraser un brouillon plus récent.
- Une approbation éditoriale ne publie pas un post public, ne planifie pas d'envoi et ne déclenche aucun e-mail.
- Le passage de PHP 7.4 à 8.4 et la désactivation des plugins tiers ne doivent pas produire d'erreur fatale.
