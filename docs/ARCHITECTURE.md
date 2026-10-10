# Architecture et frontières

## Origine
Dendrila CMS fournit les principes : schémas déclaratifs, permissions atomiques, refus par défaut, état versionné et projection limitée aux champs annoncés. Son cœur PHP 8.5 / PDO n’est **pas** importé dans un plugin WordPress compatible PHP 7.4.

## Stockage
- dse_enabled_fields : liste bornée d’identifiants de champs intégrés ou configurés. Configuration administrateur uniquement.
- dse_custom_fields : maximum 20 zones textuelles déclaratives (nom, type, identifiant généré), sans code exécutable ni mappage arbitraire vers une option WP.
- dse_state : une unique option WordPress (revision, values, history), autoload désactivé.
- Un UPDATE SQL atomique compare la sérialisation ancienne en égalité binaire pour éviter qu'une concurrence entre deux POST n'écrase une valeur sans avertissement. Après succès le cache d’options WP est invalidé et un événement dse_fields_updated est envoyé. Les caches HTML/CDN externes requièrent un raccordement spécifique ou une purge du site.
- Le public lit la valeur depuis WordPress au moyen du shortcode dendrila_simple_edit inséré volontairement par l’administrateur.
- Les champs désactivés ne sont plus éditables, mais les shortcodes existants peuvent toujours afficher les valeurs publiques déjà publiées.

## Permissions
Le rôle dendrila_site_manager est créé avec seulement read, dse_access, dse_edit, dse_publish. La configuration nécessite manage_options. Le POST requiert capability + nonce + allowlist ; pas de permissions edit_posts, edit_pages, upload_files ou edit_theme_options.

Il n’y a **pas** de restriction des autres rôles éventuellement cumulés ni de prétention à empêcher les super administrateurs WordPress d’agir.

## Parcours
Administrateur configure les champs → insère shortcodes dans le site → délègue le rôle → responsable modifie → état CAS+révision → rendu public via WP. Restauration compare la version courante et met à jour les seuls champs toujours autorisés.

## Tests et zones non couvertes
- Tests isolés pour les rôles, l’allowlist, l'assainissement, les conflits et la restauration.
- Installation pilote nécessaire pour vérifier activation/désactivation, REST natif, autres plugins accordant des droits, shortcodes des thèmes et mise en cache.
- Le premier adaptateur ACF peut modifier des champs textuels explicitement reliés à un contenu publié public ; il ne réécrit pas la structure du contenu WordPress. La vérification de conflit ACF repose sur une empreinte de valeur et n'est pas un verrou atomique contre un éditeur tiers concurrent.
- Elementor est inspecté en lecture seule pour détecter les widgets Shortcode canoniques. Aucune écriture dans les données Elementor ou Divi ; les blocs Gutenberg, les médias et les champs ACF complexes restent hors périmètre.
