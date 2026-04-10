<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with AI or remote URL with API + custom database cache, and static strings manager.
Version: 1.9.1
Author: thefreedom
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');

// Plugin constants
define('FREEDOMTRANSLATE_API_URL_OPTION',             'freedomtranslate_api_url');
define('FREEDOMTRANSLATE_API_URL_DEFAULT',             'http://localhost:5000/translate');
define('FREEDOMTRANSLATE_API_KEY_OPTION',             'freedomtranslate_api_key');
define('FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION',      'freedomtranslate_google_api_key');
define('FREEDOMTRANSLATE_LANGUAGES_OPTION',           'freedomtranslate_languages');
define('FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION',       'freedomtranslate_words_exclude');
define('FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION', 'freedomtranslate_service');
define('FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION', 'freedomtranslate_lang_detection_mode');
define('FREEDOMTRANSLATE_DEFAULT_LANG_OPTION',        'freedomtranslate_default_lang');
define('FREEDOMTRANSLATE_CACHE_TTL_OPTION',           'freedomtranslate_cache_ttl_global');
define('FREEDOMTRANSLATE_AUTO_INJECT_OPTION',         'freedomtranslate_auto_inject');
define('FREEDOMTRANSLATE_LIBRE_MODE_OPTION',          'freedomtranslate_libre_mode');
define('FREEDOMTRANSLATE_STATIC_STRINGS_OPTION',      'freedomtranslate_static_strings');
define('FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION',      'freedomtranslate_bot_signatures');
define('FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION',      'freedomtranslate_max_concurrent_jobs');
define('FREEDOMTRANSLATE_PREWARM_OPTION',             'freedomtranslate_prewarm_on_save');
define('FREEDOMTRANSLATE_CHUNK_SIZE_OPTION',          'freedomtranslate_chunk_size');
define('FREEDOMTRANSLATE_STRICT_MANUAL_OPTION',       'freedomtranslate_strict_manual');

// ========================================================================
// 1. DATABASE SETUP & CUSTOM CACHE ENGINE
// ========================================================================

function freedomtranslate_install_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'freedomtranslate_cache';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
    hash_key varchar(64) NOT NULL,
    post_id bigint(20) NOT NULL DEFAULT 0,
    target_lang varchar(10) NOT NULL,
    translation longtext NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'completed', 
    progress int(11) NOT NULL DEFAULT 0,
    total_chunks int(11) NOT NULL DEFAULT 0,
    expires_at datetime DEFAULT NULL,
    PRIMARY KEY  (hash_key),
    KEY post_id (post_id)
) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    update_option('freedomtranslate_db_version', '1.3'); // Aggiornato a 1.3 per forzare la modifica
}
register_activation_hook(__FILE__, 'freedomtranslate_install_db');
add_action('admin_init', function() {
    if (get_option('freedomtranslate_db_version') !== '1.3') {
        freedomtranslate_install_db();
    }
});

function ft_update_progress($hash_key, $post_id, $lang, $progress, $status = 'processing', $total_chunks = 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $wpdb->replace($table, [
        'hash_key'    => $hash_key,
        'post_id'     => $post_id,
        'target_lang' => $lang,
        'translation' => '',
        'status'      => $status,
        'progress'    => $progress,
        'total_chunks'=> $total_chunks,
        'expires_at'  => null
    ], ['%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']);
}

function ft_get_status_db($hash_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    return $wpdb->get_row($wpdb->prepare("SELECT status, progress, total_chunks FROM $table WHERE hash_key = %s", $hash_key));
}

function ft_get_cache($hash_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $row = $wpdb->get_row($wpdb->prepare("SELECT translation, expires_at FROM $table WHERE hash_key = %s", $hash_key));
    
    if ($row) {
        if ($row->expires_at && strtotime($row->expires_at . ' UTC') < time()) {
            return false; // Expired
        }
        return $row->translation;
    }
    return false;
}

function ft_set_cache($hash_key, $translation, $post_id, $target_lang, $ttl_seconds, $status = 'completed') {
    if ($status === 'completed' && trim($translation) === '') return;

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $expires = null;
    if ($ttl_seconds > 0) $expires = gmdate('Y-m-d H:i:s', time() + $ttl_seconds);
    
    $wpdb->replace($table, [
        'hash_key'    => $hash_key,
        'post_id'     => $post_id,
        'target_lang' => $target_lang,
        'translation' => $translation,
        'status'      => $status,
        'progress'    => 100,
        'total_chunks'=> 0,
        'expires_at'  => $expires
    ], ['%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']);
}

function freedomtranslate_get_default_bots_string() {
    return "googlebot\nbingbot\nyandex\nduckduckbot\nslurp\nbaiduspider\nia_archiver\ntwitterbot\nfacebookexternalhit\nrogerbot\nlinkedinbot\nembedly\nquora link preview\nshowyoubot\noutbrain\npinterest\nslackbot\nvkshare\nw3c_validator\nsemrushbot\nahrefsbot\nmj12bot\ndotbot\npetalbot\nseznambot\nbot\nspider\ncrawl\nscraper";
}

function freedomtranslate_is_bot() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    if (empty($user_agent)) return true; 

    $bot_signatures = get_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, false);
    
    if ($bot_signatures === false) {
        $bot_signatures = array_filter(array_map('trim', explode("\n", freedomtranslate_get_default_bots_string())));
        update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $bot_signatures);
    }

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
// 2. CORE & ROUTING LOGIC
// ========================================================================

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
// 3. SHORTCODES
// ========================================================================

function freedomtranslate_language_selector_shortcode($atts) {
    $atts = shortcode_atts(['post_id' => get_the_ID()], $atts, 'freedomtranslate_selector');
    $post_id = (int)$atts['post_id'];
    
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

    if ($post_id > 0) {
        $content_hash = md5("post_{$post_id}_the_content_{$current}") . '_the_content';
        $status_data = ft_get_status_db($content_hash);
        
        if ($status_data && $status_data->status !== 'completed') {
            $ck = esc_attr($content_hash);
            $html .= '<div class="ft-progress-banner ft-pb-visible" data-cache-key="' . $ck . '">'
                   . '<div class="ft-pb-spinner"></div>'
                   . '<span class="ft-pb-text">Translation in progress...</span>'
                   . '</div>';

            add_action('wp_footer', function() use ($content_hash) {
                freedomtranslate_async_banner_script($content_hash);
            });
        }
    }

    return $html;
}
add_shortcode('freedomtranslate_selector', 'freedomtranslate_language_selector_shortcode');

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
// 4. TRANSLATION APIS & HTML PROTECTION
// ========================================================================

function freedomtranslate_protect_shortcodes($html) {
    $placeholders = [];
    if (preg_match_all('/\[\/?(?:[a-zA-Z0-9_-]+)(?:\s+[^\]]+)?\]/s', $html, $matches)) {
        $count = 0;
        foreach ($matches[0] as $match) {
            $ph = '<ftshortcode id="' . $count . '"></ftshortcode>';
            $placeholders[$ph] = $match;
            $html = preg_replace('/' . preg_quote($match, '/') . '/', $ph, $html, 1);
            $count++;
        }
    }
    return [$html, $placeholders];
}

function freedomtranslate_restore_shortcodes($html, $placeholders) {
    foreach ($placeholders as $ph => $original) {
        $html = str_replace($ph, $original, $html);
    }
    return $html;
}

function freedomtranslate_translate_google_official($text, $source, $target, $format = 'text') {
    $api_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    if (empty($api_key)) return $text;
    
    $timeout = (int) get_option('freedomtranslate_api_timeout', 120);
    
    $response = wp_remote_post('https://translation.googleapis.com/language/translate/v2', [
        'body'    => json_encode(['q'=>$text,'source'=>$source,'target'=>$target,'format'=>$format,'key'=>$api_key]),
        'headers' => ['Content-Type'=>'application/json'],
        'timeout' => $timeout,
    ]);
    if (is_wp_error($response)) return $text;
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['data']['translations']['translatedText'])
        ? $json['data']['translations']['translatedText'] : $text;
}

function freedomtranslate_translate_libre($text, $source, $target, $format = 'text') {
    $api_url   = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key   = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');

    $timeout = (int) get_option('freedomtranslate_api_timeout', 120);

    $body = ['q'=>$text,'source'=>$source,'target'=>$target,'format'=>$format];
    if (!empty($api_key)) $body['api_key'] = $api_key;
    
    $response = wp_remote_post($api_url, ['body'=>$body,'timeout'=>$timeout]);
    if (is_wp_error($response)) return $text;
    
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['translatedText']) ? $json['translatedText'] : $text;
}

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

function freedomtranslate_get_ttl_days($post_id = 0) {
    if ($post_id > 0) {
        $post_ttl = get_post_meta($post_id, '_freedomtranslate_cache_ttl', true);
        if ($post_ttl !== '' && (int) $post_ttl > 0) return (int) $post_ttl;
    }
    $global = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    return (int) $global > 0 ? (int) $global : 30;
}

// ========================================================================
// 5. ASYNC WORKERS & POST FILTERS
// ========================================================================

function freedomtranslate_translate($text, $source, $target, $format = 'text', $post_id = 0, $custom_hash = '') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !freedomtranslate_is_language_enabled($target)) return $text;

    $sc_placeholders = [];
    if ($format === 'html') {
        list($text, $sc_placeholders) = freedomtranslate_protect_shortcodes($text);
    }

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
    
    // Hash Fallback System for Sync mode
    $legacy_hash = md5($text . $source . $target . $format . $service);
    $active_hash = !empty($custom_hash) ? $custom_hash : $legacy_hash;
    
    $cached = ft_get_cache($active_hash);
    
    // Fallback
    if ($cached === false && $active_hash !== $legacy_hash) {
        $cached = ft_get_cache($legacy_hash);
        if ($cached !== false) {
            ft_set_cache($active_hash, $cached, $post_id, $target, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
        }
    }

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
    if (!empty($sc_placeholders)) {
        $translated = freedomtranslate_restore_shortcodes($translated, $sc_placeholders);
    }

    ft_set_cache($active_hash, $translated, $post_id, $target, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
    return $translated;
}

// ========================================================================
// WORKER FOR STATIC STRINGS
// ========================================================================
add_action('freedomtranslate_async_string_translate', 'freedomtranslate_string_worker', 10, 5);

function freedomtranslate_string_worker($string_id, $text, $site_lang, $target_lang, $rand = 0) {
    if (get_transient('ft_kill_switch')) return;

    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');

    if ($service === 'google_official') {
        $translated = freedomtranslate_translate_google_official($text, $site_lang, $target_lang, 'text');
    } else {
        $translated = freedomtranslate_translate_libre($text, $site_lang, $target_lang, 'text');
    }

    if ($translated !== $text && !empty(trim($translated))) {
        $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        
        // Ci assicuriamo che l'ID esista ancora
        if (isset($strings[$string_id])) {
            $strings[$string_id]['translations'][$target_lang] = $translated;
            update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
        }
    }
}

function freedomtranslate_async_worker($hash_key, $site_lang, $user_lang, $post_id) {
    if (get_transient('ft_kill_switch')) return;

    set_time_limit(0);
    ignore_user_abort(true);

    $post = get_post($post_id);
    if (!$post) return;

    $max_concurrent = max(1, (int) get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2));
    $current_status = ft_get_status_db($hash_key);
    $is_processing = ($current_status && $current_status->status === 'processing');

    if (!$is_processing) {
        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';
        
        $active_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'");
        
        if ($active_count >= $max_concurrent) {
            wp_schedule_single_event(time() + rand(300, 600), 'freedomtranslate_async_translate', [$hash_key, $site_lang, $user_lang, $post_id, wp_rand()]);
            return;
        }

        ft_update_progress($hash_key, $post_id, $user_lang, 0, 'processing', $total_chunks);
    }

    if ($post->post_type === 'nav_menu_item') {
        $source_text = $post->post_title;

        if (empty(trim($source_text))) {
            $menu_item_type = get_post_meta($post_id, '_menu_item_type', true);
            $object_id      = get_post_meta($post_id, '_menu_item_object_id', true);
            
            if ($menu_item_type === 'post_type' && $object_id) {
                $source_text = get_the_title($object_id);
            } elseif ($menu_item_type === 'taxonomy' && $object_id) {
                $term = get_term($object_id);
                if ($term && !is_wp_error($term)) $source_text = $term->name;
            }
        }
    } elseif (strpos($hash_key, 'title') !== false) { 
        $source_text = $post->post_title;
    } elseif (strpos($hash_key, 'excerpt') !== false) {
        $source_text = $post->post_excerpt;
        if (empty(trim($source_text))) $source_text = wp_trim_excerpt('', $post); 
    } else {
        $source_text = $post->post_content;
    }

    $chunk_size = (int) get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500);
    list($protected_text, $sc_placeholders) = freedomtranslate_protect_shortcodes($source_text);
    $chunks = freedomtranslate_split_html_into_chunks($protected_text, $chunk_size);
    $total_chunks = count($chunks);

    $current_status = ft_get_status_db($hash_key);
    $done_chunks = ($current_status && $current_status->status === 'processing') ? (int) $current_status->progress : 0;

    $start_time = time();
    // Sync the loop with the api timeout, plus 10 second of margin
    $api_timeout = (int) get_option('freedomtranslate_api_timeout', 120);
    $max_execution_time = $api_timeout + 10;

    while ($done_chunks < $total_chunks) {
        if (get_transient('ft_kill_switch')) return; 

        // check engine
        $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
        
        if ($service === 'google_official') {
            $translated_chunk = freedomtranslate_translate_google_official($chunks[$done_chunks], $site_lang, $user_lang, 'html');
        } else {
            $translated_chunk = freedomtranslate_translate_libre($chunks[$done_chunks], $site_lang, $user_lang, 'html');
        }
        
        $chunk_hash = $hash_key . '_chunk_' . $done_chunks;
        ft_set_cache($chunk_hash, $translated_chunk, $post_id, $user_lang, HOUR_IN_SECONDS, 'chunk_temp');

        $done_chunks++;
        ft_update_progress($hash_key, $post_id, $user_lang, $done_chunks, 'processing', $total_chunks);

        if ($done_chunks < $total_chunks && (time() - $start_time) >= $max_execution_time) {
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [$hash_key, $site_lang, $user_lang, $post_id, wp_rand()]);
            return; 
        }
    }

    $final_content = '';
    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_part = ft_get_cache($hash_key . '_chunk_' . $i);
        $final_content .= ($chunk_part !== false) ? $chunk_part : '';
    }

    $final_content = freedomtranslate_restore_shortcodes($final_content, $sc_placeholders);

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $wpdb->replace($table, [
        'hash_key'    => $hash_key,
        'post_id'     => $post_id,
        'target_lang' => $user_lang,
        'translation' => $final_content,
        'status'      => 'completed',
        'progress'    => 100,
        'expires_at'  => gmdate('Y-m-d H:i:s', time() + (DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id)))
    ]);

    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $hash_key . '_chunk_%'));
    
    delete_option('ft_job_' . $hash_key);
}

function freedomtranslate_split_html_into_chunks($html, $max_chars = 500) {
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
    $hash_key = isset($_GET['cache_key']) ? sanitize_text_field(wp_unslash($_GET['cache_key'])) : '';
    $status_data = ft_get_status_db($hash_key);

    if (!$status_data) {
        wp_send_json(['ready' => false, 'progress' => 0]);
        return;
    }

    if ($status_data->status === 'completed') {
        wp_send_json(['ready' => true, 'progress' => 100]);
    } else {
        wp_send_json(['ready' => false, 'progress' => (int)$status_data->progress]);
    }
}
add_action('wp_ajax_nopriv_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');
add_action('wp_ajax_freedomtranslate_check_ready',        'freedomtranslate_ajax_check_ready');

function freedomtranslate_filter_post_content($content, $id = null) {
    if (is_admin()) return $content;
    if (defined('REST_REQUEST') && REST_REQUEST) return $content;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return $content;
    if (!is_string($content) || trim($content) === '') return $content;

    $current_obj_id = ($id !== null && is_numeric($id)) ? (int)$id : (int)get_the_ID();
    if (!$current_obj_id) return $content;

    if (is_main_query() && is_home() && $current_obj_id === get_the_ID()) {
         return $content;
    }

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($user_lang === $site_lang || !freedomtranslate_is_language_enabled($user_lang)) return $content;

    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $filter_name = current_filter();

    $active_hash = md5("post_{$current_obj_id}_{$filter_name}_{$user_lang}") . '_' . $filter_name;

    if (get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION) === 'async' && $service === 'libretranslate') {
        $status_data = ft_get_status_db($active_hash);

        if ($status_data && $status_data->status === 'completed') {
            $cached = ft_get_cache($active_hash);
            if ($cached !== false) return do_shortcode($cached);
        }

        if (freedomtranslate_is_bot()) return $content;

        if (!$status_data) {
            // --- STRICT MANUAL MODE CHECK ---
            if (get_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, '1') === '1') {
                return $content; 
            }
            // --------------------------------

            ft_update_progress($active_hash, $current_obj_id, $user_lang, 0, 'pending');
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [
                $active_hash, $site_lang, $user_lang, $current_obj_id, wp_rand()
            ]);
        }

        return $content;
    }

    return freedomtranslate_translate($content, $site_lang, $user_lang, 'html', $current_obj_id, $active_hash);
}

// HOOK
add_filter('the_content', 'freedomtranslate_filter_post_content', 10, 1);
add_filter('the_title',   'freedomtranslate_filter_post_content', 10, 2);
add_filter('the_excerpt', 'freedomtranslate_filter_post_content', 10, 2);

function freedomtranslate_auto_inject_selector($content) {
    if (is_admin() || !is_string($content) || trim($content) === '') return $content;

    $post_id = get_the_ID();
    if (!$post_id) return $content;

    if (get_post_meta($post_id, '_freedomtranslate_inject_selector', true) === '1') {
        return '[freedomtranslate_selector post_id="' . $post_id . '"]' . $content;
    }

    $auto_inject = get_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, array());
    if (empty($auto_inject)) return $content;

    if (in_array(get_post_type(), $auto_inject, true)) {
        return '[freedomtranslate_selector post_id="' . $post_id . '"]' . $content;
    }

    return $content;
}
add_filter('the_content', 'freedomtranslate_auto_inject_selector', 9);

function freedomtranslate_async_banner_script($hash_key) {
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <script>
    (function() {
        var cacheKey    = <?php echo json_encode($hash_key); ?>;
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
                        } else if (typeof data.progress !== 'undefined' && data.progress >= 0) {
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

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class FreedomTranslate_Registry_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'translated_post',
            'plural'   => 'translated_posts',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'post_id' => 'Post ID',
            'title'   => 'Title',
            'langs'   => 'Cached Languages',
            'action'  => 'Action'
        ];
    }

    public function get_sortable_columns() {
        return [
            'post_id' => ['post_id', true]
        ];
    }

    public function column_default($item, $column_name) {
        $group_key = isset($item['group_key']) ? $item['group_key'] : 'group_' . $item['post_id'] . '_' . $item['lang'];
        $safe_id = md5($group_key);
        $is_string = strpos($group_key, 'string_job_') === 0;

        switch ($column_name) {
            case 'post_id':
                if ($item['post_id'] === 'Unknown' || $is_string) return '<strong>' . esc_html($item['post_id']) . '</strong>';
                return '<strong>' . esc_html($item['post_id']) . '</strong> <a href="' . get_edit_post_link($item['post_id']) . '" target="_blank">(Edit)</a>';
            case 'lang':
                return '<strong>' . esc_html(strtoupper($item['lang'])) . '</strong>';
            case 'scheduled':
                if ($item['status'] === 'processing') return '<strong style="color:#27ae60;">Currently Executing ⚙️</strong>';
                if ($item['scheduled'] === 0) return '<span style="color:#d63638; font-weight:bold;">Missing Cron</span>';
                $time_diff = $item['scheduled'] - time();
                if ($time_diff <= 0) return '<strong style="color:#2271b1;">Queued</strong>';
                return 'In ' . human_time_diff(time(), $item['scheduled']);
            case 'status':
                $bg_color = ($item['status'] === 'pending') ? '#f0b849' : (($item['status'] === 'processing') ? '#2271b1' : '#72777c');
                $status_label = strtoupper($item['status']);
                if ($item['status'] === 'cron_only') $status_label = 'ZOMBIE CRON';
                
                $html = '<div style="margin-bottom: 5px;" id="ft_status_wrap_' . $safe_id . '"><span id="ft_status_badge_' . $safe_id . '" style="background: ' . $bg_color . '; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">' . esc_html($status_label) . '</span></div>';
                
                if (in_array($item['status'], ['pending', 'processing'])) {
                    $percent = 0;
                    $visual_width = 5;
                    $label = 'Chunk: ' . $item['progress'] . ' / ? (Waiting...)';
                    
                    if (isset($item['total_chunks']) && $item['total_chunks'] > 0) {
                        $percent = round(($item['progress'] / $item['total_chunks']) * 100);
                        $visual_width = max(5, $percent);
                        $label = 'Chunk: ' . $item['progress'] . ' / ' . $item['total_chunks'] . ' (' . $percent . '%)';
                    }
                    
                    $text_color = $visual_width > 50 ? '#fff' : '#000';
                    $html .= '<div style="background: #e1e1e1; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; position: relative;">
                                <div id="ft_bar_fill_' . $safe_id . '" style="background: #27ae60; width: ' . esc_attr($visual_width) . '%; height: 100%; transition: width 0.5s;"></div>
                                <span id="ft_bar_text_' . $safe_id . '" style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); font-size: 12px; font-weight: bold; color: ' . $text_color . '; line-height: 20px; white-space: nowrap;">
                                    ' . esc_html($label) . '
                                </span>
                              </div>';
                }
                return $html;
            case 'action':
                if ($is_string) return '<span style="color:#888;">(Fast String Job)</span>';
                
                $base_url = 'options-general.php?page=freedomtranslate&tab=queue_monitor';
                if (!empty($_REQUEST['orderby'])) $base_url .= '&orderby=' . sanitize_text_field($_REQUEST['orderby']);
                if (!empty($_REQUEST['order'])) $base_url .= '&order=' . sanitize_text_field($_REQUEST['order']);
                if (!empty($_REQUEST['s'])) $base_url .= '&s=' . sanitize_text_field($_REQUEST['s']);
                
                $action_suffix = '&post_id=' . $item['post_id'] . '&lang=' . $item['lang'];
                $nonce_key = 'ft_group_' . $item['post_id'] . '_' . $item['lang'];
                
                $html = '<div style="display: flex; gap: 8px; align-items: center;">';
                if ($item['status'] === 'pending') {
                    $start_url = wp_nonce_url(admin_url($base_url . '&ft_action=start_group' . $action_suffix), $nonce_key);
                    $html .= '<a href="' . esc_url($start_url) . '" class="button button-small button-primary" title="Force Start">Start Now</a>';
                }

                $cancel_url = wp_nonce_url(admin_url($base_url . '&ft_action=cancel_group' . $action_suffix), $nonce_key);
                $html .= '<a href="' . esc_url($cancel_url) . '" class="button button-small" style="color: #d63638; border-color: #d63638;" onclick="return confirm(\'Are you sure you want to cancel ALL translation parts for this post?\');">Cancel</a>';
                
                $html .= '</div>';
                return $html;
            default:
                return '';
        }
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';
        
        $db_jobs = $wpdb->get_results("SELECT * FROM $table WHERE status IN ('pending', 'processing')");
        $unified_data = [];

        foreach ($db_jobs as $job) {
            $is_string = (strpos($job->hash_key, 'string_job_') === 0);
            $group_key = $is_string ? $job->hash_key : 'group_' . $job->post_id . '_' . $job->target_lang;

            if (!isset($unified_data[$group_key])) {
                $unified_data[$group_key] = [
                    'group_key'    => $group_key,
                    'hash_key'     => $job->hash_key,
                    'post_id'      => $job->post_id,
                    'lang'         => $job->target_lang,
                    'status'       => $job->status,
                    'progress'     => (int)$job->progress,
                    'total_chunks' => (int)$job->total_chunks,
                    'scheduled'    => 0
                ];
            } else {
                $unified_data[$group_key]['progress'] += (int)$job->progress;
                $unified_data[$group_key]['total_chunks'] += (int)$job->total_chunks;
                if ($job->status === 'processing') {
                    $unified_data[$group_key]['status'] = 'processing';
                }
            }
        }

        $crons = _get_cron_array();
        if (!empty($crons)) {
            foreach ($crons as $timestamp => $cron_hooks) {
                if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                        $hash_key = $event['args'][0];
                        $post_id = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                        $lang = isset($event['args'][2]) ? $event['args'][2] : 'Unknown';
                        $group_key = 'group_' . $post_id . '_' . $lang;

                        if (isset($unified_data[$group_key])) {
                            if ($unified_data[$group_key]['scheduled'] === 0 || $timestamp < $unified_data[$group_key]['scheduled']) {
                                $unified_data[$group_key]['scheduled'] = $timestamp;
                            }
                        } else {
                            $unified_data[$group_key] = [
                                'group_key' => $group_key, 'hash_key' => $hash_key, 'post_id' => $post_id,
                                'lang' => $lang, 'status' => 'cron_only', 'progress' => 0, 'total_chunks' => 0, 'scheduled' => $timestamp
                            ];
                        }
                    }
                }
                if (isset($cron_hooks['freedomtranslate_async_string_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_string_translate'] as $sig => $event) {
                        $string_id = isset($event['args'][0]) ? $event['args'][0] : 'Unknown';
                        $target_lang = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                        $hash_key = 'string_job_' . $string_id . '_' . $target_lang . '_' . $sig; 
                        
                        $unified_data[$hash_key] = [
                            'group_key' => $hash_key, 'hash_key' => $hash_key, 'post_id' => 'String: ' . $string_id,
                            'lang' => $target_lang, 'status' => 'pending', 'progress' => 0, 'total_chunks' => 0, 'scheduled' => $timestamp
                        ];
                    }
                }
            }
        }

        $data = array_values($unified_data);

        $search_query = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_query)) {
            $data = array_filter($data, function($item) use ($search_query) {
                return (stripos($item['post_id'], $search_query) !== false || stripos($item['lang'], $search_query) !== false);
            });
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'scheduled';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'asc';
        usort($data, function($a, $b) use ($orderby, $order) {
            $result = ($a[$orderby] == $b[$orderby]) ? 0 : (($a[$orderby] < $b[$orderby]) ? -1 : 1);
            return ($order === 'asc') ? $result : -$result;
        });

        $per_page = 15;
        $current_page = $this->get_pagenum();
        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
        $this->set_pagination_args(['total_items' => count($data), 'per_page' => $per_page, 'total_pages' => ceil(count($data) / $per_page)]);
    }
}

class FreedomTranslate_Queue_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'queue_job',
            'plural'   => 'queue_jobs',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'post_id'   => 'Target Post',
            'lang'      => 'Language',
            'status'    => 'Status & Progress',
            'scheduled' => 'Scheduled Execution',
            'action'    => 'Action'
        ];
    }

    public function get_sortable_columns() {
        return [
            'post_id'   => ['post_id', false],
            'lang'      => ['lang', false],
            'status'    => ['status', false],
            'scheduled' => ['scheduled', false]
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'post_id':
                if ($item['post_id'] === 'Unknown') return 'Unknown';
                return '<strong>' . esc_html($item['post_id']) . '</strong> <a href="' . get_edit_post_link($item['post_id']) . '" target="_blank">(Edit)</a>';
            case 'lang':
                return '<strong>' . esc_html(strtoupper($item['lang'])) . '</strong>';
            case 'scheduled':
                if ($item['status'] === 'processing') return '<strong style="color:#27ae60;">Currently Executing ⚙️</strong>';
                if ($item['scheduled'] === 0) return '<span style="color:#d63638; font-weight:bold;">Missing Cron</span>';
                $time_diff = $item['scheduled'] - time();
                if ($time_diff <= 0) return '<strong style="color:#2271b1;">Queued</strong>';
                return 'In ' . human_time_diff(time(), $item['scheduled']);
            case 'status':
                $bg_color = ($item['status'] === 'pending') ? '#f0b849' : (($item['status'] === 'processing') ? '#2271b1' : '#72777c');
                $status_label = strtoupper($item['status']);
                if ($item['status'] === 'cron_only') $status_label = 'ZOMBIE CRON';
                
                $html = '<div style="margin-bottom: 5px;"><span style="background: ' . $bg_color . '; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">' . esc_html($status_label) . '</span></div>';
                
                if (in_array($item['status'], ['pending', 'processing'])) {
                    $percent = 0;
                    $visual_width = 5;
                    $label = 'Chunk: ' . $item['progress'];
                    
                    if (isset($item['total_chunks']) && $item['total_chunks'] > 0) {
                        $percent = round(($item['progress'] / $item['total_chunks']) * 100);
                        $visual_width = max(5, $percent);
                        $label = 'Chunk: ' . $item['progress'] . ' / ' . $item['total_chunks'] . ' (' . $percent . '%)';
                    } else {
                        $visual_width = 5; // Barra al minimo
                        $label = 'Chunk: ' . $item['progress'] . ' / ? (Waiting...)';
                    }
                    
                    $text_color = $visual_width > 50 ? '#fff' : '#000';
                    $html .= '<div style="background: #e1e1e1; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; position: relative;">
                                <div style="background: #27ae60; width: ' . esc_attr($visual_width) . '%; height: 100%; transition: width 0.5s;"></div>
                                <span style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); font-size: 12px; font-weight: bold; color: ' . $text_color . '; line-height: 20px; white-space: nowrap;">
                                    ' . esc_html($label) . '
                                </span>
                              </div>';
                }
                return $html;
            case 'action':
                if (strpos($item['hash_key'], 'string_job_') === 0) {
                    return '<span style="color:#888;">(Fast String Job)</span>';
                }
                
                $base_url = 'options-general.php?page=freedomtranslate&tab=queue_monitor';
                if (!empty($_REQUEST['orderby'])) $base_url .= '&orderby=' . sanitize_text_field($_REQUEST['orderby']);
                if (!empty($_REQUEST['order'])) $base_url .= '&order=' . sanitize_text_field($_REQUEST['order']);
                if (!empty($_REQUEST['s'])) $base_url .= '&s=' . sanitize_text_field($_REQUEST['s']);
                
                $html = '<div style="display: flex; gap: 8px; align-items: center;">';
                if ($item['status'] === 'pending') {
                    $start_url = wp_nonce_url(admin_url($base_url . '&ft_action=start_job&job_hash=' . $item['hash_key']), 'ft_start_' . $item['hash_key']);
                    $html .= '<a href="' . esc_url($start_url) . '" class="button button-small button-primary" title="Force Start">Start Now</a>';
                }

                $cancel_url = wp_nonce_url(admin_url($base_url . '&ft_action=cancel_job&job_hash=' . $item['hash_key']), 'ft_cancel_' . $item['hash_key']);
                $html .= '<a href="' . esc_url($cancel_url) . '" class="button button-small" style="color: #d63638; border-color: #d63638;" onclick="return confirm(\'Are you sure you want to cancel this translation job?\');">Cancel</a>';
                
                $html .= '</div>';
                return $html;
            default:
                return '';
        }
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';
        
        // 1. Fetch from Database
        $db_jobs = $wpdb->get_results("SELECT * FROM $table WHERE status IN ('pending', 'processing')");
        $unified_data = [];
        $processed_hashes = [];

        foreach ($db_jobs as $job) {
            $unified_data[$job->hash_key] = [
                'hash_key'     => $job->hash_key,
                'post_id'      => $job->post_id,
                'lang'         => $job->target_lang,
                'status'       => $job->status,
                'progress'     => (int)$job->progress,
                'total_chunks' => isset($job->total_chunks) ? (int)$job->total_chunks : 0,
                'scheduled'    => 0 // Fallback
            ];
            $processed_hashes[] = $job->hash_key;
        }

        // 2. Fetch from WP-Cron
        $crons = _get_cron_array();
        if (!empty($crons)) {
            foreach ($crons as $timestamp => $cron_hooks) {
                
                if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                        $hash_key = $event['args'][0];
                        if (isset($unified_data[$hash_key])) {
                            $unified_data[$hash_key]['scheduled'] = $timestamp;
                        } else {
                            $unified_data[$hash_key] = [
                                'hash_key'  => $hash_key,
                                'post_id'   => isset($event['args'][3]) ? $event['args'][3] : 'Unknown',
                                'lang'      => isset($event['args'][2]) ? $event['args'][2] : 'Unknown',
                                'status'    => 'cron_only',
                                'progress'  => 0,
                                'scheduled' => $timestamp
                            ];
                        }
                    }
                }

                if (isset($cron_hooks['freedomtranslate_async_string_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_string_translate'] as $sig => $event) {
                        $string_id = isset($event['args'][0]) ? $event['args'][0] : 'Unknown';
                        $target_lang = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                        $hash_key = 'string_job_' . $string_id . '_' . $target_lang . '_' . $sig; 
                        
                        $unified_data[$hash_key] = [
                            'hash_key'  => $hash_key,
                            'post_id'   => 'String: ' . $string_id, // Etichetta speciale per distinguerli
                            'lang'      => $target_lang,
                            'status'    => 'pending',
                            'progress'  => 0,
                            'scheduled' => $timestamp
                        ];
                    }
                }
                
            }
        }

        $data = array_values($unified_data);

        // 3. Handle Search
        $search_query = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_query)) {
            $data = array_filter($data, function($item) use ($search_query) {
                return (stripos($item['post_id'], $search_query) !== false || stripos($item['lang'], $search_query) !== false);
            });
        }

        // 4. Handle Sorting
        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'scheduled';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'asc';
        
        usort($data, function($a, $b) use ($orderby, $order) {
            $result = 0;
            if ($a[$orderby] == $b[$orderby]) {
                $result = 0;
            } else {
                $result = ($a[$orderby] < $b[$orderby]) ? -1 : 1;
            }
            return ($order === 'asc') ? $result : -$result;
        });

        // 5. Pagination
        $per_page = 15;
        $current_page = $this->get_pagenum();
        $total_items = count($data);
        
        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
}

class FreedomTranslate_Strings_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'static_string',
            'plural'   => 'static_strings',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'id'           => 'ID',
            'original'     => 'Original Text',
            'translations' => 'Translations',
            'shortcode'    => 'Shortcode',
            'action'       => 'Action'
        ];
    }

    public function get_sortable_columns() {
        return [
            'id'       => ['id', false],
            'original' => ['original', false]
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
                return '<strong>' . esc_html($item['id']) . '</strong>';
            case 'original':
                return esc_html($item['original']);
            case 'translations':
                $color = ($item['done_count'] >= $item['expected_count']) ? '#27ae60' : '#d63638';
                return '<strong style="color: ' . $color . ';">' . $item['done_count'] . ' / ' . $item['expected_count'] . '</strong>';
            case 'shortcode':
                return '<code>[ft_string id="' . esc_attr($item['id']) . '"]</code>';
            case 'action':

                $base_url = 'options-general.php?page=freedomtranslate&tab=static_strings';
                if (!empty($_REQUEST['orderby'])) $base_url .= '&orderby=' . sanitize_text_field($_REQUEST['orderby']);
                if (!empty($_REQUEST['order'])) $base_url .= '&order=' . sanitize_text_field($_REQUEST['order']);
                if (!empty($_REQUEST['s'])) $base_url .= '&s=' . sanitize_text_field($_REQUEST['s']);

                $ret_url = wp_nonce_url(admin_url($base_url . '&ft_action=retranslate_string&string_id=' . $item['id']), 'ft_ret_string_' . $item['id']);
                $del_url = wp_nonce_url(admin_url($base_url . '&ft_action=delete_string&string_id=' . $item['id']), 'ft_del_string_' . $item['id']);

                $html = '<div style="display: flex; gap: 8px;">';
                $html .= '<a href="' . esc_url($ret_url) . '" class="button button-small button-primary">Retranslate</a>';
                $html .= '<a href="' . esc_url($del_url) . '" class="button button-small" style="color: #d63638; border-color: #d63638;" onclick="return confirm(\'Are you sure you want to delete this string?\');">Delete</a>';
                $html .= '</div>';
                return $html;
            default:
                return '';
        }
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
        $site_lang = substr(get_locale(), 0, 2);
        $expected_count = count($enabled_languages) - (in_array($site_lang, $enabled_languages) ? 1 : 0);

        $data = [];
        foreach ($strings as $id => $sdata) {
            $done_count = isset($sdata['translations']) ? count($sdata['translations']) : 0;
            $data[] = [
                'id'             => $id,
                'original'       => $sdata['original'],
                'done_count'     => $done_count,
                'expected_count' => $expected_count
            ];
        }

        $search_query = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_query)) {
            $data = array_filter($data, function($item) use ($search_query) {
                return (stripos($item['id'], $search_query) !== false || stripos($item['original'], $search_query) !== false);
            });
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'id';
        $order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'asc';

        usort($data, function($a, $b) use ($orderby, $order) {
            $result = strcasecmp($a[$orderby], $b[$orderby]);
            return ($order === 'asc') ? $result : -$result;
        });

        $per_page = 15;
        $current_page = $this->get_pagenum();
        $total_items = count($data);

        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
}

function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have sufficient permissions to access this page.'));

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    // --- FORM HANDLERS ---
    // --- HANDLER: DIRECT TRANSLATE PUSH ---
    if (isset($_POST['freedomtranslate_direct_push'])) {
        check_admin_referer('freedomtranslate_direct_push', 'freedomtranslate_nonce_direct');
        
        $input = sanitize_text_field(wp_unslash($_POST['direct_post_input']));
        
        $post_id = 0;
        if (is_numeric($input)) {
            $post_id = intval($input);
        } else {
            $post_id = url_to_postid($input);
            if ($post_id === 0 && function_exists('attachment_url_to_postid')) {
                $post_id = attachment_url_to_postid($input);
            }
        }
        
        $selected_langs = isset($_POST['direct_langs']) ? array_map('sanitize_text_field', wp_unslash($_POST['direct_langs'])) : [];
        update_option('freedomtranslate_direct_last_langs', $selected_langs);

        if ($post_id > 0 && !empty($selected_langs)) {
            $post = get_post($post_id);
            if ($post) {
                $site_lang = substr(get_locale(), 0, 2);
                $queued_count = 0;
                $delay = 0;

                global $wpdb;
                $table = $wpdb->prefix . 'freedomtranslate_cache';

                foreach ($selected_langs as $lang) {
                    if ($lang === $site_lang) continue;

                    if (!empty($post->post_title)) {
                        $t_hash = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
                        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $t_hash . '_chunk_%')); 
                        ft_update_progress($t_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$t_hash, $site_lang, $lang, $post_id, wp_rand()]);
                        $delay += 2; 
                        $queued_count++;
                    }

                    if (!empty($post->post_content)) {
                        $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
                        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $c_hash . '_chunk_%')); 
                        ft_update_progress($c_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$c_hash, $site_lang, $lang, $post_id, wp_rand()]);
                        $delay += 3;
                        $queued_count++;
                    }
                    
                    if (!empty($post->post_excerpt)) {
                        $e_hash = md5("post_{$post_id}_the_excerpt_{$lang}") . '_the_excerpt';
                        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $e_hash . '_chunk_%'));
                        ft_update_progress($e_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$e_hash, $site_lang, $lang, $post_id, wp_rand()]);
                        $delay += 2;
                        $queued_count++;
                    }
                }
                echo '<div class="notice notice-success"><p>🚀 Success! Queued <strong>' . $queued_count . '</strong> translation tasks for Post ID ' . $post_id . '. Check the Queue Monitor!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Post not found in the database.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Please enter a valid URL/ID and select at least one language.</p></div>';
        }
    }
    // --- HANDLERS: START, CANCEL & DELETE ---
    if (isset($_GET['ft_msg'])) {
        $msg = sanitize_text_field($_GET['ft_msg']);
        if ($msg === 'started') echo '<div class="notice notice-success is-dismissible"><p>Job forced to restart!</p></div>';
        elseif ($msg === 'cancelled') echo '<div class="notice notice-success is-dismissible"><p>Job killed and removed.</p></div>';
        elseif ($msg === 'deleted_cache') echo '<div class="notice notice-success is-dismissible"><p>Cache cleared successfully.</p></div>';
        elseif ($msg === 'retranslated') echo '<div class="notice notice-success is-dismissible"><p>String queued for retranslation!</p></div>';
        elseif ($msg === 'deleted_string') echo '<div class="notice notice-success is-dismissible"><p>String deleted.</p></div>';
    }

    // --- HANDLERS: START, CANCEL, DELETE & STRINGS (VIA GET) ---
    if (isset($_GET['ft_action'])) {
        $action = sanitize_text_field($_GET['ft_action']);
        
        $clean_url = remove_query_arg(['ft_action', 'job_hash', 'post_id', 'string_id', '_wpnonce']);
        
        if ($action === 'start_job' && isset($_GET['job_hash'])) {
            $hash_key = sanitize_text_field($_GET['job_hash']);
            check_admin_referer('ft_start_' . $hash_key);
            
            $job = $wpdb->get_row($wpdb->prepare("SELECT post_id, target_lang FROM $table WHERE hash_key = %s", $hash_key));
            if ($job) {
                $wpdb->update($table, ['status' => 'processing'], ['hash_key' => $hash_key]);
                $site_lang = substr(get_locale(), 0, 2);
                $args = [$hash_key, $site_lang, $job->target_lang, (int)$job->post_id, wp_rand()]; 
                wp_clear_scheduled_hook('freedomtranslate_async_translate', $args);
                wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'started', $clean_url));
            exit;
        }
        
        elseif ($action === 'cancel_job' && isset($_GET['job_hash'])) {
            $hash_key = sanitize_text_field($_GET['job_hash']);
            check_admin_referer('ft_cancel_' . $hash_key);
            
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $hash_key . '_chunk_%'));
            $wpdb->delete($table, ['hash_key' => $hash_key]);

            $crons = _get_cron_array();
            if (is_array($crons)) {
                $changed = false;
                foreach ($crons as $timestamp => $cron_hooks) {
                    if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                        foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                            if (isset($event['args'][0]) && $event['args'][0] === $hash_key) {
                                unset($crons[$timestamp]['freedomtranslate_async_translate'][$sig]);
                                if (empty($crons[$timestamp]['freedomtranslate_async_translate'])) unset($crons[$timestamp]['freedomtranslate_async_translate']);
                                if (empty($crons[$timestamp])) unset($crons[$timestamp]);
                                $changed = true;
                            }
                        }
                    }
                }
                if ($changed) update_option('cron', $crons);
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'cancelled', $clean_url));
            exit;
        }

        elseif ($action === 'start_group' && isset($_GET['post_id']) && isset($_GET['lang'])) {
            $p_id = intval($_GET['post_id']);
            $lang = sanitize_text_field($_GET['lang']);
            check_admin_referer('ft_group_' . $p_id . '_' . $lang);
            
            $job = $wpdb->get_row($wpdb->prepare("SELECT hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND status = 'pending' LIMIT 1", $p_id, $lang));
            if ($job) {
                $wpdb->update($table, ['status' => 'processing'], ['hash_key' => $job->hash_key]);
                $site_lang = substr(get_locale(), 0, 2);
                $args = [$job->hash_key, $site_lang, $lang, $p_id, wp_rand()]; 
                wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'started', $clean_url));
            exit;
        }
        
        elseif ($action === 'cancel_group' && isset($_GET['post_id']) && isset($_GET['lang'])) {
            $p_id = intval($_GET['post_id']);
            $lang = sanitize_text_field($_GET['lang']);
            check_admin_referer('ft_group_' . $p_id . '_' . $lang);
            
            $hashes = $wpdb->get_col($wpdb->prepare("SELECT hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND status IN ('pending', 'processing')", $p_id, $lang));
            if (!empty($hashes)) {
                foreach($hashes as $h) {
                    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $h . '_chunk_%'));
                    $wpdb->delete($table, ['hash_key' => $h]);
                }
                
                $crons = _get_cron_array();
                if (is_array($crons)) {
                    $changed = false;
                    foreach ($crons as $timestamp => $cron_hooks) {
                        if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                            foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                                if (isset($event['args'][0]) && in_array($event['args'][0], $hashes)) {
                                    unset($crons[$timestamp]['freedomtranslate_async_translate'][$sig]);
                                    $changed = true;
                                }
                            }
                        }
                    }
                    if ($changed) update_option('cron', $crons);
                }
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'cancelled', $clean_url));
            exit;
        }
        
        elseif ($action === 'delete_cache' && isset($_GET['post_id'])) {
            $post_id = intval($_GET['post_id']);
            check_admin_referer('ft_del_cache_' . $post_id);
            if ($post_id > 0) {
                $wpdb->delete($table, ['post_id' => $post_id]);
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'deleted_cache', $clean_url));
            exit;
        }

        elseif ($action === 'retranslate_string' && isset($_GET['string_id'])) {
            $id = sanitize_key($_GET['string_id']);
            check_admin_referer('ft_ret_string_' . $id);
            
            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            if (isset($strings[$id])) {
                $text = $strings[$id]['original'];
                $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
                $site_lang = substr(get_locale(), 0, 2);
                $delay = 0;

                foreach ($enabled_langs as $lang) {
                    if ($lang === $site_lang) continue;
                    wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_string_translate', [$id, $text, $site_lang, $lang, wp_rand()]);
                    $delay += 2; 
                }
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'retranslated', $clean_url));
            exit;
        }
        
        elseif ($action === 'delete_string' && isset($_GET['string_id'])) {
            $id = sanitize_key($_GET['string_id']);
            check_admin_referer('ft_del_string_' . $id);
            
            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            if (isset($strings[$id])) {
                unset($strings[$id]);
                update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            }
            wp_safe_redirect(add_query_arg('ft_msg', 'deleted_string', $clean_url));
            exit;
        }
    }

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
        update_option('freedomtranslate_prewarm_on_save', $prewarm);

        $strict_manual = isset($_POST['freedomtranslate_strict_manual']) ? '1' : '0';
        update_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, $strict_manual);

        $ttl = max(0, min(365, absint(wp_unslash($_POST['freedomtranslate_cache_ttl_global']))));
        update_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, $ttl);

        $max_concurrent = max(1, min(50, absint(wp_unslash($_POST['freedomtranslate_max_concurrent_jobs']))));
        update_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, $max_concurrent);

        $chunk_size = max(100, min(6000, absint(wp_unslash($_POST['freedomtranslate_chunk_size']))));
        update_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, $chunk_size);

        $api_timeout = max(30, min(1800, absint(wp_unslash($_POST['freedomtranslate_api_timeout']))));
        update_option('freedomtranslate_api_timeout', $api_timeout);

        if (isset($_POST['freedomtranslate_bot_signatures'])) {
            $bots = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_bot_signatures'])))));
            update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $bots);
        }

        if (isset($_POST['freedomtranslate_words_exclude'])) {
            $words = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_words_exclude'])))));
            update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
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
            
            if (!isset($strings[$id])) {
                $strings[$id] = ['original' => $text, 'translations' => []];
            } else {
                $strings[$id]['original'] = $text; // Aggiorna il testo se esiste già
            }
            update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            
            $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
            $site_lang = substr(get_locale(), 0, 2);
            
            $queued = 0;
            $delay = 0;

            foreach ($enabled_langs as $lang) {
                if ($lang === $site_lang) continue;

                wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_string_translate', [$id, $text, $site_lang, $lang, wp_rand()]);
                $delay += 1;
                $queued++;
            }
            
            echo '<div class="notice notice-success"><p>String saved instantly! <strong>' . $queued . '</strong> background translation tasks have been queued. Refresh the page in a few minutes to see them populated.</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_purge_single'])) {
        check_admin_referer('freedomtranslate_purge_single', 'freedomtranslate_nonce_single');
        $input = sanitize_text_field(wp_unslash($_POST['single_post_input']));
        
        $post_id = is_numeric($input) ? intval($input) : url_to_postid($input);

        if ($post_id > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'freedomtranslate_cache';
            
            $deleted = $wpdb->delete($table, ['post_id' => $post_id]);
            if ($deleted) {
                echo '<div class="notice notice-success"><p>Cache cleared successfully for Post ID: <strong>' . esc_html($post_id) . '</strong> (' . $deleted . ' translations removed).</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>No cache found for Post ID: <strong>' . esc_html($post_id) . '</strong>.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Could not find a valid Post ID or URL.</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_purge_cache'])) {
        check_admin_referer('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache');
        
        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';
        $wpdb->query("TRUNCATE TABLE $table");

        echo '<div class="notice notice-success"><p>Entire translation database cache cleared successfully.</p></div>';
    }

if (isset($_POST['freedomtranslate_purge_cron'])) {
    check_admin_referer('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron');
    if (!current_user_can('manage_options')) wp_die('You do not have the permission');

    set_transient('ft_kill_switch', '1', 120);

    $crons = _get_cron_array();
    $found = false;
    if ( is_array( $crons ) ) {
        foreach ( $crons as $timestamp => $cron_hooks ) {
            if ( isset( $cron_hooks['freedomtranslate_async_translate'] ) || 
            isset( $cron_hooks['freedomtranslate_async_string_translate'] ) ||
                 isset( $cron_hooks['freedomtranslate_trigger_prewarm'] ) || 
                 isset( $cron_hooks['freedomtranslate_master_ping'] ) ) {
                
                unset( $crons[$timestamp]['freedomtranslate_async_translate'] );
                unset( $crons[$timestamp]['freedomtranslate_async_string_translate'] );
                unset( $crons[$timestamp]['freedomtranslate_trigger_prewarm'] );
                unset( $crons[$timestamp]['freedomtranslate_master_ping'] );
                if ( empty( $crons[$timestamp] ) ) unset( $crons[$timestamp] );
                $found = true;
            }
        }
    }
    if ( $found ) update_option( 'cron', $crons );

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    $wpdb->query("DELETE FROM $table WHERE hash_key LIKE '%_chunk_%'");

    $wpdb->query("DELETE FROM $table WHERE status IN ('pending', 'processing')");

    echo '<div class="notice notice-success"><p>🚨 PANIC BUTTON ACTIVATED: All active background workers killed, queue cleared, and half-baked translations removed from the database.</p></div>';
}

    if (isset($_POST['freedomtranslate_delete_single_cron'])) {
        check_admin_referer('freedomtranslate_delete_single_cron', 'freedomtranslate_nonce_single_cron');
        $timestamp = intval($_POST['cron_timestamp']);
        $args = unserialize(base64_decode($_POST['cron_args']));
        
        if ($timestamp && is_array($args)) {
            wp_unschedule_event($timestamp, 'freedomtranslate_async_translate', $args);
            $hash_key = $args[0]; 
            
            global $wpdb;
            $table = $wpdb->prefix . 'freedomtranslate_cache';
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $hash_key . '_chunk_%'));
            $wpdb->delete($table, ['hash_key' => $hash_key]);

            delete_transient('freedomtranslate_pending_' . $hash_key);
            
            echo '<div class="notice notice-success"><p>Specific background job cancelled and removed from the database.</p></div>';
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
    $max_concurrent    = get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2);
    $static_strings    = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
    $active_tab        = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    ?>
    <div class="wrap">
        <h1>FreedomTranslate Settings</h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=freedomtranslate&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General & API</a>
            <a href="?page=freedomtranslate&tab=languages" class="nav-tab <?php echo $active_tab == 'languages' ? 'nav-tab-active' : ''; ?>">Languages</a>
            
            <?php if ($current_service !== 'googlehash'): // hide all if using google free ?>
                <a href="?page=freedomtranslate&tab=static_strings" class="nav-tab <?php echo $active_tab == 'static_strings' ? 'nav-tab-active' : ''; ?>">Static Strings</a>
                <a href="?page=freedomtranslate&tab=direct_translate" class="nav-tab <?php echo $active_tab == 'direct_translate' ? 'nav-tab-active' : ''; ?>">Direct Translate</a>
                <a href="?page=freedomtranslate&tab=queue_monitor" class="nav-tab <?php echo $active_tab == 'queue_monitor' ? 'nav-tab-active' : ''; ?>">Queue Monitor</a>
                <a href="?page=freedomtranslate&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>">Tools & Database</a>
            <?php endif; ?>
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
                    <h3>Cache & Performance</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Auto-Translate on Save</th>
                            <td>
                                <label>
                                    <?php $prewarm_val = get_option('freedomtranslate_prewarm_on_save', '0'); ?>
                                    <input type="checkbox" name="freedomtranslate_prewarm_on_save" value="1" <?php checked($prewarm_val, '1'); ?>>
                                    <strong>Automatically translate posts in background when saved</strong>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Strict Manual Mode</th>
                            <td>
                                <label>
                                    <?php $strict_val = get_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, '1'); ?>
                                    <input type="checkbox" name="freedomtranslate_strict_manual" value="1" <?php checked($strict_val, '1'); ?>>
                                    <strong style="color: #d63638;">Disable automatic background translations on page visit (Recommended)</strong>
                                </label>
                                <p class="description">If checked, translations will ONLY start if you manually push them via the <strong>Direct Translate</strong> tab.</p>
                                <?php if ($strict_val === '0'): ?>
                                    <div class="notice notice-warning inline" style="margin-top: 10px; padding: 10px;">
                                        <p style="margin: 0;">⚠️ <strong>WARNING:</strong> You have enabled "On-the-fly" automatic translations. If your site has hundreds of posts and multiple languages enabled, an unexpected traffic spike or a bot crawl could overload your local AI server with thousands of background jobs. Use with caution!</p>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_cache_ttl_global">Cache TTL (Days)</label></th>
                            <td>
                                <input type="number" id="freedomtranslate_cache_ttl_global" name="freedomtranslate_cache_ttl_global" value="<?php echo esc_attr($global_ttl); ?>" min="0" max="365" style="width: 80px;">
                                <p class="description">Set to <strong>0</strong> to make cache permanent (it will never expire). Otherwise, set the expiration in days (e.g. 30).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_max_concurrent_jobs">Max Concurrent Translations</label></th>
                            <td>
                                <input type="number" id="freedomtranslate_max_concurrent_jobs" name="freedomtranslate_max_concurrent_jobs" value="<?php echo esc_attr($max_concurrent); ?>" min="1" max="50" style="width: 80px;">
                                <p class="description">Limit simultaneous background AI translation jobs. Local LLMs (like Ollama on Mac Mini) perform best with <strong>1 o 2</strong> concurrent jobs to prevent RAM exhaustion and slowdowns.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_chunk_size">HTML Chunk Size</label></th>
                            <td>
                                <?php $current_chunk = get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500); ?>
                                <input type="number" id="freedomtranslate_chunk_size" name="freedomtranslate_chunk_size" value="<?php echo esc_attr($current_chunk); ?>" min="100" max="6000" step="100" style="width: 80px;">
                                <p class="description">Maximum characters per translation block. Increase this (e.g., <strong>1000-1500</strong>) for complex page builders to preserve HTML layout. Lower it (<strong>400-500</strong>) if the AI server goes into Timeout. Max: 6000</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_api_timeout">AI API Timeout (Seconds)</label></th>
                            <td>
                                <?php $current_timeout = get_option('freedomtranslate_api_timeout', 120); ?>
                                <input type="number" id="freedomtranslate_api_timeout" name="freedomtranslate_api_timeout" value="<?php echo esc_attr($current_timeout); ?>" min="30" max="1800" step="30" style="width: 80px;">
                                <p class="description">Maximum time WordPress will wait for the AI to reply before giving up on a chunk. For local servers (Ollama) translating large chunks, increase this to <strong>300 (5 minutes)</strong> or even <strong>600 (10 minutes)</strong> to prevent skipped/missing translations. Max: 1800 (30 min).</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="ui_block_security">
                    <hr>
                    <h3>Security, Content Protection & Anti-Bot</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_words_exclude">Excluded Words</label></th>
                            <td>
                                <?php $excluded = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []); ?>
                                <textarea id="freedomtranslate_words_exclude" name="freedomtranslate_words_exclude" rows="4" cols="50" class="large-text"><?php echo esc_textarea(implode("\n", $excluded)); ?></textarea>
                                <p class="description">List words or brand names (one per line) that should <strong>NEVER</strong> be translated (e.g., your company name, technical terms).</p>
                            </td>
                        </tr>
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
            <div style="background:#e5f5fa; border-left:4px solid #00a0d2; padding:15px; margin-bottom:20px; max-width: 800px;">
                <h4 style="margin-top:0; font-size: 14px;">🌐 Language Selector Shortcode</h4>
                <p style="margin-bottom:10px;">To display the language dropdown menu anywhere on your site (like in a widget, sidebar, header, or footer), use the following shortcode:</p>
                <p><code style="font-size: 16px; padding: 5px 10px; background: #fff; border: 1px solid #ccc;">[freedomtranslate_selector]</code></p>
                <p class="description" style="margin-bottom:0;"><em>If you want to force the selector to translate a specific post ID regardless of the page it's placed on, you can use: <code>[freedomtranslate_selector post_id="123"]</code></em></p>
            </div>
            
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
            <?php
            $strings_table = new FreedomTranslate_Strings_Table();
            $strings_table->prepare_items();
            
            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);
            
            if (!$strings_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;">No static strings added yet.</p>
                      </div>';
            } else {
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';
                
                $strings_table->search_box('Search Strings', 'search_strings');
                $strings_table->display();
                
                echo '</form>';
            }
            ?>

            <?php elseif ($active_tab === 'direct_translate'): ?>
            <h3>Manual Direct Translation</h3>
            <p class="description">Save your local AI server resources! Instead of waiting for users to visit pages, paste a post URL here and push it to the background translation queue manually.</p>
            
            <div style="background:#f9f9f9; padding:20px; border:1px solid #ccc; margin-top: 15px; border-radius: 5px;">
                <form method="post">
                    <?php wp_nonce_field('freedomtranslate_direct_push', 'freedomtranslate_nonce_direct'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label>Target Post/Page</label></th>
                            <td>
                                <input type="text" name="direct_post_input" placeholder="e.g., https://yoursite.com/my-post/  OR  Post ID (123)" class="large-text" required style="max-width: 600px;">
                                <p class="description">Paste the full URL of the post or its ID.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Translate Into...</label></th>
                            <td>
                                <div style="column-count: 3; background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">
                                    <?php 
                                    $site_lang = substr(get_locale(), 0, 2);
                                    // Peschiamo le ultime usate, altrimenti le selezioniamo tutte di default la prima volta
                                    $last_langs = get_option('freedomtranslate_direct_last_langs', $enabled_languages);
                                    foreach ($enabled_languages as $code): 
                                        if ($code === $site_lang) continue;
                                        $is_checked = in_array($code, $last_langs, true) ? 'checked' : '';
                                    ?>
                                        <label style="display:block;margin-bottom:5px;">
                                            <input type="checkbox" name="direct_langs[]" value="<?php echo esc_attr($code); ?>" <?php echo $is_checked; ?>>
                                            <strong><?php echo esc_html(strtoupper($code)); ?></strong> - <?php echo esc_html($all_languages[$code]); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description" style="margin-top: 10px;">
                                    <a href="#" onclick="jQuery('input[name=\'direct_langs[]\']').prop('checked', true); return false;">Select All</a> | 
                                    <a href="#" onclick="jQuery('input[name=\'direct_langs[]\']').prop('checked', false); return false;">Deselect All</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="freedomtranslate_direct_push" class="button button-primary button-large" value="Push to Translation Queue 🚀">
                    </p>
                </form>
            </div>

        <?php elseif ($active_tab === 'queue_monitor'): ?>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;">Unified Translation Queue</h3>
                <form method="post" onsubmit="return confirm('Are you sure you want to delete ALL pending background translations and cronjobs?');">
                    <?php wp_nonce_field('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron'); ?>
                    <input type="submit" name="freedomtranslate_purge_cron" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="Clear ALL Pending Jobs (Panic Button)">
                </form>
            </div>
            
            <p class="description">This table merges database translation intents with scheduled WP-Cron system jobs. It gives you a complete overview of what is waiting to be translated and when the next background worker will execute.</p>

            <?php
            $queue_table = new FreedomTranslate_Queue_Table();
            $queue_table->prepare_items();
            
            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);
            
            if (!$queue_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>The translation queue is perfectly clear!</strong> No jobs are currently pending or processing.</p>
                      </div>';
            } else {
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';
                
                $queue_table->search_box('Search by Post ID or Lang', 'search_queue');
                $queue_table->display();
                
                echo '</form>';
            }
            ?>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var ajaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
                setInterval(function() {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', ajaxUrl + '?action=ft_queue_monitor_data', true);
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var data = JSON.parse(xhr.responseText);
                                var needsReload = false;
                                
                                var wrappers = document.querySelectorAll('div[id^="ft_status_wrap_"]');
                                wrappers.forEach(function(wrap) {
                                    var safe_id = wrap.id.replace('ft_status_wrap_', '');

                                    if (!data[safe_id]) {
                                        needsReload = true;
                                        return;
                                    }
                                    
                                    var job = data[safe_id];
                                    
                                    var badge = document.getElementById('ft_status_badge_' + safe_id);
                                    if (badge) {
                                        badge.textContent = job.s.toUpperCase();
                                        badge.style.background = (job.s === 'pending') ? '#f0b849' : ((job.s === 'processing') ? '#2271b1' : '#72777c');
                                    }
                                    
                                    var barFill = document.getElementById('ft_bar_fill_' + safe_id);
                                    var barText = document.getElementById('ft_bar_text_' + safe_id);
                                    
                                    if (barFill && barText) {
                                        var visual_width = 5;
                                        var label = 'Chunk: ' + job.p + ' / ? (Waiting...)';
                                        if (job.t > 0) {
                                            var percent = Math.round((job.p / job.t) * 100);
                                            visual_width = Math.max(5, percent);
                                            label = 'Chunk: ' + job.p + ' / ' + job.t + ' (' + percent + '%)';
                                        }
                                        barFill.style.width = visual_width + '%';
                                        barText.textContent = label;
                                        barText.style.color = (visual_width > 50) ? '#fff' : '#000';
                                    }
                                });
                                
                                if (needsReload) window.location.reload();
                            } catch(e) {}
                        }
                    };
                    xhr.send();
                }, 3000);
            });
            </script>

        <?php elseif ($active_tab === 'tools'): ?>
            <h3>Database Cache Management</h3>

            <div style="margin-bottom:30px;">
                <h4>Clear ALL Cache (Danger Zone)</h4>
                <p class="description">Clear the entire translation custom database to force re-translation of ALL content across the site.</p>
                <form method="post" onsubmit="return confirm('🚨 WARNING 🚨\n\nClearing the entire database means the AI will have to re-translate EVERY SINGLE PAGE on your site from scratch!\n\nAre you absolutely sure you want to proceed?');">
                    <?php wp_nonce_field('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache'); ?>
                    <input type="submit" name="freedomtranslate_purge_cache" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="Clear ALL Database Cache">
                </form>
            </div>

            <hr style="margin: 40px 0;">
            <h3>Translated Posts Registry</h3>
            <p class="description">This table lists all posts/pages that have been successfully cached in the custom database.</p>
            
            <?php
            $registry_table = new FreedomTranslate_Registry_Table();
            $registry_table->prepare_items();
            
            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);
            
            if (!$registry_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>The custom translation database is currently empty.</strong> Visit a page or use auto-prewarm to start caching.</p>
                      </div>';
            } else {
                echo '<form method="get">';

                echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';
                
                $registry_table->search_box('Search Posts', 'search_id');
                
                $registry_table->display();
                echo '</form>';
            }
            ?>
        <?php endif; ?>
        
        </div>
    </div>
    <?php
}

//
// FT CRON CLEANUP
//
add_action('init', function() {

    if (isset($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            setcookie('freedomtranslate_lang', $lang, time() + (DAY_IN_SECONDS * 30), '/', COOKIE_DOMAIN, is_ssl(), false);
            $_COOKIE['freedomtranslate_lang'] = $lang;
        }
    }

    if (get_transient('ft_cron_cleaned')) return;
    set_transient('ft_cron_cleaned', '1', DAY_IN_SECONDS);

    $crons = _get_cron_array();
    if (!is_array($crons)) return;

    $changed = false;
    foreach ($crons as $timestamp => $hooks) {
        if (!is_array($hooks)) { unset($crons[$timestamp]); $changed = true; continue; }
        foreach ($hooks as $hook => $events) {
            if (!is_array($events)) { unset($crons[$timestamp][$hook]); $changed = true; continue; }
            foreach ($events as $key => $event) {
                if (!isset($event['args']) || !isset($event['schedule']) || !is_array($event['args'])) {
                    unset($crons[$timestamp][$hook][$key]);
                    $changed = true;
                }
            }
            if (empty($crons[$timestamp][$hook])) unset($crons[$timestamp][$hook]);
        }
        if (empty($crons[$timestamp])) unset($crons[$timestamp]);
    }

    if ($changed) {
        _set_cron_array($crons);
    }
});

/**
 * AUTO-PREWARM ON SAVE
 */
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (isset($_GET['meta-box-loader'])) return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'meta-box-loader') !== false) return;

    if (get_option('freedomtranslate_prewarm_on_save', '0') !== '1') return;
    if (get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $service = get_option('freedomtranslate_service', 'libretranslate');
    if ($service !== 'libretranslate') return;

    $site_lang     = substr(get_locale(), 0, 2);
    $enabled_langs = get_option('freedomtranslate_languages', []);
    $content       = is_string($post->post_content) ? $post->post_content : '';
    $title         = is_string($post->post_title)   ? $post->post_title   : '';

    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;

        // ── POST CONTENT ──
        if (!empty($content)) {
            $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
            $status_data = ft_get_status_db($c_hash);
            
            if (!$status_data) {
                ft_update_progress($c_hash, $post_id, $lang, 0, 'pending');
                wp_schedule_single_event(time() + 5, 'freedomtranslate_async_translate', [$c_hash, $site_lang, $lang, $post_id]);
            }
        }

        // ── POST TITLE ──
        if (!empty($title)) {
            $t_hash = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
            $status_data = ft_get_status_db($t_hash);
            
            if (!$status_data) {
                ft_update_progress($t_hash, $post_id, $lang, 0, 'pending');
                wp_schedule_single_event(time() + 5, 'freedomtranslate_async_translate', [$t_hash, $site_lang, $lang, $post_id]);
            }
        }
    }
});

add_action('freedomtranslate_master_ping', function($post_id) {
    $site_lang = substr(get_locale(), 0, 2);
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $url = get_permalink($post_id);
    
    if (!$url) return;

    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;
        $ping_url = add_query_arg('freedomtranslate_lang', $lang, $url);
        wp_remote_get($ping_url, ['timeout' => 0.1, 'blocking' => false, 'sslverify' => false]);
    }
});

// Auto-purge cron
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('freedomtranslate_auto_purge')) wp_schedule_event(time(), 'daily', 'freedomtranslate_auto_purge');
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('freedomtranslate_auto_purge');
});
add_action('freedomtranslate_auto_purge', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    
    // clean only custom db table
    $wpdb->query("DELETE FROM $table WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    
    // zombie translation go back to pending
    $wpdb->query("UPDATE $table SET status = 'pending', progress = 0 WHERE status = 'processing'");
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

// ========================================================================
// 7. AJAX QUEUE MONITOR
// ========================================================================
add_action('wp_ajax_ft_queue_monitor_data', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $db_jobs = $wpdb->get_results("SELECT hash_key, post_id, target_lang, status, progress, total_chunks FROM $table WHERE status IN ('pending', 'processing')");
    $unified = [];
    
    foreach ($db_jobs as $job) {
        $is_string = (strpos($job->hash_key, 'string_job_') === 0);
        $group_key = $is_string ? $job->hash_key : 'group_' . $job->post_id . '_' . $job->target_lang;
        $safe_id = md5($group_key);
        
        if (!isset($unified[$safe_id])) {
            $unified[$safe_id] = [
                's' => $job->status,
                'p' => (int)$job->progress,
                't' => (int)$job->total_chunks
            ];
        } else {
            $unified[$safe_id]['p'] += (int)$job->progress;
            $unified[$safe_id]['t'] += (int)$job->total_chunks;
            if ($job->status === 'processing') $unified[$safe_id]['s'] = 'processing';
        }
    }
    wp_send_json($unified);
});
