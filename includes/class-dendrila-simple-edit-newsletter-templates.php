<?php
/** Four real mail layouts, each with a separate editable palette. */
if (!defined('ABSPATH')) { exit; }

final class Dendrila_Simple_Edit_Newsletter_Templates
{
    const META = '_dendrila_simple_edit_newsletter_template';
    const DEFAULT_OPTION = 'dendrila_simple_edit_newsletter_default_template';

    public static function layouts()
    {
        return array('classique'=>__('Classique — en-tête, contenu, bouton', 'dendrila-simple-edit'),
            'magazine'=>__('Magazine — une grande ouverture et des rubriques', 'dendrila-simple-edit'),
            'editorial'=>__('Éditorial — lettre aérée et typographie soignée', 'dendrila-simple-edit'),
            'catalogue'=>__('Catalogue — fiches en grille de deux colonnes', 'dendrila-simple-edit'));
    }

    public static function defaults()
    {
        return array('layout'=>'classique','background'=>'#f1f5f1','paper'=>'#ffffff',
            'accent'=>'#3f9146','ink'=>'#293a30','button'=>'#24543b');
    }

    public static function clean($raw)
    {
        $settings = self::defaults();
        if (!is_array($raw)) { return $settings; }
        if (isset(self::layouts()[$raw['layout'] ?? ''])) { $settings['layout'] = $raw['layout']; }
        foreach (array('background','paper','accent','ink','button') as $key) {
            $candidate = isset($raw[$key]) && is_string($raw[$key]) ? $raw[$key] : '';
            if (preg_match('/^#[0-9a-f]{6}$/i', $candidate)) { $settings[$key] = strtolower($candidate); }
        }
        return $settings;
    }

    public static function for_user($user_id = null)
    {
        $id = $user_id ?: get_current_user_id();
        return self::clean($id ? get_user_meta($id,self::DEFAULT_OPTION,true) : array());
    }

    public static function for_post($post)
    {
        $id = is_object($post) ? $post->ID : (int)$post;
        $stored = get_post_meta($id,self::META,true);
        return is_array($stored) ? self::clean($stored) : self::defaults();
    }

    public static function save($post_id, $input, $default = false)
    {
        $settings = self::clean($input);
        update_post_meta($post_id,self::META,$settings);
        if ($default) { update_user_meta(get_current_user_id(),self::DEFAULT_OPTION,$settings); }
    }

    public static function form($post)
    {
        $creating = !$post;
        $settings = $creating ? self::for_user() : self::for_post($post);
        echo '<section class="dendrila-simple-edit-template-step" data-dendrila-simple-edit-template-step>'; 
        if ($creating) {
            echo '<h3>' . esc_html__('1. Choisissez la mise en page', 'dendrila-simple-edit') . '</h3><p>' . esc_html__('Quatre structures différentes. Votre dernier modèle choisi est présélectionné.', 'dendrila-simple-edit') . '</p>';
        } else {
            echo '<details class="dendrila-simple-edit-template-existing"><summary>' . esc_html__('Changer la mise en page et les couleurs de ce brouillon', 'dendrila-simple-edit') . '</summary>';
        }
        echo '<div class="dendrila-simple-edit-template-grid" role="radiogroup" aria-label="' . esc_attr__('Modèle de newsletter', 'dendrila-simple-edit') . '">';
        foreach (self::layouts() as $id=>$label) {
            echo '<label class="dendrila-simple-edit-template-card">';
            echo '<input type="radio" name="dendrila_simple_edit_template[layout]" value="'.esc_attr($id).'" '.checked($settings['layout'],$id,false).'>';
            echo '<span class="dendrila-simple-edit-template-thumb is-'.esc_attr($id).'" aria-hidden="true"><span></span><span></span><span></span><span></span></span>';
            echo '<strong>'.esc_html($label).'</strong></label>';
        }
        echo '</div><h3>' . esc_html__('2. Personnalisez les couleurs du modèle', 'dendrila-simple-edit') . '</h3>';
        echo '<div class="dendrila-simple-edit-template-colors">';
        foreach (array('background'=>__('Fond extérieur', 'dendrila-simple-edit'),'paper'=>__('Fond du contenu', 'dendrila-simple-edit'),
            'accent'=>__('Couleur d’accent', 'dendrila-simple-edit'),'ink'=>__('Texte', 'dendrila-simple-edit'),'button'=>__('Boutons', 'dendrila-simple-edit')) as $key=>$label) {
            echo '<label>'.esc_html($label).' <input type="color" name="dendrila_simple_edit_template['.esc_attr($key).']" value="'.
                esc_attr($settings[$key]).'"></label>';
        }
        echo '</div><p class="description">' . esc_html__('Les couleurs des éléments individuels peuvent ensuite être personnalisées séparément.', 'dendrila-simple-edit') . '</p>';
        if (!$creating) {
            echo '<label class="dendrila-simple-edit-check"><input type="checkbox" name="dendrila_simple_edit_template_set_default" value="1"> Utiliser aussi ce modèle par défaut pour mes prochaines newsletters</label>';
            echo '</details>';
        } else {
            echo '<button type="button" class="button button-primary" data-dendrila-simple-edit-template-next>' . esc_html__('Continuer vers la rédaction', 'dendrila-simple-edit') . '</button>';
        }
        echo '</section>';
    }
}
