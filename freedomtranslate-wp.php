<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) or remote URL with API + cache and language selection
Version: 1.4.1
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
        $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
        if (in_array($lang, $enabled, true)) {
            return $lang;
        }
    } elseif (isset($_COOKIE['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_COOKIE['freedomtranslate_lang']));
        $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
        if (in_array($lang, $enabled, true)) {
            return $lang;
        }
    }

    $browser_lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 2) : 'en';
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    return in_array($browser_lang, $enabled, true) ? $browser_lang : 'en';
}

/**
 * Set language cookie on init hook securely
 */
add_action('init', function() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
        if (in_array($lang, $enabled, true)) {
            setcookie('freedomtranslate_lang', $lang, time() + 3600 * 24 * 30, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }
});

/**
 * List all supported languages
 */
function freedomtranslate_get_all_languages() {
    return [
        'ar' => 'Arabic','az' => 'Azerbaijani','zh' => 'Chinese','cs' => 'Czech','da' => 'Danish','nl' => 'Dutch','en' => 'English',
        'fi' => 'Finnish','fr' => 'Français','de' => 'Deutsch','el' => 'Greek','he' => 'Hebrew','hi' => 'Hindi','hu' => 'Hungarian',
        'id' => 'Indonesian','ga' => 'Irish','it' => 'Italiano','ja' => 'Japanese','ko' => 'Korean','no' => 'Norwegian','pl' => 'Polish',
        'pt' => 'Português','ro' => 'Romanian','ru' => 'Русский','sk' => 'Slovak','es' => 'Español','sv' => 'Swedish','tr' => 'Turkish',
        'uk' => 'Ukrainian','vi' => 'Vietnamese'
    ];
}

/**
 * Language selector shortcode
 */
function freedomtranslate_language_selector_shortcode() {
    $all_languages = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $current = freedomtranslate_get_user_lang();

    $html = '<form method="get" id="freedomtranslate_lang_form"><select name="freedomtranslate_lang" onchange="this.form.submit()">';
    foreach ($all_languages as $code => $label) {
        if (!in_array($code, $enabled_languages, true)) continue;
        $selected = ($code === $current) ? 'selected' : '';
        $html .= '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($label) . '</option>';
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
                $placeholder = substr(md5($word), 0, 8);
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
            $placeholder = substr(md5($word), 0, 8);
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
function freedomtranslate_admin_menu() {
    add_options_page('FreedomTranslate', 'FreedomTranslate', 'manage_options', 'freedomtranslate_freedomtranslate', 'freedomtranslate_admin_page');
}
add_action('admin_menu', 'freedomtranslate_admin_menu');

/**
 * Admin page callback with sanitization, validation and nonce check
 */
function freedomtranslate_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html(__('You do not have sufficient permissions to access this page.', 'freedomtranslate-wp')));
    }

    if (isset($_POST['_wpnonce']) && !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'freedomtranslate_admin_save')) {
        echo '<div class="error"><p>' . esc_html__('Security check failed. Please try again.', 'freedomtranslate-wp') . '</p></div>';
    } else {
        if (isset($_POST['freedomtranslate_clear_cache'])) {
            global $wpdb;
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '" . esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX) . "%'");
            echo '<div class="updated"><p>' . esc_html__('Cache purged.', 'freedomtranslate-wp') . '</p></div>';
        }

        if (isset($_POST['freedomtranslate_save_languages'], $_POST['freedomtranslate_languages']) && is_array($_POST['freedomtranslate_languages'])) {
            $languages = array_map('sanitize_text_field', wp_unslash($_POST['freedomtranslate_languages']));
            update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $languages);
            echo '<div class="updated"><p>' . esc_html__('Languages saved.', 'freedomtranslate-wp') . '</p></div>';
        }

        if (isset($_POST['freedomtranslate_save_excluded_words'], $_POST['freedomtranslate_excluded_words'])) {
            $words_raw = sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_excluded_words']));
            $words = preg_split("/\r\n|\n|\r/", $words_raw);
            $words = array_map('trim', array_filter($words));
            update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
            echo '<div class="updated"><p>' . esc_html__('Excluded words saved.', 'freedomtranslate-wp') . '</p></div>';
        }

        if (isset($_POST['freedomtranslate_save_api_url'], $_POST['freedomtranslate_api_url'])) {
            $url = trim(sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_url'])));
            if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) {
                update_option(FREEDOMTRANSLATE_API_URL_OPTION, esc_url_raw($url));
                echo '<div class="updated"><p>' . esc_html__('API URL saved.', 'freedomtranslate-wp') . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html__('Invalid API URL. Please enter a valid http or https URL.', 'freedomtranslate-wp') . '</p></div>';
            }
        }

        if (isset($_POST['freedomtranslate_save_api_key'], $_POST['freedomtranslate_api_key'])) {
            $key = sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key']));
            update_option(FREEDOMTRANSLATE_API_KEY_OPTION, $key);
            echo '<div class="updated"><p>' . esc_html__('API Key saved.', 'freedomtranslate-wp') . '</p></div>';
        }
    }

    // Fetch current settings for form
    $all_languages = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    ?>
    <div class="wrap">
        <h2>FreedomTranslate Settings</h2>

        <form method="post" action="">
            <?php wp_nonce_field('freedomtranslate_admin_save'); ?>

            <h3>Enabled Languages</h3>
            <select multiple name="freedomtranslate_languages[]" style="height:200px; width:250px;">
                <?php foreach ($all_languages as $code => $label): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected(in_array($code, $enabled_languages, true)); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p><em>Hold CTRL (Windows) or CMD (Mac) to select multiple.</em></p>
            <p><input type="submit" name="freedomtranslate_save_languages" class="button button-primary" value="Save Languages" /></p>
        </form>

        <hr />

        <form method="post" action="">
            <?php wp_nonce_field('freedomtranslate_admin_save'); ?>

            <h3>Excluded Words</h3>
            <p>(separate words by new row)</p>
            <textarea name="freedomtranslate_excluded_words" style="width:300px; height:150px;"><?php echo esc_textarea(implode("\n", $excluded_words)); ?></textarea>
            <p><input type="submit" name="freedomtranslate_save_excluded_words" class="button button-primary" value="Save Exclusions" /></p>
        </form>

        <hr />

        <form method="post" action="">
            <?php wp_nonce_field('freedomtranslate_admin_save'); ?>

            <h3>FreedomTranslate API URL</h3>
            <input type="text" name="freedomtranslate_api_url" style="width: 400px;" value="<?php echo esc_attr($api_url); ?>" />
            <p><input type="submit" name="freedomtranslate_save_api_url" class="button button-primary" value="Save API URL" /></p>

            <hr />

            <h3>FreedomTranslate API Key (optional)</h3>
            <input type="text" name="freedomtranslate_api_key" style="width: 400px;" value="<?php echo esc_attr($api_key); ?>" />
            <p><input type="submit" name="freedomtranslate_save_api_key" class="button button-primary" value="Save API Key" /></p>
        </form>

        <hr />

        <h3>Translation Cache</h3>
        <form method="post" action="">
            <?php wp_nonce_field('freedomtranslate_admin_save'); ?>
            <input type="submit" name="freedomtranslate_clear_cache" class="button button-secondary" value="Clear Translation Cache" />
        </form>

    </div>
    <?php
}

/**
 * Add checkbox meta box on post/page editor to exclude translation
 */
add_action('add_meta_boxes', function() {
    add_meta_box('freedomtranslate_exclude_meta', 'FreedomTranslate', function($post) {
        $value = get_post_meta($post->ID, '_freedomtranslate_exclude', true);
        ?>
        <?php wp_nonce_field('freedomtranslate_meta_box', 'freedomtranslate_meta_nonce'); ?>
        <label>
            <input type="checkbox" name="freedomtranslate_exclude" value="1" <?php checked($value, '1'); ?> />
            <?php echo esc_html__('Exclude this page/post from the automatic translation', 'freedomtranslate-wp'); ?>
        </label>
        <?php
    }, ['post', 'page'], 'side');
});

/**
 * Save meta box data securely
 */
add_action('save_post', function($post_id) {
    // Verify nonce
    if (
        !isset($_POST['freedomtranslate_meta_nonce']) ||
        !wp_verify_nonce(wp_unslash($_POST['freedomtranslate_meta_nonce']), 'freedomtranslate_meta_box')
    ) {
        return;
    }

    // Avoid autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // Check permission
    if (!current_user_can('edit_post', $post_id)) return;

    // Save or delete meta
    if (isset($_POST['freedomtranslate_exclude'])) {
        update_post_meta($post_id, '_freedomtranslate_exclude', sanitize_text_field(wp_unslash($_POST['freedomtranslate_exclude'])));
    } else {
        delete_post_meta($post_id, '_freedomtranslate_exclude');
    }
});
?>
