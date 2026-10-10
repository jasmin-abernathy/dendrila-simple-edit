# Constructeurs et ACF — guide d'intégration (socle 0.1.x)

## Stratégie
Le contenu est modifié **sans réécrire les données de mise en page**. Deux chemins sont disponibles :
1. Zones Dendrila dont les données restent dans les options WordPress, affichées là où l'administrateur place un shortcode.
2. Champs ACF existants d'un contenu public, autorisés un par un et édités via l'API ACF.

L’interface propose un diagnostic non destructif. Ce n'est ni un scanner exhaustif du site ni une confirmation automatique du rendu public.

## Gutenberg
- Placer un bloc « Code court » contenant le shortcode exact, par exemple [dendrila_simple_edit field="zone_intro"].
- Le texte reste indépendant de la mise en page.
- L'édition ciblée de blocs paragraphe/titre/image existants est prévue mais **non activée**.
- Les blocs synchronisés, motifs et modèles de thème nécessitent un contrôle de propagation séparé.

## Elementor
- Préférence : utiliser le widget natif **Shortcode** et y placer le code de la zone Dendrila.
- Le plugin repère, en lecture seule, les widgets dont settings.shortcode est **exactement** le shortcode attendu dans _elementor_data des pages/articles publics.
- Un texte similaire trouvé dans un widget HTML ou une métadonnée arbitraire ne vaut pas preuve d'utilisation.
- Le plugin n'effectue aucun remplacement dans _elementor_data, n'altère ni settings, ni elements, ni CSS, ni les IDs de widgets.
- Les modèles Elementor, les popups, les contenus dynamiques Elementor Pro et les widgets text-editor ne sont pas encore couverts par le repérage.
- L’API de Dynamic Tags Elementor Pro pourrait servir plus tard ; la variante gratuite ne doit pas dépendre de Pro.

## Divi
- Divi 4 peut enregistrer une structure de shortcodes ET de modules, dont et_pb_text.
- Divi 5 utilise aussi des structures natives de blocs avec attributs propres ; le format ne doit pas être traité comme de simples chaînes Divi 4.
- Sur les deux générations, utiliser seulement un module compatible avec les shortcodes dont le rendu public a été vérifié dans la version du site.
- Le plugin ne réécrit pas les modules Divi à ce stade. Les blocs, attributs imbriqués, classes, global presets et modèles restent inchangés.
- Un test différentiel avant/après sauvegarde sur un site Divi 4 et un site Divi 5 est obligatoire avant d'introduire une écriture ciblée.

## Champs personnalisés ACF
- Optionnel : ACF doit être actif. Dendrila Simple Edit n'impose pas ACF à tous les sites.
- L'administrateur sélectionne l'un des derniers contenus publics et choisit parmi ses champs simples déjà renseignés ; il peut aussi fournir un ID d'article/page/CPT **public publié, non protégé par mot de passe** et une clé de champ existant commençant par field_.
- Le champ doit être directement rattaché à un groupe de champs ; les champs enfants de répéteurs et structures imbriquées sont refusés.
- Types de champs admis : text, textarea, email, url. Les valeurs sont manipulées sans rendu HTML et revérifiées côté serveur.
- Aucun champ n'est proposé automatiquement à l'ensemble des utilisateurs ; seules les associations autorisées sont disponibles dans l'espace.
- Les utilisateurs avec le seul rôle Responsable du site n'obtiennent aucun droit edit_posts, edit_pages, edit_others_posts ou edit_post_meta.
- Un administrateur peut révoquer une association ; la valeur enregistrée demeure dans ACF.
- Chaque formulaire contient un jeton WordPress et l'empreinte de la valeur initiale : un changement intervenu entre ouverture et enregistrement est refusé. La mise à jour ACF n'est **pas atomique** face à une mutation simultanée provenant d'un autre éditeur : vérifier sur pilote.
- Les hooks dse_acf_field_updated(post_id, field_key) et dse_fields_updated(changed_keys, revision) permettent de connecter la purge du cache externe.
- Ni historique ACF, ni restauration ACF, ni validation avant publication pour le moment ; ces fonctionnalités doivent être ajoutées avant de présenter le flux ACF comme complet.

## Images
L’administrateur choisit des images existantes et renseigne leurs identifiants dans « Images autorisées » (60 maximum). La zone « Photo principale » devient configurable comme les champs de texte. Le Responsable du site ne voit que les images approuvées et peut sélectionner une photo ; l’ancienne image reste publiée si sa référence est ensuite retirée de la liste. La bibliothèque complète, l’import de fichiers, les images ACF, le traitement de formats et la suppression restent réservés à des lots ultérieurs.

## Cas actuellement non pris en charge
- Options globales ACF, profils utilisateurs, taxonomies, blocs ACF, écrans d’administration d’autres plugins.
- Répéteurs, Flexible Content, Group, Clone, Relation, Post Object, Image, Gallery, WYSIWYG et contenus HTML.
- Contenus en brouillon, privés ou protégés par mot de passe.
- Mutation automatique de structures Gutenberg, Divi 4/5 et Elementor.

## Vérification sur pilote (à réaliser)
1. Créer un site WordPress de test (pas de production) et installer les constructeurs réellement utilisés.
2. Avec compte administrateur, relier un shortcode à une page, puis créer une association ACF simple sur une page publique.
3. Attribuer le rôle Responsable du site à un utilisateur **sans** autre rôle privilégié.
4. Modifier uniquement ces données depuis Modifier mon site. Vérifier le rendu public, l’enregistrement du champ réel ACF, le cache et les protections.
5. Tenter un POST vers un champ hors liste, un post privé, un champ ACF imbriqué, une ancienne empreinte. Chaque tentative doit échouer.
6. Contrôler que les données Divi/Elementor, la structure et les styles sont identiques après édition.
7. Refaire sur les versions Divi 4, Divi 5, Elementor et ACF présentes chez les utilisateurs pilotes.

## Sources techniques
- https://www.advancedcustomfields.com/resources/get_field/
- https://www.advancedcustomfields.com/resources/update_field/
- https://www.advancedcustomfields.com/resources/get_field_object/
- https://elementor.com/help/shortcode-widget/
- https://developers.elementor.com/docs/dynamic-tags/
- https://dev.elegantthemes.com/docs/

Les fichiers d’export originaux des conversations précédentes n’étaient pas présents parmi les fichiers accessibles à ce chantier. Aucune compatibilité binaire d'un ancien export n'est donc affirmée.
