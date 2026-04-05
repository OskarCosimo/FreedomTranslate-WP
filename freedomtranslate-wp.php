<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with AI or remote URL with API + cache, auto-prewarm, and static strings manager.
Version: 1.5.9
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
define('FREEDOMTRANSLATE_LIBRE_MODE_OPTION',          'freedomtranslate_libre_mode');
define('FREEDOMTRANSLATE_PREWARM_OPTION',             'freedomtranslate_prewarm_on_save');
define('FREEDOMTRANSLATE_STATIC_STRINGS_OPTION',      'freedomtranslate_static_strings');
define('FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION', 'freedomtranslate_bot_signatures');

function freedomtranslate_get_default_bots_string() {
    return "googlebot\nbingbot\nyandex\nduckduckbot\nslurp\nbaiduspider\nia_archiver\ntwitterbot\nfacebookexternalhit\nrogerbot\nlinkedinbot\nembedly\nquora link preview\nshowyoubot\noutbrain\npinterest\nslackbot\nvkshare\nw3c_validator\nsemrushbot\nahrefsbot\nmj12bot\ndotbot\npetalbot\nseznambot\nbot\nspider\ncrawl\nscraper";
}

/**
 * Detects if the current visitor is a bot/crawler using ONLY the database list.
 */
function freedomtranslate_is_bot() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    // I bot più basilari a volte omettono l'user-agent. Li blocchiamo per sicurezza.
    if (empty($user_agent)) return true; 

    // Recupera la lista dal DB (ritorna 'false' solo se l'opzione non è MAI stata salvata)
    $bot_signatures = get_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, false);
    
    // Al primo avvio in assoluto: popoliamo il DB con i default
    if ($bot_signatures === false) {
        $bot_signatures = array_filter(array_map('trim', explode("\n", freedomtranslate_get_default_bots_string())));
        update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $bot_signatures);
    }

    // Se la lista è un array (anche vuoto se hai cancellato tutto a mano), controlliamo
    if (is_array($bot_signatures)) {
        foreach ($bot_signatures as $bot) {
            $bot = strtolower(trim($bot));
            if ($bot !== '' && strpos($user_agent, $bot) !== false) {
                return true;
            }
        }
    }
    return false;
}

// ========================================================================
// 1. CORE & ROUTING LOGIC
// ========================================================================

add_action('init', function() {
    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            setcookie('freedomtranslate_lang', $lang, time() + (DAY_IN_SECONDS * 30), '/', COOKIE_DOMAIN, is_ssl(), false);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }
});

add_filter('the_permalink', function($url) {
    $lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($lang !== $site_lang) {
        $url = add_query_arg('freedomtranslate_lang', $lang, $url);
    }
    return $url;
});

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

function freedomtranslate_is_language_enabled($lang) {
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys(freedomtranslate_get_all_languages()));
    return in_array($lang, $enabled, true);
}

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

// ========================================================================
// 2. SHORTCODES (Selector & Static Strings)
// ========================================================================

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
 * Shortcode for Static Strings (e.g. headers, footers)
 * Usage: [ft_string id="my_footer_text"]
 */
add_shortcode('ft_string', function($atts) {
    $atts = shortcode_atts(['id' => ''], $atts, 'ft_string');
    if (empty($atts['id'])) return '';

    $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
    if (!isset($strings[$atts['id']])) return '';

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);

    if ($user_lang === $site_lang || !isset($strings[$atts['id']]['translations'][$user_lang])) {
        return esc_html($strings[$atts['id']]['original']);
    }
    return esc_html($strings[$atts['id']]['translations'][$user_lang]);
});


// ========================================================================
// 3. TRANSLATION APIS
// ========================================================================

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

function freedomtranslate_translate_libre($text, $source, $target, $format = 'text') {
    $api_url   = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key   = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    $body = ['q'=>$text,'source'=>$source,'target'=>$target,'format'=>$format];
    if (!empty($api_key)) $body['api_key'] = $api_key;
    
    // Increased timeout for large AI models
    $response = wp_remote_post($api_url, ['body'=>$body,'timeout'=>900]);
    if (is_wp_error($response)) return $text;
    
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['translatedText']) ? $json['translatedText'] : $text;
}

// ========================================================================
// 4. HTML PROTECTION & ASYNC WORKERS
// ========================================================================

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

function freedomtranslate_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        $text = preg_replace('/' . preg_quote($placeholder, '/') . '/i', $original_word, $text);
    }
    return $text;
}

function freedomtranslate_translate_html_by_nodes($html, $source, $target, $api_url, $api_key) {
    $parts = preg_split('/(<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;

    $skip_depth = 0;
    $result     = array();

    foreach ($parts as $part) {
        if (isset($part[0]) && $part[0] === '<') {
            $result[] = $part;
            $tag_name = '';
            if (preg_match('/<\/?([a-zA-Z][a-zA-Z0-9]*)/', $part, $tm)) {
                $tag_name = strtolower($tm[1]);
            }
            if (in_array($tag_name, array('script', 'style'), true)) {
                if ($part[1] === '/') $skip_depth = max(0, $skip_depth - 1);
                else $skip_depth++;
            }
            continue;
        }

        if ($skip_depth > 0 || trim($part) === '') {
            $result[] = $part;
            continue;
        }

        preg_match('/^(\s*)/', $part, $lm);
        preg_match('/(\s*)$/', $part, $tm);
        $leading  = isset($lm[1]) ? $lm[1] : '';
        $trailing = isset($tm[1]) ? $tm[1] : '';
        $trimmed  = trim($part);

        if ($trimmed === '' || !preg_match('/\p{L}/u', $trimmed)) { $result[] = $part; continue; }

        $body = ['q' => $trimmed, 'source' => $source, 'target' => $target, 'format' => 'text'];
        if (!empty($api_key)) $body['api_key'] = $api_key;

        $response = wp_remote_post($api_url, ['body' => $body, 'timeout' => 900]);
        if (is_wp_error($response)) { $result[] = $part; continue; }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($json['translatedText'])) {
            $result[] = $leading . $json['translatedText'] . $trailing;
        } else {
            $result[] = $part;
        }
    }

    return implode('', $result);
}

function freedomtranslate_get_ttl_days($post_id = 0) {
    if ($post_id > 0) {
        $post_ttl = get_post_meta($post_id, '_freedomtranslate_cache_ttl', true);
        if ($post_ttl !== '' && (int) $post_ttl > 0) return (int) $post_ttl;
    }
    $global = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    return (int) $global > 0 ? (int) $global : 30;
}

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
            $translated = freedomtranslate_translate_libre($text, $source, $target, $format);
            break;
    }

    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }
    set_transient($cache_key, $translated, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
    return $translated;
}

function freedomtranslate_async_worker($cache_key, $content, $site_lang, $user_lang, $post_id) {
    set_time_limit(0);
    ignore_user_abort(true);
    if (get_transient($cache_key) !== false) return;

    $placeholder = '';
    $content     = str_replace('[freedomtranslate_selector]', $placeholder, $content);

    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $placeholders   = [];
    if (!empty($excluded_words)) {
        list($content, $placeholders) = freedomtranslate_protect_excluded_words_in_html($content, $excluded_words);
    }

    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

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

    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }

    $translated = str_replace($placeholder, '[freedomtranslate_selector]', $translated);

    set_transient($cache_key, $translated, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
    set_transient('freedomtranslate_ready_' . md5($cache_key), '1', HOUR_IN_SECONDS);
    delete_transient('freedomtranslate_progress_' . md5($cache_key));
    delete_transient('freedomtranslate_pending_' . md5($cache_key));
}

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

function freedomtranslate_ajax_check_ready() {
    $cache_key = isset($_GET['cache_key']) ? sanitize_text_field(wp_unslash($_GET['cache_key'])) : '';
    if (empty($cache_key)) { wp_send_json(array('ready' => false, 'progress' => 0)); return; }

    $ready = get_transient('freedomtranslate_ready_' . md5($cache_key)) !== false;
    if ($ready) {
        wp_send_json(array('ready' => true, 'progress' => 100));
        return;
    }

    $progress_data = get_transient('freedomtranslate_progress_' . md5($cache_key));
    $percent = 0;
    if (is_array($progress_data) && !empty($progress_data['total'])) {
        $percent = (int) round(($progress_data['done'] / $progress_data['total']) * 100);
    }
    wp_send_json(array('ready' => false, 'progress' => $percent));
}
add_action('wp_ajax_nopriv_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');
add_action('wp_ajax_freedomtranslate_check_ready',        'freedomtranslate_ajax_check_ready');

// ========================================================================
// 5. CONTENT FILTERS & PRE-WARMING
// ========================================================================

function freedomtranslate_filter_post_content($content) {
    if (is_admin()) return $content;

    global $post;
    if ($post && get_post_meta($post->ID, '_freedomtranslate_exclude', true) === '1') return $content;

    if ($post) {
        $skip_types = apply_filters('freedomtranslate_skip_post_types',
            array('shop_order', 'shop_coupon', 'shop_webhook', 'wc_order', 'wc_product_tab')
        );
        if (in_array($post->post_type, $skip_types, true)) return $content;
    }

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($user_lang === $site_lang || !freedomtranslate_is_language_enabled($user_lang)) return $content;

    $service    = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $libre_mode = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
    $post_id    = $post ? $post->ID : 0;

    $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($content . $site_lang . $user_lang . 'html' . $service);

    if ($service === 'libretranslate' && $libre_mode === 'async') {
        $cached = get_transient($cache_key);

        if ($cached !== false) return do_shortcode($cached);

        if (freedomtranslate_is_bot()) {
            return $content; 
        }

        $pending_key = 'freedomtranslate_pending_' . md5($cache_key);
        if (!get_transient($pending_key)) {

            set_transient($pending_key, '1', 30 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [
                $cache_key, $content, $site_lang, $user_lang, $post_id,
            ]);
        }

        global $freedomtranslate_active_cache_key;
        $freedomtranslate_active_cache_key = $cache_key;
        add_action('wp_footer', function() use ($cache_key) {
            freedomtranslate_async_banner_script($cache_key);
        });
        return $content;
    }

    $placeholder = '';
    $content     = str_replace('[freedomtranslate_selector]', $placeholder, $content);
    $translated  = freedomtranslate_translate($content, $site_lang, $user_lang, 'html', $post_id);
    $translated  = str_replace($placeholder, '[freedomtranslate_selector]', $translated);
    return do_shortcode($translated);
}
add_filter('the_content', 'freedomtranslate_filter_post_content');
add_filter('the_title',   'freedomtranslate_filter_post_content');

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

add_filter('the_content', function($content) {
    if (is_admin()) return $content;
    $post_id = get_the_ID();
    if (!$post_id) return $content;
    if (get_post_meta($post_id, '_freedomtranslate_inject_selector', true) !== '1') return $content;
    return '[freedomtranslate_selector]' . $content;
}, 9);

/**
 * AUTO-PREWARM ON SAVE
 * Schedules translations for all enabled languages when a post is created/updated.
 */
add_action('save_post', function($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (get_option(FREEDOMTRANSLATE_PREWARM_OPTION, '0') !== '1') return;
    if (get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;
    if ($post->post_status !== 'publish') return;

    $content = apply_filters('the_content', $post->post_content); // Get parsed content
    if (empty(trim($content))) return;

    $site_lang = substr(get_locale(), 0, 2);
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    
    // Only prewarm for AI/LibreTranslate
    if ($service !== 'libretranslate') return;

    $delay_counter = 0;
    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;
        
        $cache_key = FREEDOMTRANSLATE_CACHE_PREFIX . md5($content . $site_lang . $lang . 'html' . $service);
        $pending_key = 'freedomtranslate_pending_' . md5($cache_key);
        
        // Skip if already translated or queued
        if (get_transient($cache_key) !== false || get_transient($pending_key) !== false) continue;

        set_transient($pending_key, '1', 30 * MINUTE_IN_SECONDS);
        
        // Schedule with a 10-second gap between languages to avoid crashing the AI Server
        wp_schedule_single_event(time() + ($delay_counter * 10), 'freedomtranslate_async_translate', [
            $cache_key, $content, $site_lang, $lang, $post_id
        ]);
        $delay_counter++;
    }
}, 10, 3);


function freedomtranslate_async_banner_script($cache_key) {
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <script>
    (function() {
        var cacheKey    = <?php echo json_encode($cache_key); ?>;
        var ajaxUrl     = <?php echo json_encode($ajax_url); ?>;
        var sessionFlag = 'ft_reloaded_' + cacheKey;

        function getBanners() {
            var all = document.querySelectorAll('.ft-progress-banner[data-cache-key]');
            var res = [];
            for (var i = 0; i < all.length; i++) {
                if (all[i].getAttribute('data-cache-key') === cacheKey) res.push(all[i]);
            }
            return res;
        }

        if (sessionStorage.getItem(sessionFlag)) {
            var bs = getBanners();
            for (var i = 0; i < bs.length; i++) bs[i].classList.remove('ft-pb-visible');
            return;
        }

        var interval    = null;
        var attempts    = 0;
        var maxAttempts = 360;

        function checkReady() {
            attempts++;
            if (attempts > maxAttempts) {
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

        interval = setInterval(checkReady, 5000);
    })();
    </script>
    <?php
}

// ========================================================================
// 6. ADMIN PANEL
// ========================================================================

function freedomtranslate_admin_menu() {
    add_options_page(
        'FreedomTranslate Settings', 'FreedomTranslate',
        'manage_options', 'freedomtranslate', 'freedomtranslate_settings_page'
    );
}
add_action('admin_menu', 'freedomtranslate_admin_menu');

function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have sufficient permissions to access this page.'));

    // --- FORM HANDLERS ---

    if (isset($_POST['freedomtranslate_restore_bots'])) {
        check_admin_referer('freedomtranslate_save_general', 'freedomtranslate_nonce_general');
        $default_bots = array_filter(array_map('trim', explode("\n", freedomtranslate_get_default_bots_string())));
        update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $default_bots);
        echo '<div class="notice notice-success"><p>Default bot signatures restored successfully.</p></div>';
    }

    elseif (isset($_POST['freedomtranslate_save_general'])) {
        check_admin_referer('freedomtranslate_save_general', 'freedomtranslate_nonce_general');
        update_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, sanitize_text_field(wp_unslash($_POST['translation_service'])));
        
        $mode = sanitize_text_field(wp_unslash($_POST['lang_detection_mode']));
        update_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, $mode);
        if ($mode === 'manual' && isset($_POST['default_language'])) {
            update_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, sanitize_text_field(wp_unslash($_POST['default_language'])));
        }

        $url = esc_url_raw(trim(wp_unslash($_POST['freedomtranslate_api_url'])));
        if (!empty($url)) update_option(FREEDOMTRANSLATE_API_URL_OPTION, $url);
        update_option(FREEDOMTRANSLATE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key'])));
        
        if (isset($_POST['freedomtranslate_google_api_key'])) {
            update_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_google_api_key'])));
        }
        
        $allowed_modes = ['sync', 'async'];
        $libre_mode = sanitize_text_field(wp_unslash($_POST['freedomtranslate_libre_mode']));
        update_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, in_array($libre_mode, $allowed_modes, true) ? $libre_mode : 'async');
        
        $prewarm = isset($_POST['freedomtranslate_prewarm_on_save']) ? '1' : '0';
        update_option(FREEDOMTRANSLATE_PREWARM_OPTION, $prewarm);

        $ttl = max(0, min(365, absint(wp_unslash($_POST['freedomtranslate_cache_ttl_global']))));
        update_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, $ttl);

        if (isset($_POST['freedomtranslate_bot_signatures'])) {
            $bots = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_bot_signatures'])))));
            update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $bots);
        }
        
        echo '<div class="notice notice-success"><p>General settings saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_languages'])) {
        check_admin_referer('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages');
        $languages = isset($_POST['freedomtranslate_languages']) ? array_map('sanitize_text_field', wp_unslash($_POST['freedomtranslate_languages'])) : [];
        update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $languages);
        echo '<div class="notice notice-success"><p>Enabled languages saved.</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_static_string'])) {
        check_admin_referer('freedomtranslate_save_static_string', 'freedomtranslate_nonce_static');
        $id = sanitize_key($_POST['new_string_id']);
        $text = sanitize_textarea_field($_POST['new_string_text']);
        
        if (!empty($id) && !empty($text)) {
            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            
            $translations = [];
            $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
            $site_lang = substr(get_locale(), 0, 2);
            $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
            $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

            foreach ($enabled_langs as $lang) {
                if ($lang === $site_lang) continue;
                $body = ['q' => $text, 'source' => $site_lang, 'target' => $lang, 'format' => 'text'];
                if (!empty($api_key)) $body['api_key'] = $api_key;
                $response = wp_remote_post($api_url, ['body' => $body, 'timeout' => 30]);
                if (!is_wp_error($response)) {
                    $json = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($json['translatedText'])) {
                        $translations[$lang] = $json['translatedText'];
                    }
                }
            }
            
            $strings[$id] = [
                'original' => $text,
                'translations' => $translations
            ];
            update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            echo '<div class="notice notice-success"><p>String saved and translated successfully!</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_delete_string'])) {
        check_admin_referer('freedomtranslate_delete_string', 'freedomtranslate_nonce_del_string');
        $id = sanitize_key($_POST['delete_string_id']);
        $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        if (isset($strings[$id])) {
            unset($strings[$id]);
            update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            echo '<div class="notice notice-success"><p>String deleted.</p></div>';
        }
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

    // --- CRON QUEUE MANAGERS ---
    
    // Purge ALL Crons
    if (isset($_POST['freedomtranslate_purge_cron'])) {
        check_admin_referer('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron');
        wp_clear_scheduled_hook('freedomtranslate_async_translate');
        global $wpdb;
        $p = esc_sql('freedomtranslate_pending_');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $p . '%', '_transient_timeout_' . $p . '%'));
        echo '<div class="notice notice-success"><p>All pending translation jobs and safety padlocks have been cleared.</p></div>';
    }

    // Delete SINGLE Cron
    if (isset($_POST['freedomtranslate_delete_single_cron'])) {
        check_admin_referer('freedomtranslate_delete_single_cron', 'freedomtranslate_nonce_single_cron');
        $timestamp = intval($_POST['cron_timestamp']);
        // Unserialize the exact args to unschedule
        $args = unserialize(base64_decode($_POST['cron_args']));
        
        if ($timestamp && is_array($args)) {
            wp_unschedule_event($timestamp, 'freedomtranslate_async_translate', $args);
            // Also delete the specific padlock for this job
            $cache_key = $args[0]; // The first argument is the cache_key
            delete_transient('freedomtranslate_pending_' . md5($cache_key));
            echo '<div class="notice notice-success"><p>Specific background job cancelled.</p></div>';
        }
    }

    // Load Variables
    $current_service   = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $detection_mode    = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    $default_lang      = get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, substr(get_locale(), 0, 2));
    $all_languages     = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $api_url           = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key           = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $global_ttl        = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    $libre_mode        = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
    $prewarm           = get_option(FREEDOMTRANSLATE_PREWARM_OPTION, '0');
    $static_strings    = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
    $active_tab        = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    ?>
    <div class="wrap">
        <h1>FreedomTranslate Settings</h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=freedomtranslate&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General & API</a>
            <a href="?page=freedomtranslate&tab=languages" class="nav-tab <?php echo $active_tab == 'languages' ? 'nav-tab-active' : ''; ?>">Languages</a>
            <a href="?page=freedomtranslate&tab=static_strings" class="nav-tab <?php echo $active_tab == 'static_strings' ? 'nav-tab-active' : ''; ?>">Static Strings</a>
            <a href="?page=freedomtranslate&tab=queue_monitor" class="nav-tab <?php echo $active_tab == 'queue_monitor' ? 'nav-tab-active' : ''; ?>">Queue Monitor</a>
            <a href="?page=freedomtranslate&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>">Tools</a>
        </h2>

        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); margin-top:15px;">
        
        <?php if ($active_tab === 'general'): ?>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_general', 'freedomtranslate_nonce_general'); ?>
                
                <h3>Translation Service</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Select Engine</th>
                        <td>
                            <label><input type="radio" name="translation_service" value="libretranslate" onchange="ftToggleServiceUI()" <?php checked($current_service,'libretranslate'); ?>> AI / LibreTranslate (Local/Remote)</label><br>
                            <label><input type="radio" name="translation_service" value="google_official" onchange="ftToggleServiceUI()" <?php checked($current_service,'google_official'); ?>> Google Cloud API (Official - Paid)</label><br>
                            <label><input type="radio" name="translation_service" value="googlehash" onchange="ftToggleServiceUI()" <?php checked($current_service,'googlehash'); ?>> Google Translate (free hash-based)</label>
                        </td>
                    </tr>
                </table>

                <hr>

                <div id="ui_block_libretranslate" class="ft-service-block" style="display:none;">
                    <h3>AI / LibreTranslate Configuration</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_api_url">API URL</label></th>
                            <td><input type="text" id="freedomtranslate_api_url" name="freedomtranslate_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_api_key">API Key</label></th>
                            <td><input type="text" id="freedomtranslate_api_key" name="freedomtranslate_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_libre_mode">Processing Mode</label></th>
                            <td>
                                <select id="freedomtranslate_libre_mode" name="freedomtranslate_libre_mode">
                                    <option value="async" <?php selected($libre_mode,'async'); ?>>Background Translation (Async - Recommended)</option>
                                    <option value="sync" <?php selected($libre_mode,'sync'); ?>>Real-time Translation (Sync - Blocking)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="ui_block_google_official" class="ft-service-block" style="display:none;">
                    <h3>Google Cloud API Configuration</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_google_api_key">Google API Key</label></th>
                            <td><input type="text" id="freedomtranslate_google_api_key" name="freedomtranslate_google_api_key" value="<?php $g_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, ''); echo esc_attr($g_key); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                </div>

                <div id="ui_block_googlehash" class="ft-service-block" style="display:none;">
                    <div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>ℹ️ Google Translate (Hash-based) is active.</strong><br>This mode works entirely on the client side. It does not require API keys, background tasks, or local caching.</p>
                    </div>
                </div>

                <div id="ui_block_automation">
                    <hr>
                    <h3>Automation & Cache</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Auto-Translate on Save</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="freedomtranslate_prewarm_on_save" value="1" <?php checked($prewarm, '1'); ?>>
                                    <strong>Automatically translate posts in background when saved</strong>
                                </label>
                                <p class="description">If enabled, when you publish/update a post, WP-Cron will trigger translations for all enabled languages. (Requires AI/LibreTranslate)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_cache_ttl_global">Cache TTL (Days)</label></th>
                            <td>
                                <input type="number" id="freedomtranslate_cache_ttl_global" name="freedomtranslate_cache_ttl_global" value="<?php echo esc_attr($global_ttl); ?>" min="0" max="365" style="width: 80px;">
                                <p class="description">Set to <strong>0</strong> to make cache permanent (it will never expire). Otherwise, set the expiration in days (e.g. 30).</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="ui_block_security">
                    <hr>
                    <h3>Security & Anti-Bot</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_bot_signatures">Bot / Crawler Signatures</label></th>
                            <td>
                                <?php
                                $saved_bots = get_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, false);
                                $display_bots = ($saved_bots === false) ? freedomtranslate_get_default_bots_string() : implode("\n", $saved_bots);
                                ?>
                                <textarea id="freedomtranslate_bot_signatures" name="freedomtranslate_bot_signatures" rows="6" cols="50" class="large-text"><?php echo esc_textarea($display_bots); ?></textarea>
                                <p class="description">List user-agent keywords (one per line) that should <strong>NOT</strong> trigger automatic background translations.</p>
                                
                                <button type="submit" name="freedomtranslate_restore_bots" class="button button-secondary" style="margin-top: 10px;" onclick="return confirm('Are you sure you want to overwrite your current list and restore the default bots?');">Restore Default Bots</button>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit"><input type="submit" name="freedomtranslate_save_general" class="button button-primary" value="Save Settings"></p>
            </form>

            <script>
            function ftToggleServiceUI() {
                var service = document.querySelector('input[name="translation_service"]:checked').value;
                document.getElementById('ui_block_libretranslate').style.display = 'none';
                document.getElementById('ui_block_google_official').style.display = 'none';
                document.getElementById('ui_block_googlehash').style.display = 'none';
                
                if (document.getElementById('ui_block_' + service)) {
                    document.getElementById('ui_block_' + service).style.display = 'block';
                }

                if (service === 'googlehash') {
                    document.getElementById('ui_block_automation').style.display = 'none';
                } else {
                    document.getElementById('ui_block_automation').style.display = 'block';
                }
            }
            document.addEventListener("DOMContentLoaded", ftToggleServiceUI);
            </script>

        <?php elseif ($active_tab === 'languages'): ?>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages'); ?>
                <h3>Enable Languages</h3>
                <div style="column-count:3;margin-top:15px; margin-bottom:20px;">
                    <?php foreach ($all_languages as $code => $label): ?>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="checkbox" name="freedomtranslate_languages[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $enabled_languages, true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="submit"><input type="submit" name="freedomtranslate_save_languages" class="button button-primary" value="Save Languages"></p>
            </form>

        <?php elseif ($active_tab === 'static_strings'): ?>
            <h3>Global Static Strings Manager</h3>
            <p class="description">Use this to translate global theme elements (like headers, footers, or widgets) once and for all, without querying the AI server on page load.</p>
            
            <div style="background:#f9f9f9; padding:15px; border:1px solid #ccc; margin-bottom:20px;">
                <h4>Add New String</h4>
                <form method="post">
                    <?php wp_nonce_field('freedomtranslate_save_static_string', 'freedomtranslate_nonce_static'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label>String ID (e.g., footer_credits)</label></th>
                            <td><input type="text" name="new_string_id" required pattern="[a-zA-Z0-9_-]+" title="Only letters, numbers, dashes, and underscores"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Original Text (Default Language)</label></th>
                            <td><textarea name="new_string_text" rows="3" class="large-text" required></textarea></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="freedomtranslate_save_static_string" class="button button-primary" value="Translate in all languages & Save"></p>
                </form>
            </div>

            <h4>Saved Strings</h4>
            <?php if (empty($static_strings)): ?>
                <p>No static strings added yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>ID</th><th>Original Text</th><th>Shortcode</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($static_strings as $id => $data): ?>
                            <tr>
                                <td><strong><?php echo esc_html($id); ?></strong></td>
                                <td><?php echo esc_html($data['original']); ?></td>
                                <td><code>[ft_string id="<?php echo esc_attr($id); ?>"]</code></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Delete this string?');">
                                        <?php wp_nonce_field('freedomtranslate_delete_string', 'freedomtranslate_nonce_del_string'); ?>
                                        <input type="hidden" name="delete_string_id" value="<?php echo esc_attr($id); ?>">
                                        <input type="submit" name="freedomtranslate_delete_string" class="button button-small" value="Delete">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($active_tab === 'queue_monitor'): ?>
            
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Background Translation Jobs (WP-Cron)</h3>
                <form method="post" onsubmit="return confirm('Are you sure you want to delete ALL pending background translations?');">
                    <?php wp_nonce_field('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron'); ?>
                    <input type="submit" name="freedomtranslate_purge_cron" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="Clear ALL Pending Translations (Panic Button)">
                </form>
            </div>
            
            <p class="description">Monitor translations currently waiting to be processed by your server. If this list gets stuck, use the Panic Button to clear the queue.</p>

            <?php
            // Fetch scheduled crons specifically for our plugin
            $crons = _get_cron_array();
            $ft_crons = [];
            
            if (!empty($crons)) {
                foreach ($crons as $timestamp => $cron_hooks) {
                    if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                        foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                            $ft_crons[] = [
                                'timestamp' => $timestamp,
                                'args'      => $event['args'],
                                'sig'       => $sig
                            ];
                        }
                    }
                }
            }
            ?>

            <?php if (empty($ft_crons)): ?>
                <div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                    <p style="margin:0;"><strong>The queue is currently empty.</strong> No translations are waiting to be processed.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Scheduled Time</th>
                            <th>Target Post ID</th>
                            <th>Target Language</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ft_crons as $job): 
                            $time_diff = $job['timestamp'] - time();
                            $when = $time_diff > 0 ? "In " . human_time_diff(time(), $job['timestamp']) : "<strong style='color:#d63638;'>Past due</strong> (Processing soon...)";
                            
                            // args mapping: [0] cache_key, [1] content, [2] site_lang, [3] user_lang, [4] post_id
                            $post_id = isset($job['args'][4]) ? $job['args'][4] : 'Unknown';
                            $target_lang = isset($job['args'][3]) ? strtoupper($job['args'][3]) : 'Unknown';
                            
                            // Safe serialization for hidden field
                            $encoded_args = base64_encode(serialize($job['args']));
                        ?>
                            <tr>
                                <td><?php echo wp_kses_post($when); ?></td>
                                <td><strong><?php echo esc_html($post_id); ?></strong> <a href="<?php echo get_edit_post_link($post_id); ?>" target="_blank">(Edit)</a></td>
                                <td><?php echo esc_html($target_lang); ?></td>
                                <td>
                                    <form method="post">
                                        <?php wp_nonce_field('freedomtranslate_delete_single_cron', 'freedomtranslate_nonce_single_cron'); ?>
                                        <input type="hidden" name="cron_timestamp" value="<?php echo esc_attr($job['timestamp']); ?>">
                                        <input type="hidden" name="cron_args" value="<?php echo esc_attr($encoded_args); ?>">
                                        <input type="submit" name="freedomtranslate_delete_single_cron" class="button button-small" value="Delete">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($active_tab === 'tools'): ?>
            <h3>Cache Management</h3>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache'); ?>
                <p>Clear the entire translation cache to force re-translation of all content.</p>
                <input type="submit" name="freedomtranslate_purge_cache" class="button button-secondary" value="Clear All Cache">
            </form>
        <?php endif; ?>
        
        </div>
    </div>
    <?php
}

add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['freedomtranslate_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['freedomtranslate_meta_nonce'])), 'freedomtranslate_metabox')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['freedomtranslate_exclude'])) update_post_meta($post_id, '_freedomtranslate_exclude', '1');
    else delete_post_meta($post_id, '_freedomtranslate_exclude');
});

// Auto-purge cron
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('freedomtranslate_auto_purge')) wp_schedule_event(time(), 'weekly', 'freedomtranslate_auto_purge');
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('freedomtranslate_auto_purge');
});
add_action('freedomtranslate_auto_purge', function() {
    global $wpdb;
    $p = esc_sql(FREEDOMTRANSLATE_CACHE_PREFIX);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $p . '%', '_transient_timeout_' . $p . '%'));
    wp_cache_flush();
});

// Google Hash JS injection
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
    });
    </script>
    <?php
});
