<?php
/*
Plugin Name: LibreTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) + cache and language selection
Version: 1.1.2
Author: Freedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('ABSPATH')) exit; // Block direct access

define('LT_CACHE_OPTION', 'libretranslate_cache');

function lt_is_language_enabled($lang_code) {
    $enabled = get_option('libretranslate_enabled_languages', []);
    return in_array($lang_code, $enabled);
}

function lt_get_user_lang() {
    if (isset($_GET['lt_lang'])) {
        return $_GET['lt_lang'];
    } elseif (isset($_COOKIE['lt_lang'])) {
        return $_COOKIE['lt_lang'];
    }

    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
$enabled = get_option('libretranslate_enabled_languages', []);
return in_array($browser_lang, $enabled) ? $browser_lang : 'en';
}

add_action('init', function() {
    if (isset($_GET['lt_lang'])) {
        $lang = sanitize_text_field($_GET['lt_lang']);
        setcookie('lt_lang', $lang, time() + 3600 * 24 * 30, "/");
        $_COOKIE['lt_lang'] = $lang; // così lt_get_user_lang lo legge subito
    }
});

function lt_get_all_languages() {
    return [
        'ar' => 'Arabic',
        'az' => 'Azerbaijani',
        'zh' => 'Chinese',
        'cs' => 'Czech',
        'da' => 'Danish',
        'nl' => 'Dutch',
        'en' => 'English',
        'fi' => 'Finnish',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'el' => 'Greek',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'hu' => 'Hungarian',
        'id' => 'Indonesian',
        'ga' => 'Irish',
        'it' => 'Italiano',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'no' => 'Norwegian',
        'pl' => 'Polish',
        'pt' => 'Português',
        'ro' => 'Romanian',
        'ru' => 'Русский',
        'sk' => 'Slovak',
        'es' => 'Español',
        'sv' => 'Swedish',
        'tr' => 'Turkish',
        'uk' => 'Ukrainian',
        'vi' => 'Vietnamese'
    ];
}

function lt_language_selector_shortcode() {
    $all_languages = lt_get_all_languages();
    $enabled_languages = get_option('libretranslate_enabled_languages', array_keys($all_languages));
    $current = lt_get_user_lang();

    $html = '<form method="get" id="lt_lang_form"><select name="lt_lang" onchange="this.form.submit()">';

    foreach ($all_languages as $code => $label) {
        if (!in_array($code, $enabled_languages)) continue;
        $selected = ($code === $current) ? 'selected' : '';
        $html .= "<option value=\"{$code}\" {$selected}>{$label}</option>";
    }

    $html .= '</select></form>';
    return $html;
}

add_shortcode('libretranslate_selector', 'lt_language_selector_shortcode');

function lt_translate($text, $source, $target, $format = 'text') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !lt_is_language_enabled($target)) return $text;

    $cache_key = md5($text . $source . $target . $format);
    $cache = get_option(LT_CACHE_OPTION, []);
    if (isset($cache[$cache_key])) return $cache[$cache_key];

    $response = wp_remote_post('http://localhost:5000/translate', [
        'body' => [
            'q' => $text,
            'source' => $source,
            'target' => $target,
            'format' => $format
        ],
        'timeout' => 60
    ]);

    if (is_wp_error($response)) return $text;
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (!isset($json['translatedText'])) return $text;

    $translated = $json['translatedText'];
    $cache[$cache_key] = $translated;
    update_option(LT_CACHE_OPTION, $cache);

    return $translated;
}


function lt_filter_post_content($content) {
    if (is_admin()) return $content;

    global $post;
    if ($post && get_post_meta($post->ID, '_lt_exclude', true) === '1') {
        return $content;
    }

    $user_lang = lt_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);

    // preprocessing to not translate the shortcode
    $placeholder = '<lt-selector></lt-selector>';
    $content = str_replace('[libretranslate_selector]', $placeholder, $content);

    // translate with html
    $translated = lt_translate($content, $site_lang, $user_lang, 'html');

    // postprocessing to restore the original shortcode
    $translated = str_replace($placeholder, '[libretranslate_selector]', $translated);

    // run the shortcode
    return do_shortcode($translated);
}

add_filter('the_content', 'lt_filter_post_content');


function lt_filter_post_title($title) {
    if (is_admin()) return $title;
    $user_lang = lt_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    return lt_translate($title, $site_lang, $user_lang);
}
add_filter('the_title', 'lt_filter_post_title');

if (!function_exists('lt_filter_gettext')) {
    function lt_filter_gettext($translated_text, $text, $domain) {
        if (is_admin()) return $translated_text;

        $blocked_domains = ['zstore-manager-basic', 'woocommerce', 'default'];
        if (in_array($domain, $blocked_domains)) return $translated_text;

        if (preg_match('/%[\d\$\.\-\+]*[bcdeEfFgGosuxX]/', $translated_text)) {
            return $translated_text;
        }

        $user_lang = lt_get_user_lang();
        $site_lang = substr(get_locale(), 0, 2);

        return lt_translate($translated_text, $site_lang, $user_lang);
    }
}

add_filter('gettext', 'lt_filter_gettext', 20, 3);

function lt_admin_menu() {
    add_options_page('LibreTranslate', 'LibreTranslate', 'manage_options', 'libretranslate', 'lt_admin_page');
}
add_action('admin_menu', 'lt_admin_menu');

function lt_admin_page() {
    if (isset($_POST['lt_clear_cache'])) {
        delete_option(LT_CACHE_OPTION);
        echo '<div class="updated"><p>Cache purged.</p></div>';
    }

    if (isset($_POST['lt_save_languages']) && isset($_POST['lt_languages'])) {
        update_option('libretranslate_enabled_languages', array_map('sanitize_text_field', $_POST['lt_languages']));
        echo '<div class="updated"><p>Languages saved.</p></div>';
    }

    $all_languages = lt_get_all_languages();
    $enabled_languages = get_option('libretranslate_enabled_languages', array_keys($all_languages));
    ?>
    <div class="wrap">
        <h2>LibreTranslate Settings</h2>
        <form method="post">
            <h3>Lingue disponibili nel selettore</h3>
            <select multiple name="lt_languages[]" style="height:200px; width:250px;">
                <?php foreach ($all_languages as $code => $label): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected(in_array($code, $enabled_languages)); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p><em>Usa CTRL+clic per selezioni multiple.</em></p>
            <p><input type="submit" name="lt_save_languages" class="button button-primary" value="Save selected languages" /></p>
        </form>
        <hr />
        <form method="post">
            <input type="submit" name="lt_clear_cache" class="button button-secondary" value="Empty translation cache" />
        </form>
    </div>
    <?php
}


add_action('add_meta_boxes', function() {
    add_meta_box('lt_exclude_meta', 'LibreTranslate', function($post) {
        $value = get_post_meta($post->ID, '_lt_exclude', true);
        ?>
        <label><input type="checkbox" name="lt_exclude" value="1" <?php checked($value, '1'); ?> />
        Exclude this page/post from the automatic translation</label>
        <?php
    }, ['post', 'page'], 'side');
});

add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['lt_exclude'])) {
        update_post_meta($post_id, '_lt_exclude', '1');
    } else {
        delete_post_meta($post_id, '_lt_exclude');
    }
});
