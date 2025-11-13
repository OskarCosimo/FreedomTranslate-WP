<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) or remote URL with API + cache and language selection
Version: 1.4.4
Author: thefreedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://github.com/OskarCosimo/FreedomTranslate-WP
Requires at least: 5.0
Requires PHP: 7.4
*/

defined('ABSPATH') or die('No script kiddies please!');

// Plugin constants
define('FREEDOMTRANSLATE_API_URL_OPTION', 'freedomtranslate_api_url');
define('FREEDOMTRANSLATE_API_URL_DEFAULT', 'http://localhost:5000/translate');
define('FREEDOMTRANSLATE_API_KEY_OPTION', 'freedomtranslate_api_key');
define('FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION', 'freedomtranslate_google_api_key');
define('FREEDOMTRANSLATE_LANGUAGES_OPTION', 'freedomtranslate_languages');
define('FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION', 'freedomtranslate_words_exclude');
define('FREEDOMTRANSLATE_CACHE_PREFIX', 'freedomtranslate_cache_');
define('FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION', 'freedomtranslate_service');
define('FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION', 'freedomtranslate_lang_detection_mode');
define('FREEDOMTRANSLATE_DEFAULT_LANG_OPTION', 'freedomtranslate_default_lang');

/**
 * Handle language selection from GET parameter and set cookie
 * This hook runs early to process language changes from URL
 */
add_action('init', function() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            setcookie('freedomtranslate_lang', $lang, time() + (DAY_IN_SECONDS * 30), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }
});

/**
 * Get user's language preference
 * Priority: GET parameter > Cookie > Detection mode (auto/manual)
 * 
 * @return string Language code (e.g., 'en', 'it', 'fr')
 */
function freedomtranslate_get_user_lang() {
    // Priority 1: GET parameter (for immediate language switching)
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            return $lang;
        }
    }
    
    // Priority 2: Cookie (user has previously selected a language)
    if (isset($_COOKIE['freedomtranslate_lang'])) {
        $lang = sanitize_text_field($_COOKIE['freedomtranslate_lang']);
        if (freedomtranslate_is_language_enabled($lang)) {
            return $lang;
        }
    }
    
    // Priority 3: Detection mode settings
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    
    if ($detection_mode === 'auto') {
        // Automatic: detect from browser
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browser = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 2);
            return freedomtranslate_is_language_enabled($browser) ? $browser : substr(get_locale(), 0, 2);
        }
    } else {
        // Manual: use admin-configured default
        $default_lang = get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, substr(get_locale(), 0, 2));
        return $default_lang;
    }
    
    // Fallback to site language
    return substr(get_locale(), 0, 2);
}

/**
 * Check if a language is enabled in plugin settings
 * 
 * @param string $lang Language code to check
 * @return bool True if language is enabled
 */
function freedomtranslate_is_language_enabled($lang) {
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys(freedomtranslate_get_all_languages()));
    return in_array($lang, $enabled, true);
}

/**
 * Get all supported languages
 * 
 * @return array Associative array of language codes and names
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
 * Language selector shortcode (using GET method like original)
 * User can always change language via this selector
 * Shows loading overlay when changing language
 * 
 * @return string HTML markup for language selector
 */
function freedomtranslate_language_selector_shortcode() {
    $all = freedomtranslate_get_all_languages();
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all));
    $current = freedomtranslate_get_user_lang();
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    
    $html = '<div class="freedomtranslate-selector-wrapper">';
    $html .= '<form method="get" id="freedomtranslate-form">';
    $html .= '<select name="freedomtranslate_lang" onchange="showTranslationLoader(); this.form.submit();">';
    
    foreach ($enabled as $code) {
        if (isset($all[$code])) {
            $selected = ($code === $current) ? 'selected' : '';
            $html .= sprintf('<option value="%s" %s>%s</option>', 
                esc_attr($code), 
                $selected, 
                esc_html($all[$code])
            );
        }
    }
    
    $html .= '</select>';
    
    // Preserve other GET parameters
    foreach ($_GET as $key => $value) {
        if ($key !== 'freedomtranslate_lang') {
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', 
                esc_attr($key), 
                esc_attr($value)
            );
        }
    }
    
    $html .= '</form>';
    
    // Mode indicator icon
    if ($detection_mode === 'manual') {
        $html .= '<span class="freedomtranslate-mode-indicator" title="Manual: initial language defined">📌</span>';
    } else {
        $html .= '<span class="freedomtranslate-mode-indicator" title="Automatic: browser-detected default">🌐</span>';
    }
    
    $html .= '</div>';
    
    // Loading overlay HTML
    $html .= '
    <div id="freedomtranslate-loader" style="display: none;">
        <div class="freedomtranslate-loader-content">
            <div class="freedomtranslate-spinner"></div>
            <p>Translation loading...</p>
        </div>
    </div>';
    
    // CSS styling
    $html .= '<style>
    .freedomtranslate-selector-wrapper { display: inline-flex; align-items: center; gap: 8px; }
    .freedomtranslate-selector-wrapper form { margin: 0; display: inline; }
    .freedomtranslate-selector-wrapper select { padding: 5px 10px; border-radius: 4px; }
    .freedomtranslate-mode-indicator { font-size: 18px; cursor: help; }
    
    /* Loading overlay styles */
    #freedomtranslate-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .freedomtranslate-loader-content {
        background: white;
        padding: 30px 50px;
        border-radius: 10px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    }
    
    .freedomtranslate-spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #3498db;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: freedomtranslate-spin 1s linear infinite;
        margin: 0 auto 15px;
    }
    
    @keyframes freedomtranslate-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .freedomtranslate-loader-content p {
        margin: 0;
        font-size: 16px;
        color: #333;
        font-weight: 500;
    }
    </style>';
    
    // JavaScript for loading overlay
    $html .= '<script>
    function showTranslationLoader() {
        var loader = document.getElementById("freedomtranslate-loader");
        if (loader) {
            loader.style.display = "flex";
        }
    }
    
    // Show loader if page is reloading with language parameter
    if (window.location.search.indexOf("freedomtranslate_lang=") !== -1) {
        window.addEventListener("DOMContentLoaded", function() {
            var loader = document.getElementById("freedomtranslate-loader");
            if (loader) {
                loader.style.display = "flex";
            }
        });
    }
    </script>';
    
    return $html;
}
add_shortcode('freedomtranslate_selector', 'freedomtranslate_language_selector_shortcode');

/**
 * Translate using Google Translate free unofficial method
 * Note: This method uses an unofficial API endpoint that may be rate-limited
 * 
 * @param string $text Text to translate
 * @param string $source Source language code
 * @param string $target Target language code
 * @return string Translated text or original text if translation fails
 */
function freedomtranslate_translate_google_free($text, $source, $target) {
    $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=" 
        . urlencode($source) . "&tl=" . urlencode($target) . "&dt=t&q=" . urlencode($text);
    
    $response = wp_remote_get($url, ['timeout' => 120]);
    
    if (is_wp_error($response)) {
        return $text;
    }
    
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    
    if (!isset($json) || !is_array($json)) {
        return $text;
    }
    
    $translated = '';
    foreach ($json as $sentence) {
        if (isset($sentence)) {
            $translated .= $sentence;
        }
    }
    
    return !empty($translated) ? $translated : $text;
}

/**
 * Translate using Google Cloud Translation API (official, paid)
 * Requires valid API key from Google Cloud Console
 * 
 * @param string $text Text to translate
 * @param string $source Source language code
 * @param string $target Target language code
 * @param string $format Format of text ('text' or 'html')
 * @return string Translated text or original text if translation fails
 */
function freedomtranslate_translate_google_official($text, $source, $target, $format = 'text') {
    $api_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    
    if (empty($api_key)) {
        return $text;
    }
    
    $url = 'https://translation.googleapis.com/language/translate/v2';
    
    $body = [
        'q' => $text,
        'source' => $source,
        'target' => $target,
        'format' => $format,
        'key' => $api_key
    ];
    
    $response = wp_remote_post($url, [
        'body' => json_encode($body),
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'timeout' => 120,
    ]);
    
    if (is_wp_error($response)) {
        return $text;
    }
    
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    
    if (!isset($json['data']['translations']['translatedText'])) {
        return $text;
    }
    
    return $json['data']['translations']['translatedText'];
}

/**
 * Translate using LibreTranslate/MarianMT
 * 
 * @param string $text Text to translate
 * @param string $source Source language code
 * @param string $target Target language code
 * @param string $format Format of text ('text' or 'html')
 * @return string Translated text or original text if translation fails
 */
function freedomtranslate_translate_libre($text, $source, $target, $format = 'text') {
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
    
    if (is_wp_error($response)) {
        return $text;
    }
    
    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    
    if (!isset($json['translatedText'])) {
        return $text;
    }
    
    return $json['translatedText'];
}

/**
 * Protect excluded words inside HTML text nodes by replacing them with placeholders
 * 
 * @param string $html HTML content to process
 * @param array $excluded_words Array of words to exclude from translation
 * @return array Array containing protected HTML and placeholder mappings
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
            
            $pattern = '/(?<!\w)' . preg_quote($word, '/') . '(?!\w)/ui';
            $placeholder = '[PH_' . strtoupper(substr(md5($word), 0, 8)) . ']';
            
            if (preg_match($pattern, $text)) {
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
 * Restore excluded words by replacing placeholders with original words
 * 
 * @param string $text Text containing placeholders
 * @param array $placeholders Placeholder to original word mappings
 * @return string Text with restored original words
 */
function freedomtranslate_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        $pattern = '/' . preg_quote($placeholder, '/') . '/i';
        $text = preg_replace($pattern, $original_word, $text);
    }
    return $text;
}

/**
 * Main translation function with service selection
 * Supports LibreTranslate, Google Translate (free), and Google Translate (official)
 * 
 * @param string $text Text to translate
 * @param string $source Source language code
 * @param string $target Target language code
 * @param string $format Format of text ('text' or 'html')
 * @return string Translated text
 */
function freedomtranslate_translate($text, $source, $target, $format = 'text') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !freedomtranslate_is_language_enabled($target)) {
        return $text;
    }
    
    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    
    // Protect excluded words from translation
    if ($format === 'html' && !empty($excluded_words)) {
        list($text, $placeholders) = freedomtranslate_protect_excluded_words_in_html($text, $excluded_words);
    } else {
        $placeholders = [];
        foreach ($excluded_words as $word) {
            $word = trim($word);
            if ($word === '') continue;
            $placeholder = '[PH_' . strtoupper(substr(md5($word), 0, 8)) . ']';
            $pattern = '/\b' . preg_quote($word, '/') . '\b/ui';
            $text = preg_replace($pattern, $placeholder, $text);
            $placeholders[$placeholder] = $word;
        }
    }
    
    // Check cache
    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($text . $source . $target . $format . $service);
    $cached = get_option($cache_key, false);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Select translation service
    switch ($service) {
        case 'google_free':
            $translated = freedomtranslate_translate_google_free($text, $source, $target);
            break;
        case 'google_official':
            $translated = freedomtranslate_translate_google_official($text, $source, $target, $format);
            break;
        case 'libretranslate':
        default:
            $translated = freedomtranslate_translate_libre($text, $source, $target, $format);
            break;
    }
    
    // Restore excluded words
    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }
    
    // Save to cache
    update_option($cache_key, $translated);
    return $translated;
}

/**
 * Filter post content for translation
 * 
 * @param string $content Post content
 * @return string Translated content
 */
function freedomtranslate_filter_post_content($content) {
    if (is_admin()) return $content;
    
    global $post;
    if ($post && get_post_meta($post->ID, '_freedomtranslate_exclude', true) === '1') {
        return $content;
    }
    
    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    
    if ($user_lang === $site_lang || !freedomtranslate_is_language_enabled($user_lang)) {
        return $content;
    }
    
    // Protect shortcode placeholder
    $placeholder = '<!--freedomtranslate-selector-->';
    $content = str_replace('[freedomtranslate_selector]', $placeholder, $content);
    
    $translated = freedomtranslate_translate($content, $site_lang, $user_lang, 'html');
    
    // Restore shortcode
    $translated = str_replace($placeholder, '[freedomtranslate_selector]', $translated);
    
    return do_shortcode($translated);
}
add_filter('the_content', 'freedomtranslate_filter_post_content');
add_filter('the_title', 'freedomtranslate_filter_post_content');

/**
 * Add admin menu page
 */
function freedomtranslate_admin_menu() {
    add_options_page(
        'FreedomTranslate Settings',
        'FreedomTranslate',
        'manage_options',
        'freedomtranslate',
        'freedomtranslate_settings_page'
    );
}
add_action('admin_menu', 'freedomtranslate_admin_menu');

/**
 * Render settings page
 */
function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }
    
    // Handle translation service selection
    if (isset($_POST['freedomtranslate_save_service'])) {
        check_admin_referer('freedomtranslate_save_service', 'freedomtranslate_nonce_service');
        $service = sanitize_text_field(wp_unslash($_POST['translation_service']));
        update_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, $service);
        echo '<div class="notice notice-success"><p>Translation service saved.</p></div>';
    }
    
    // Handle language detection mode
    if (isset($_POST['freedomtranslate_save_detection_mode'])) {
    check_admin_referer('freedomtranslate_save_detection_mode', 'freedomtranslate_nonce_detection');
    
    if (isset($_POST['lang_detection_mode'])) {
        $mode = sanitize_text_field(wp_unslash($_POST['lang_detection_mode']));
        
        // Delete and re-add to force update
        delete_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION);
        add_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, $mode, '', 'yes');
        
        // Save default language ONLY if manual mode AND field is present
        if ($mode === 'manual' && isset($_POST['default_language'])) {
            $default_lang = sanitize_text_field(wp_unslash($_POST['default_language']));
            delete_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION);
            add_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, $default_lang, '', 'yes');
        }
        
        echo '<div class="notice notice-success"><p>Language detection mode saved successfully.</p></div>';
    }
}
    
    // Handle cache purge
    if (isset($_POST['freedomtranslate_purge_cache'])) {
        check_admin_referer('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache');
        global $wpdb;
        $prefix_esc = esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX);
        $option_names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix_esc . '%'));
        if (!empty($option_names)) {
            foreach ($option_names as $option_name) {
                delete_option($option_name);
                wp_cache_delete($option_name, 'options');
            }
        }
        echo '<div class="notice notice-success"><p>Translation cache cleared.</p></div>';
    }
    
    // Handle enabled languages
    if (isset($_POST['freedomtranslate_save_languages'])) {
        check_admin_referer('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages');
        $languages = isset($_POST['freedomtranslate_languages']) 
            ? array_map('sanitize_text_field', wp_unslash($_POST['freedomtranslate_languages'])) 
            : [];
        update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $languages);
        echo '<div class="notice notice-success"><p>Enabled languages saved.</p></div>';
    }
    
    // Handle excluded words
    if (isset($_POST['freedomtranslate_save_excluded_words'])) {
        check_admin_referer('freedomtranslate_save_excluded_words', 'freedomtranslate_nonce_words');
        $raw = sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_excluded_words']));
        $words = array_filter(array_map('trim', preg_split('/\R/', $raw)));
        update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
        echo '<div class="notice notice-success"><p>Excluded words saved.</p></div>';
    }
    
    // Handle LibreTranslate API URL
    if (isset($_POST['freedomtranslate_save_api_url'])) {
        check_admin_referer('freedomtranslate_save_api_url', 'freedomtranslate_nonce_url');
        $url = trim(sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_url'])));
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            update_option(FREEDOMTRANSLATE_API_URL_OPTION, esc_url_raw($url));
            echo '<div class="notice notice-success"><p>API URL saved.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Invalid API URL.</p></div>';
        }
    }
    
    // Handle LibreTranslate API key
    if (isset($_POST['freedomtranslate_save_api_key'])) {
        check_admin_referer('freedomtranslate_save_api_key', 'freedomtranslate_nonce_apikey');
        $key = sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key']));
        update_option(FREEDOMTRANSLATE_API_KEY_OPTION, $key);
        echo '<div class="notice notice-success"><p>API key saved.</p></div>';
    }
    
    // Handle Google Cloud API key
    if (isset($_POST['freedomtranslate_save_google_api_key'])) {
        check_admin_referer('freedomtranslate_save_google_api_key', 'freedomtranslate_nonce_google');
        $key = sanitize_text_field(wp_unslash($_POST['freedomtranslate_google_api_key']));
        update_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, $key);
        echo '<div class="notice notice-success"><p>Google Cloud API key saved.</p></div>';
    }
    
    // Get current settings
    $current_service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    $default_lang = get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, substr(get_locale(), 0, 2));
    $all_languages = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $google_api_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    
    ?>
    <div class="wrap">
        <h1>FreedomTranslate Settings</h1>
        
        <!-- Translation Service Selection -->
        <div class="card">
            <h2>Translation Service</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_service', 'freedomtranslate_nonce_service'); ?>
                <table class="form-table">
                    <tr>
                        <td>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="translation_service" value="libretranslate" 
                                    <?php checked($current_service, 'libretranslate'); ?>>
                                <strong>LibreTranslate / MarianMT</strong>
                            </label>
                            <p class="description" style="margin-left: 24px; margin-bottom: 15px;">
                                Self-hosted or public server. Supports API keys. Open-source solution.
                            </p>
                            
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="translation_service" value="google_free" 
                                    <?php checked($current_service, 'google_free'); ?>>
                                <strong>Google Translate (Free - Unofficial)</strong>
                            </label>
                            <p class="description" style="margin-left: 24px; margin-bottom: 15px;">
                                Uses unofficial Google Translate API endpoint. Free but unofficial and may be inaccurate.
                            </p>
                            
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="translation_service" value="google_official" 
                                    <?php checked($current_service, 'google_official'); ?>>
                                <strong>Google Cloud Translation API (Official - Paid)</strong>
                            </label>
                            <p class="description" style="margin-left: 24px;">
                                Official Google Cloud Translation API. Requires API key and billing account.
                                <br>Pricing: <a href="https://cloud.google.com/translate/pricing" target="_blank">View pricing details</a>
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_service" class="button button-primary">Save Service</button>
            </form>
        </div>
        
        <!-- Language Detection Mode -->
        <div class="card" style="margin-top: 20px;">
            <h2>Language Detection Mode</h2>
            <form method="post" id="detection-mode-form">
                <?php wp_nonce_field('freedomtranslate_save_detection_mode', 'freedomtranslate_nonce_detection'); ?>
                <table class="form-table">
                    <tr>
                        <td>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="lang_detection_mode" value="auto" 
                                    <?php checked($detection_mode, 'auto'); ?> 
                                    onchange="toggleDefaultLangSelect()">
                                🌐 <strong>Automatic</strong> (detect initial language from browser)
                            </label>
                            <p class="description" style="margin-left: 24px; margin-bottom: 15px;">
                                Initial language is automatically detected from browser settings (HTTP_ACCEPT_LANGUAGE).<br>
                                <strong>User can still change language manually via selector.</strong>
                            </p>
                            
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="lang_detection_mode" value="manual" 
                                    <?php checked($detection_mode, 'manual'); ?>
                                    onchange="toggleDefaultLangSelect()">
                                📌 <strong>Manual</strong> (admin chooses default language)
                            </label>
                            <p class="description" style="margin-left: 24px; margin-bottom: 15px;">
                                No browser detection. Admin sets default initial language below.<br>
                                <strong>User can still change language manually via selector.</strong>
                            </p>
                            
                            <div id="default-lang-container" style="margin-left: 24px; margin-top: 15px; <?php echo ($detection_mode === 'auto') ? 'display:none;' : ''; ?>">
                                <label for="default_language"><strong>Default Language (for Manual mode):</strong></label><br>
                                <select name="default_language" id="default_language" style="margin-top: 8px;">
                                    <?php foreach ($enabled_languages as $code): ?>
                                        <?php if (isset($all_languages[$code])): ?>
                                            <option value="<?php echo esc_attr($code); ?>" <?php selected($default_lang, $code); ?>>
                                                <?php echo esc_html($all_languages[$code]); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">This language will be shown by default when manual mode is active.</p>
                            </div>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_detection_mode" class="button button-primary">Save Mode</button>
            </form>
            
            <script>
            function toggleDefaultLangSelect() {
                var manualRadio = document.querySelector('input[name="lang_detection_mode"][value="manual"]');
                var container = document.getElementById('default-lang-container');
                if (manualRadio && manualRadio.checked) {
                    container.style.display = 'block';
                } else {
                    container.style.display = 'none';
                }
            }
            </script>
        </div>
        
        <!-- LibreTranslate API Settings -->
        <div class="card" style="margin-top: 20px;">
            <h2>LibreTranslate Configuration</h2>
            <p class="description">Only required if LibreTranslate service is selected above.</p>
            
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_api_url', 'freedomtranslate_nonce_url'); ?>
                <table class="form-table">
                    <tr>
                        <th>API URL</th>
                        <td>
                            <input type="text" name="freedomtranslate_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" size="50">
                            <p class="description">LibreTranslate server endpoint (localhost for self-hosted or url as example: https://libretranslate.de/translate)</p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_api_url" class="button button-primary">Save URL</button>
            </form>
            
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('freedomtranslate_save_api_key', 'freedomtranslate_nonce_apikey'); ?>
                <table class="form-table">
                    <tr>
                        <th>API Key (optional)</th>
                        <td>
                            <input type="text" name="freedomtranslate_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" size="50">
                            <p class="description">Required only if your LibreTranslate server requires authentication.</p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_api_key" class="button button-primary">Save API Key</button>
            </form>
        </div>
        
        <!-- Google Cloud Translation API Settings -->
        <div class="card" style="margin-top: 20px;">
            <h2>Google Cloud Translation API Configuration</h2>
            <p class="description">Only required if Google Cloud Translation API (official) is selected above.</p>
            
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_google_api_key', 'freedomtranslate_nonce_google'); ?>
                <table class="form-table">
                    <tr>
                        <th>Google Cloud API Key</th>
                        <td>
                            <input type="text" name="freedomtranslate_google_api_key" value="<?php echo esc_attr($google_api_key); ?>" class="regular-text" size="50">
                            <p class="description">
                                Get your API key from <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.
                                <br>Make sure to enable the Cloud Translation API and set up billing.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_google_api_key" class="button button-primary">Save Google API Key</button>
            </form>
        </div>
        
        <!-- Enabled Languages -->
        <div class="card" style="margin-top: 20px;">
            <h2>Enabled Languages</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages'); ?>
                <p class="description">Select which languages will be available for translation.</p>
                <div style="column-count: 3; margin-top: 15px;">
                    <?php foreach ($all_languages as $code => $label): ?>
                        <?php $is_checked = in_array($code, $enabled_languages, true); ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="freedomtranslate_languages[]" value="<?php echo esc_attr($code); ?>" 
                                <?php checked($is_checked); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="freedomtranslate_save_languages" class="button button-primary" style="margin-top: 15px;">Save Languages</button>
            </form>
        </div>
        
        <!-- Excluded Words -->
        <div class="card" style="margin-top: 20px;">
            <h2>Excluded Words</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_excluded_words', 'freedomtranslate_nonce_words'); ?>
                <p class="description">Words or phrases that should not be translated (one per line).</p>
                <textarea name="freedomtranslate_excluded_words" rows="6" cols="50" style="margin-top: 10px;"><?php echo esc_textarea(implode("\n", $excluded_words)); ?></textarea>
                <p class="description">Example: brand names, technical terms, product names, etc.</p>
                <br>
                <button type="submit" name="freedomtranslate_save_excluded_words" class="button button-primary">Save Excluded Words</button>
            </form>
        </div>
        
        <!-- Cache Management -->
        <div class="card" style="margin-top: 20px;">
            <h2>Cache Management</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache'); ?>
                <p>Clear the translation cache to force re-translation of all content.</p>
                <p class="description">This is useful after changing translation service or updating excluded words.</p>
                <button type="submit" name="freedomtranslate_purge_cache" class="button button-secondary">Clear Cache</button>
            </form>
        </div>
        
        <!-- Usage Instructions -->
        <div class="card" style="margin-top: 20px;">
            <h2>Usage Instructions</h2>
            <h3>Shortcode</h3>
            <p>To view the language selector with available languages, use: <b>[freedomtranslate_selector]</b></p>
            
            <h3 style="margin-top: 20px;">How Detection Modes Work</h3>
            <p><strong>🌐 Automatic Mode:</strong> Initial language is detected from user's browser. User can change it anytime via selector.</p>
            <p><strong>📌 Manual Mode:</strong> Initial language is set by admin (no browser detection). User can change it anytime via selector.</p>
            <p style="margin-top: 15px;"><em>In both modes, users can always override and select their preferred language.</em></p>
            
            <h3 style="margin-top: 20px;">Exclude Posts from Translation</h3>
            <p>To exclude a specific page/post from automatic translation, check the box in the FreedomTranslate meta box in the post editor sidebar.</p>
        </div>
    </div>
    <?php
}

/**
 * Add meta box to post editor for excluding posts from translation
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'freedomtranslate_exclude_meta',
        'FreedomTranslate',
        function($post) {
            wp_nonce_field('freedomtranslate_metabox', 'freedomtranslate_meta_nonce');
            $val = get_post_meta($post->ID, '_freedomtranslate_exclude', true);
            echo '<label><input type="checkbox" name="freedomtranslate_exclude" value="1" ' . checked($val, '1', false) . '> ';
            echo esc_html__('Exclude this page/post from automatic translation') . '</label>';
        },
        ['post', 'page'],
        'side'
    );
});

/**
 * Save post meta for translation exclusion
 */
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['freedomtranslate_meta_nonce']) || 
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freedomtranslate_meta_nonce'])), 'freedomtranslate_metabox')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    if (isset($_POST['freedomtranslate_exclude'])) {
        update_post_meta($post_id, '_freedomtranslate_exclude', '1');
    } else {
        delete_post_meta($post_id, '_freedomtranslate_exclude');
    }
});
