<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with LibreTranslate (localhost:5000) or remote URL with API + cache and language selection
Version: 1.5.1
Author: thefreedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://github.com/OskarCosimo/FreedomTranslate-WP
Requires at least: 5.0
Requires PHP: 7.4
*/

defined('ABSPATH') or die('No script kiddies please!');

// Plugin constants
define('FREEDOMTRANSLATE_API_URL_OPTION',             'freedomtranslate_api_url');
define('FREEDOMTRANSLATE_API_URL_DEFAULT',             'http://localhost:5000/translate');
define('FREEDOMTRANSLATE_API_KEY_OPTION',             'freedomtranslate_api_key');
define('FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION',      'freedomtranslate_google_api_key');
define('FREEDOMTRANSLATE_LANGUAGES_OPTION',           'freedomtranslate_languages');
define('FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION',       'freedomtranslate_words_exclude');
define('FREEDOMTRANSLATE_CACHE_PREFIX',               'freedomtranslate_cache_');
define('FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION', 'freedomtranslate_service');
define('FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION', 'freedomtranslate_lang_detection_mode');
define('FREEDOMTRANSLATE_DEFAULT_LANG_OPTION',        'freedomtranslate_default_lang');
define('FREEDOMTRANSLATE_CACHE_TTL_OPTION',           'freedomtranslate_cache_ttl_global');
define('FREEDOMTRANSLATE_AUTO_INJECT_OPTION',         'freedomtranslate_auto_inject');
// Translation mode for LibreTranslate: 'sync' | 'chunks' | 'async'
define('FREEDOMTRANSLATE_LIBRE_MODE_OPTION',          'freedomtranslate_libre_mode');

/**
 * Handle language selection from GET parameter and set cookie
 */
add_action('init', function() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            // HttpOnly = false so JS can also read the cookie (needed for googlehash mode)
            setcookie('freedomtranslate_lang', $lang, time() + (DAY_IN_SECONDS * 30), '/', COOKIE_DOMAIN, is_ssl(), false);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }
});

/**
 * Append freedomtranslate_lang parameter to all internal links
 * so language persists across page navigation without relying solely on cookie
 */
add_filter('the_permalink', function($url) {
    $lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($lang !== $site_lang) {
        $url = add_query_arg('freedomtranslate_lang', $lang, $url);
    }
    return $url;
});

/**
 * Get user's language preference
 * Priority: GET parameter > Cookie > Detection mode (auto/manual)
 *
 * @return string Language code
 */
function freedomtranslate_get_user_lang() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) return $lang;
    }
    if (isset($_COOKIE['freedomtranslate_lang'])) {
        $lang = sanitize_text_field($_COOKIE['freedomtranslate_lang']);
        if (freedomtranslate_is_language_enabled($lang)) return $lang;
    }
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    if ($detection_mode === 'auto') {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browser = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 2);
            return freedomtranslate_is_language_enabled($browser) ? $browser : substr(get_locale(), 0, 2);
        }
    } else {
        return get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, substr(get_locale(), 0, 2));
    }
    return substr(get_locale(), 0, 2);
}

/**
 * Check if a language is enabled in plugin settings
 *
 * @param string $lang
 * @return bool
 */
function freedomtranslate_is_language_enabled($lang) {
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys(freedomtranslate_get_all_languages()));
    return in_array($lang, $enabled, true);
}

/**
 * Get all supported languages
 *
 * @return array code => name
 */
function freedomtranslate_get_all_languages() {
    return [
        'ar'=>'Arabic',     'az'=>'Azerbaijani', 'zh'=>'Chinese',    'cs'=>'Czech',
        'da'=>'Danish',     'nl'=>'Dutch',        'en'=>'English',    'fi'=>'Finnish',
        'fr'=>'Français',   'de'=>'Deutsch',      'el'=>'Greek',      'he'=>'Hebrew',
        'hi'=>'Hindi',      'hu'=>'Hungarian',    'id'=>'Indonesian', 'ga'=>'Irish',
        'it'=>'Italiano',   'ja'=>'Japanese',     'ko'=>'Korean',     'no'=>'Norwegian',
        'pl'=>'Polish',     'pt'=>'Português',    'ro'=>'Romanian',   'ru'=>'Русский',
        'sk'=>'Slovak',     'es'=>'Español',      'sv'=>'Swedish',    'tr'=>'Turkish',
        'uk'=>'Ukrainian',  'vi'=>'Vietnamese',
    ];
}

/**
 * Language selector shortcode
 *
 * @return string HTML
 */
function freedomtranslate_language_selector_shortcode() {
    $all            = freedomtranslate_get_all_languages();
    $enabled        = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all));
    $current        = freedomtranslate_get_user_lang();
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');

    $html  = '<div class="freedomtranslate-selector-wrapper">';
    $html .= '<form method="get" id="freedomtranslate-form">';
    $html .= '<select name="freedomtranslate_lang" onchange="showTranslationLoader(); this.form.submit();">';
    foreach ($enabled as $code) {
        if (isset($all[$code])) {
            $selected = ($code === $current) ? 'selected' : '';
            $html .= sprintf('<option value="%s" %s>%s</option>', esc_attr($code), $selected, esc_html($all[$code]));
        }
    }
    $html .= '</select>';
    foreach ($_GET as $key => $value) {
        if ($key !== 'freedomtranslate_lang') {
            $html .= sprintf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr($value));
        }
    }
    $html .= '</form>';
    $html .= ($detection_mode === 'manual')
        ? '<span class="freedomtranslate-mode-indicator" title="Manual: initial language defined">&#128205;</span>'
        : '<span class="freedomtranslate-mode-indicator" title="Automatic: browser-detected default">&#127758;</span>';
    $html .= '</div>';

    $html .= '
    <div id="freedomtranslate-loader" style="display:none;">
        <div class="freedomtranslate-loader-content">
            <div class="freedomtranslate-spinner"></div>
            <p>Translation loading...</p>
        </div>
    </div>';

    $html .= '<style>
    .freedomtranslate-selector-wrapper{display:inline-flex;align-items:center;gap:8px;}
    .freedomtranslate-selector-wrapper form{margin:0;display:inline;}
    .freedomtranslate-selector-wrapper select{padding:5px 10px;border-radius:4px;}
    .freedomtranslate-mode-indicator{font-size:18px;cursor:help;}
    #freedomtranslate-loader{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;}
    .freedomtranslate-loader-content{background:#fff;padding:30px 50px;border-radius:10px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);}
    .freedomtranslate-spinner{border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;width:50px;height:50px;animation:freedomtranslate-spin 1s linear infinite;margin:0 auto 15px;}
    @keyframes freedomtranslate-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
    .freedomtranslate-loader-content p{margin:0;font-size:16px;color:#333;font-weight:500;}
    .ft-progress-banner{display:none;align-items:center;gap:8px;margin-top:8px;font-size:13px;color:#555;}
    .ft-progress-banner.ft-pb-visible{display:flex;}
    .ft-progress-banner .ft-pb-spinner{border:2px solid #ccc;border-top:2px solid #3498db;border-radius:50%;width:14px;height:14px;animation:freedomtranslate-spin 1s linear infinite;flex-shrink:0;}
    .ft-progress-banner.ft-pb-ready{color:#27ae60;}
    .ft-progress-banner.ft-pb-ready .ft-pb-spinner{display:none;}
    </style>';

    $html .= '<script>
    function showTranslationLoader(){var l=document.getElementById("freedomtranslate-loader");if(l)l.style.display="flex";}
    if(window.location.search.indexOf("freedomtranslate_lang=")!==-1){
        window.addEventListener("DOMContentLoaded",function(){var l=document.getElementById("freedomtranslate-loader");if(l)l.style.display="flex";});
    }
    </script>';

    // Inline progress banner — shown only when an async translation is running
    // Uses class + data-cache-key (not id) so multiple widgets on the same page work fine
    global $freedomtranslate_active_cache_key;
    if (!empty($freedomtranslate_active_cache_key)) {
        $ck = esc_attr($freedomtranslate_active_cache_key);
        $html .= '<div class="ft-progress-banner ft-pb-visible" data-cache-key="' . $ck . '">'
               . '<div class="ft-pb-spinner"></div>'
               . '<span class="ft-pb-text">Translation in progress...</span>'
               . '</div>';
    }

    return $html;
}
add_shortcode('freedomtranslate_selector', 'freedomtranslate_language_selector_shortcode');

/**
 * Translate using Google Cloud Translation API (official, paid)
 *
 * @param string $text
 * @param string $source
 * @param string $target
 * @param string $format
 * @return string
 */
function freedomtranslate_translate_google_official($text, $source, $target, $format = 'text') {
    $api_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    if (empty($api_key)) return $text;
    $response = wp_remote_post('https://translation.googleapis.com/language/translate/v2', [
        'body'    => json_encode(['q'=>$text,'source'=>$source,'target'=>$target,'format'=>$format,'key'=>$api_key]),
        'headers' => ['Content-Type'=>'application/json'],
        'timeout' => 120,
    ]);
    if (is_wp_error($response)) return $text;
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['data']['translations']['translatedText'])
        ? $json['data']['translations']['translatedText'] : $text;
}

/**
 * Split long text into smaller chunks (paragraphs first, then sentences)
 *
 * @param string $text
 * @param int    $max_length
 * @return array
 */
function freedomtranslate_split_text($text, $max_length = 400) {
    $paragraphs = preg_split('/(\n\n+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $chunks = []; $current = '';
    foreach ($paragraphs as $para) {
        if (strlen($current . $para) <= $max_length) {
            $current .= $para;
        } else {
            if ($current !== '') { $chunks[] = $current; $current = ''; }
            $sentences = preg_split('/(?<=[.!?»])\s+/u', $para);
            foreach ($sentences as $sentence) {
                if (strlen($current . ' ' . $sentence) > $max_length && $current !== '') {
                    $chunks[] = trim($current); $current = $sentence;
                } else {
                    $current .= ' ' . $sentence;
                }
            }
        }
    }
    if (trim($current) !== '') $chunks[] = trim($current);
    return array_filter($chunks);
}

/**
 * Translate using LibreTranslate/MarianMT
 *
 * @param string $text
 * @param string $source
 * @param string $target
 * @param string $format
 * @param bool   $use_chunks Whether to split into 400-char chunks before translating
 * @return string
 */
function freedomtranslate_translate_libre($text, $source, $target, $format = 'text', $use_chunks = false) {
    $api_url   = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key   = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $max_chunk = 400;
    $chunks    = ($use_chunks && mb_strlen($text) > $max_chunk)
        ? freedomtranslate_split_text($text, $max_chunk) : [$text];

    $translated_chunks = [];
    foreach ($chunks as $chunk) {
        if (trim($chunk) === '') { $translated_chunks[] = $chunk; continue; }
        $body = ['q'=>$chunk,'source'=>$source,'target'=>$target,'format'=>$format];
        if (!empty($api_key)) $body['api_key'] = $api_key;
        $response = wp_remote_post($api_url, ['body'=>$body,'timeout'=>60]);
        if (is_wp_error($response)) { $translated_chunks[] = $chunk; continue; }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        $translated_chunks[] = isset($json['translatedText']) ? $json['translatedText'] : $chunk;
    }
    return implode('', $translated_chunks);
}

/**
 * Protect excluded words in HTML by replacing them with placeholders
 *
 * @param string $html
 * @param array  $excluded_words
 * @return array [protected_html, placeholders_map]
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
            $pattern     = '/(?<!\w)' . preg_quote($word, '/') . '(?!\w)/ui';
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
 * @param string $text
 * @param array  $placeholders
 * @return string
 */
function freedomtranslate_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        $text = preg_replace('/' . preg_quote($placeholder, '/') . '/i', $original_word, $text);
    }
    return $text;
}

/**
 * Translate HTML content by extracting text nodes via DOMDocument,
 * translating each node individually (pure text, no HTML risk),
 * and reinserting them back into the DOM.
 * This is the safest method for long posts: HTML structure is never touched.
 *
 * @param string $html      HTML content to translate
 * @param string $source    Source language code
 * @param string $target    Target language code
 * @param string $api_url   LibreTranslate endpoint
 * @param string $api_key   LibreTranslate API key (optional)
 * @return string Translated HTML with original structure preserved
 */
function freedomtranslate_translate_html_by_nodes($html, $source, $target, $api_url, $api_key) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath     = new DOMXPath($dom);
    // Select only text nodes that are not empty and not inside <script> or <style>
    $textNodes = $xpath->query('//text()[normalize-space(.) != ""][not(ancestor::script)][not(ancestor::style)]');

    foreach ($textNodes as $textNode) {
        $original = $textNode->nodeValue;
        if (trim($original) === '') continue;

        // Preserve leading/trailing whitespace: LibreTranslate strips them,
        // causing missing spaces after inline tags like </a> </strong> </em>
        preg_match('/^(\s*)/', $original, $lm);
        preg_match('/(\s*)$/', $original, $tm);
        $leading_space  = isset($lm[1])  ? $lm[1]  : '';
        $trailing_space = isset($tm[1]) ? $tm[1] : '';

        $body = ['q' => trim($original), 'source' => $source, 'target' => $target, 'format' => 'text'];
        if (!empty($api_key)) $body['api_key'] = $api_key;

        $response = wp_remote_post($api_url, ['body' => $body, 'timeout' => 60]);
        if (is_wp_error($response)) continue;

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($json['translatedText'])) {
            $textNode->nodeValue = $leading_space . $json['translatedText'] . $trailing_space;
        }
    }

    $translated = $dom->saveHTML();
    // Remove DOCTYPE/html/body wrappers added by DOMDocument
    $translated = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $translated);
    return $translated;
}

/**
 * Get effective cache TTL in days for a post
 * Priority: post meta TTL > global admin TTL > 30 days
 *
 * @param int $post_id
 * @return int
 */
function freedomtranslate_get_ttl_days($post_id = 0) {
    if ($post_id > 0) {
        $post_ttl = get_post_meta($post_id, '_freedomtranslate_cache_ttl', true);
        if ($post_ttl !== '' && (int) $post_ttl > 0) return (int) $post_ttl;
    }
    $global = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    return (int) $global > 0 ? (int) $global : 30;
}

/**
 * Main translation function (sync/chunks modes only)
 * Not used in async mode — async worker calls freedomtranslate_translate_libre() directly
 *
 * @param string $text
 * @param string $source
 * @param string $target
 * @param string $format
 * @param int    $post_id
 * @return string
 */
function freedomtranslate_translate($text, $source, $target, $format = 'text', $post_id = 0) {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !freedomtranslate_is_language_enabled($target)) return $text;

    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    if ($format === 'html' && !empty($excluded_words)) {
        list($text, $placeholders) = freedomtranslate_protect_excluded_words_in_html($text, $excluded_words);
    } else {
        $placeholders = [];
        foreach ($excluded_words as $word) {
            $word = trim($word); if ($word === '') continue;
            $placeholder = 'FTPH' . strtoupper(substr(md5($word), 0, 8)) . 'FTPH';
            $pattern     = '/(?<![a-zA-Z0-9_\-])' . preg_quote($word, '/') . '(?![a-zA-Z0-9_\-])/ui';
            $text        = preg_replace($pattern, $placeholder, $text);
            $placeholders[$placeholder] = $word;
        }
    }

    $service   = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($text . $source . $target . $format . $service);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    switch ($service) {
        case 'googlehash':       $translated = $text; break;
        case 'google_official':  $translated = freedomtranslate_translate_google_official($text, $source, $target, $format); break;
        case 'libretranslate':
        default:
            $libre_mode = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
            $use_chunks = ($libre_mode === 'chunks');
            $translated = freedomtranslate_translate_libre($text, $source, $target, $format, $use_chunks);
            break;
    }

    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }
    set_transient($cache_key, $translated, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
    return $translated;
}

/**
 * Background translation worker (WP-Cron, async mode only)
 *
 * Stores the result under the EXACT cache_key computed in filter_post_content,
 * so the next page load finds it immediately and the banner is never shown again.
 *
 * @param string $cache_key Exact transient key computed in filter_post_content
 * @param string $content   Original post content
 * @param string $site_lang Source language code
 * @param string $user_lang Target language code
 * @param int    $post_id   Post ID for TTL resolution
 */
function freedomtranslate_async_worker($cache_key, $content, $site_lang, $user_lang, $post_id) {
    // Bail if another process already finished
    if (get_transient($cache_key) !== false) return;

    // Protect the language-selector shortcode from being translated
    $placeholder = '<!--freedomtranslate-selector-->';
    $content     = str_replace('[freedomtranslate_selector]', $placeholder, $content);

    // Protect excluded words before sending to LibreTranslate
    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $placeholders   = [];
    if (!empty($excluded_words)) {
        list($content, $placeholders) = freedomtranslate_protect_excluded_words_in_html($content, $excluded_words);
    }

    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    // Split HTML into ~3000-char block-level chunks and translate each one separately.
    // Progress is saved after every chunk so the JS banner shows a real percentage.
    $chunks           = freedomtranslate_split_html_into_chunks($content, 3000);
    $total_chunks     = count($chunks);
    $translated_parts = array();

    foreach ($chunks as $i => $chunk) {
        $translated_parts[] = freedomtranslate_translate_html_by_nodes(
            $chunk, $site_lang, $user_lang, $api_url, $api_key
        );
        set_transient(
            'freedomtranslate_progress_' . md5($cache_key),
            array('done' => $i + 1, 'total' => $total_chunks),
            HOUR_IN_SECONDS
        );
    }

    $translated = implode('', $translated_parts);

    // Restore excluded words
    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }

    $translated = str_replace($placeholder, '[freedomtranslate_selector]', $translated);

    // Store final result under the EXACT cache_key
    set_transient($cache_key, $translated, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));

    // Set ready flag
    set_transient('freedomtranslate_ready_' . md5($cache_key), '1', HOUR_IN_SECONDS);

    // Clean up
    delete_transient('freedomtranslate_progress_' . md5($cache_key));
    delete_transient('freedomtranslate_pending_' . md5($cache_key));
}

/**
 * Split HTML into block-level chunks for incremental background translation.
 * Splits only before block-level tags so inline elements are never broken.
 *
 * @param string $html
 * @param int    $max_chars
 * @return array
 */
function freedomtranslate_split_html_into_chunks($html, $max_chars = 3000) {
    $blocks  = preg_split(
        '/(?=<(?:p|div|h[1-6]|li|blockquote|pre|table|ul|ol|figure|tr|section|article)[\s>])/i',
        $html, -1, PREG_SPLIT_NO_EMPTY
    );
    $chunks  = array();
    $current = '';
    foreach ($blocks as $block) {
        if ($current !== '' && strlen($current) + strlen($block) > $max_chars) {
            $chunks[] = $current;
            $current  = $block;
        } else {
            $current .= $block;
        }
    }
    if ($current !== '') $chunks[] = $current;
    return $chunks ? $chunks : array($html);
}
add_action('freedomtranslate_async_translate', 'freedomtranslate_async_worker', 10, 5);

/**
 * AJAX endpoint: returns { ready: true/false } for the given cache_key
 * Accessible by both logged-in and non-logged-in users
 */
function freedomtranslate_ajax_check_ready() {
    $cache_key = isset($_GET['cache_key']) ? sanitize_text_field(wp_unslash($_GET['cache_key'])) : '';
    if (empty($cache_key)) { wp_send_json(array('ready' => false, 'progress' => 0)); return; }

    // The ready flag is stored as md5(cache_key) — must match what the worker sets
    $ready = get_transient('freedomtranslate_ready_' . md5($cache_key)) !== false;
    if ($ready) {
        wp_send_json(array('ready' => true, 'progress' => 100));
        return;
    }

    // Return incremental progress percentage while the worker is still running
    $progress_data = get_transient('freedomtranslate_progress_' . md5($cache_key));
    $percent = 0;
    if (is_array($progress_data) && !empty($progress_data['total'])) {
        $percent = (int) round(($progress_data['done'] / $progress_data['total']) * 100);
    }
    wp_send_json(array('ready' => false, 'progress' => $percent));
}
add_action('wp_ajax_nopriv_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');
add_action('wp_ajax_freedomtranslate_check_ready',        'freedomtranslate_ajax_check_ready');

/**
 * Filter post content for translation
 *
 * async mode : returns original content immediately, schedules background job, shows polling banner
 * sync/chunks: translates inline on first load
 *
 * @param string $content
 * @return string
 */
function freedomtranslate_filter_post_content($content) {
    if (is_admin()) return $content;

    global $post;
    if ($post && get_post_meta($post->ID, '_freedomtranslate_exclude', true) === '1') return $content;

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($user_lang === $site_lang || !freedomtranslate_is_language_enabled($user_lang)) return $content;

    $service    = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $libre_mode = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
    $post_id    = $post ? $post->ID : 0;

    // The cache_key is computed on the RAW original content (before any placeholder manipulation)
    // so it stays stable across all requests for the same post + language combination
    $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($content . $site_lang . $user_lang . 'html' . $service);

    // --- ASYNC MODE (LibreTranslate only) ---
    if ($service === 'libretranslate' && $libre_mode === 'async') {
        $cached = get_transient($cache_key);

        // Translation already in cache: serve it directly — banner will NOT be injected
        if ($cached !== false) {
            return do_shortcode($cached);
        }

        // Schedule background job only once (dedup via pending transient)
        $pending_key = 'freedomtranslate_pending_' . md5($cache_key);
        if (!get_transient($pending_key)) {
            set_transient($pending_key, '1', 5 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [
                $cache_key, $content, $site_lang, $user_lang, $post_id,
            ]);
        }

        // Store active cache_key globally so the language selector shortcode
        // can render the inline progress banner right below the widget
        global $freedomtranslate_active_cache_key;
        $freedomtranslate_active_cache_key = $cache_key;

        // Inject polling JS in footer (HTML banner is rendered inline by the shortcode)
        add_action('wp_footer', function() use ($cache_key) {
            freedomtranslate_async_banner_script($cache_key);
        });

        // Return original content while translation is in progress
        return $content;
    }

    // --- SYNC / CHUNKS MODE ---
    $placeholder = '<!--freedomtranslate-selector-->';
    $content     = str_replace('[freedomtranslate_selector]', $placeholder, $content);
    $translated  = freedomtranslate_translate($content, $site_lang, $user_lang, 'html', $post_id);
    $translated  = str_replace($placeholder, '[freedomtranslate_selector]', $translated);
    return do_shortcode($translated);
}
add_filter('the_content', 'freedomtranslate_filter_post_content');
add_filter('the_title',   'freedomtranslate_filter_post_content');

/**
 * Auto-inject the language selector shortcode at the top of pages and/or posts
 * based on the admin setting. Runs at priority 5, before the translation filter.
 */
add_filter('the_content', function($content) {
    if (is_admin()) return $content;
    $auto_inject = get_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, array());
    if (empty($auto_inject)) return $content;
    $post_type = get_post_type();
    $should_inject = (in_array('page', $auto_inject, true) && $post_type === 'page')
                  || (in_array('post', $auto_inject, true) && $post_type === 'post');
    if (!$should_inject) return $content;
    return '[freedomtranslate_selector]' . $content;
}, 9);

/**
 * Per-post inject: if the post has _freedomtranslate_inject_selector meta set,
 * prepend the language selector shortcode right after the title (at content top).
 * Priority 9 so it runs before the translation filter and the shortcode
 * gets protected by the placeholder mechanism.
 */
add_filter('the_content', function($content) {
    if (is_admin()) return $content;
    $post_id = get_the_ID();
    if (!$post_id) return $content;
    if (get_post_meta($post_id, '_freedomtranslate_inject_selector', true) !== '1') return $content;
    // Skip if the global auto-inject already added it for this post type
    // (the translation filter will handle de-duplication via placeholder)
    return '[freedomtranslate_selector]' . $content;
}, 9);


/**
 * Output async translation banner + JS polling in the page footer
 *
 * FIX: Uses sessionStorage flag keyed on cache_key to suppress the banner
 * on subsequent page loads after the automatic reload has already occurred.
 * This prevents the banner from reappearing in an infinite reload loop.
 *
 * @param string $cache_key
 */
function freedomtranslate_async_banner_script($cache_key) {
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <script>
    (function() {
        var cacheKey    = <?php echo json_encode($cache_key); ?>;
        var ajaxUrl     = <?php echo json_encode($ajax_url); ?>;

        // Unique sessionStorage flag for this translation job.
        // Set to '1' just BEFORE reloading so that after the reload
        // this script detects it and hides the banner immediately —
        // preventing the infinite-reload bug.
        var sessionFlag = 'ft_reloaded_' + cacheKey;
// isSingular check removed: the inline banner is shown on all page types
// and is hidden only when translation is ready or after maxAttempts give-up

        // Helper: trova tutti i banner inline con questo cache_key
        function getBanners() {
            var all = document.querySelectorAll('.ft-progress-banner[data-cache-key]');
            var res = [];
            for (var i = 0; i < all.length; i++) {
                if (all[i].getAttribute('data-cache-key') === cacheKey) res.push(all[i]);
            }
            return res;
        }

        // If we already triggered a reload for this translation in this session,
        // the cache is ready: hide all banners and stop.
        if (sessionStorage.getItem(sessionFlag)) {
            var bs = getBanners();
            for (var i = 0; i < bs.length; i++) bs[i].classList.remove('ft-pb-visible');
            return;
        }

        var interval    = null;
        var attempts    = 0;
        var maxAttempts = 60; // max ~5 minutes of polling (check every 5 s)

        function checkReady() {
            attempts++;
            if (attempts > maxAttempts) {
                // Give up: hide all matching banners gracefully
                clearInterval(interval);
                var bns = getBanners();
                for (var i = 0; i < bns.length; i++) bns[i].classList.remove('ft-pb-visible');
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('GET', ajaxUrl + '?action=freedomtranslate_check_ready&cache_key=' + encodeURIComponent(cacheKey), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.ready) {
                            clearInterval(interval);
                            // Mark all matching banners as ready
                            var bns = getBanners();
                            for (var bi = 0; bi < bns.length; bi++) {
                                bns[bi].classList.add('ft-pb-ready');
                                var sp = bns[bi].querySelector('.ft-pb-spinner');
                                var tx = bns[bi].querySelector('.ft-pb-text');
                                if (sp) sp.style.display = 'none';
                                if (tx) tx.textContent = 'Translation ready! Reloading...';
                                bns[bi].style.cursor = 'pointer';
                                bns[bi].addEventListener('click', function() {
                                    sessionStorage.setItem(sessionFlag, '1');
                                    window.location.reload();
                                });
                            }
                            setTimeout(function() {
                                sessionStorage.setItem(sessionFlag, '1');
                                window.location.reload();
                            }, 2000);
                        } else if (typeof data.progress !== 'undefined' && data.progress > 0) {
                            // Update progress % in all matching banners
                            var bns = getBanners();
                            for (var bi = 0; bi < bns.length; bi++) {
                                var tx = bns[bi].querySelector('.ft-pb-text');
                                if (tx) tx.textContent = 'Translation in progress... ' + data.progress + '%';
                            }
                        }
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        // Start polling every 5 seconds
        interval = setInterval(checkReady, 5000);
    })();
    </script>
    <?php
}

/**
 * Add admin menu page under Settings
 */
function freedomtranslate_admin_menu() {
    add_options_page(
        'FreedomTranslate Settings', 'FreedomTranslate',
        'manage_options', 'freedomtranslate', 'freedomtranslate_settings_page'
    );
}
add_action('admin_menu', 'freedomtranslate_admin_menu');

/**
 * Render the plugin settings page
 */
function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.'));
    }

    if (isset($_POST['freedomtranslate_save_service'])) {
        check_admin_referer('freedomtranslate_save_service', 'freedomtranslate_nonce_service');
        update_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, sanitize_text_field(wp_unslash($_POST['translation_service'])));
        echo '<div class="notice notice-success"><p>Translation service saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_detection_mode'])) {
        check_admin_referer('freedomtranslate_save_detection_mode', 'freedomtranslate_nonce_detection');
        if (isset($_POST['lang_detection_mode'])) {
            $mode = sanitize_text_field(wp_unslash($_POST['lang_detection_mode']));
            delete_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION);
            add_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, $mode, '', 'yes');
            if ($mode === 'manual' && isset($_POST['default_language'])) {
                $default_lang = sanitize_text_field(wp_unslash($_POST['default_language']));
                delete_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION);
                add_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, $default_lang, '', 'yes');
            }
            echo '<div class="notice notice-success"><p>Language detection mode saved successfully.</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_save_libre_config'])) {
        check_admin_referer('freedomtranslate_save_libre_config', 'freedomtranslate_nonce_libre');
        $url = esc_url_raw(trim(wp_unslash($_POST['freedomtranslate_api_url'])));
        if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            delete_option(FREEDOMTRANSLATE_API_URL_OPTION);
            add_option(FREEDOMTRANSLATE_API_URL_OPTION, $url, '', 'yes');
        }
        update_option(FREEDOMTRANSLATE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key'])));
        $allowed_modes = ['sync', 'chunks', 'async'];
        $mode = sanitize_text_field(wp_unslash($_POST['freedomtranslate_libre_mode']));
        update_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, in_array($mode, $allowed_modes, true) ? $mode : 'async');
        echo '<div class="notice notice-success"><p>LibreTranslate configuration saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_cache_ttl'])) {
        check_admin_referer('freedomtranslate_save_cache_ttl', 'freedomtranslate_nonce_cache_ttl');
        $ttl = max(1, min(365, absint(wp_unslash($_POST['freedomtranslate_cache_ttl_global']))));
        update_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, $ttl);
        echo '<div class="notice notice-success"><p>Global cache TTL saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_purge_cache'])) {
        check_admin_referer('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache');
        global $wpdb;
        $p = esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $p . '%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $p . '%', '_transient_timeout_' . $p . '%'));
        wp_cache_flush();
        echo '<div class="notice notice-success"><p>Translation cache cleared successfully.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_languages'])) {
        check_admin_referer('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages');
        $languages = isset($_POST['freedomtranslate_languages'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['freedomtranslate_languages'])) : [];
        update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $languages);
        echo '<div class="notice notice-success"><p>Enabled languages saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_excluded_words'])) {
        check_admin_referer('freedomtranslate_save_excluded_words', 'freedomtranslate_nonce_words');
        $words = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_excluded_words'])))));
        update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
        echo '<div class="notice notice-success"><p>Excluded words saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_auto_inject'])) {
        check_admin_referer('freedomtranslate_save_auto_inject', 'freedomtranslate_nonce_auto_inject');
        $inject = array();
        if (isset($_POST['freedomtranslate_auto_inject']) && is_array($_POST['freedomtranslate_auto_inject'])) {
            $allowed = array('page', 'post');
            foreach ($_POST['freedomtranslate_auto_inject'] as $v) {
                $v = sanitize_text_field($v);
                if (in_array($v, $allowed, true)) $inject[] = $v;
            }
        }
        update_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, $inject);
        echo '<div class="notice notice-success"><p>Auto-inject settings saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_google_api_key'])) {
        check_admin_referer('freedomtranslate_save_google_api_key', 'freedomtranslate_nonce_google');
        update_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_google_api_key'])));
        echo '<div class="notice notice-success"><p>Google Cloud API key saved.</p></div>';
    }

    $current_service   = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $detection_mode    = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    $default_lang      = get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, substr(get_locale(), 0, 2));
    $all_languages     = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $excluded_words    = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $api_url           = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key           = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $google_api_key    = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    $global_ttl        = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    $libre_mode        = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
    ?>
    <div class="wrap">
        <h1>FreedomTranslate Settings</h1>

        <!-- Translation Service -->
        <div class="card">
            <h2>Translation Service</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_service', 'freedomtranslate_nonce_service'); ?>
                <table class="form-table"><tr><td>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="translation_service" value="libretranslate" <?php checked($current_service,'libretranslate'); ?>>
                        <strong>LibreTranslate / MarianMT</strong>
                    </label>
                    <p class="description" style="margin-left:24px;margin-bottom:15px;">Self-hosted or public server. Supports API keys. Open-source solution.</p>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="translation_service" value="googlehash" <?php checked($current_service,'googlehash'); ?>>
                        <strong>Google Translate (free, hash-based)</strong>
                    </label>
                    <p class="description" style="margin-left:24px;margin-bottom:15px;">Uses #googtrans hash in URL. No API key needed. Translation mode selector not available for this service.</p>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="translation_service" value="google_official" <?php checked($current_service,'google_official'); ?>>
                        <strong>Google Cloud Translation API (Official - Paid)</strong>
                    </label>
                    <p class="description" style="margin-left:24px;">Official Google Cloud Translation API. Requires API key and billing account.<br>
                    Pricing: <a href="https://cloud.google.com/translate/pricing" target="_blank">View pricing details</a></p>
                </td></tr></table>
                <button type="submit" name="freedomtranslate_save_service" class="button button-primary">Save Service</button>
            </form>
        </div>

        <!-- Language Detection Mode -->
        <div class="card" style="margin-top:20px;">
            <h2>Language Detection Mode</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_detection_mode', 'freedomtranslate_nonce_detection'); ?>
                <table class="form-table"><tr><td>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="lang_detection_mode" value="auto" <?php checked($detection_mode,'auto'); ?> onchange="toggleDefaultLangSelect()">
                        &#127758; <strong>Automatic</strong> (detect initial language from browser)
                    </label>
                    <p class="description" style="margin-left:24px;margin-bottom:15px;">
                        Initial language is automatically detected from browser settings (HTTP_ACCEPT_LANGUAGE).<br>
                        <strong>User can still change language manually via selector.</strong>
                    </p>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="lang_detection_mode" value="manual" <?php checked($detection_mode,'manual'); ?> onchange="toggleDefaultLangSelect()">
                        &#128205; <strong>Manual</strong> (admin chooses default language)
                    </label>
                    <p class="description" style="margin-left:24px;margin-bottom:15px;">
                        No browser detection. Admin sets default initial language below.<br>
                        <strong>User can still change language manually via selector.</strong>
                    </p>
                    <div id="default-lang-container" style="margin-left:24px;margin-top:15px;<?php echo ($detection_mode==='auto')?'display:none;':''; ?>">
                        <label for="default_language"><strong>Default Language (for Manual mode):</strong></label><br>
                        <select name="default_language" id="default_language" style="margin-top:8px;">
                            <?php foreach ($enabled_languages as $code): ?>
                                <?php if (isset($all_languages[$code])): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($default_lang,$code); ?>><?php echo esc_html($all_languages[$code]); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">This language will be shown by default when manual mode is active.</p>
                    </div>
                </td></tr></table>
                <button type="submit" name="freedomtranslate_save_detection_mode" class="button button-primary">Save Mode</button>
            </form>
            <script>
            function toggleDefaultLangSelect() {
                var m = document.querySelector('input[name="lang_detection_mode"][value="manual"]');
                document.getElementById('default-lang-container').style.display = (m && m.checked) ? 'block' : 'none';
            }
            </script>
        </div>

        <!-- LibreTranslate Configuration -->
        <div class="card" style="margin-top:20px;">
            <h2>LibreTranslate Configuration</h2>
            <p class="description">Only required if LibreTranslate service is selected above.</p>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_libre_config', 'freedomtranslate_nonce_libre'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="freedomtranslate_api_url">API URL</label></th>
                        <td>
                            <input type="text" id="freedomtranslate_api_url" name="freedomtranslate_api_url"
                                value="<?php echo esc_attr($api_url); ?>" class="regular-text" size="50">
                            <p class="description">LibreTranslate server endpoint (e.g. http://localhost:5000/translate)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="freedomtranslate_api_key">API Key <em>(optional)</em></label></th>
                        <td>
                            <input type="text" id="freedomtranslate_api_key" name="freedomtranslate_api_key"
                                value="<?php echo esc_attr($api_key); ?>" class="regular-text" size="50">
                            <p class="description">Required only if your LibreTranslate server requires authentication.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="freedomtranslate_libre_mode">Translation Mode</label></th>
                        <td>
                            <select id="freedomtranslate_libre_mode" name="freedomtranslate_libre_mode">
                                <option value="sync"   <?php selected($libre_mode,'sync');   ?>>Traditional translation without chunks (sync)</option>
                                <option value="chunks" <?php selected($libre_mode,'chunks'); ?>>Translation with chunks (sync, splits every 400 chars)</option>
                                <option value="async"  <?php selected($libre_mode,'async');  ?>>Translation in background (async, recommended)</option>
                            </select>
                            <p class="description" style="margin-top:8px;">
                                <strong>sync:</strong> Translates inline on first load — may be slow for long posts.<br>
                                <strong>chunks:</strong> Same as sync but splits long content into 400-char pieces — useful if you get timeout errors.
                                &#9888;&#65039; May cause layout issues on complex HTML pages.<br>
                                <strong>async (default):</strong> Page loads instantly; translation runs in background via WP-Cron.
                                A banner notifies the user when ready and the page reloads automatically.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_libre_config" class="button button-primary">Save LibreTranslate Settings</button>
            </form>
        </div>

        <!-- Google Cloud Translation API -->
        <div class="card" style="margin-top:20px;">
            <h2>Google Cloud Translation API Configuration</h2>
            <p class="description">Only required if Google Cloud Translation API (official) is selected above.</p>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_google_api_key', 'freedomtranslate_nonce_google'); ?>
                <table class="form-table">
                    <tr>
                        <th>Google Cloud API Key</th>
                        <td>
                            <input type="text" name="freedomtranslate_google_api_key"
                                value="<?php echo esc_attr($google_api_key); ?>" class="regular-text" size="50">
                            <p class="description">
                                Get your API key from <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.<br>
                                Make sure to enable the Cloud Translation API and set up billing.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_google_api_key" class="button button-primary">Save Google API Key</button>
            </form>
        </div>

        <!-- Enabled Languages -->
        <div class="card" style="margin-top:20px;">
            <h2>Enabled Languages</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages'); ?>
                <p class="description">Select which languages will be available for translation.</p>
                <div style="column-count:3;margin-top:15px;">
                    <?php foreach ($all_languages as $code => $label): ?>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="checkbox" name="freedomtranslate_languages[]"
                                value="<?php echo esc_attr($code); ?>"
                                <?php checked(in_array($code, $enabled_languages, true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="freedomtranslate_save_languages" class="button button-primary" style="margin-top:15px;">Save Languages</button>
            </form>
        </div>

        <!-- Excluded Words -->
        <div class="card" style="margin-top:20px;">
            <h2>Excluded Words</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_excluded_words', 'freedomtranslate_nonce_words'); ?>
                <p class="description">Words or phrases that should not be translated (one per line).</p>
                <textarea name="freedomtranslate_excluded_words" rows="6" cols="50" style="margin-top:10px;"><?php echo esc_textarea(implode("\n", $excluded_words)); ?></textarea>
                <p class="description">Example: brand names, technical terms, product names, etc.</p><br>
                <button type="submit" name="freedomtranslate_save_excluded_words" class="button button-primary">Save Excluded Words</button>
            </form>
        </div>

        <!-- Auto-inject Selector -->
        <div class="card" style="margin-top:20px;">
            <h2>Auto-inject Language Selector</h2>
            <p class="description">Automatically prepend the <code>[freedomtranslate_selector]</code> widget at the top of the content. Only applies if the shortcode is not already present in the content.</p>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_auto_inject', 'freedomtranslate_nonce_auto_inject'); ?>
                <?php $auto_inject = get_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, array()); ?>
                <p style="margin-top:12px;">
                    <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" name="freedomtranslate_auto_inject[]" value="page"
                            <?php checked(in_array('page', $auto_inject, true)); ?>> 
                        <strong>Pages</strong> &mdash; inject on all WordPress pages
                    </label>
                    <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" name="freedomtranslate_auto_inject[]" value="post"
                            <?php checked(in_array('post', $auto_inject, true)); ?>>
                        <strong>Posts</strong> &mdash; inject on all WordPress posts
                    </label>
                </p>
                <button type="submit" name="freedomtranslate_save_auto_inject" class="button button-primary">Save Auto-inject Settings</button>
            </form>
        </div>

        <!-- Cache Management -->
        <div class="card" style="margin-top:20px;">
            <h2>Cache Management</h2>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_cache_ttl', 'freedomtranslate_nonce_cache_ttl'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="freedomtranslate_cache_ttl_global">Default cache duration (days)</label></th>
                        <td>
                            <input type="number" id="freedomtranslate_cache_ttl_global"
                                name="freedomtranslate_cache_ttl_global"
                                value="<?php echo esc_attr($global_ttl); ?>"
                                min="1" max="365" style="width:80px;">
                            <p class="description">
                                Applies to all posts/pages unless overridden per-post in the editor sidebar.<br>
                                Default: 30 days. Range: 1–365.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="freedomtranslate_save_cache_ttl" class="button button-primary">Save TTL</button>
            </form>
            <form method="post" style="margin-top:15px;">
                <?php wp_nonce_field('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache'); ?>
                <p>Clear the entire translation cache to force re-translation of all content.</p>
                <p class="description">Useful after changing translation service or updating excluded words.</p>
                <button type="submit" name="freedomtranslate_purge_cache" class="button button-secondary">Clear Cache</button>
            </form>
        </div>

        <!-- Usage Instructions -->
        <div class="card" style="margin-top:20px;">
            <h2>Usage Instructions</h2>
            <h3>Shortcode</h3>
            <p>To display the language selector use: <b>[freedomtranslate_selector]</b></p>
            <h3 style="margin-top:20px;">How Detection Modes Work</h3>
            <p><strong>&#127758; Automatic Mode:</strong> Initial language detected from user browser. User can change it anytime via selector.</p>
            <p><strong>&#128205; Manual Mode:</strong> Initial language set by admin. User can change it anytime via selector.</p>
            <h3 style="margin-top:20px;">Exclude Posts from Translation</h3>
            <p>Check the box in the FreedomTranslate meta box in the post editor sidebar to exclude a specific post/page.</p>
            <h3 style="margin-top:20px;">Per-Post Cache Duration</h3>
            <p>Override the global TTL for individual posts using the duration field in the FreedomTranslate meta box in the editor sidebar.</p>
        </div>
    </div>
    <?php
}

/**
 * Add meta box to post/page editor for per-post translation settings
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'freedomtranslate_exclude_meta', 'FreedomTranslate',
        function($post) {
            wp_nonce_field('freedomtranslate_metabox', 'freedomtranslate_meta_nonce');
            $exclude    = get_post_meta($post->ID, '_freedomtranslate_exclude', true);
            $ttl_days   = get_post_meta($post->ID, '_freedomtranslate_cache_ttl', true);
            $global_ttl = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
            $inject_selector = get_post_meta($post->ID, '_freedomtranslate_inject_selector', true);
            echo '<label><input type="checkbox" name="freedomtranslate_inject_selector" value="1" ' . checked($inject_selector,'1',false) . '> ';
            echo esc_html('Inject language selector at top of this post/page') . '</label>';
            echo '<br style="margin:6px 0;">';
            echo '<label><input type="checkbox" name="freedomtranslate_exclude" value="1" ' . checked($exclude,'1',false) . '> ';
            echo esc_html('Exclude this page/post from automatic translation') . '</label>';
            echo '<p style="margin-top:12px;"><label for="freedomtranslate_cache_ttl"><strong>Cache duration (days):</strong></label><br>';
            echo '<input type="number" id="freedomtranslate_cache_ttl" name="freedomtranslate_cache_ttl" ';
            echo 'value="' . esc_attr($ttl_days) . '" min="1" max="365" style="width:80px;margin-top:6px;"></p>';
            echo '<p class="description">Leave empty to use global default (' . esc_html($global_ttl) . ' days).</p>';
        },
        ['post','page'], 'side'
    );
});

/**
 * Save per-post translation meta when a post is saved
 */
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['freedomtranslate_meta_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freedomtranslate_meta_nonce'])), 'freedomtranslate_metabox')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['freedomtranslate_inject_selector'])) {
        update_post_meta($post_id, '_freedomtranslate_inject_selector', '1');
    } else {
        delete_post_meta($post_id, '_freedomtranslate_inject_selector');
    }

    if (isset($_POST['freedomtranslate_exclude'])) {
        update_post_meta($post_id, '_freedomtranslate_exclude', '1');
    } else {
        delete_post_meta($post_id, '_freedomtranslate_exclude');
    }
    if (isset($_POST['freedomtranslate_cache_ttl']) && $_POST['freedomtranslate_cache_ttl'] !== '') {
        update_post_meta($post_id, '_freedomtranslate_cache_ttl', max(1, min(365, absint($_POST['freedomtranslate_cache_ttl']))));
    } else {
        delete_post_meta($post_id, '_freedomtranslate_cache_ttl');
    }
});

/**
 * Schedule automatic weekly cache purge on plugin activation
 */
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('freedomtranslate_auto_purge'))
        wp_schedule_event(time(), 'weekly', 'freedomtranslate_auto_purge');
});

/**
 * Remove scheduled cache purge on plugin deactivation
 */
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('freedomtranslate_auto_purge');
});

/**
 * Worker for automatic weekly cache purge
 */
add_action('freedomtranslate_auto_purge', function() {
    global $wpdb;
    $p = esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $p . '%', '_transient_timeout_' . $p . '%'));
    wp_cache_flush();
});

/**
 * Inject Google Translate hash-based widget in footer
 * Only runs when googlehash service is selected and post is not excluded
 */
add_action('wp_footer', function() {
    if (get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, '') !== 'googlehash') return;
    $post_id = get_the_ID();
    if ($post_id && get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    ?>
    <div id="google_translate_element" style="display:none;"></div>
    <script type="text/javascript">
        function googleTranslateElementInit() {
            new google.translate.TranslateElement({ pageLanguage: '<?php echo esc_js($site_lang); ?>' }, 'google_translate_element');
        }
    </script>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
    <script type="text/javascript">
    // Helper: read a cookie value by name
    // Works because HttpOnly is false for the freedomtranslate_lang cookie
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
    document.addEventListener('DOMContentLoaded', function() {
        var userLang = getCookie('freedomtranslate_lang') || '<?php echo esc_js($user_lang); ?>';
        var siteLang = '<?php echo esc_js($site_lang); ?>';
        if (userLang !== siteLang && window.location.hash.indexOf('googtrans') === -1) {
            if (!sessionStorage.getItem('ft_google_redirected')) {
                sessionStorage.setItem('ft_google_redirected', '1');
                window.location.hash = 'googtrans(' + siteLang + '|' + userLang + ')';
                window.location.reload();
            }
        }
        var selector = document.querySelector('select[name="freedomtranslate_lang"]');
        if (selector) selector.addEventListener('change', function() { sessionStorage.removeItem('ft_google_redirected'); });
    });
    </script>
    <?php
});
