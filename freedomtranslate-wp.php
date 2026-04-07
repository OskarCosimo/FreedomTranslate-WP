<?php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with AI or remote URL with API + custom database cache, and static strings manager.
Version: 1.6.6
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
        expires_at datetime DEFAULT NULL,
        PRIMARY KEY  (hash_key),
        KEY post_id (post_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'freedomtranslate_install_db');
add_action('admin_init', function() {
    if (get_option('freedomtranslate_db_version') !== '1.1') {
        freedomtranslate_install_db();
        update_option('freedomtranslate_db_version', '1.1');
    }
});

function ft_get_cache($hash_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $row = $wpdb->get_row($wpdb->prepare("SELECT translation, expires_at FROM $table WHERE hash_key = %s", $hash_key));
    
    if ($row) {
        if ($row->expires_at && strtotime($row->expires_at) < time()) {
            return false; // Expired
        }
        return $row->translation;
    }
    return false;
}

function ft_set_cache($hash_key, $translation, $post_id, $target_lang, $ttl_seconds) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $expires = null;
    
    if ($ttl_seconds > 0) {
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl_seconds);
    }
    
    $wpdb->replace($table, [
        'hash_key'    => $hash_key,
        'post_id'     => $post_id,
        'target_lang' => $target_lang,
        'translation' => $translation,
        'expires_at'  => $expires
    ], ['%s', '%d', '%s', '%s', '%s']);
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
// 3. SHORTCODES
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

    global $freedomtranslate_active_hash_key;
    if (!empty($freedomtranslate_active_hash_key)) {
        $ck = esc_attr($freedomtranslate_active_hash_key);
        $html .= '<div class="ft-progress-banner ft-pb-visible" data-cache-key="' . $ck . '">'
               . '<div class="ft-pb-spinner"></div>'
               . '<span class="ft-pb-text">Translation in progress...</span>'
               . '</div>';
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
    
    $response = wp_remote_post($api_url, ['body'=>$body,'timeout'=>900]);
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

function freedomtranslate_async_worker($hash_key, $original_content, $site_lang, $user_lang, $post_id) {
    set_time_limit(0);
    ignore_user_abort(true);
    
    $lock_key = 'ft_lock_' . $post_id . '_' . $user_lang;

    if (ft_get_cache($hash_key) !== false) {
        delete_transient('freedomtranslate_progress_' . $hash_key);
        delete_transient('freedomtranslate_pending_' . $hash_key);
        delete_transient($lock_key);
        return;
    }

    $content_to_process = $original_content;

    list($content_to_process, $sc_placeholders) = freedomtranslate_protect_shortcodes($content_to_process);
    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $placeholders   = [];
    if (!empty($excluded_words)) {
        list($content_to_process, $placeholders) = freedomtranslate_protect_excluded_words_in_html($content_to_process, $excluded_words);
    }

    $chunks = freedomtranslate_split_html_into_chunks($content_to_process, 1000);
    $total_chunks = count($chunks);

    $progress_key = 'freedomtranslate_progress_' . $hash_key;
    $progress_data = get_transient($progress_key);
    $current_index = (is_array($progress_data)) ? (int)$progress_data['done'] : 0;

    if ($current_index < $total_chunks) {
        
        $max_concurrent = (int) get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2);
        if ($max_concurrent > 0) {
            $active_jobs = get_option('ft_active_jobs_array', []);
            if (!is_array($active_jobs)) $active_jobs = [];
            
            $now = time();
            $cleaned_jobs = [];
            foreach ($active_jobs as $job_hash => $expires_at) {
                if ($expires_at > $now) $cleaned_jobs[$job_hash] = $expires_at;
            }

            if (!isset($cleaned_jobs[$hash_key]) && count($cleaned_jobs) >= $max_concurrent) {
                update_option('ft_active_jobs_array', $cleaned_jobs);
                wp_schedule_single_event(time() + 30, 'freedomtranslate_async_translate', [
                    $hash_key, $site_lang, $user_lang, $post_id
                ]);
                return;
            }

            $cleaned_jobs[$hash_key] = $now + 120;
            update_option('ft_active_jobs_array', $cleaned_jobs);
        }

        if ($current_index === 0 && !get_transient($progress_key)) {
            set_transient($progress_key, array(
                'done' => 0, 
                'total' => $total_chunks,
                'post_id' => $post_id,
                'lang' => $user_lang
            ), 12 * HOUR_IN_SECONDS);
        }

        $chunk_to_translate = $chunks[$current_index];

        $translated_chunk = freedomtranslate_translate_libre(
            $chunk_to_translate, $site_lang, $user_lang, 'html'
        );
        
        if ($max_concurrent > 0) {
            $active_jobs = get_option('ft_active_jobs_array', []);
            if (isset($active_jobs[$hash_key])) {
                unset($active_jobs[$hash_key]);
                update_option('ft_active_jobs_array', $active_jobs);
            }
        }

        $chunk_hash = $hash_key . '_chunk_' . $current_index;
        ft_set_cache($hash_key . '_chunk_' . $current_index, $translated_chunk, $post_id, $user_lang, DAY_IN_SECONDS * 7);
        
        $current_index++;
        set_transient($progress_key, array('done' => $current_index, 'total' => $total_chunks), 1800);
        
        // Schedule next
        wp_schedule_single_event(time() + 1, 'freedomtranslate_async_translate', [$hash_key, $site_lang, $user_lang, $post_id]);

            return; 
    }

    // ASSEMBLY AND CLEANUP
    $translated_parts = array();
    for ($i = 0; $i < $total_chunks; $i++) {
        $c_hash = $hash_key . '_chunk_' . $i;
        $part = ft_get_cache($c_hash);
        
        if ($part === false) {
            delete_transient($progress_key);
            delete_transient($lock_key);
            return; 
        }
        $translated_parts[] = $part;
    }

    $translated_final = implode('', $translated_parts);

    if (!empty($placeholders)) {
        $translated_final = freedomtranslate_restore_excluded_words_in_html($translated_final, $placeholders);
    }
    $translated_final = freedomtranslate_restore_shortcodes($translated_final, $sc_placeholders);

    ft_set_cache($hash_key, $translated_final, $post_id, $user_lang, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
    
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $hash_key . '_chunk_%'));

    set_transient('freedomtranslate_ready_' . $hash_key, '1', HOUR_IN_SECONDS);
    delete_transient($progress_key);
    delete_transient('freedomtranslate_pending_' . $hash_key);
    delete_transient($lock_key);

    if ($post_id > 0) {
        if (function_exists('clean_post_cache')) clean_post_cache($post_id);
        if (function_exists('rocket_clean_post')) rocket_clean_post($post_id);
        if (function_exists('w3tc_flush_post')) w3tc_flush_post($post_id);
        do_action('litespeed_purge_post', $post_id);
    }
}

function freedomtranslate_split_html_into_chunks($html, $max_chars = 1000) {
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
    if (empty($hash_key)) { wp_send_json(array('ready' => false, 'progress' => 0)); return; }

    if (ft_get_cache($hash_key) !== false) {
        wp_send_json(array('ready' => true, 'progress' => 100));
        return;
    }
    $progress_data = get_transient('freedomtranslate_progress_' . $hash_key);
    $progress = get_transient('freedomtranslate_progress_' . $hash_key);
    if (is_array($progress) && $progress['total'] > 0) {
        $percent = (int) round(($progress['done'] / $progress['total']) * 100);
        wp_send_json(array('ready' => false, 'progress' => $percent));
        return;
    }

    wp_send_json(array('ready' => false, 'progress' => 0));
}
add_action('wp_ajax_nopriv_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');
add_action('wp_ajax_freedomtranslate_check_ready',        'freedomtranslate_ajax_check_ready');

function freedomtranslate_filter_post_content($content, $id = null) {
    if (is_admin()) return $content;
    if (defined('REST_REQUEST') && REST_REQUEST) return $content;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return $content;
    if (!is_string($content)) return $content;

    global $post;

    $current_obj_id = ($id !== null && is_numeric($id)) ? (int)$id : (isset($post->ID) ? $post->ID : 0);
    if (is_main_query() && is_home() && $current_obj_id === get_the_ID()) {
         return $content;
    }

    $current_post_id = 0;
    $post_modified = '';
    
    if ($id !== null && is_numeric($id)) {
        $current_post_id = (int)$id;
        $current_post_obj = get_post($current_post_id);
        if (is_object($current_post_obj) && isset($current_post_obj->post_modified)) {
            $post_modified = $current_post_obj->post_modified;
        }
    } elseif (is_object($post) && isset($post->ID)) {
        $current_post_id = $post->ID;
        $post_modified = $post->post_modified;
    }

    if ($current_post_id > 0 && get_post_meta($current_post_id, '_freedomtranslate_exclude', true) === '1') return $content;

    if ($current_post_id > 0) {
        $post_type = get_post_type($current_post_id);
        $skip_types = apply_filters('freedomtranslate_skip_post_types',
            array('shop_order', 'shop_coupon', 'shop_webhook', 'wc_order', 'wc_product_tab')
        );
        if (in_array($post_type, $skip_types, true)) return $content;
    }

    $user_lang = freedomtranslate_get_user_lang();
    $site_lang = substr(get_locale(), 0, 2);
    if ($user_lang === $site_lang || !freedomtranslate_is_language_enabled($user_lang)) return $content;

    $service    = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $libre_mode = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');
    $filter_name = current_filter();

    // HASH CORRETTO E SICURO
    $content_hash = md5($content);
    $legacy_hash = md5($content . $site_lang . $user_lang . 'html' . $service);

    if ($current_post_id > 0 && in_array($filter_name, ['the_content', 'the_title', 'the_excerpt'])) {
        $active_hash = md5("post_{$current_post_id}_{$post_modified}_{$filter_name}_{$site_lang}_{$user_lang}_{$service}_{$content_hash}");
    } else {
        $active_hash = $legacy_hash;
    }

    if ($service === 'libretranslate' && $libre_mode === 'async') {
        
        $cached = ft_get_cache($active_hash);
        
        if ($cached === false && $active_hash !== $legacy_hash) {
            $cached = ft_get_cache($legacy_hash);
            if ($cached !== false) {
                ft_set_cache($active_hash, $cached, $current_post_id, $user_lang, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($current_post_id));
            }
        }

        if ($cached !== false) return do_shortcode($cached);

        if (freedomtranslate_is_bot()) {
            return $content; 
        }

        $pending_key = 'freedomtranslate_pending_' . $active_hash;
        $lock_key    = 'ft_lock_' . $current_post_id . '_' . $user_lang;

        if (!get_transient($pending_key) && !get_transient($lock_key)) {
            set_transient($pending_key, '1', 30 * MINUTE_IN_SECONDS);
            if ($current_post_id > 0) {
                set_transient($lock_key, '1', 30 * MINUTE_IN_SECONDS);
            }
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [
                $active_hash, $content, $site_lang, $user_lang, $current_post_id,
            ]);
        }

        global $freedomtranslate_active_hash_key;
        $freedomtranslate_active_hash_key = $active_hash;
        add_action('wp_footer', function() use ($active_hash) {
            freedomtranslate_async_banner_script($active_hash);
        });
        return $content;
    }

    $translated = freedomtranslate_translate($content, $site_lang, $user_lang, 'html', $current_post_id, $active_hash);
    return do_shortcode($translated);
}

// HOOK
add_filter('the_content', 'freedomtranslate_filter_post_content', 10, 1);
add_filter('the_title',   'freedomtranslate_filter_post_content', 10, 2);
add_filter('the_excerpt', 'freedomtranslate_filter_post_content', 10, 2);

add_filter('the_content', function($content) {
    if (is_admin() || !is_string($content)) return $content;
    $auto_inject = get_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, array());
    if (empty($auto_inject)) return $content;
    $post_type = get_post_type();
    $should_inject = (in_array('page', $auto_inject, true) && $post_type === 'page')
                  || (in_array('post', $auto_inject, true) && $post_type === 'post');
    if (!$should_inject) return $content;
    return '[freedomtranslate_selector]' . $content;
}, 9);

add_filter('the_content', function($content) {
    if (is_admin() || !is_string($content)) return $content;
    $post_id = get_the_ID();
    if (!$post_id) return $content;
    if (get_post_meta($post_id, '_freedomtranslate_inject_selector', true) !== '1') return $content;
    return '[freedomtranslate_selector]' . $content;
}, 9);

/**
 * AUTO-PREWARM ON SAVE
 */
add_action('save_post', function($post_id) {
    $post = get_post($post_id); if (!$post) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (get_option(FREEDOMTRANSLATE_PREWARM_OPTION, '0') !== '1') return;
    if (get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;
    if ($post->post_status !== 'publish') return;

    $site_lang = substr(get_locale(), 0, 2);
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    
    if ($service !== 'libretranslate') return;

    // API REST
    $content = is_string($post->post_content) ? $post->post_content : '';
    $title = is_string($post->post_title) ? $post->post_title : '';
    $post_modified = isset($post->post_modified) ? $post->post_modified : '';

    $delay = 0;
    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;

        // Prewarm the_content
        if (!empty($content)) {
            $c_hash = md5("post_{$post_id}_{$post_modified}_the_content_{$site_lang}_{$lang}_{$service}_" . md5($content));
            if (ft_get_cache($c_hash) === false && !get_transient('freedomtranslate_pending_' . $c_hash)) {
                set_transient('freedomtranslate_pending_' . $c_hash, '1', 30 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$c_hash, $content, $site_lang, $lang, $post_id]);
                $delay += 2;
            }
        }

        // Prewarm the_title
        if (!empty($title)) {
            $t_hash = md5("post_{$post_id}_{$post_modified}_the_title_{$site_lang}_{$lang}_{$service}_" . md5($title));
            if (ft_get_cache($t_hash) === false && !get_transient('freedomtranslate_pending_' . $t_hash)) {
                set_transient('freedomtranslate_pending_' . $t_hash, '1', 30 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$t_hash, $title, $site_lang, $lang, $post_id]);
                $delay += 2;
            }
        }
    }
}, 10, 3);

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

function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have sufficient permissions to access this page.'));

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

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
        update_option('freedomtranslate_prewarm_on_save', $prewarm);

        $ttl = max(0, min(365, absint(wp_unslash($_POST['freedomtranslate_cache_ttl_global']))));
        update_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, $ttl);

        $max_concurrent = max(1, min(50, absint(wp_unslash($_POST['freedomtranslate_max_concurrent_jobs']))));
        update_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, $max_concurrent);

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

    if (isset($_POST['freedomtranslate_purge_single'])) {
        check_admin_referer('freedomtranslate_purge_single', 'freedomtranslate_nonce_single');
        $input = sanitize_text_field(wp_unslash($_POST['single_post_input']));
        
        $post_id = is_numeric($input) ? intval($input) : url_to_postid($input);

        if ($post_id > 0) {
            $hashes = $wpdb->get_col($wpdb->prepare("SELECT hash_key FROM $table WHERE post_id = %d", $post_id));
            if (!empty($hashes)) {
                $active_jobs = get_option('ft_active_jobs_array', []);
                foreach ($hashes as $h) {
                    $base_hash = preg_replace('/_chunk_\d+$/', '', $h);
                    delete_transient('freedomtranslate_pending_' . $base_hash);
                    delete_transient('freedomtranslate_progress_' . $base_hash);
                    delete_transient('freedomtranslate_ready_' . $base_hash);
                    if (isset($active_jobs[$base_hash])) unset($active_jobs[$base_hash]);
                }
                update_option('ft_active_jobs_array', $active_jobs);
            }
            
            $langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
            foreach ($langs as $l) delete_transient('ft_lock_' . $post_id . '_' . $l);
            
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
        
        $wpdb->query("TRUNCATE TABLE $table");
        
        $p = esc_sql('freedomtranslate_pending_');
        $pr = esc_sql('freedomtranslate_progress_');
        $lck = esc_sql('ft_lock_');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", '_transient_' . $p . '%', '_transient_timeout_' . $p . '%', '_transient_' . $pr . '%', '_transient_timeout_' . $pr . '%', '_transient_' . $lck . '%', '_transient_timeout_' . $lck . '%'));
        
        delete_option('ft_active_jobs_array');
        echo '<div class="notice notice-success"><p>Entire translation database cache cleared successfully.</p></div>';
    }

if (isset($_POST['freedomtranslate_purge_cron'])) {

    check_admin_referer('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron');

    if (!current_user_can('manage_options')) {
        wp_die('Non hai i permessi necessari.');
    }

    $crons = _get_cron_array();
    $found = false;

    if ( is_array( $crons ) ) {
        foreach ( $crons as $timestamp => $cron_hooks ) {
            if ( isset( $cron_hooks['freedomtranslate_async_translate'] ) || 
                 isset( $cron_hooks['freedomtranslate_trigger_prewarm'] ) || 
                 isset( $cron_hooks['freedomtranslate_master_ping'] ) ) {
                
                unset( $crons[$timestamp]['freedomtranslate_async_translate'] );
                unset( $crons[$timestamp]['freedomtranslate_trigger_prewarm'] );
                unset( $crons[$timestamp]['freedomtranslate_master_ping'] );

                if ( empty( $crons[$timestamp] ) ) {
                    unset( $crons[$timestamp] );
                }
                $found = true;
            }
        }
    }

    if ( $found ) {
        update_option( 'cron', $crons );
    }

    global $wpdb;
    $prefixes = ['freedomtranslate_pending_', 'freedomtranslate_progress_', 'ft_lock_', 'ft_source_', 'ft_temp_source_'];
    
    foreach ($prefixes as $prefix) {
        $escaped_prefix = esc_sql($prefix);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $escaped_prefix . '%',
            '_transient_timeout_' . $escaped_prefix . '%'
        ));
    }
    
    delete_option('ft_active_jobs_array');

    echo '<div class="notice notice-success"><p>Queue cleaned.</p></div>';
}

    if (isset($_POST['freedomtranslate_delete_single_cron'])) {
        check_admin_referer('freedomtranslate_delete_single_cron', 'freedomtranslate_nonce_single_cron');
        $timestamp = intval($_POST['cron_timestamp']);
        $args = unserialize(base64_decode($_POST['cron_args']));
        
        if ($timestamp && is_array($args)) {
            wp_unschedule_event($timestamp, 'freedomtranslate_async_translate', $args);
            $hash_key = $args[0]; 
            delete_transient('freedomtranslate_pending_' . $hash_key);
            delete_transient('ft_lock_' . $args[4] . '_' . $args[3]);
            
            $active_jobs = get_option('ft_active_jobs_array', []);
            if (isset($active_jobs[$hash_key])) {
                unset($active_jobs[$hash_key]);
                update_option('ft_active_jobs_array', $active_jobs);
            }
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
    $max_concurrent    = get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2);
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
            <a href="?page=freedomtranslate&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>">Tools & Database</a>
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
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <h3 style="margin: 0;">Monitor Status</h3>
                    <select id="ft_queue_view_selector" style="font-size: 16px; padding: 5px 10px;">
                        <option value="view_cronjobs">WP-Cron Queue (System)</option>
                        <option value="view_translations">Translations in Progress</option>
                    </select>
                </div>
                <form method="post" onsubmit="return confirm('Are you sure you want to delete ALL pending background translations?');">
                    <?php wp_nonce_field('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron'); ?>
                    <input type="submit" name="freedomtranslate_purge_cron" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="Clear ALL Pending Jobs (Panic Button)">
                </form>
            </div>
            
            <p class="description">Monitor your active AI translations or inspect the underlying WP-Cron system jobs.</p>

            <div id="view_translations" class="ft-queue-view" style="display: none;">
                <?php
                global $wpdb;
                $active_hashes = [];

                $crons = _get_cron_array();
                if (!empty($crons)) {
                    foreach ($crons as $cron_hooks) {
                        if (isset($cron_hooks['freedomtranslate_async_translate'])) {
                            foreach ($cron_hooks['freedomtranslate_async_translate'] as $event) {
                                if (isset($event['args'][0])) {
                                    $active_hashes[$event['args'][0]] = true;
                                }
                            }
                        }
                    }
                }

                $table = $wpdb->prefix . 'freedomtranslate_cache';
                $chunk_rows = $wpdb->get_results("SELECT hash_key FROM $table WHERE hash_key LIKE '%_chunk_%'");
                if (!empty($chunk_rows)) {
                    foreach ($chunk_rows as $row) {
                        $hash = preg_replace('/_chunk_\d+$/', '', $row->hash_key);
                        $active_hashes[$hash] = true;
                    }
                }

                $progress_options = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_freedomtranslate_progress_%'");
                if (!empty($progress_options)) {
                    foreach ($progress_options as $opt) {
                        $hash = str_replace('_transient_freedomtranslate_progress_', '', $opt->option_name);
                        $active_hashes[$hash] = true;
                    }
                }

                $active_translations = [];
                
                foreach (array_keys($active_hashes) as $hash) {
                    $data = get_transient('freedomtranslate_progress_' . $hash);
                    if (is_array($data) && isset($data['total']) && $data['total'] > 0) {
                        $percent = (int) round(($data['done'] / $data['total']) * 100);
                        if ($percent < 100) { 
                            $active_translations[$hash] = [
                                'hash' => $hash,
                                'done' => $data['done'],
                                'total' => $data['total'],
                                'percent' => $percent,
                                'post_id' => isset($data['post_id']) ? $data['post_id'] : 'N/A',
                                'lang' => isset($data['lang']) ? strtoupper($data['lang']) : 'N/A'
                            ];
                        }
                    }
                }
                ?>

                <?php if (empty($active_translations)): ?>
                    <div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>No active translations at the moment.</strong></p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Target Post ID</th>
                                <th>Target Language</th>
                                <th>Progress</th>
                                <th>Chunks (Done / Total)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_translations as $t): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($t['post_id']); ?></strong> 
                                        <?php if(is_numeric($t['post_id'])) echo '<a href="'.get_edit_post_link($t['post_id']).'" target="_blank">(Edit)</a>'; ?>
                                    </td>
                                    <td><?php echo esc_html($t['lang']); ?></td>
                                    <td>
                                        <div style="background: #e1e1e1; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; position: relative;">
                                            <div style="background: #2271b1; width: <?php echo esc_attr($t['percent']); ?>%; height: 100%; transition: width 0.5s;"></div>
                                            <span style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); font-size: 12px; font-weight: bold; color: <?php echo $t['percent'] > 50 ? '#fff' : '#000'; ?>; line-height: 20px;">
                                                <?php echo esc_html($t['percent']); ?>%
                                            </span>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($t['done'] . ' / ' . $t['total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div id="view_cronjobs" class="ft-queue-view">
                <?php
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
                        <p style="margin:0;"><strong>The WP-Cron queue is currently empty.</strong> No translations are waiting to be processed.</p>
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
                                $when = $time_diff > 0 ? "In " . human_time_diff(time(), $job['timestamp']) : "<strong style='color:#d63638;'>Processing now / Queued</strong>";
                                
                                $post_id = isset($job['args'][4]) ? $job['args'][4] : 'Unknown';
                                $target_lang = isset($job['args'][3]) ? strtoupper($job['args'][3]) : 'Unknown';
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
            </div>

            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var selector = document.getElementById('ft_queue_view_selector');
                var views = document.querySelectorAll('.ft-queue-view');
                
                var savedView = localStorage.getItem('ft_queue_view_preference') || 'view_cronjobs';
                selector.value = savedView;
                
                for (var i = 0; i < views.length; i++) {
                    views[i].style.display = 'none';
                }
                var activeView = document.getElementById(savedView);
                if (activeView) {
                    activeView.style.display = 'block';
                }
                
                selector.addEventListener('change', function() {
                    localStorage.setItem('ft_queue_view_preference', this.value);
                    for (var i = 0; i < views.length; i++) {
                        views[i].style.display = 'none';
                    }
                    document.getElementById(this.value).style.display = 'block';
                });
            });
            </script>

        <?php elseif ($active_tab === 'tools'): ?>
            <h3>Database Cache Management</h3>
            
            <div style="background:#f9f9f9; padding:15px; border:1px solid #ccc; margin-bottom:30px;">
                <h4>Clear Cache for a Single Post/Page</h4>
                <p class="description">Enter the <strong>Post ID</strong> or the <strong>full URL</strong> of the page you want to clear. The AI will re-translate it automatically on the next visit.</p>
                <form method="post" style="display:flex; gap:10px; align-items:center; margin-top:10px;">
                    <?php wp_nonce_field('freedomtranslate_purge_single', 'freedomtranslate_nonce_single'); ?>
                    <input type="text" name="single_post_input" placeholder="e.g. 123 or https://..." class="regular-text" required style="width: 300px;">
                    <input type="submit" name="freedomtranslate_purge_single" class="button button-primary" value="Clear Single Cache">
                </form>
            </div>

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
            $table = $wpdb->prefix . 'freedomtranslate_cache';
            $translated_posts = $wpdb->get_results("
                SELECT post_id, GROUP_CONCAT(DISTINCT target_lang ORDER BY target_lang ASC SEPARATOR ', ') as langs, COUNT(*) as chunks
                FROM $table 
                WHERE post_id > 0 
                GROUP BY post_id 
                ORDER BY post_id DESC
            ");
            ?>

            <?php if (empty($translated_posts)): ?>
                <div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                    <p style="margin:0;"><strong>The custom translation database is currently empty.</strong> Visit a page or use auto-prewarm to start caching.</p>
                </div>
            <?php else: ?>
                <table id="ft-registry-table" class="wp-list-table widefat fixed striped" style="width:100%; margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Post ID</th>
                            <th>Title</th>
                            <th>Cached Languages</th>
                            <th style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($translated_posts as $t_post): 
                            $title = get_the_title($t_post->post_id);
                            if (empty($title)) $title = '(No Title / Deleted Post)';
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($t_post->post_id); ?></strong></td>
                                <td><a href="<?php echo get_edit_post_link($t_post->post_id); ?>" target="_blank"><?php echo esc_html($title); ?></a></td>
                                <td><?php echo esc_html(strtoupper($t_post->langs)); ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete the cache for this post?');">
                                        <?php wp_nonce_field('freedomtranslate_purge_single', 'freedomtranslate_nonce_single'); ?>
                                        <input type="hidden" name="single_post_input" value="<?php echo esc_attr($t_post->post_id); ?>">
                                        <input type="submit" name="freedomtranslate_purge_single" class="button button-small" style="color: #d63638;" value="Delete Cache">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
                <style>
                    #ft-registry-table_wrapper { margin-top: 20px; }
                    #ft-registry-table_wrapper .dataTables_filter input { margin-bottom: 10px; padding: 3px 8px; }
                </style>
                <script type="text/javascript" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
                <script>
                jQuery(document).ready(function($) {
                    $('#ft-registry-table').DataTable({
                        "pageLength": 10,
                        "order": [[ 0, "desc" ]],
                        "language": {
                            "search": "Search in translated posts:"
                        }
                    });
                });
                </script>
            <?php endif; ?>
        <?php endif; ?>
        
        </div>
    </div>
    <?php
}

/**
 * AUTO-PREWARM ON SAVE (GUTENBERG SAFE)
 */
add_action('save_post', function($post_id) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    
    if (get_option('freedomtranslate_prewarm_on_save', '0') !== '1') return;
    if (get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $service = get_option('freedomtranslate_service', 'libretranslate');
    if ($service !== 'libretranslate') return;

    if (!wp_next_scheduled('freedomtranslate_trigger_prewarm', [$post_id])) {
        wp_schedule_single_event(time() + 2, 'freedomtranslate_trigger_prewarm', [$post_id]);
    }
});

add_action('freedomtranslate_trigger_prewarm', function($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $site_lang = substr(get_locale(), 0, 2);
    $enabled_langs = get_option('freedomtranslate_languages', []);
    $service = get_option('freedomtranslate_service', 'libretranslate');

    $content = is_string($post->post_content) ? $post->post_content : '';
    $title = is_string($post->post_title) ? $post->post_title : '';
    $post_modified = isset($post->post_modified) ? $post->post_modified : '';

    $delay = 0;
    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;

        if (!empty($content)) {
            $c_hash = md5("post_{$post_id}_{$post_modified}_the_content_{$site_lang}_{$lang}_{$service}_" . md5($content));
            if (ft_get_cache($c_hash) === false && !get_transient('freedomtranslate_pending_' . $c_hash)) {
                set_transient('freedomtranslate_pending_' . $c_hash, '1', 30 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$c_hash, $content, $site_lang, $lang, $post_id]);
                $delay += 2;
            }
        }

        if (!empty($title)) {
            $t_hash = md5("post_{$post_id}_{$post_modified}_the_title_{$site_lang}_{$lang}_{$service}_" . md5($title));
            if (ft_get_cache($t_hash) === false && !get_transient('freedomtranslate_pending_' . $t_hash)) {
                set_transient('freedomtranslate_pending_' . $t_hash, '1', 30 * MINUTE_IN_SECONDS);
                wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$t_hash, $title, $site_lang, $lang, $post_id]);
                $delay += 2;
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
    
    $wpdb->query("DELETE FROM $table WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    
    $p = esc_sql('freedomtranslate_pending_');
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $p . '%', '_transient_timeout_' . $p . '%'));
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
