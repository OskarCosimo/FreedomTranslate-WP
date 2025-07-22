<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) or remote URL with API + cache and language selection
Version: 1.4.3
Author: thefreedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://github.com/OskarCosimo/FreedomTranslate-WP
Requires at least: 5.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit; // Block direct access

// Prefix: freedomtranslate_

define('FREEDOMTRANSLATE_CACHE_PREFIX', 'freedomtranslate_cache_');
define('FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION', 'freedomtranslate_exclude_words');
define('FREEDOMTRANSLATE_LANGUAGES_OPTION', 'freedomtranslate_enabled_languages');
define('FREEDOMTRANSLATE_API_URL_OPTION', 'freedomtranslate_api_url');
define('FREEDOMTRANSLATE_API_KEY_OPTION', 'freedomtranslate_api_key');
define('FREEDOMTRANSLATE_API_URL_DEFAULT', 'http://localhost:5000/translate');

/**
 * Check if a target language is enabled
 */
function freedomtranslate_is_language_enabled($lang_code) {
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    return in_array($lang_code, $enabled, true);
}

/**
 * Get user language from sanitized GET, COOKIE, or browser header
 */
function freedomtranslate_get_user_lang() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) return $lang;
    }
    if (isset($_COOKIE['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_COOKIE['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) return $lang;
    }
    $browser = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 2)
        : 'en';
    return freedomtranslate_is_language_enabled($browser) ? $browser : 'en';
}

add_action('init', function() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            setcookie('freedomtranslate_lang', $lang, time() + DAY_IN_SECONDS * 30, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }
});

/**
 * List all supported languages
 */
function freedomtranslate_get_all_languages() {
    return [
        'ar'=>'Arabic','az'=>'Azerbaijani','zh'=>'Chinese','cs'=>'Czech','da'=>'Danish','nl'=>'Dutch',
        'en'=>'English','fi'=>'Finnish','fr'=>'Français','de'=>'Deutsch','el'=>'Greek','he'=>'Hebrew',
        'hi'=>'Hindi','hu'=>'Hungarian','id'=>'Indonesian','ga'=>'Irish','it'=>'Italiano','ja'=>'Japanese',
        'ko'=>'Korean','no'=>'Norwegian','pl'=>'Polish','pt'=>'Português','ro'=>'Romanian','ru'=>'Русский',
        'sk'=>'Slovak','es'=>'Español','sv'=>'Swedish','tr'=>'Turkish','uk'=>'Ukrainian','vi'=>'Vietnamese'
    ];
}

/**
 * Language selector shortcode
 */
function freedomtranslate_language_selector_shortcode() {
    $all = freedomtranslate_get_all_languages();
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all));
    $current = freedomtranslate_get_user_lang();
    $html = '<form method="get"><select name="freedomtranslate_lang" onchange="this.form.submit()">';
    foreach ($all as $code => $label) {
        if (!in_array($code, $enabled, true)) continue;
        $sel = selected($code, $current, false);
        $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($code), $sel, esc_html($label));
    }
    $html .= '</select></form>';
    return $html;
}
add_shortcode('freedomtranslate_selector', 'freedomtranslate_language_selector_shortcode');

/**
 * Protect excluded words inside HTML text nodes replacing them with placeholders
 */
function freedomtranslate_protect_excluded_words_in_html($html, $excluded_words) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $textNodes = $xpath->query('//text()');

    $placeholders = [];

    foreach ($textNodes as $textNode) {
        $text = $textNode->nodeValue;
        foreach ($excluded_words as $word) {
            $word = trim($word);
            if ($word === '') continue;

            // Word boundary, unicode-aware, case-insensitive
            $pattern = '/(?<!\p{L})' . preg_quote($word, '/') . '(?!\p{L})/ui';

            if (preg_match($pattern, $text)) {
                $placeholder = $placeholder = '[PH_' . strtoupper(substr(md5($word), 0, 8)) . ']';
                $text = preg_replace($pattern, $placeholder, $text);
                $placeholders[$placeholder] = $word;
            }
        }
        $textNode->nodeValue = $text;
    }

    $html = $dom->saveHTML();
    $html = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $html);

    return [$html, $placeholders];
}

/**
 * Restore excluded words from placeholders in translated text
 */
function freedomtranslate_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        $pattern = '/' . preg_quote($placeholder, '/') . '/i';
        // Ripristina esattamente la parola originale, senza alterazioni
        $text = preg_replace($pattern, $original_word, $text);
    }
    return $text;
}

/**
 * Perform translation with LibreTranslate and cache it
 */
function freedomtranslate_translate($text, $source, $target, $format = 'text') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !freedomtranslate_is_language_enabled($target)) return $text;

    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);

    if ($format === 'html' && !empty($excluded_words)) {
        list($text, $placeholders) = freedomtranslate_protect_excluded_words_in_html($text, $excluded_words);
    } else {
        $placeholders = [];
        foreach ($excluded_words as $word) {
            $word = trim($word);
            if ($word === '') continue;
            $placeholder = $placeholder = '[PH_' . strtoupper(substr(md5($word), 0, 8)) . ']';
            $pattern = '/\b' . preg_quote($word, '/') . '\b/ui';
            $text = preg_replace($pattern, $placeholder, $text);
            $placeholders[$placeholder] = $word;
        }
    }

    $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($text . $source . $target . $format);
    $cached = get_option($cache_key, false);
    if ($cached !== false) return $cached;

    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    $body = [
        'q' => $text,
        'source' => $source,
        'target' => $target,
        'format' => $format
    ];

    if (!empty($api_key)) {
        $body['api_key'] = $api_key;
    }

    $response = wp_remote_post($api_url, [
        'body' => $body,
        'timeout' => 120,
    ]);

    if (is_wp_error($response)) return $text;
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (!isset($json['translatedText'])) return $text;

    $translated = $json['translatedText'];

    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }

    update_option($cache_key, $translated);
    return $translated;
}

/**
 * Translate post content filter
 */
function freedomtranslate_filter_post_content($content) {
    if (is_admin()) return $content;
    global $post;
    if ($post && get_post_meta($post->ID, '_freedomtranslate_exclude', true) === '1') return $content;

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);

    $placeholder = '<freedomtranslate-selector></freedomtranslate-selector>';
    $content = str_replace('[freedomtranslate_selector]', $placeholder, $content);
    $translated = freedomtranslate_translate($content, $site_lang, $user_lang, 'html');
    $translated = str_replace($placeholder, '[freedomtranslate_selector]', $translated);

    return do_shortcode($translated);
}
add_filter('the_content', 'freedomtranslate_filter_post_content');

/**
 * Translate post title filter
 */
function freedomtranslate_filter_post_title($title) {
    if (is_admin()) return $title;
    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    return freedomtranslate_translate($title, $site_lang, $user_lang);
}
add_filter('the_title', 'freedomtranslate_filter_post_title');

/**
 * Translate gettext strings filter
 */
if (!function_exists('freedomtranslate_filter_gettext')) {
    function freedomtranslate_filter_gettext($translated_text, $text, $domain) {
        if (is_admin()) return $translated_text;
        $blocked_domains = ['zstore-manager-basic', 'woocommerce', 'default'];
        if (in_array($domain, $blocked_domains, true)) return $translated_text;
        if (preg_match('/%[\d\$\.\-\+]*[bcdeEfFgGosuxX]/', $translated_text)) return $translated_text;

        $user_lang = freedomtranslate_get_user_lang();
        $site_lang = substr(get_locale(), 0, 2);
        return freedomtranslate_translate($translated_text, $site_lang, $user_lang);
    }
}
add_filter('gettext', 'freedomtranslate_filter_gettext', 20, 3);

/**
 * Add admin menu page
 */
add_action('admin_menu','freedomtranslate_admin_menu');
function freedomtranslate_admin_menu(){
    add_options_page(
        __('FreedomTranslate','freedomtranslate-wp'),
        __('FreedomTranslate','freedomtranslate-wp'),
        'manage_options',
        'freedomtranslate',
        'freedomtranslate_admin_page'
    );
}
/**
 * Admin page callback with sanitization, validation and nonce check
 */
function freedomtranslate_admin_page(){
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'freedomtranslate-wp'));
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        ! isset($_POST['freedomtranslate_admin_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freedomtranslate_admin_nonce'])), 'freedomtranslate_admin_save')
    ) {
        wp_die(esc_html__('Security check failed. Please try again.', 'freedomtranslate-wp'));
    }

    if (isset($_POST['freedomtranslate_clear_cache'])) {
    global $wpdb;
    $prefix_esc = esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX);
    $option_names = $wpdb->get_col(
        $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix_esc . '%')
    );

    if (!empty($option_names)) {
        foreach ($option_names as $option_name) {
            delete_option($option_name);
            wp_cache_delete($option_name, 'options');
        }
    }

    echo '<div class="updated"><p>' . esc_html__('Cache purged.', 'freedomtranslate-wp') . '</p></div>';
}

    if (isset($_POST['freedomtranslate_save_languages'], $_POST['freedomtranslate_languages'])) {
        $langs = array_map('sanitize_text_field', wp_unslash($_POST['freedomtranslate_languages']));
        update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $langs);
        echo '<div class="updated"><p>'.esc_html__('Languages saved.', 'freedomtranslate-wp').'</p></div>';
    }
    if (isset($_POST['freedomtranslate_save_excluded_words'], $_POST['freedomtranslate_excluded_words'])) {
        $raw = sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_excluded_words']));
        $words = array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $raw)));
        update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
        echo '<div class="updated"><p>'.esc_html__('Excluded words saved.', 'freedomtranslate-wp').'</p></div>';
    }
    if (isset($_POST['freedomtranslate_save_api_url'], $_POST['freedomtranslate_api_url'])) {
        $url = trim(sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_url'])));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            update_option(FREEDOMTRANSLATE_API_URL_OPTION, esc_url_raw($url));
            echo '<div class="updated"><p>'.esc_html__('API URL saved.', 'freedomtranslate-wp').'</p></div>';
        } else {
            echo '<div class="error"><p>'.esc_html__('Invalid API URL.', 'freedomtranslate-wp').'</p></div>';
        }
    }
    if (isset($_POST['freedomtranslate_save_api_key'], $_POST['freedomtranslate_api_key'])) {
        $key = sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key']));
        update_option(FREEDOMTRANSLATE_API_KEY_OPTION, $key);
        echo '<div class="updated"><p>'.esc_html__('API Key saved.', 'freedomtranslate-wp').'</p></div>';
    }
}
    $all = freedomtranslate_get_all_languages();
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all));
    $excluded = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    echo '<div class="wrap"><h2>'.esc_html__('FreedomTranslate Settings','freedomtranslate-wp').'</h2>';

    echo '<form method="post" action="">';
    wp_nonce_field('freedomtranslate_admin_save','freedomtranslate_admin_nonce');

    // Languages multi-select
    echo '<h3>' . esc_html__('Enabled Languages', 'freedomtranslate-wp') . '</h3>';
    echo '<h3>' . esc_html__('Enabled Languages', 'freedomtranslate-wp') . '</h3>';
foreach ($all as $code => $label) {
    $is_checked = in_array($code, $enabled, true);
    ?>
    <label>
        <input type="checkbox" name="freedomtranslate_languages[]" value="<?php echo esc_attr($code); ?>" <?php checked($is_checked); ?> />
        <?php echo esc_html($label); ?>
    </label><br>
    <?php
}
echo '<p><input type="submit" name="freedomtranslate_save_languages" class="button button-primary" value="' . esc_attr__('Save Languages', 'freedomtranslate-wp') . '" /></p>';
    echo '</form><hr/>';

    // Excluded words textarea
    echo '<form method="post" action="">';
    wp_nonce_field('freedomtranslate_admin_save','freedomtranslate_admin_nonce');

    echo '<h3>' . esc_html__('Excluded Words (one per line)', 'freedomtranslate-wp') . '</h3>';
    echo '<textarea name="freedomtranslate_excluded_words" rows="6" cols="50">' . esc_textarea(implode("\n", $excluded)) . '</textarea><br>';
    echo '<p><input type="submit" name="freedomtranslate_save_excluded_words" class="button button-primary" value="' . esc_attr__('Save Excluded Words', 'freedomtranslate-wp') . '" /></p>';
    echo '</form><hr/>';

    // API URL field
    echo '<form method="post" action="">';
    wp_nonce_field('freedomtranslate_admin_save','freedomtranslate_admin_nonce');

    echo '<h3>' . esc_html__('API URL', 'freedomtranslate-wp') . '</h3>';
    echo '<input type="text" name="freedomtranslate_api_url" value="' . esc_attr($api_url) . '" size="50" /><br>';
    echo '<p><input type="submit" name="freedomtranslate_save_api_url" class="button button-primary" value="' . esc_attr__('Save API URL', 'freedomtranslate-wp') . '" /></p>';
    echo '</form><hr/>';

    // API Key field
    echo '<form method="post" action="">';
    wp_nonce_field('freedomtranslate_admin_save','freedomtranslate_admin_nonce');

    echo '<h3>' . esc_html__('API Key (optional)', 'freedomtranslate-wp') . '</h3>';
    echo '<input type="text" name="freedomtranslate_api_key" value="' . esc_attr($api_key) . '" size="50" /><br>';
    echo '<p><input type="submit" name="freedomtranslate_save_api_key" class="button button-primary" value="' . esc_attr__('Save API Key', 'freedomtranslate-wp') . '" /></p>';
    echo '</form><hr/>';

	echo '<h3>Shortcode</h3>';
	echo 'To view the selct box with available languages, use <b>[freedomtranslate_selector]</b>';
	echo '<hr/>';

    // Clear cache button
	echo '<h3>Clear the translation cache</h3>';
	echo 'Use this button to clear all the cache of the translations; warning: by doing so a new translation will be requested when the page is refreshed';
    echo '<form method="post" action="">';
    wp_nonce_field('freedomtranslate_admin_save','freedomtranslate_admin_nonce');
    echo '<input type="submit" name="freedomtranslate_clear_cache" class="button button-secondary" value="' . esc_attr__('Clear Translation Cache', 'freedomtranslate-wp') . '" />';
    echo '</form></div>';
}


//-- META BOX --//
add_action('add_meta_boxes', function(){
    add_meta_box('freedomtranslate_exclude_meta', __('FreedomTranslate','freedomtranslate-wp'),
        function($post){
            wp_nonce_field('freedomtranslate_meta_box','freedomtranslate_meta_nonce');
            $val = get_post_meta($post->ID,'_freedomtranslate_exclude',true);
            echo '<label><input type="checkbox" name="freedomtranslate_exclude" value="1" '
                .checked($val,'1',false).'/> '
                .esc_html__('Exclude this page/post from automatic translation','freedomtranslate-wp')
                .'</label>';
        }, ['post','page'],'side');
});

//-- SAVE POST META --//
add_action('save_post', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (
        ! isset($_POST['freedomtranslate_meta_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freedomtranslate_meta_nonce'])), 'freedomtranslate_meta_box')
    ) {
        return;
    }
    if (!current_user_can('edit_post',$post_id)) return;

    if (isset($_POST['freedomtranslate_exclude'])) {
        update_post_meta($post_id,'_freedomtranslate_exclude','1');
    } else {
        delete_post_meta($post_id,'_freedomtranslate_exclude');
    }
});
?>
