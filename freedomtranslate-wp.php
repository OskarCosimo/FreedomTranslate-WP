<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) or remote URL with API + cache and language selection
Version: 1.4.1
Author: Freedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('ABSPATH')) exit; // Block direct access

define('LT_CACHE_PREFIX', 'freedomtranslate_cache_');
define('LT_WORDS_EXCLUDE_OPTION', 'freedomtranslate_exclude_words');
define('LT_LANGUAGES_OPTION', 'freedomtranslate_enabled_languages');
define('LT_API_URL_OPTION', 'freedomtranslate_api_url');
define('LT_API_KEY_OPTION', 'freedomtranslate_api_key');
define('LT_API_URL_DEFAULT', 'http://localhost:5000/translate');

// Check if a target language is enabled
function lt_is_language_enabled($lang_code) {
    $enabled = get_option(LT_LANGUAGES_OPTION, []);
    return in_array($lang_code, $enabled);
}

// Get user language from URL, cookie, or browser
function lt_get_user_lang() {
    if (isset($_GET['lt_lang'])) {
        return $_GET['lt_lang'];
    } elseif (isset($_COOKIE['lt_lang'])) {
        return $_COOKIE['lt_lang'];
    }

    $browser_lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : 'en';
    $enabled = get_option('freedomtranslate_enabled_languages', []);
    return in_array($browser_lang, $enabled) ? $browser_lang : 'en';
}

// Set language cookie
add_action('init', function() {
    if (isset($_GET['lt_lang'])) {
        $lang = sanitize_text_field($_GET['lt_lang']);
        setcookie('lt_lang', $lang, time() + 3600 * 24 * 30, "/");
        $_COOKIE['lt_lang'] = $lang;
    }
});

// List of all supported languages
function lt_get_all_languages() {
    return [
        'ar' => 'Arabic','az' => 'Azerbaijani','zh' => 'Chinese','cs' => 'Czech','da' => 'Danish','nl' => 'Dutch','en' => 'English',
        'fi' => 'Finnish','fr' => 'Français','de' => 'Deutsch','el' => 'Greek','he' => 'Hebrew','hi' => 'Hindi','hu' => 'Hungarian',
        'id' => 'Indonesian','ga' => 'Irish','it' => 'Italiano','ja' => 'Japanese','ko' => 'Korean','no' => 'Norwegian','pl' => 'Polish',
        'pt' => 'Português','ro' => 'Romanian','ru' => 'Русский','sk' => 'Slovak','es' => 'Español','sv' => 'Swedish','tr' => 'Turkish',
        'uk' => 'Ukrainian','vi' => 'Vietnamese'
    ];
}

// Language selector shortcode
function lt_language_selector_shortcode() {
    $all_languages = lt_get_all_languages();
    $enabled_languages = get_option(LT_LANGUAGES_OPTION, array_keys($all_languages));
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
add_shortcode('freedomtranslate_selector', 'lt_language_selector_shortcode');

// Protect excluded words inside HTML text nodes replacing them with placeholders
function lt_protect_excluded_words_in_html($html, $excluded_words) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $textNodes = $xpath->query('//text()');

    $placeholders = [];

    foreach ($textNodes as $textNode) {
        $text = $textNode->nodeValue;
        foreach ($excluded_words as $i => $word) {
            $word = trim($word);
            if ($word === '') continue;

            // Match word anywhere (not limited to \b)
            $pattern = '/(?<!\p{L})' . preg_quote($word, '/') . '(?!\p{L})/ui';

            if (preg_match($pattern, $text)) {
                $placeholder = 'LTEXCL_' . substr(md5($word), 0, 8);
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

function lt_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        // Sostituisci tutti i placeholder case-insensitive con la parola originale fissa così com'è
        $pattern = '/' . preg_quote($placeholder, '/') . '/i';
        $text = preg_replace($pattern, $original_word, $text);
    }
    return $text;
}

// Perform translation with freedomTranslate and cache it
function lt_translate($text, $source, $target, $format = 'text') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !lt_is_language_enabled($target)) return $text;

    $excluded_words = get_option(LT_WORDS_EXCLUDE_OPTION, []);

    if ($format === 'html' && !empty($excluded_words)) {
        list($text, $placeholders) = lt_protect_excluded_words_in_html($text, $excluded_words);
    } else {
        $placeholders = [];
        foreach ($excluded_words as $i => $word) {
            $word = trim($word);
            if ($word === '') continue;
            $placeholder = 'LTEXCL_' . substr(md5($word), 0, 8);
            $pattern = '/\b' . preg_quote($word, '/') . '\b/ui';
            $text = preg_replace($pattern, $placeholder, $text);
            $placeholders[$placeholder] = $word;
        }
    }

    $cache_key = 'lt_' . md5($text . $source . $target . $format);
    $cached = get_option($cache_key, false);
    if ($cached !== false) return $cached;

    $api_url = get_option(LT_API_URL_OPTION, LT_API_URL_DEFAULT);
    $api_key = get_option(LT_API_KEY_OPTION, '');

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
        'timeout' => 120
    ]);

    if (is_wp_error($response)) return $text;
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (!isset($json['translatedText'])) return $text;

    $translated = $json['translatedText'];

    if (!empty($placeholders)) {
        $translated = lt_restore_excluded_words_in_html($translated, $placeholders);
    }

    update_option($cache_key, $translated);
    return $translated;
}

// Translate post content
function lt_filter_post_content($content) {
    if (is_admin()) return $content;
    global $post;
    if ($post && get_post_meta($post->ID, '_lt_exclude', true) === '1') return $content;

    $user_lang = lt_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);

    $placeholder = '<lt-selector></lt-selector>';
    $content = str_replace('[freedomtranslate_selector]', $placeholder, $content);
    $translated = lt_translate($content, $site_lang, $user_lang, 'html');
    $translated = str_replace($placeholder, '[freedomtranslate_selector]', $translated);

    return do_shortcode($translated);
}
add_filter('the_content', 'lt_filter_post_content');

// Translate post titles
function lt_filter_post_title($title) {
    if (is_admin()) return $title;
    $user_lang = lt_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    return lt_translate($title, $site_lang, $user_lang);
}
add_filter('the_title', 'lt_filter_post_title');

// Translate gettext strings
if (!function_exists('lt_filter_gettext')) {
    function lt_filter_gettext($translated_text, $text, $domain) {
        if (is_admin()) return $translated_text;
        $blocked_domains = ['zstore-manager-basic', 'woocommerce', 'default'];
        if (in_array($domain, $blocked_domains)) return $translated_text;
        if (preg_match('/%[\d\$\.\-\+]*[bcdeEfFgGosuxX]/', $translated_text)) return $translated_text;

        $user_lang = lt_get_user_lang();
        $site_lang = substr(get_locale(), 0, 2);
        return lt_translate($translated_text, $site_lang, $user_lang);
    }
}
add_filter('gettext', 'lt_filter_gettext', 20, 3);

// Admin settings page
function lt_admin_menu() {
    add_options_page('FreedomTranslate', 'FreedomTranslate', 'manage_options', 'freedomtranslate', 'lt_admin_page');
}
add_action('admin_menu', 'lt_admin_menu');

function lt_admin_page() {
    if (isset($_POST['lt_clear_cache'])) {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'lt_%'");
    echo '<div class="updated"><p>Cache purged.</p></div>';
}

    if (isset($_POST['lt_save_languages']) && isset($_POST['lt_languages'])) {
        update_option(LT_LANGUAGES_OPTION, array_map('sanitize_text_field', $_POST['lt_languages']));
        echo '<div class="updated"><p>Languages saved.</p></div>';
    }

    if (isset($_POST['lt_save_excluded_words'])) {
        $words = explode("
", sanitize_textarea_field($_POST['lt_excluded_words']));
        $words = array_map('trim', array_filter($words));
        update_option(LT_WORDS_EXCLUDE_OPTION, $words);
        echo '<div class="updated"><p>Excluded words saved.</p></div>';
    }

    $all_languages = lt_get_all_languages();
    $enabled_languages = get_option(LT_LANGUAGES_OPTION, array_keys($all_languages));
    $excluded_words = get_option(LT_WORDS_EXCLUDE_OPTION, []);
    ?>
    <div class="wrap">
        <h2>FreedomTranslate Settings</h2>
        <form method="post">
            <h3>Enabled Languages</h3>
            <select multiple name="lt_languages[]" style="height:200px; width:250px;">
                <?php foreach ($all_languages as $code => $label): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected(in_array($code, $enabled_languages)); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p><em>Hold CTRL (Windows) or CMD (Mac) to select multiple.</em></p>
            <p><input type="submit" name="lt_save_languages" class="button button-primary" value="Save Languages" /></p>
        </form>
        <hr />
        <form method="post">
            <h3>Excluded Words</h3>(separate words by new row)<br>
            <textarea name="lt_excluded_words" style="width:300px; height:150px;"><?php echo esc_textarea(implode("
", $excluded_words)); ?></textarea>
            <p><input type="submit" name="lt_save_excluded_words" class="button button-primary" value="Save Exclusions" /></p>
        </form>
		
		<hr />
<form method="post">
    <h3>FreedomTranslate API URL</h3>
    <input type="text" name="lt_api_url" style="width: 400px;" value="<?php echo esc_attr(get_option(LT_API_URL_OPTION, LT_API_URL_DEFAULT)); ?>" />
    <p><input type="submit" name="lt_save_api_url" class="button button-primary" value="Save API URL" /></p>
<hr />
<h3>FreedomTranslate API Key (optional)</h3>
<input type="text" name="lt_api_key" style="width: 400px;" value="<?php echo esc_attr(get_option(LT_API_KEY_OPTION, '')) ?>" />
<p><input type="submit" name="lt_save_api_key" class="button button-primary" value="Save API Key" /></p>

</form>
		<?php
		if (isset($_POST['lt_save_api_url'])) {
    $url = trim($_POST['lt_api_url']);
if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) {
    update_option(LT_API_URL_OPTION, esc_url_raw($url));
    echo '<div class="updated"><p>API URL saved.</p></div>';
} else {
    echo '<div class="error"><p>Invalid API URL. Please enter a valid http or https URL.</p></div>';
}
}
if (isset($_POST['lt_save_api_key'])) {
    $key = sanitize_text_field($_POST['lt_api_key']);
    update_option(LT_API_KEY_OPTION, $key);
    echo '<div class="updated"><p>API Key saved.</p></div>';
}
		?>
        <hr />
		<h3>Translation Cache</h3>
        <form method="post">
            <input type="submit" name="lt_clear_cache" class="button button-secondary" value="Clear Translation Cache" />
        </form>
    </div>
    <?php
}

// Add checkbox to post edit screen to exclude page/post
add_action('add_meta_boxes', function() {
    add_meta_box('lt_exclude_meta', 'FreedomTranslate', function($post) {
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
