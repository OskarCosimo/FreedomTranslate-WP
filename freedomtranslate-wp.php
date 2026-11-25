<?php // /freedomtranslate-wp.php
/*
Plugin Name: FreedomTranslate WP
Description: Translate on-the-fly with AI or remote URL with API + custom database cache, and static strings manager.
Version: 2.3.2
Author: thefreedom
Text Domain: freedomtranslate-wp
Requires at least: 5.8
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

defined('ABSPATH') or die('No script kiddies please!');

// Plugin constants
define('FREEDOMTRANSLATE_VERSION',                    '2.3.2');
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
define('FREEDOMTRANSLATE_NUM_CTX_OPTION',             'freedomtranslate_num_ctx');
define('FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION',      'freedomtranslate_seo_permalinks');
define('FREEDOMTRANSLATE_AI_LABEL_ENABLE_OPTION',     'freedomtranslate_ai_label_enable');
define('FREEDOMTRANSLATE_AI_LABEL_TEXT_OPTION',       'freedomtranslate_ai_label_text');

/**
 * Determine the true site source language reliably without being affected by user locale or switch_to_locale().
 *
 * @return string Two-letter language code.
 */
function freedomtranslate_get_site_source_lang() {
    if (defined('FREEDOMTRANSLATE_SITE_LANG')) {
        return FREEDOMTRANSLATE_SITE_LANG;
    }
    $wplang = get_option('WPLANG', '');
    if (!empty($wplang)) {
        return strtolower(substr($wplang, 0, 2));
    }
    return 'en';
}

/**
 * Capture the true site language early on plugins_loaded before switch_to_locale() executes.
 */
add_action('plugins_loaded', function() {
    if (!defined('FREEDOMTRANSLATE_SITE_LANG')) {
        $wplang = get_option('WPLANG', '');
        $lang = !empty($wplang) ? strtolower(substr($wplang, 0, 2)) : 'en';
        define('FREEDOMTRANSLATE_SITE_LANG', $lang);
    }
}, 0);

/**
 * Enqueue jQuery UI Sortable for the languages priority drag & drop.
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'freedomtranslate') {
        wp_enqueue_script('jquery-ui-sortable');
    }
});

/**
 * Send a fire-and-forget cancellation request to the remote translation server.
 *
 * @param string|array $hashkeys Job ID or list of job IDs.
 */
function freedomtranslate_cancel_remote_job($hashkeys) {
    $api_url    = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $cancel_url = rtrim(preg_replace('#/translate$#i', '', $api_url), '/') . '/cancel';

    $body = is_array($hashkeys)
        ? wp_json_encode(['job_ids' => array_values($hashkeys)])
        : wp_json_encode(['job_id'  => $hashkeys]);

    wp_remote_post($cancel_url, [
        'body'     => $body,
        'headers'  => ['Content-Type' => 'application/json'],
        'timeout'  => 3,
        'blocking' => false,
    ]);
}

// ========================================================================
// 1. DATABASE SETUP & CUSTOM CACHE ENGINE
// ========================================================================

function freedomtranslate_install_db() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'freedomtranslate_cache';
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
    KEY post_id (post_id),
    KEY post_lang (post_id, target_lang),
    KEY status (status)
) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    update_option('freedomtranslate_db_version', '1.4');
}
register_activation_hook(__FILE__, 'freedomtranslate_install_db');
add_action('admin_init', function() {
    if (get_option('freedomtranslate_db_version') !== '1.4') {
        freedomtranslate_install_db();
    }
});

function freedomtranslate_update_progress($hash_key, $post_id, $lang, $progress, $status = 'processing', $total_chunks = 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    $wpdb->replace($table, [
        'hash_key'     => $hash_key,
        'post_id'      => $post_id,
        'target_lang'  => $lang,
        'translation'  => '',
        'status'       => $status,
        'progress'     => $progress,
        'total_chunks' => $total_chunks,
        'expires_at'   => null
    ], ['%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']);
}

function freedomtranslate_get_status_db($hash_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';
    return $wpdb->get_row($wpdb->prepare("SELECT status, progress, total_chunks FROM $table WHERE hash_key = %s", $hash_key));
}

function freedomtranslate_get_cache($hash_key) {
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

function freedomtranslate_set_cache($hash_key, $translation, $post_id, $target_lang, $ttl_seconds, $status = 'completed') {
    if ($status === 'completed' && trim($translation) === '') {
        return;
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'freedomtranslate_cache';
    $expires = null;
    if ($ttl_seconds > 0) {
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl_seconds);
    }

    $wpdb->replace($table, [
        'hash_key'     => $hash_key,
        'post_id'      => $post_id,
        'target_lang'  => $target_lang,
        'translation'  => $translation,
        'status'       => $status,
        'progress'     => 100,
        'total_chunks' => 0,
        'expires_at'   => $expires
    ], ['%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s']);
}

function freedomtranslate_get_default_bots_string() {
    return "googlebot\nbingbot\nyandex\nduckduckbot\nslurp\nbaiduspider\nia_archiver\ntwitterbot\nfacebookexternalhit\nrogerbot\nlinkedinbot\nembedly\nquora link preview\nshowyoubot\noutbrain\npinterest\nslackbot\nvkshare\nw3c_validator\nsemrushbot\nahrefsbot\nmj12bot\ndotbot\npetalbot\nseznambot\nbot\nspider\ncrawl\nscraper";
}

function freedomtranslate_is_bot() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';
    if (empty($user_agent)) {
        return true;
    }

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

/**
 * Helper function to cleanly inject the language code as a prefix into the URL path.
 */
function freedomtranslate_inject_lang_prefix($url, $lang) {
    $parsed = wp_parse_url($url);
    if (!is_array($parsed)) {
        return $url;
    }

    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!empty($parsed['host']) && !empty($home_host) && strcasecmp($parsed['host'], $home_host) !== 0) {
        return $url;
    }

    $scheme   = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
    $host     = isset($parsed['host']) ? $parsed['host'] : '';
    $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $path     = isset($parsed['path']) ? $parsed['path'] : '';
    $query    = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

    $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
    $site_path = $site_path ? rtrim($site_path, '/') : '';

    $relative_path = $path;
    if ($site_path && strpos($path, $site_path) === 0) {
        $relative_path = substr($path, strlen($site_path));
    }

    // Prevent double language prefix injection (e.g., when both post_link and the_permalink fire)
    if (preg_match('#^/([a-z]{2})(?:/|$)#i', '/' . ltrim($relative_path, '/'), $matches)) {
        $existing_lang = strtolower($matches[1]);
        if ($existing_lang === strtolower($lang) || freedomtranslate_is_language_enabled($existing_lang)) {
            return $url;
        }
    }

    if ($site_path && strpos($path, $site_path) === 0) {
        $new_path = $site_path . '/' . $lang . '/' . ltrim($relative_path, '/');
    } else {
        $new_path = '/' . $lang . '/' . ltrim($path, '/');
    }

    $new_path = preg_replace('#/{2,}#', '/', $new_path);

    return $scheme . $host . $port . $new_path . $query . $fragment;
}

/**
 * 1. UNIVERSAL LINK FILTERING
 * Deferred to template_redirect (priority 20) so request parsing, canonical redirects,
 * and third-party permalink plugins (e.g. Custom Permalinks) see clean unprefixed URLs
 * before templates render localized links.
 */
function freedomtranslate_register_link_filters() {
    if (is_admin()) {
        return;
    }

    $freedomtranslate_link_filters = [
        'the_permalink', 'post_link', 'page_link', 'post_type_link', 'attachment_link',
        'post_type_archive_link', 'category_link', 'tag_link', 'term_link',
        'author_link', 'day_link', 'month_link', 'year_link', 'search_link', 'get_pagenum_link'
    ];

    foreach ($freedomtranslate_link_filters as $filter) {
        add_filter($filter, function($url) {
            if (is_admin()) {
                return $url;
            }

            $lang      = freedomtranslate_get_user_lang();
            $site_lang = freedomtranslate_get_site_source_lang();

            if ($lang !== $site_lang) {
                if (get_option(FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION, '0') === '1') {
                    return freedomtranslate_inject_lang_prefix($url, $lang);
                } else {
                    return add_query_arg('freedomtranslate_lang', $lang, $url);
                }
            }
            return $url;
        }, 999);
    }
}
add_action('template_redirect', 'freedomtranslate_register_link_filters', 20);

// 2. INTERNAL URL REWRITE (Run very early before other plugins inspect REQUEST_URI)
add_action('plugins_loaded', 'freedomtranslate_internal_url_rewrite', -999);

function freedomtranslate_internal_url_rewrite() {
    if (is_admin() || empty($_SERVER['REQUEST_URI'])) {
        return;
    }
    if (get_option(FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION, '0') !== '1') {
        return;
    }

    // Preserve percent-encoded sequences while stripping control characters
    $request_uri = preg_replace('/[\x00-\x1F\x7F]/', '', wp_unslash($_SERVER['REQUEST_URI']));

    if (!defined('FT_ORIGINAL_URI')) {
        define('FT_ORIGINAL_URI', $request_uri);
    }

    $parsed_url = wp_parse_url($request_uri);
    $path       = isset($parsed_url['path']) ? $parsed_url['path'] : '';

    $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
    $site_path = $site_path ? rtrim($site_path, '/') : '';

    $relative_path = $path;
    if ($site_path && strpos($path, $site_path) === 0) {
        $relative_path = substr($path, strlen($site_path));
    }

    $pattern = '#^/([a-z]{2})(?:/|$)#i';

    if (preg_match($pattern, $relative_path, $matches)) {
        $lang_code     = strtolower($matches[1]);
        $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);

        if (is_array($enabled_langs) && in_array($lang_code, $enabled_langs, true)) {
            if (!defined('FT_IS_PRETTY_URL')) {
                define('FT_IS_PRETTY_URL', $lang_code);
            }

            // Store detected pretty-URL language internally without polluting $_GET before WP::parse_request()
            $GLOBALS['freedomtranslate_pretty_lang'] = $lang_code;

            $remaining_path    = substr($relative_path, strlen('/' . $lang_code));
            $new_relative_path = '/' . ltrim($remaining_path, '/');
            $new_path          = $site_path . $new_relative_path;

            $new_uri = $new_path;
            if (isset($parsed_url['query'])) {
                $new_uri .= '?' . $parsed_url['query'];
            }

            $_SERVER['REQUEST_URI'] = $new_uri;

            if (isset($_SERVER['PATH_INFO']))      $_SERVER['PATH_INFO']      = $new_path;
            if (isset($_SERVER['ORIG_PATH_INFO'])) $_SERVER['ORIG_PATH_INFO'] = $new_path;
            if (isset($_SERVER['REDIRECT_URL']))   $_SERVER['REDIRECT_URL']   = $new_path;
            if (isset($_SERVER['SCRIPT_URL']))     $_SERVER['SCRIPT_URL']     = $new_path;
            if (isset($_SERVER['UNENCODED_URL']))  $_SERVER['UNENCODED_URL']  = $new_uri;
        }
    }
}

// 3. HANDLE CANONICAL REDIRECTS SAFELY ON TRANSLATED URLS
add_filter('redirect_canonical', function($redirect_url, $requested_url) {
    if (!defined('FT_IS_PRETTY_URL')) {
        return $redirect_url;
    }

    if (empty($redirect_url)) {
        return false;
    }

    $prefixed_redirect = freedomtranslate_inject_lang_prefix($redirect_url, FT_IS_PRETTY_URL);
    $parsed_target     = wp_parse_url($prefixed_redirect);
    $target_uri        = (isset($parsed_target['path']) ? $parsed_target['path'] : '/')
        . (isset($parsed_target['query']) ? '?' . $parsed_target['query'] : '');

    // Abort redirect if the localized canonical URL already matches the original requested URI
    if (defined('FT_ORIGINAL_URI') && $target_uri === FT_ORIGINAL_URI) {
        return false;
    }

    return $prefixed_redirect;
}, 10, 2);

add_filter('wpseo_canonical', function($canonical) {
    if (defined('FT_IS_PRETTY_URL')) return false;
    return $canonical;
}, 10, 1);

add_filter('rank_math/frontend/canonical', function($canonical) {
    if (defined('FT_IS_PRETTY_URL')) return false;
    return $canonical;
}, 10, 1);

// Prevent page caches / CDNs from caching 404 responses on translated pretty URLs
add_action('template_redirect', function() {
    if (defined('FT_IS_PRETTY_URL') && is_404()) {
        nocache_headers();
    }
}, 999);

// 4. SMART SEO REDIRECTOR
add_action('template_redirect', 'freedomtranslate_seo_language_redirect', 1);

function freedomtranslate_seo_language_redirect() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $req_uri_raw = isset($_SERVER['REQUEST_URI']) ? preg_replace('/[\x00-\x1F\x7F]/', '', wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if (is_feed() || strpos($req_uri_raw, '/feed') !== false) {
        return;
    }

    if (get_option(FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION, '0') !== '1') {
        return;
    }

    $site_source_lang = freedomtranslate_get_site_source_lang();

    // Derive scheme and host safely from WordPress home_url() to prevent Host Header Injection
    $home_parsed = wp_parse_url(home_url());
    $scheme      = isset($home_parsed['scheme']) ? $home_parsed['scheme'] . '://' : (is_ssl() ? 'https://' : 'http://');
    $host        = isset($home_parsed['host']) ? $home_parsed['host'] : '';
    if (isset($home_parsed['port'])) {
        $host .= ':' . $home_parsed['port'];
    }
    if (empty($host)) {
        return;
    }
    $site_path = isset($home_parsed['path']) ? rtrim($home_parsed['path'], '/') : '';

    $actual_uri = defined('FT_ORIGINAL_URI') ? FT_ORIGINAL_URI : $req_uri_raw;

    $has_explicit_query = false;
    $explicit_lang      = '';
    if (isset($_SERVER['QUERY_STRING'])) {
        parse_str(wp_unslash($_SERVER['QUERY_STRING']), $query_params);
        if (isset($query_params['freedomtranslate_lang']) && is_scalar($query_params['freedomtranslate_lang'])) {
            $candidate = sanitize_text_field($query_params['freedomtranslate_lang']);
            if (freedomtranslate_is_language_enabled($candidate)) {
                $has_explicit_query = true;
                $explicit_lang      = $candidate;
            }
        }
    }

    if ($has_explicit_query) {
        if (defined('FT_IS_PRETTY_URL') && FT_IS_PRETTY_URL === $explicit_lang) {
            return;
        }

        $clean_uri = remove_query_arg('freedomtranslate_lang', $actual_uri);

        if (defined('FT_IS_PRETTY_URL')) {
            $prefix_pattern = '#^' . preg_quote($site_path . '/' . FT_IS_PRETTY_URL, '#') . '(?:/|$)#i';
            $clean_uri      = preg_replace($prefix_pattern, $site_path . '/', $clean_uri);
        }

        $clean_base_url = $scheme . $host . $clean_uri;

        if ($explicit_lang === $site_source_lang) {
            $redirect_url = $clean_base_url;
        } else {
            $redirect_url = freedomtranslate_inject_lang_prefix($clean_base_url, $explicit_lang);
        }

        nocache_headers();
        wp_safe_redirect(esc_url_raw($redirect_url), 302);
        exit;
    }

    if (defined('FT_IS_PRETTY_URL')) {
        return;
    }

    $target_lang = freedomtranslate_get_user_lang();
    if ($target_lang === $site_source_lang) {
        return;
    }

    $current_url  = $scheme . $host . $actual_uri;
    $redirect_url = freedomtranslate_inject_lang_prefix($current_url, $target_lang);

    if ($redirect_url !== $current_url) {
        nocache_headers();
        wp_safe_redirect(esc_url_raw($redirect_url), 302);
        exit;
    }
}

// 5. HELPER FUNCTIONS
function freedomtranslate_get_user_lang() {
    if (!empty($GLOBALS['freedomtranslate_pretty_lang']) && freedomtranslate_is_language_enabled($GLOBALS['freedomtranslate_pretty_lang'])) {
        return $GLOBALS['freedomtranslate_pretty_lang'];
    }
    if (isset($_GET['freedomtranslate_lang']) && is_scalar($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            return $lang;
        }
    }
    if (isset($_COOKIE['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_COOKIE['freedomtranslate_lang']));
        if (freedomtranslate_is_language_enabled($lang)) {
            return $lang;
        }
    }
    $site_lang      = freedomtranslate_get_site_source_lang();
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');
    if ($detection_mode === 'auto') {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browser = strtolower(substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])), 0, 2));
            return freedomtranslate_is_language_enabled($browser) ? $browser : $site_lang;
        }
    } else {
        return get_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, $site_lang);
    }
    return $site_lang;
}

function freedomtranslate_is_language_enabled($lang) {
    $enabled = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys(freedomtranslate_get_all_languages()));
    return is_array($enabled) && in_array($lang, $enabled, true);
}

function freedomtranslate_get_all_languages() {
    return [
        'ar' => 'Arabic',     'az' => 'Azerbaijani', 'zh' => 'Chinese',    'cs' => 'Czech',
        'da' => 'Danish',     'nl' => 'Dutch',       'en' => 'English',    'fi' => 'Finnish',
        'fr' => 'Français',   'de' => 'Deutsch',     'el' => 'Greek',      'he' => 'Hebrew',
        'hi' => 'Hindi',      'hu' => 'Hungarian',   'id' => 'Indonesian', 'ga' => 'Irish',
        'it' => 'Italiano',   'ja' => 'Japanese',    'ko' => 'Korean',     'no' => 'Norwegian',
        'pl' => 'Polish',     'pt' => 'Português',   'ro' => 'Romanian',   'ru' => 'Русский',
        'sk' => 'Slovak',     'es' => 'Español',     'sv' => 'Swedish',    'tr' => 'Turkish',
        'uk' => 'Ukrainian',  'vi' => 'Vietnamese',
    ];
}

// Switch WordPress locale AFTER request parsing so localized rewrite rules never trigger 404s
add_action('wp', 'freedomtranslate_force_active_locale', 1);

function freedomtranslate_force_active_locale() {
    if (is_admin()) {
        return;
    }

    $user_lang = '';
    if (!empty($GLOBALS['freedomtranslate_pretty_lang'])) {
        $user_lang = $GLOBALS['freedomtranslate_pretty_lang'];
    } elseif (isset($_GET['freedomtranslate_lang']) && is_scalar($_GET['freedomtranslate_lang'])) {
        $user_lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
    } elseif (isset($_COOKIE['freedomtranslate_lang'])) {
        $user_lang = sanitize_text_field(wp_unslash($_COOKIE['freedomtranslate_lang']));
    }

    if (empty($user_lang) || !freedomtranslate_is_language_enabled($user_lang)) {
        return;
    }

    $locales_map = [
        'ar' => 'ar',    'az' => 'az',    'zh' => 'zh_CN', 'cs' => 'cs_CZ',
        'da' => 'da_DK', 'nl' => 'nl_NL', 'en' => 'en_US', 'fi' => 'fi',
        'fr' => 'fr_FR', 'de' => 'de_DE', 'el' => 'el',    'he' => 'he_IL',
        'hi' => 'hi_IN', 'hu' => 'hu_HU', 'id' => 'id_ID', 'ga' => 'ga',
        'it' => 'it_IT', 'ja' => 'ja',    'ko' => 'ko_KR', 'no' => 'nb_NO',
        'pl' => 'pl_PL', 'pt' => 'pt_PT', 'ro' => 'ro_RO', 'ru' => 'ru_RU',
        'sk' => 'sk_SK', 'es' => 'es_ES', 'sv' => 'sv_SE', 'tr' => 'tr_TR',
        'uk' => 'uk',    'vi' => 'vi',
    ];

    if (isset($locales_map[$user_lang])) {
        $target_locale = $locales_map[$user_lang];
        if (get_locale() !== $target_locale) {
            switch_to_locale($target_locale);
        }
    }
}

// ========================================================================
// 3. SHORTCODES & FRONTEND ASSETS
// ========================================================================

/**
 * Register frontend styles and scripts cleanly.
 */
add_action('wp_enqueue_scripts', function() {
    wp_register_style('freedomtranslate-frontend', false, [], FREEDOMTRANSLATE_VERSION);
    $css = '
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
    ';
    wp_add_inline_style('freedomtranslate-frontend', $css);

    wp_register_script('freedomtranslate-frontend', '', [], FREEDOMTRANSLATE_VERSION, true);
    wp_add_inline_script('freedomtranslate-frontend', 'function freedomtranslateShowLoader(){var l=document.getElementById("freedomtranslate-loader");if(l)l.style.display="flex";}');

    // Enqueue Google Translate Element cleanly when googlehash is active
    if (get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, '') === 'googlehash') {
        $post_id = get_the_ID();
        if (!($post_id && get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1')) {
            $user_lang = freedomtranslate_get_user_lang();
            $site_lang = freedomtranslate_get_site_source_lang();

            wp_register_script('freedomtranslate-google-init', '', [], FREEDOMTRANSLATE_VERSION, false);
            wp_enqueue_script('freedomtranslate-google-init');
            wp_add_inline_script(
                'freedomtranslate-google-init',
                'function freedomtranslateGoogleInit(){new google.translate.TranslateElement({pageLanguage:"' . esc_js($site_lang) . '"},"google_translate_element");}'
            );

            wp_enqueue_script(
                'freedomtranslate-google-translate',
                'https://translate.google.com/translate_a/element.js?cb=freedomtranslateGoogleInit',
                ['freedomtranslate-google-init'],
                null,
                true
            );

            $inline_redirect = '
            document.addEventListener("DOMContentLoaded",function(){
                var m=document.cookie.match(new RegExp("(^| )freedomtranslate_lang=([^;]+)"));
                var userLang=m?m[2]:"' . esc_js($user_lang) . '";
                var siteLang="' . esc_js($site_lang) . '";
                if(userLang!==siteLang&&window.location.hash.indexOf("googtrans")===-1){
                    if(!sessionStorage.getItem("ft_google_redirected")){
                        sessionStorage.setItem("ft_google_redirected","1");
                        window.location.hash="googtrans("+siteLang+"|"+userLang+")";
                        window.location.reload();
                    }
                }
            });';
            wp_add_inline_script('freedomtranslate-google-translate', $inline_redirect);
        }
    }
});

/**
 * Render the single loader overlay in footer when the selector shortcode is used.
 */
add_action('wp_footer', function() {
    if (!empty($GLOBALS['freedomtranslate_has_selector'])) {
        echo '<div id="freedomtranslate-loader" style="display:none;"><div class="freedomtranslate-loader-content"><div class="freedomtranslate-spinner"></div><p>' . esc_html__('Translation loading...', 'freedomtranslate-wp') . '</p></div></div>';
    }
    if (get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, '') === 'googlehash') {
        $post_id = get_the_ID();
        if (!($post_id && get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1')) {
            echo '<div id="google_translate_element" style="display:none;"></div>';
        }
    }
});

function freedomtranslate_language_selector_shortcode($atts) {
    $atts = shortcode_atts(['post_id' => get_the_ID()], $atts, 'freedomtranslate_selector');

    $all            = freedomtranslate_get_all_languages();
    $enabled        = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all));
    $current        = freedomtranslate_get_user_lang();
    $detection_mode = get_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, 'auto');

    wp_enqueue_style('freedomtranslate-frontend');
    wp_enqueue_script('freedomtranslate-frontend');
    $GLOBALS['freedomtranslate_has_selector'] = true;

    $html  = '<div class="freedomtranslate-selector-wrapper">';
    $html .= '<form method="get" class="freedomtranslate-form">';
    $html .= '<select name="freedomtranslate_lang" onchange="freedomtranslateShowLoader(); this.form.submit();">';
    if (is_array($enabled)) {
        foreach ($enabled as $code) {
            if (isset($all[$code])) {
                $selected = ($code === $current) ? 'selected' : '';
                $html    .= sprintf('<option value="%s" %s>%s</option>', esc_attr($code), $selected, esc_html($all[$code]));
            }
        }
    }
    $html .= '</select>';

    foreach ($_GET as $key => $value) {
        if ($key !== 'freedomtranslate_lang' && is_scalar($value)) {
            $html .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr(sanitize_key(wp_unslash($key))),
                esc_attr(sanitize_text_field(wp_unslash($value)))
            );
        }
    }
    $html .= '</form>';
    $html .= ($detection_mode === 'manual')
        ? '<span class="freedomtranslate-mode-indicator" title="' . esc_attr__('Manual: initial language defined', 'freedomtranslate-wp') . '">&#128205;</span>'
        : '<span class="freedomtranslate-mode-indicator" title="' . esc_attr__('Automatic: browser-detected default', 'freedomtranslate-wp') . '">&#127758;</span>';
    $html .= '</div>';

    return $html;
}
add_shortcode('freedomtranslate_selector', 'freedomtranslate_language_selector_shortcode');

add_shortcode('ft_string', function($atts) {
    $atts    = shortcode_atts(['id' => ''], $atts, 'ft_string');
    $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
    if (empty($atts['id']) || !isset($strings[$atts['id']])) {
        return '';
    }

    $user_lang        = freedomtranslate_get_user_lang();
    $site_source_lang = freedomtranslate_get_site_source_lang();

    if ($user_lang === $site_source_lang) {
        return esc_html($strings[$atts['id']]['original']);
    }

    if (!empty($strings[$atts['id']]['translations'][$user_lang])) {
        return esc_html($strings[$atts['id']]['translations'][$user_lang]);
    }

    return esc_html($strings[$atts['id']]['original']);
});

// ========================================================================
// 4. TRANSLATION APIS & HTML PROTECTION
// ========================================================================

/**
 * Format a placeholder token cleanly depending on the translation engine.
 * LLMs (Ollama) get neutral bracket tokens so they do not misinterpret translate="no" spans.
 */
function freedomtranslate_wrap_placeholder($token, $use_notranslate_span = true) {
    if ($use_notranslate_span) {
        return ' <span translate="no" class="notranslate">' . $token . '</span> ';
    }
    return ' ' . $token . ' ';
}

/**
 * Protect WordPress shortcodes before sending HTML for translation.
 */
function freedomtranslate_protect_shortcodes($html, $use_notranslate_span = true) {
    $placeholders = [];

    if (preg_match_all('/\[\/?(?:[a-zA-Z0-9_-]+)(?:\s+[^\]]+)?\]/s', $html, $matches)) {
        $count = 0;
        foreach ($matches[0] as $match) {
            $ph         = '[[FTSC_' . $count . ']]';
            $wrapped_ph = freedomtranslate_wrap_placeholder($ph, $use_notranslate_span);

            $placeholders[$ph] = $match;
            $pos = strpos($html, $match);
            if ($pos !== false) {
                $html = substr_replace($html, $wrapped_ph, $pos, strlen($match));
            }
            $count++;
        }
    }
    return [$html, $placeholders];
}

/**
 * Restore shortcodes safely without regex backreference issues.
 */
function freedomtranslate_restore_shortcodes($html, $placeholders) {
    foreach ($placeholders as $ph => $original) {
        $pattern = '/\s*<span[^>]*translate="no"[^>]*>\s*' . preg_quote($ph, '/') . '\s*<\/span>\s*/i';
        if (preg_match($pattern, $html)) {
            $html = preg_replace_callback($pattern, function() use ($original) {
                return $original;
            }, $html);
        } else {
            $html = str_replace($ph, $original, $html);
        }
    }
    return $html;
}

/**
 * Protect WordPress Gutenberg block comments from being altered by translation APIs.
 */
function freedomtranslate_protect_gutenberg_blocks($html, $use_notranslate_span = true) {
    $placeholders = [];

    if (preg_match_all('/<!--\s*\/?\s*wp:[^>]+-->/is', $html, $matches)) {
        $count = 0;
        foreach ($matches[0] as $match) {
            $ph         = '[[FTWP_' . $count . ']]';
            $wrapped_ph = freedomtranslate_wrap_placeholder($ph, $use_notranslate_span);

            $placeholders[$ph] = $match;
            $pos = strpos($html, $match);
            if ($pos !== false) {
                $html = substr_replace($html, $wrapped_ph, $pos, strlen($match));
            }
            $count++;
        }
    }
    return [$html, $placeholders];
}

/**
 * Restore Gutenberg block comments safely without regex backreferences or corrupting restored comments.
 */
function freedomtranslate_restore_gutenberg_blocks($html, $placeholders) {
    foreach ($placeholders as $ph => $original) {
        $pattern = '/\s*<span[^>]*translate="no"[^>]*>\s*' . preg_quote($ph, '/') . '\s*<\/span>\s*/i';
        if (preg_match($pattern, $html)) {
            $html = preg_replace_callback($pattern, function() use ($original) {
                return $original;
            }, $html);
        } else {
            $html = str_replace($ph, $original, $html);
        }
    }

    // Only clean up any un-restored placeholder tokens, never strip valid wp: block names
    $html = preg_replace('/\[\[FTWP_\d+\]\]/i', '', $html);

    return $html;
}

/**
 * Protect spaces around inline formatting tags (excluding span so protected placeholder spans are never double-wrapped).
 */
function freedomtranslate_protect_formatting_spaces($html, $use_notranslate_span = true) {
    $placeholders = [];
    $count        = 0;

    // Intentionally exclude <span> so placeholder spans are never double-wrapped
    $pattern = '/(\s+)?(<\/?(?:a|b|i|u|em|strong|code|mark|small|del|ins|sub|sup)(?:\s+[^>]*)?>)(\s+)?/i';

    $html = preg_replace_callback($pattern, function($m) use (&$placeholders, &$count, $use_notranslate_span) {
        $ph_before = isset($m[1]) ? $m[1] : '';
        $tag       = isset($m[2]) ? $m[2] : '';
        $ph_after  = isset($m[3]) ? $m[3] : '';

        if ($ph_before !== '') {
            $token = '[[FTSPB' . $count . ']]';
            $placeholders[$token] = $ph_before;
            $ph_before = $use_notranslate_span ? '<span translate="no" class="notranslate">' . $token . '</span>' : $token;
        }

        if ($ph_after !== '') {
            $token = '[[FTSPA' . $count . ']]';
            $placeholders[$token] = $ph_after;
            $ph_after = $use_notranslate_span ? '<span translate="no" class="notranslate">' . $token . '</span>' : $token;
        }

        if ($ph_before !== '' || $ph_after !== '') {
            $count++;
        }

        return $ph_before . $tag . $ph_after;
    }, $html);

    return [$html, $placeholders];
}

/**
 * Restore spaces around inline formatting tags safely.
 */
function freedomtranslate_restore_formatting_spaces($html, $placeholders) {
    foreach ($placeholders as $ph => $original_space) {
        $pattern = '/<span[^>]*translate="no"[^>]*>\s*' . preg_quote($ph, '/') . '\s*<\/span>/i';
        if (preg_match($pattern, $html)) {
            $html = preg_replace_callback($pattern, function() use ($original_space) {
                return $original_space;
            }, $html);
        } else {
            $html = str_replace($ph, $original_space, $html);
        }
    }
    return $html;
}

function freedomtranslate_translate_google_official($text, $source, $target, $format = 'text') {
    $api_key = get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '');
    if (empty($api_key)) {
        return $text;
    }

    $timeout = (int) get_option('freedomtranslate_api_timeout', 120);

    $response = wp_remote_post('https://translation.googleapis.com/language/translate/v2', [
        'body'    => wp_json_encode(['q' => $text, 'source' => $source, 'target' => $target, 'format' => $format, 'key' => $api_key]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => $timeout,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $json = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($json['data']['translations'][0]['translatedText'])) {
        return $json['data']['translations'][0]['translatedText'];
    }

    return new WP_Error('api_error', 'Missing translatedText');
}

function freedomtranslate_translate_libre($text, $source, $target, $format = 'text', $job_id = '') {
    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $timeout = (int) get_option('freedomtranslate_api_timeout', 120);

    $body = [
        'q'      => $text,
        'source' => $source,
        'target' => $target,
        'format' => $format
    ];

    if (!empty($job_id)) {
        $body['job_id'] = $job_id;
    }

    if (!empty($api_key)) {
        $body['api_key'] = $api_key;
    }

    $num_ctx         = (int) get_option(FREEDOMTRANSLATE_NUM_CTX_OPTION, 4096);
    $chars_per_chunk = (int) get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500);
    $actual_len      = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

    // Ensure num_predict scales with actual chunk size so long single blocks are never truncated
    $dynamic_kill_switch = max($chars_per_chunk, $actual_len) + 1000;

    $body['options'] = [
        'num_ctx'     => $num_ctx > 0 ? $num_ctx : 4096,
        'num_predict' => $dynamic_kill_switch
    ];

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($body),
        'timeout' => $timeout
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $json = json_decode(wp_remote_retrieve_body($response), true);
    return isset($json['translatedText']) ? $json['translatedText'] : new WP_Error('apierror', 'Missing translatedText');
}

/**
 * Clean up LLM hallucinations and chain-of-thought artifacts.
 */
function freedomtranslate_clean_ollama_translation($text) {
    $text = preg_replace('/<think(?:ing)?>.*?<\/think(?:ing)?>/is', '', $text);
    $text = trim($text);

    $text = preg_replace('/^(Text:\s*|Testo:\s*|HTML:\s*)/i', '', $text);
    $text = preg_replace('/^```[a-zA-Z]*\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);

    return trim($text);
}

/**
 * Native direct integration for Ollama API.
 */
function freedomtranslate_translate_ollama($text, $source, $target, $format = 'text', $job_id = '', $temperature = 0.1) {
    $api_url = get_option(FREEDOMTRANSLATE_API_URL_OPTION, 'http://localhost:11434/api/generate');

    if (strpos(strtolower($api_url), '/api/') === false) {
        $api_url = rtrim($api_url, '/') . '/api/generate';
    }

    $model_name = get_option('freedomtranslate_ollama_model', 'gemma4-translate');
    $keep_alive = get_option('freedomtranslate_keep_alive', '1m');
    $timeout    = (int) get_option('freedomtranslate_api_timeout', 120);

    $lang_map = [
        'ar' => 'Arabic',     'az' => 'Azerbaijani', 'bg' => 'Bulgarian',  'zh' => 'Chinese',
        'cs' => 'Czech',      'da' => 'Danish',      'nl' => 'Dutch',      'en' => 'English',
        'fi' => 'Finnish',    'fr' => 'French',      'de' => 'German',     'el' => 'Greek',
        'he' => 'Hebrew',     'hi' => 'Hindi',       'hu' => 'Hungarian',  'id' => 'Indonesian',
        'ga' => 'Irish',      'it' => 'Italian',     'ja' => 'Japanese',   'ko' => 'Korean',
        'no' => 'Norwegian',  'pl' => 'Polish',      'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian',    'sk' => 'Slovak',      'es' => 'Spanish',    'sv' => 'Swedish',
        'th' => 'Thai',       'tr' => 'Turkish',     'uk' => 'Ukrainian',  'vi' => 'Vietnamese'
    ];

    $full_source = isset($lang_map[strtolower($source)]) ? $lang_map[strtolower($source)] : $source;
    $full_target = isset($lang_map[strtolower($target)]) ? $lang_map[strtolower($target)] : $target;

    if ($format === 'html') {
        $prompt = "/no_think\n" .
                  "You are a direct translation engine. DO NOT THINK. DO NOT USE THINK TAGS.\n" .
                  "Translate ALL visible text (including quoted text and text inside <em>, <strong>, <a>) in the following HTML from {$full_source} to {$full_target}.\n" .
                  "CRITICAL RULES:\n" .
                  "- Translate 100% of the {$full_source} prose into {$full_target}. NEVER return untranslated {$full_source} sentences.\n" .
                  "- Preserve all HTML tags, attributes (href, src, class, id), spaces around inline tags, and placeholder tokens like [[FTWP_0]], [[FTSC_0]], [[FTSPB0]], [PH_...] untouched.\n" .
                  "- Return ONLY the translated HTML.\n\n" .
                  "HTML to translate:\n{$text}";
    } else {
        $prompt = "/no_think\n" .
                  "You are a direct translation engine. DO NOT THINK. DO NOT USE THINK TAGS.\n" .
                  "Translate from {$full_source} to {$full_target}.\n" .
                  "CRITICAL RULE: Provide ONLY the translated {$full_target} text. NO explanations. NO introductions.\n\n" .
                  "Text to translate:\n{$text}";
    }

    $num_ctx         = (int) get_option(FREEDOMTRANSLATE_NUM_CTX_OPTION, 4096);
    $chars_per_chunk = (int) get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500);
    $actual_len      = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $num_predict     = max($chars_per_chunk, $actual_len) + 1000;

    $payload = [
        'model'      => $model_name,
        'prompt'     => $prompt,
        'stream'     => false,
        'raw'        => false,
        'keep_alive' => $keep_alive,
        'options'    => [
            'temperature' => $temperature,
            'top_p'       => 0.9,
            'num_predict' => $num_predict,
            'num_ctx'     => $num_ctx > 0 ? $num_ctx : 4096
        ]
    ];

    if (get_option('freedomtranslate_ollama_disable_think', '1') === '1') {
        $payload['think'] = false;
    }

    $response = wp_remote_post($api_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
        'timeout' => $timeout
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $json = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($json['response'])) {
        return freedomtranslate_clean_ollama_translation($json['response']);
    }

    return new WP_Error('apierror', 'Missing response from Ollama API');
}

/**
 * Universal wrapper for API translation calls.
 */
function freedomtranslate_do_api_translation($text, $source, $target, $format = 'text', $job_id = '', $temperature = 0.1) {
    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    if ($service === 'google_official') {
        return freedomtranslate_translate_google_official($text, $source, $target, $format);
    } elseif ($service === 'ollama') {
        return freedomtranslate_translate_ollama($text, $source, $target, $format, $job_id, $temperature);
    } else {
        return freedomtranslate_translate_libre($text, $source, $target, $format, $job_id);
    }
}

/**
 * Protect excluded words inside HTML text nodes (PHP 8.2+ compatible without deprecated HTML-ENTITIES).
 */
function freedomtranslate_protect_excluded_words_in_html($html, $excluded_words) {
    if (empty($excluded_words) || trim($html) === '') {
        return [$html, []];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $encoded = function_exists('mb_encode_numericentity')
        ? mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, ~0], 'UTF-8')
        : '<?xml encoding="utf-8" ?>' . $html;
    $dom->loadHTML('<div>' . $encoded . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath        = new DOMXPath($dom);
    $textNodes    = $xpath->query('//text()');
    $placeholders = [];

    if ($textNodes) {
        foreach ($textNodes as $textNode) {
            $text = $textNode->nodeValue;
            foreach ($excluded_words as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }
                $pattern     = '/(?<!\w)' . preg_quote($word, '/') . '(?!\w)/ui';
                $placeholder = '[PH_' . strtoupper(substr(md5($word), 0, 8)) . ']';
                if (preg_match($pattern, $text)) {
                    $text = preg_replace($pattern, $placeholder, $text);
                    $placeholders[$placeholder] = $word;
                }
            }
            $textNode->nodeValue = $text;
        }
    }

    $wrapper = $dom->getElementsByTagName('div')->item(0);
    $result  = '';
    if ($wrapper) {
        foreach ($wrapper->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }
    } else {
        $result = $html;
    }

    return [$result, $placeholders];
}

function freedomtranslate_restore_excluded_words_in_html($text, $placeholders) {
    foreach ($placeholders as $placeholder => $original_word) {
        $text = preg_replace_callback('/' . preg_quote($placeholder, '/') . '/i', function() use ($original_word) {
            return $original_word;
        }, $text);
    }
    return $text;
}

function freedomtranslate_get_ttl_days($post_id = 0) {
    if ($post_id > 0) {
        $post_ttl = get_post_meta($post_id, '_freedomtranslate_cache_ttl', true);
        if ($post_ttl !== '' && (int) $post_ttl > 0) {
            return (int) $post_ttl;
        }
    }
    $global = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    return (int) $global > 0 ? (int) $global : 30;
}

// ========================================================================
// 5. ASYNC WORKERS & POST FILTERS
// ========================================================================

/**
 * Helper to safely remove scheduled translation crons by hash keys.
 */
function freedomtranslate_remove_crons_by_hash($hash_keys) {
    if (empty($hash_keys)) return false;
    if (!is_array($hash_keys)) $hash_keys = [$hash_keys];

    $crons = _get_cron_array();
    if (!is_array($crons)) return false;

    $changed = false;
    foreach ($crons as $timestamp => $cron_hooks) {
        if (isset($cron_hooks['freedomtranslate_async_translate']) && is_array($cron_hooks['freedomtranslate_async_translate'])) {
            foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                if (isset($event['args'][0]) && in_array($event['args'][0], $hash_keys, true)) {
                    unset($crons[$timestamp]['freedomtranslate_async_translate'][$sig]);
                    if (empty($crons[$timestamp]['freedomtranslate_async_translate'])) {
                        unset($crons[$timestamp]['freedomtranslate_async_translate']);
                    }
                    if (empty($crons[$timestamp])) {
                        unset($crons[$timestamp]);
                    }
                    $changed = true;
                }
            }
        }
    }

    if ($changed) {
        _set_cron_array($crons);
    }

    return $changed;
}

function freedomtranslate_translate($text, $source, $target, $format = 'text', $post_id = 0, $custom_hash = '') {
    if (!function_exists('wp_remote_post')) return $text;
    if (trim($text) === '' || $source === $target || !freedomtranslate_is_language_enabled($target)) return $text;

    $service              = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $use_notranslate_span = ($service !== 'ollama');

    $sc_placeholders    = [];
    $wp_placeholders    = [];
    $space_placeholders = [];

    if ($format === 'html') {
        list($text, $wp_placeholders) = freedomtranslate_protect_gutenberg_blocks($text, $use_notranslate_span);
        list($text, $sc_placeholders) = freedomtranslate_protect_shortcodes($text, $use_notranslate_span);
        if ($use_notranslate_span) {
            list($text, $space_placeholders) = freedomtranslate_protect_formatting_spaces($text, true);
        }
    }

    $excluded_words = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $placeholders   = [];
    if ($format === 'html' && !empty($excluded_words)) {
        list($text, $placeholders) = freedomtranslate_protect_excluded_words_in_html($text, $excluded_words);
    } elseif (!empty($excluded_words)) {
        foreach ($excluded_words as $word) {
            $word = trim($word);
            if ($word === '') continue;

            $placeholder = 'FTPH' . strtoupper(substr(md5($word), 0, 8)) . 'FTPH';
            $pattern     = '/(?<![a-zA-Z0-9_\-])' . preg_quote($word, '/') . '(?![a-zA-Z0-9_\-])/ui';
            $text        = preg_replace($pattern, $placeholder, $text);
            $placeholders[$placeholder] = $word;
        }
    }

    $legacy_hash = md5($text . $source . $target . $format . $service);
    $active_hash = !empty($custom_hash) ? $custom_hash : $legacy_hash;

    $cached = freedomtranslate_get_cache($active_hash);

    if ($cached === false && $active_hash !== $legacy_hash) {
        $cached = freedomtranslate_get_cache($legacy_hash);
        if ($cached !== false) {
            freedomtranslate_set_cache($active_hash, $cached, $post_id, $target, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));
        }
    }

    if ($cached !== false) return $cached;

    if ($service === 'googlehash') {
        $translated = $text;
    } else {
        $translated = freedomtranslate_do_api_translation($text, $source, $target, $format, $active_hash);
    }

    if (is_wp_error($translated)) return $text;

    if (!empty($placeholders)) {
        $translated = freedomtranslate_restore_excluded_words_in_html($translated, $placeholders);
    }
    if (!empty($space_placeholders)) {
        $translated = freedomtranslate_restore_formatting_spaces($translated, $space_placeholders);
    }
    if (!empty($sc_placeholders)) {
        $translated = freedomtranslate_restore_shortcodes($translated, $sc_placeholders);
    }
    if (!empty($wp_placeholders)) {
        $translated = freedomtranslate_restore_gutenberg_blocks($translated, $wp_placeholders);
    }

    freedomtranslate_set_cache($active_hash, $translated, $post_id, $target, DAY_IN_SECONDS * freedomtranslate_get_ttl_days($post_id));

    return $translated;
}

// ========================================================================
// WORKER FOR STATIC STRINGS
// ========================================================================
add_action('freedomtranslate_async_string_translate', 'freedomtranslate_string_worker', 10, 5);

function freedomtranslate_string_worker($string_id, $text, $site_lang, $target_lang, $rand = '') {
    $worker_start_time = time();

    if ((int) get_option('freedomtranslate_last_panic_time', 0) > $worker_start_time) return;
    if (empty($string_id) || empty($target_lang)) return;

    $translated = freedomtranslate_do_api_translation($text, $site_lang, $target_lang, 'text');

    if (is_wp_error($translated)) {
        return;
    }

    if ($translated !== $text && !empty(trim($translated))) {
        $lock_name = 'freedomtranslate_lock_static_strings';
        $locked    = false;
        $attempts  = 0;

        while ($attempts < 40) {
            if (false === get_transient($lock_name)) {
                set_transient($lock_name, '1', 10);
                $locked = true;
                break;
            }
            usleep(50000);
            $attempts++;
        }

        wp_cache_delete(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, 'options');
        $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        if (!is_array($strings)) {
            $strings = [];
        }

        if (!isset($strings[$string_id])) {
            $strings[$string_id] = [
                'original'     => $text,
                'translations' => []
            ];
        }

        $strings[$string_id]['translations'][$target_lang] = $translated;
        update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);

        if ($locked) {
            delete_transient($lock_name);
        }
    }
}

function freedomtranslate_async_worker($hash_key, $site_lang, $user_lang, $post_id, $rand_id = '') {
    $worker_start_time = time();

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    ignore_user_abort(true);

    $post = get_post($post_id);
    if (!$post) return;

    $max_concurrent = max(1, (int) get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2));
    $current_status = freedomtranslate_get_status_db($hash_key);
    if ($current_status && $current_status->status === 'completed') {
        return;
    }
    $is_processing = ($current_status && $current_status->status === 'processing');

    if (!$is_processing) {
        $lock_name = 'freedomtranslate_lock_worker_startup';
        $locked    = false;
        $attempts  = 0;

        while ($attempts < 30) {
            if (false === get_transient($lock_name)) {
                set_transient($lock_name, '1', 5);
                $locked = true;
                break;
            }
            usleep(50000);
            $attempts++;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';

        $active_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = 'processing' AND hash_key != %s",
            $hash_key
        ));

        if ($active_count >= $max_concurrent) {
            if ($locked) delete_transient($lock_name);
            wp_schedule_single_event(time() + 10, 'freedomtranslate_async_translate', [$hash_key, $site_lang, $user_lang, (int) $post_id, uniqid('', true)]);
            return;
        }

        freedomtranslate_update_progress($hash_key, $post_id, $user_lang, 0, 'processing', 0);

        if ($locked) delete_transient($lock_name);
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
                if ($term && !is_wp_error($term)) {
                    $source_text = $term->name;
                }
            }
        }
    } elseif (strpos($hash_key, 'title') !== false) {
        $source_text = $post->post_title;
    } elseif (strpos($hash_key, 'excerpt') !== false) {
        $source_text = $post->post_excerpt;
        if (empty(trim($source_text))) {
            $source_text = wp_trim_excerpt('', $post);
        }
    } else {
        $source_text = $post->post_content;
    }

    $service              = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $use_notranslate_span = ($service !== 'ollama');
    $chunk_size           = (int) get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500);

    // Split raw HTML into chunks FIRST so placeholder markup does not inflate chunk size calculations
    $raw_chunks   = freedomtranslate_split_html_into_chunks($source_text, $chunk_size);
    $total_chunks = count($raw_chunks);

    $current_status = freedomtranslate_get_status_db($hash_key);
    $done_chunks    = ($current_status && $current_status->status === 'processing') ? (int) $current_status->progress : 0;

    $excluded_words     = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []);
    $start_time         = time();
    $api_timeout        = (int) get_option('freedomtranslate_api_timeout', 120);
    $max_execution_time = $api_timeout + 10;

    while ($done_chunks < $total_chunks) {
        if ((int) get_option('freedomtranslate_last_panic_time', 0) > $worker_start_time || get_transient('freedomtranslate_pause_' . $hash_key)) {
            if (get_transient('freedomtranslate_pause_' . $hash_key)) {
                delete_transient('freedomtranslate_pause_' . $hash_key);
                freedomtranslate_update_progress($hash_key, $post_id, $user_lang, $done_chunks, 'paused', $total_chunks);
            }
            return;
        }

        $chunk_source = $raw_chunks[$done_chunks];

        // Protect each chunk independently so restore maps stay clean and scoped
        list($protected_chunk, $wp_placeholders) = freedomtranslate_protect_gutenberg_blocks($chunk_source, $use_notranslate_span);
        list($protected_chunk, $sc_placeholders) = freedomtranslate_protect_shortcodes($protected_chunk, $use_notranslate_span);

        $space_placeholders = [];
        if ($use_notranslate_span) {
            list($protected_chunk, $space_placeholders) = freedomtranslate_protect_formatting_spaces($protected_chunk, true);
        }

        $ex_placeholders = [];
        if (!empty($excluded_words)) {
            list($protected_chunk, $ex_placeholders) = freedomtranslate_protect_excluded_words_in_html($protected_chunk, $excluded_words);
        }

        $translated_chunk = false;
        $max_retries      = 3;
        $retry_count      = 0;

        while ($retry_count < $max_retries) {
            $temp = ($retry_count === 0) ? 0.1 : 0.25;
            $translated_chunk = freedomtranslate_do_api_translation($protected_chunk, $site_lang, $user_lang, 'html', $hash_key, $temp);

            if (!is_wp_error($translated_chunk)) {
                // Detect echo failure where an LLM returns long prose completely untranslated
                $plain_src = trim(wp_strip_all_tags(preg_replace('/\[\[FT[^\]]+\]\]|\[PH_[^\]]+\]/', '', $protected_chunk)));
                $plain_out = trim(wp_strip_all_tags(preg_replace('/\[\[FT[^\]]+\]\]|\[PH_[^\]]+\]/', '', $translated_chunk)));
                $plain_len = function_exists('mb_strlen') ? mb_strlen($plain_src, 'UTF-8') : strlen($plain_src);

                if ($site_lang !== $user_lang && $plain_len > 40 && $plain_src === $plain_out && $retry_count < ($max_retries - 1)) {
                    $retry_count++;
                    sleep(2);
                    continue;
                }
                break;
            }

            $retry_count++;
            if ($retry_count < $max_retries) {
                sleep(4);
            }
        }

        if (is_wp_error($translated_chunk)) {
            freedomtranslate_update_progress($hash_key, $post_id, $user_lang, $done_chunks, 'timeout', $total_chunks);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';

        $job_still_exists = $wpdb->get_var($wpdb->prepare("SELECT hash_key FROM $table WHERE hash_key = %s", $hash_key));
        if (!$job_still_exists) {
            return;
        }

        // Restore protections in reverse order for this chunk
        if (!empty($ex_placeholders)) {
            $translated_chunk = freedomtranslate_restore_excluded_words_in_html($translated_chunk, $ex_placeholders);
        }
        if (!empty($space_placeholders)) {
            $translated_chunk = freedomtranslate_restore_formatting_spaces($translated_chunk, $space_placeholders);
        }
        if (!empty($sc_placeholders)) {
            $translated_chunk = freedomtranslate_restore_shortcodes($translated_chunk, $sc_placeholders);
        }
        if (!empty($wp_placeholders)) {
            $translated_chunk = freedomtranslate_restore_gutenberg_blocks($translated_chunk, $wp_placeholders);
        }

        $chunk_hash = $hash_key . '_chunk_' . $done_chunks;
        freedomtranslate_set_cache($chunk_hash, $translated_chunk, $post_id, $user_lang, DAY_IN_SECONDS * 2, 'chunk_temp');

        $done_chunks++;
        freedomtranslate_update_progress($hash_key, $post_id, $user_lang, $done_chunks, 'processing', $total_chunks);

        if ($done_chunks < $total_chunks && (time() - $start_time) >= $max_execution_time) {
            $args = [$hash_key, $site_lang, $user_lang, (int) $post_id, uniqid('', true)];
            wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
            spawn_cron();
            return;
        }
    }

    $final_content = '';
    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_part = freedomtranslate_get_cache($hash_key . '_chunk_' . $i);
        $final_content .= ($chunk_part !== false) ? $chunk_part : '';
    }

    global $wpdb;
    $table       = $wpdb->prefix . 'freedomtranslate_cache';
    $ttl_days    = freedomtranslate_get_ttl_days($post_id);
    $ttl_seconds = $ttl_days > 0 ? (DAY_IN_SECONDS * $ttl_days) : 0;
    $expires_at  = $ttl_seconds > 0 ? gmdate('Y-m-d H:i:s', time() + $ttl_seconds) : null;

    $wpdb->replace($table, [
        'hash_key'     => $hash_key,
        'post_id'      => $post_id,
        'target_lang'  => $user_lang,
        'translation'  => $final_content,
        'status'       => 'completed',
        'progress'     => 100,
        'total_chunks' => $total_chunks,
        'expires_at'   => $expires_at
    ]);

    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($hash_key . '_chunk_') . '%'));

    $next_job = $wpdb->get_row("SELECT hash_key, target_lang, post_id FROM $table WHERE status = 'pending' LIMIT 1");
    if ($next_job) {
        $real_site_lang = freedomtranslate_get_site_source_lang();
        $args           = [$next_job->hash_key, $real_site_lang, $next_job->target_lang, (int) $next_job->post_id, uniqid('', true)];
        wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
        spawn_cron();
    }
}

/**
 * Split raw HTML into chunks keeping Gutenberg opening comments paired with their block elements.
 */
function freedomtranslate_split_html_into_chunks($html, $max_chars = 500) {
    $blocks = preg_split(
        '/(?=(?:<!--\s*wp:[^>]+-->\s*)?<(?:p|div|h[1-6]|li|blockquote|pre|table|ul|ol|figure|tr|section|article)[\s>])/i',
        $html,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $chunks  = [];
    $current = '';
    $len_fn  = function_exists('mb_strlen') ? function($s) { return mb_strlen($s, 'UTF-8'); } : 'strlen';

    foreach ($blocks as $block) {
        if ($current !== '' && ($len_fn($current) + $len_fn($block)) > $max_chars) {
            $chunks[] = $current;
            $current  = $block;
        } else {
            $current .= $block;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks ? $chunks : [$html];
}
add_action('freedomtranslate_async_translate', 'freedomtranslate_async_worker', 10, 5);

function freedomtranslate_ajax_check_ready() {
    $hash_key    = isset($_GET['cache_key']) ? sanitize_text_field(wp_unslash($_GET['cache_key'])) : '';
    $status_data = freedomtranslate_get_status_db($hash_key);

    if (!$status_data) {
        wp_send_json(['ready' => false, 'progress' => 0]);
        return;
    }

    if ($status_data->status === 'completed') {
        wp_send_json(['ready' => true, 'progress' => 100]);
    } else {
        wp_send_json(['ready' => false, 'progress' => (int) $status_data->progress]);
    }
}
add_action('wp_ajax_nopriv_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');
add_action('wp_ajax_freedomtranslate_check_ready', 'freedomtranslate_ajax_check_ready');

// ========================================================================
// CORE FILTERS FOR CONTENT TRANSLATION
// ========================================================================
add_filter('the_content',     'freedomtranslate_filter_post_content', 10, 1);
add_filter('the_title',       'freedomtranslate_filter_post_content', 10, 2);
add_filter('the_excerpt',     'freedomtranslate_filter_post_content', 10, 2);
add_filter('get_the_excerpt', 'freedomtranslate_filter_post_content', 10, 2);

function freedomtranslate_filter_post_content($content, $id = null) {
    if (is_admin()) return $content;

    $site_source_lang = freedomtranslate_get_site_source_lang();
    $user_lang        = freedomtranslate_get_user_lang();

    if ($user_lang === $site_source_lang || !freedomtranslate_is_language_enabled($user_lang)) {
        return $content;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) return $content;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return $content;
    if (!is_string($content) || trim($content) === '') return $content;

    global $post;
    $current_obj_id = 0;
    if (is_numeric($id) && $id > 0) {
        $current_obj_id = (int) $id;
    } elseif (is_object($id) && isset($id->ID)) {
        $current_obj_id = (int) $id->ID;
    } elseif (isset($post->ID)) {
        $current_obj_id = (int) $post->ID;
    } else {
        $current_obj_id = (int) get_the_ID();
    }

    if (!$current_obj_id) return $content;

    if (get_post_meta($current_obj_id, '_freedomtranslate_exclude', true) === '1') {
        return $content;
    }

    $service     = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $filter_name = current_filter();

    if ($filter_name === 'get_the_excerpt') {
        $filter_name = 'the_excerpt';
    }

    $active_hash = md5("post_{$current_obj_id}_{$filter_name}_{$user_lang}") . '_' . $filter_name;

    // Request-level memory cache to avoid duplicate DB queries in loops/templates
    static $request_cache = [];
    if (isset($request_cache[$active_hash])) {
        return $request_cache[$active_hash];
    }

    $libre_mode = get_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, 'async');

    if ($libre_mode === 'async' && in_array($service, ['libretranslate', 'ollama'], true)) {
        global $wpdb;
        $table       = $wpdb->prefix . 'freedomtranslate_cache';
        $current_ttl = (int) get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 0);

        if ($filter_name === 'the_excerpt') {
            // Priority 1: Exact primary key lookup, then fallback
            $db_explicit = $wpdb->get_row($wpdb->prepare(
                "SELECT translation, expires_at, hash_key FROM $table WHERE hash_key = %s AND status = 'completed' LIMIT 1",
                $active_hash
            ));

            if (!$db_explicit) {
                $db_explicit = $wpdb->get_row($wpdb->prepare(
                    "SELECT translation, expires_at, hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND hash_key LIKE %s AND status = 'completed' LIMIT 1",
                    $current_obj_id,
                    $user_lang,
                    '%_the_excerpt'
                ));
            }

            if ($db_explicit && trim((string) $db_explicit->translation) !== '') {
                if ($db_explicit->hash_key !== $active_hash) {
                    $wpdb->update($table, ['hash_key' => $active_hash], ['hash_key' => $db_explicit->hash_key]);
                }
                if ($current_ttl === 0 || empty($db_explicit->expires_at) || strtotime($db_explicit->expires_at . ' UTC') > time()) {
                    $res = do_shortcode($db_explicit->translation);
                    $request_cache[$active_hash] = $res;
                    return $res;
                }
            }

            // Priority 2: Fallback to translated body trim
            $content_hash = md5("post_{$current_obj_id}_the_content_{$user_lang}") . '_the_content';
            $db_body      = $wpdb->get_row($wpdb->prepare(
                "SELECT translation, expires_at FROM $table WHERE hash_key = %s AND status = 'completed' LIMIT 1",
                $content_hash
            ));

            if (!$db_body) {
                $db_body = $wpdb->get_row($wpdb->prepare(
                    "SELECT translation, expires_at FROM $table WHERE post_id = %d AND target_lang = %s AND hash_key LIKE %s AND status = 'completed' LIMIT 1",
                    $current_obj_id,
                    $user_lang,
                    '%_the_content'
                ));
            }

            if ($db_body && trim((string) $db_body->translation) !== '') {
                if ($current_ttl === 0 || empty($db_body->expires_at) || strtotime($db_body->expires_at . ' UTC') > time()) {
                    $res = wp_trim_words(wp_strip_all_tags(do_shortcode($db_body->translation)), 55);
                    $request_cache[$active_hash] = $res;
                    return $res;
                }
            }
        }

        // Standard cache retrieval: primary key first
        $db_row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, translation, expires_at, hash_key FROM $table WHERE hash_key = %s LIMIT 1",
            $active_hash
        ));

        if (!$db_row) {
            $db_row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, translation, expires_at, hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND hash_key LIKE %s LIMIT 1",
                $current_obj_id,
                $user_lang,
                '%' . $wpdb->esc_like($filter_name)
            ));
        }

        $cached_translation = false;
        $is_completed       = false;

        if ($db_row) {
            if ($db_row->hash_key !== $active_hash) {
                $wpdb->update($table, ['hash_key' => $active_hash], ['hash_key' => $db_row->hash_key]);
            }

            if ($db_row->status === 'completed') {
                $is_completed = true;
                if ($current_ttl === 0 || empty($db_row->expires_at) || strtotime($db_row->expires_at . ' UTC') > time()) {
                    $cached_translation = $db_row->translation;
                }
            }
        }

        // Always fall back to original content if translation is missing or empty
        if ($is_completed && $cached_translation !== false && trim((string) $cached_translation) !== '') {
            $output = do_shortcode($cached_translation);

            if ($filter_name === 'the_content' && get_option(FREEDOMTRANSLATE_AI_LABEL_ENABLE_OPTION, '0') === '1') {
                $label_text = get_option(FREEDOMTRANSLATE_AI_LABEL_TEXT_OPTION, 'This post is translated thanks to artificial intelligence');
                if (!empty(trim($label_text))) {
                    $label_top    = '<div class="freedomtranslate-ai-label ft-label-top" style="font-size: 0.9em; font-style: italic; color: #777; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">' . esc_html($label_text) . '</div>';
                    $label_bottom = '<div class="freedomtranslate-ai-label ft-label-bottom" style="font-size: 0.9em; font-style: italic; color: #777; margin-top: 20px; padding-top: 10px; border-top: 1px solid #eee;">' . esc_html($label_text) . '</div>';
                    $output       = $label_top . $output . $label_bottom;
                }
            }

            $request_cache[$active_hash] = $output;
            return $output;
        }

        if (freedomtranslate_is_bot()) {
            return $content;
        }

        if (!$db_row || ($is_completed && $cached_translation === false)) {
            if (get_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, '1') === '1') {
                return $content;
            }

            $lock_key = 'freedomtranslate_lock_' . md5($active_hash);
            if (false === get_transient($lock_key)) {
                set_transient($lock_key, true, 60);

                freedomtranslate_update_progress($active_hash, $current_obj_id, $user_lang, 0, 'pending');
                wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [
                    $active_hash, $site_source_lang, $user_lang, $current_obj_id, uniqid('', true)
                ]);
            }
        }

        return $content;
    }

    // Legacy synchronous fallback
    $translated = freedomtranslate_translate($content, $site_source_lang, $user_lang, 'html', $current_obj_id, $active_hash);

    if ($filter_name === 'the_content' && get_option(FREEDOMTRANSLATE_AI_LABEL_ENABLE_OPTION, '0') === '1') {
        $label_text = get_option(FREEDOMTRANSLATE_AI_LABEL_TEXT_OPTION, 'This post is translated thanks to artificial intelligence');
        if (!empty(trim($label_text))) {
            $label_top    = '<div class="freedomtranslate-ai-label ft-label-top" style="font-size: 0.9em; font-style: italic; color: #777; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee;">' . esc_html($label_text) . '</div>';
            $label_bottom = '<div class="freedomtranslate-ai-label ft-label-bottom" style="font-size: 0.9em; font-style: italic; color: #777; margin-top: 20px; padding-top: 10px; border-top: 1px solid #eee;">' . esc_html($label_text) . '</div>';
            $translated   = $label_top . $translated . $label_bottom;
        }
    }

    $request_cache[$active_hash] = $translated;
    return $translated;
}

/**
 * Auto-inject selector AFTER translation filter (priority 11) so cached translations do not overwrite it.
 */
function freedomtranslate_auto_inject_selector($content) {
    if (is_admin() || !is_string($content) || trim($content) === '') return $content;

    $post_id = get_the_ID();
    if (!$post_id) return $content;

    if (get_post_meta($post_id, '_freedomtranslate_inject_selector', true) === '1') {
        return do_shortcode('[freedomtranslate_selector post_id="' . $post_id . '"]') . $content;
    }

    $auto_inject = get_option(FREEDOMTRANSLATE_AUTO_INJECT_OPTION, []);
    if (empty($auto_inject) || !is_array($auto_inject)) return $content;

    if (in_array(get_post_type(), $auto_inject, true)) {
        return do_shortcode('[freedomtranslate_selector post_id="' . $post_id . '"]') . $content;
    }

    return $content;
}
add_filter('the_content', 'freedomtranslate_auto_inject_selector', 11);

// ========================================================================
// 6. ADMIN PANEL & LIST TABLES
// ========================================================================

function freedomtranslate_admin_menu() {
    add_options_page(
        __('FreedomTranslate Settings', 'freedomtranslate-wp'),
        __('FreedomTranslate', 'freedomtranslate-wp'),
        'manage_options',
        'freedomtranslate',
        'freedomtranslate_settings_page'
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
            'cb'      => '<input type="checkbox" />',
            'post_id' => __('Post Info', 'freedomtranslate-wp'),
            'langs'   => __('Cached Content Breakdown', 'freedomtranslate-wp'),
            'action'  => __('Action', 'freedomtranslate-wp')
        ];
    }

    public function get_sortable_columns() {
        return [
            'post_id' => ['post_id', true]
        ];
    }

    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="bulk_post_ids[]" value="%s" />',
            esc_attr($item['post_id'])
        );
    }

    public function get_bulk_actions() {
        return [
            'bulk_delete_cache' => __('Clear Cache', 'freedomtranslate-wp')
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'post_id':
                $edit_link  = get_edit_post_link($item['post_id']);
                $title_html = !empty($item['title']) ? esc_html($item['title']) : __('(No Title)', 'freedomtranslate-wp');
                $link_html  = $edit_link ? ' <a href="' . esc_url($edit_link) . '" target="_blank">(' . esc_html__('Edit', 'freedomtranslate-wp') . ')</a>' : '';
                return '<strong>ID: ' . esc_html($item['post_id']) . '</strong><br>' . $title_html . $link_html;
            case 'langs':
                $html = '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
                foreach ($item['langs'] as $lang => $types) {
                    $html .= '<div style="background:#f1f1f1; border:1px solid #ccc; border-radius:4px; padding:6px; min-width: 100px;">';
                    $html .= '<strong style="display:block; margin-bottom:5px; text-align:center; font-size:13px;">' . esc_html(strtoupper($lang)) . '</strong>';
                    $html .= '<div style="display:flex; gap:4px; flex-wrap:wrap; justify-content:center;">';
                    foreach ($types as $t) {
                        $badge_color = ($t === 'TITLE') ? '#0073aa' : (($t === 'EXCERPT') ? '#e67e22' : '#46b450');
                        $html       .= '<span style="background: ' . esc_attr($badge_color) . '; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight:bold;">' . esc_html($t) . '</span>';
                    }
                    $html .= '</div></div>';
                }
                $html .= '</div>';
                return $html;
            case 'action':
                $base_url   = 'options-general.php?page=freedomtranslate&tab=tools';
                $delete_url = wp_nonce_url(admin_url($base_url . '&ft_action=delete_cache&post_id=' . intval($item['post_id'])), 'ft_del_cache_' . intval($item['post_id']));
                return '<a href="' . esc_url($delete_url) . '" class="button button-small" style="color: #d63638; border-color: #d63638;" onclick="return confirm(\'' . esc_js(__('Are you sure you want to clear the cache for this post?', 'freedomtranslate-wp')) . '\');">' . esc_html__('Clear Cache', 'freedomtranslate-wp') . '</a>';
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

        $site_lang    = freedomtranslate_get_site_source_lang();
        $db_jobs      = $wpdb->get_results("SELECT hash_key, post_id, target_lang FROM $table WHERE status = 'completed'");
        $unified_data = [];

        foreach ($db_jobs as $job) {
            if (empty($job->post_id) || $job->target_lang === $site_lang) continue;

            $content_type = 'BODY';
            if (strpos($job->hash_key, '_the_title') !== false) $content_type = 'TITLE';
            elseif (strpos($job->hash_key, '_the_excerpt') !== false) $content_type = 'EXCERPT';

            if (!isset($unified_data[$job->post_id])) {
                $unified_data[$job->post_id] = [
                    'post_id' => (int) $job->post_id,
                    'title'   => get_the_title($job->post_id),
                    'langs'   => [$job->target_lang => [$content_type]]
                ];
            } else {
                if (!isset($unified_data[$job->post_id]['langs'][$job->target_lang])) {
                    $unified_data[$job->post_id]['langs'][$job->target_lang] = [$content_type];
                } elseif (!in_array($content_type, $unified_data[$job->post_id]['langs'][$job->target_lang], true)) {
                    $unified_data[$job->post_id]['langs'][$job->target_lang][] = $content_type;
                }
            }
        }

        $data = array_values($unified_data);

        $search_query = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_query)) {
            $data = array_filter($data, function($item) use ($search_query) {
                return (stripos((string) $item['post_id'], $search_query) !== false || stripos((string) $item['title'], $search_query) !== false);
            });
        }

        $allowed_orderby = ['post_id'];
        $orderby         = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'post_id';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'post_id';
        }
        $order = (isset($_REQUEST['order']) && strtolower(sanitize_text_field(wp_unslash($_REQUEST['order']))) === 'asc') ? 'asc' : 'desc';

        usort($data, function($a, $b) use ($orderby, $order) {
            $result = ($a[$orderby] == $b[$orderby]) ? 0 : (($a[$orderby] < $b[$orderby]) ? -1 : 1);
            return ($order === 'asc') ? $result : -$result;
        });

        $per_page     = 15;
        $current_page = $this->get_pagenum();
        $this->items  = array_slice($data, (($current_page - 1) * $per_page), $per_page);
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
            'cb'        => '<input type="checkbox" />',
            'post_id'   => __('Target Post', 'freedomtranslate-wp'),
            'lang'      => __('Language', 'freedomtranslate-wp'),
            'status'    => __('Status & Progress', 'freedomtranslate-wp'),
            'scheduled' => __('Scheduled Execution', 'freedomtranslate-wp'),
            'action'    => __('Action', 'freedomtranslate-wp')
        ];
    }

    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="bulk_hash_keys[]" value="%s" />',
            esc_attr($item['hash_key'])
        );
    }

    public function get_sortable_columns() {
        return [
            'post_id'   => ['post_id', false],
            'lang'      => ['lang', false],
            'status'    => ['status', false],
            'scheduled' => ['scheduled', false]
        ];
    }

    public function get_bulk_actions() {
        return [
            'bulk_resume' => __('Start / Resume Jobs', 'freedomtranslate-wp'),
            'bulk_pause'  => __('Pause Jobs', 'freedomtranslate-wp'),
            'bulk_cancel' => __('Cancel Jobs', 'freedomtranslate-wp')
        ];
    }

    public function column_default($item, $column_name) {
        $group_key = isset($item['group_key']) ? $item['group_key'] : 'group_' . $item['post_id'] . '_' . $item['lang'];
        $safe_id   = md5($group_key);
        $is_string = strpos($group_key, 'string_job_') === 0;

        switch ($column_name) {
            case 'post_id':
                if ($item['post_id'] === 'Unknown' || $is_string) {
                    return '<strong>' . esc_html($item['post_id']) . '</strong>';
                }
                $edit_link = get_edit_post_link($item['post_id']);
                $link_html = $edit_link ? ' <a href="' . esc_url($edit_link) . '" target="_blank">(' . esc_html__('Edit', 'freedomtranslate-wp') . ')</a>' : '';
                return '<strong>' . esc_html($item['post_id']) . '</strong>' . $link_html;
            case 'lang':
                return '<strong>' . esc_html(strtoupper($item['lang'])) . '</strong>';
            case 'scheduled':
                if ($item['status'] === 'processing') return '<strong style="color:#27ae60;">' . esc_html__('Currently Executing', 'freedomtranslate-wp') . ' &#9881;&#65039;</strong>';
                if ($item['scheduled'] === 0) return '<span style="color:#d63638; font-weight:bold;">' . esc_html__('Missing Cron', 'freedomtranslate-wp') . '</span>';
                $time_diff = $item['scheduled'] - time();
                if ($time_diff <= 0) return '<strong style="color:#2271b1;">' . esc_html__('Queued', 'freedomtranslate-wp') . '</strong>';
                return esc_html(sprintf(__('In %s', 'freedomtranslate-wp'), human_time_diff(time(), $item['scheduled'])));
            case 'status':
                $bg_color     = ($item['status'] === 'pending') ? '#f0b849' : (($item['status'] === 'processing') ? '#2271b1' : '#72777c');
                $status_label = strtoupper($item['status']);
                if ($item['status'] === 'cron_only') $status_label = 'ZOMBIE CRON';

                $html = '<div style="margin-bottom: 5px;" id="ft_status_wrap_' . esc_attr($safe_id) . '"><span id="ft_status_badge_' . esc_attr($safe_id) . '" style="background: ' . esc_attr($bg_color) . '; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;">' . esc_html($status_label) . '</span></div>';

                if (in_array($item['status'], ['pending', 'processing', 'timeout', 'paused'], true)) {
                    $percent      = 0;
                    $visual_width = 5;
                    $label        = 'Chunk: ' . intval($item['progress']) . ' / ? (Waiting...)';

                    if (isset($item['total_chunks']) && $item['total_chunks'] > 0) {
                        $percent      = round(($item['progress'] / $item['total_chunks']) * 100);
                        $visual_width = max(5, $percent);
                        $label        = 'Chunk: ' . intval($item['progress']) . ' / ' . intval($item['total_chunks']) . ' (' . $percent . '%)';
                    }

                    $text_color = $visual_width > 50 ? '#fff' : '#000';
                    $html .= '<div style="background: #e1e1e1; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; position: relative;">
                                <div id="ft_bar_fill_' . esc_attr($safe_id) . '" style="background: #27ae60; width: ' . esc_attr($visual_width) . '%; height: 100%; transition: width 0.5s;"></div>
                                <span id="ft_bar_text_' . esc_attr($safe_id) . '" style="position: absolute; top: 0; left: 50%; transform: translateX(-50%); font-size: 12px; font-weight: bold; color: ' . esc_attr($text_color) . '; line-height: 20px; white-space: nowrap;">
                                    ' . esc_html($label) . '
                                </span>
                              </div>';

                    $content_type = 'BODY';
                    if (strpos($item['hash_key'], '_the_title') !== false) $content_type = 'TITLE';
                    elseif (strpos($item['hash_key'], '_the_excerpt') !== false) $content_type = 'EXCERPT';
                    $badge_color = ($content_type === 'TITLE') ? '#0073aa' : (($content_type === 'EXCERPT') ? '#e67e22' : '#46b450');

                    $html .= '<div id="ft_type_label_' . esc_attr($safe_id) . '" style="text-align: center; margin-top: 6px;">';
                    $html .= '<span style="background: ' . esc_attr($badge_color) . '; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-style: normal; font-weight: bold; display: inline-block;">' . esc_html($content_type) . '</span>';
                    $html .= '</div>';
                }
                return $html;
            case 'action':
                if ($is_string) return '<span style="color:#888;">(' . esc_html__('Fast String Job', 'freedomtranslate-wp') . ')</span>';

                $nonce_val = wp_create_nonce('freedomtranslate_queue_action');
                $html      = '<div style="display: flex; gap: 8px; align-items: center;">';

                if (in_array($item['status'], ['pending', 'timeout', 'paused'], true)) {
                    $btn_text = in_array($item['status'], ['timeout', 'paused'], true) ? __('Resume Job', 'freedomtranslate-wp') : __('Start Now', 'freedomtranslate-wp');
                    $html    .= '<button class="button button-small button-primary ft-ajax-start" data-hash="' . esc_attr($item['hash_key']) . '" data-nonce="' . esc_attr($nonce_val) . '">' . esc_html($btn_text) . '</button>';
                }

                if (in_array($item['status'], ['pending', 'processing'], true)) {
                    $html .= '<button class="button button-small ft-ajax-pause" style="margin-left: 4px;" data-hash="' . esc_attr($item['hash_key']) . '" data-nonce="' . esc_attr($nonce_val) . '">' . esc_html__('Pause', 'freedomtranslate-wp') . '</button>';
                }

                $html .= '<button class="button button-small ft-ajax-cancel" style="color: #d63638; border-color: #d63638; margin-left: 4px;" data-hash="' . esc_attr($item['hash_key']) . '" data-nonce="' . esc_attr($nonce_val) . '">' . esc_html__('Cancel', 'freedomtranslate-wp') . '</button>';
                $html .= '</div>';

                return $html;
        }
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';

        $db_jobs      = $wpdb->get_results("SELECT * FROM $table WHERE status IN ('pending', 'processing', 'timeout', 'paused')");
        $unified_data = [];

        foreach ($db_jobs as $job) {
            $group_key = $job->hash_key;

            if (!isset($unified_data[$group_key])) {
                $unified_data[$group_key] = [
                    'group_key'    => $group_key,
                    'hash_key'     => $job->hash_key,
                    'post_id'      => $job->post_id,
                    'lang'         => $job->target_lang,
                    'status'       => $job->status,
                    'progress'     => (int) $job->progress,
                    'total_chunks' => (int) $job->total_chunks,
                    'scheduled'    => 0
                ];
            } else {
                $unified_data[$group_key]['progress']     += (int) $job->progress;
                $unified_data[$group_key]['total_chunks'] += (int) $job->total_chunks;
                if ($job->status === 'processing') {
                    $unified_data[$group_key]['status'] = 'processing';
                }
            }
        }

        $crons = _get_cron_array();
        if (!empty($crons) && is_array($crons)) {
            foreach ($crons as $timestamp => $cron_hooks) {
                if (isset($cron_hooks['freedomtranslate_async_translate']) && is_array($cron_hooks['freedomtranslate_async_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                        $hash_key  = isset($event['args'][0]) ? $event['args'][0] : 'Unknown';
                        $post_id   = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                        $lang      = isset($event['args'][2]) ? $event['args'][2] : 'Unknown';
                        $group_key = $hash_key;

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

                if (isset($cron_hooks['freedomtranslate_async_string_translate']) && is_array($cron_hooks['freedomtranslate_async_string_translate'])) {
                    foreach ($cron_hooks['freedomtranslate_async_string_translate'] as $sig => $event) {
                        $string_id   = isset($event['args'][0]) ? $event['args'][0] : 'Unknown';
                        $target_lang = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                        $hash_key    = 'string_job_' . $string_id . '_' . $target_lang . '_' . $sig;

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
                return (stripos((string) $item['post_id'], $search_query) !== false || stripos((string) $item['lang'], $search_query) !== false);
            });
        }

        $allowed_orderby = ['post_id', 'lang', 'status', 'scheduled'];
        $orderby         = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'scheduled';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'scheduled';
        }
        $order = (isset($_REQUEST['order']) && strtolower(sanitize_text_field(wp_unslash($_REQUEST['order']))) === 'desc') ? 'desc' : 'asc';

        usort($data, function($a, $b) use ($orderby, $order) {
            $result = ($a[$orderby] == $b[$orderby]) ? 0 : (($a[$orderby] < $b[$orderby]) ? -1 : 1);
            return ($order === 'asc') ? $result : -$result;
        });

        $per_page     = 15;
        $current_page = $this->get_pagenum();
        $this->items  = array_slice($data, (($current_page - 1) * $per_page), $per_page);
        $this->set_pagination_args(['total_items' => count($data), 'per_page' => $per_page, 'total_pages' => ceil(count($data) / $per_page)]);
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
            'cb'               => '<input type="checkbox" />',
            'id'               => __('String ID', 'freedomtranslate-wp'),
            'original'         => __('Original Text', 'freedomtranslate-wp'),
            'translated_langs' => __('Translations', 'freedomtranslate-wp'),
            'action'           => __('Action', 'freedomtranslate-wp')
        ];
    }

    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="bulk_string_ids[]" value="%s" />',
            esc_attr($item['id'])
        );
    }

    public function get_sortable_columns() {
        return [
            'id'       => ['id', false],
            'original' => ['original', false]
        ];
    }

    public function get_bulk_actions() {
        return [
            'bulk_retranslate_strings' => __('Retranslate Selected', 'freedomtranslate-wp'),
            'bulk_delete_strings'      => __('Delete Selected', 'freedomtranslate-wp')
        ];
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'original':
                return esc_html($item['original']);

            case 'translated_langs':
                if (empty($item['translated_langs'])) {
                    return '<span style="color:#d63638; font-weight:bold;">' . esc_html__('No translations yet', 'freedomtranslate-wp') . '</span>';
                }

                $badges = '';
                foreach ($item['translated_langs'] as $l) {
                    $badges .= '<span style="background:#46b450; color:#fff; padding:2px 6px; border-radius:4px; font-size:11px; margin-right:4px;">' . esc_html(strtoupper($l)) . '</span>';
                }
                return $badges . '<br><small style="color:#666; margin-top:4px; display:inline-block;">' . intval($item['done_count']) . ' / ' . intval($item['expected_count']) . ' ' . esc_html__('languages completed', 'freedomtranslate-wp') . '</small>';

            case 'action':
                $base_url        = 'options-general.php?page=freedomtranslate&tab=static_strings';
                $retranslate_url = wp_nonce_url(admin_url($base_url . '&ft_action=retranslate_string&string_id=' . rawurlencode($item['id'])), 'ft_ret_string_' . $item['id']);
                $delete_url      = wp_nonce_url(admin_url($base_url . '&ft_action=delete_string&string_id=' . rawurlencode($item['id'])), 'ft_del_string_' . $item['id']);

                return '<a href="' . esc_url($retranslate_url) . '" class="button button-small" style="margin-right:5px;">' . esc_html__('Retranslate', 'freedomtranslate-wp') . '</a>' .
                       '<a href="' . esc_url($delete_url) . '" class="button button-small" style="color: #d63638; border-color: #d63638;" onclick="return confirm(\'' . esc_js(__('Delete this string permanently?', 'freedomtranslate-wp')) . '\');">' . esc_html__('Delete', 'freedomtranslate-wp') . '</a>';

            default:
                return '';
        }
    }

    public function column_id($item) {
        $shortcode = '[ft_string id="' . esc_attr($item['id']) . '"]';

        $html  = '<strong>' . esc_html($item['id']) . '</strong><br>';
        $html .= '<input type="text" readonly value="' . esc_attr($shortcode) . '" ';
        $html .= 'onclick="this.select();" ';
        $html .= 'style="width: 100%; max-width: 220px; font-family: monospace; font-size: 11px; padding: 2px 6px; margin-top: 4px; background: #f6f7f7; color: #2c3338; border: 1px solid #c3c4c7; border-radius: 3px; cursor: pointer;" ';
        $html .= 'title="' . esc_attr__('Click to select all for easy copy', 'freedomtranslate-wp') . '" />';

        return $html;
    }

    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $strings           = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
        $site_lang         = freedomtranslate_get_site_source_lang();
        $expected_count    = is_array($enabled_languages) ? (count($enabled_languages) - (in_array($site_lang, $enabled_languages, true) ? 1 : 0)) : 0;

        $data = [];
        if (is_array($strings)) {
            foreach ($strings as $id => $sdata) {
                $done_count = isset($sdata['translations']) && is_array($sdata['translations']) ? count($sdata['translations']) : 0;
                $data[]     = [
                    'id'               => $id,
                    'original'         => isset($sdata['original']) ? $sdata['original'] : '',
                    'done_count'       => $done_count,
                    'expected_count'   => max(0, $expected_count),
                    'translated_langs' => isset($sdata['translations']) && is_array($sdata['translations']) ? array_keys($sdata['translations']) : []
                ];
            }
        }

        $search_query = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        if (!empty($search_query)) {
            $data = array_filter($data, function($item) use ($search_query) {
                return (stripos((string) $item['id'], $search_query) !== false || stripos((string) $item['original'], $search_query) !== false);
            });
        }

        $allowed_orderby = ['id', 'original'];
        $orderby         = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'id';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'id';
        }
        $order = (isset($_REQUEST['order']) && strtolower(sanitize_text_field(wp_unslash($_REQUEST['order']))) === 'desc') ? 'desc' : 'asc';

        usort($data, function($a, $b) use ($orderby, $order) {
            $result = strcasecmp((string) $a[$orderby], (string) $b[$orderby]);
            return ($order === 'asc') ? $result : -$result;
        });

        $per_page     = 15;
        $current_page = $this->get_pagenum();
        $total_items  = count($data);

        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
}

/**
 * Handle static string save, GET row actions, and WP_List_Table bulk actions cleanly on admin_init
 * with proper capability checks, nonces, and wp_safe_redirect().
 */
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // 1. Save static string
    if (isset($_POST['freedomtranslate_save_static_string'])) {
        check_admin_referer('freedomtranslate_save_static_string', 'freedomtranslate_nonce_static');

        $id   = isset($_POST['new_string_id']) ? sanitize_key(wp_unslash($_POST['new_string_id'])) : '';
        $text = isset($_POST['new_string_text']) ? sanitize_textarea_field(wp_unslash($_POST['new_string_text'])) : '';

        if (!empty($id) && !empty($text)) {
            $lock_name = 'freedomtranslate_lock_static_strings';
            $locked    = false;
            $attempts  = 0;
            while ($attempts < 40) {
                if (false === get_transient($lock_name)) {
                    set_transient($lock_name, '1', 10);
                    $locked = true;
                    break;
                }
                usleep(50000);
                $attempts++;
            }

            wp_cache_delete(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, 'options');
            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            if (!is_array($strings)) {
                $strings = [];
            }

            $strings[$id] = ['original' => $text, 'translations' => []];
            update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            if ($locked) delete_transient($lock_name);

            $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
            $site_lang     = freedomtranslate_get_site_source_lang();

            $delay = 0;
            if (is_array($enabled_langs)) {
                foreach ($enabled_langs as $lang) {
                    if ($lang === $site_lang) continue;
                    wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_string_translate', [$id, $text, $site_lang, $lang, uniqid('', true)]);
                    $delay += 15;
                }
            }

            wp_safe_redirect(admin_url('options-general.php?page=freedomtranslate&tab=static_strings&ft_msg=string_saved'));
            exit;
        }
    }

    if (!isset($_GET['page']) || $_GET['page'] !== 'freedomtranslate') {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    // 2. Single-row GET actions
    if (isset($_GET['ft_action'])) {
        $action    = sanitize_text_field(wp_unslash($_GET['ft_action']));
        $clean_url = remove_query_arg(['ft_action', 'job_hash', 'post_id', 'string_id', '_wpnonce']);
        $site_lang = freedomtranslate_get_site_source_lang();

        if ($action === 'start_job' && isset($_GET['job_hash'])) {
            $hash_key = sanitize_text_field(wp_unslash($_GET['job_hash']));
            check_admin_referer('ft_start_' . $hash_key);

            $job = $wpdb->get_row($wpdb->prepare("SELECT post_id, target_lang FROM $table WHERE hash_key = %s", $hash_key));
            if ($job) {
                $wpdb->update($table, ['status' => 'pending'], ['hash_key' => $hash_key]);
                $args = [$hash_key, $site_lang, $job->target_lang, (int) $job->post_id, uniqid('', true)];
                freedomtranslate_remove_crons_by_hash($hash_key);
                wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'started', $clean_url)));
            exit;
        } elseif ($action === 'cancel_job' && isset($_GET['job_hash'])) {
            $hash_key = sanitize_text_field(wp_unslash($_GET['job_hash']));
            check_admin_referer('ft_cancel_' . $hash_key);

            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($hash_key . '_chunk_') . '%'));
            $wpdb->delete($table, ['hash_key' => $hash_key]);
            freedomtranslate_remove_crons_by_hash($hash_key);

            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'cancelled', $clean_url)));
            exit;
        } elseif ($action === 'start_group' && isset($_GET['post_id']) && isset($_GET['lang'])) {
            $p_id = intval($_GET['post_id']);
            $lang = sanitize_text_field(wp_unslash($_GET['lang']));
            check_admin_referer('ft_group_' . $p_id . '_' . $lang);

            $job = $wpdb->get_row($wpdb->prepare("SELECT hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND status IN ('pending', 'timeout', 'paused') LIMIT 1", $p_id, $lang));
            if ($job) {
                $wpdb->update($table, ['status' => 'pending'], ['hash_key' => $job->hash_key]);
                $args = [$job->hash_key, $site_lang, $lang, $p_id, uniqid('', true)];
                wp_schedule_single_event(time(), 'freedomtranslate_async_translate', $args);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'started', $clean_url)));
            exit;
        } elseif ($action === 'cancel_group' && isset($_GET['post_id']) && isset($_GET['lang'])) {
            $p_id = intval($_GET['post_id']);
            $lang = sanitize_text_field(wp_unslash($_GET['lang']));
            check_admin_referer('ft_group_' . $p_id . '_' . $lang);

            $hashes = $wpdb->get_col($wpdb->prepare("SELECT hash_key FROM $table WHERE post_id = %d AND target_lang = %s AND status IN ('pending', 'processing')", $p_id, $lang));
            if (!empty($hashes)) {
                freedomtranslate_cancel_remote_job($hashes);
                foreach ($hashes as $h) {
                    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($h . '_chunk_') . '%'));
                    $wpdb->delete($table, ['hash_key' => $h]);
                }
                freedomtranslate_remove_crons_by_hash($hashes);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'cancelled', $clean_url)));
            exit;
        } elseif ($action === 'delete_cache' && isset($_GET['post_id'])) {
            $post_id = intval($_GET['post_id']);
            check_admin_referer('ft_del_cache_' . $post_id);
            if ($post_id > 0) {
                $wpdb->delete($table, ['post_id' => $post_id]);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'deleted_cache', $clean_url)));
            exit;
        } elseif ($action === 'retranslate_string' && isset($_GET['string_id'])) {
            $id = sanitize_key(wp_unslash($_GET['string_id']));
            check_admin_referer('ft_ret_string_' . $id);

            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            if (isset($strings[$id])) {
                $text          = $strings[$id]['original'];
                $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
                $delay         = 0;

                if (is_array($enabled_langs)) {
                    foreach ($enabled_langs as $lang) {
                        if ($lang === $site_lang) continue;
                        wp_schedule_single_event(
                            time() + $delay,
                            'freedomtranslate_async_string_translate',
                            [$id, $text, $site_lang, $lang, uniqid('', true)]
                        );
                        $delay += 15;
                    }
                }
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'retranslated', $clean_url)));
            exit;
        } elseif ($action === 'delete_string' && isset($_GET['string_id'])) {
            $id = sanitize_key(wp_unslash($_GET['string_id']));
            check_admin_referer('ft_del_string_' . $id);

            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            if (isset($strings[$id])) {
                unset($strings[$id]);
                update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'deleted_string', $clean_url)));
            exit;
        }
    }

    // 3. Bulk Actions with strict WP_List_Table nonce verification
    $bulk_action = '';
    if (isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1') {
        $bulk_action = sanitize_text_field(wp_unslash($_REQUEST['action']));
    } elseif (isset($_REQUEST['action2']) && $_REQUEST['action2'] !== '-1') {
        $bulk_action = sanitize_text_field(wp_unslash($_REQUEST['action2']));
    }

    if (!empty($bulk_action)) {
        $clean_url = remove_query_arg(['action', 'action2', '_wpnonce', '_wp_http_referer', 'bulk_post_ids', 'bulk_hash_keys', 'bulk_string_ids']);

        if ($bulk_action === 'bulk_delete_cache' && !empty($_REQUEST['bulk_post_ids'])) {
            check_admin_referer('bulk-translated_posts');
            $post_ids = array_map('intval', (array) $_REQUEST['bulk_post_ids']);
            foreach ($post_ids as $p_id) {
                if ($p_id > 0) $wpdb->delete($table, ['post_id' => $p_id]);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_cache_cleared', $clean_url)));
            exit;
        } elseif ($bulk_action === 'bulk_cancel' && !empty($_REQUEST['bulk_hash_keys'])) {
            check_admin_referer('bulk-queue_jobs');
            $hashes = array_map('sanitize_text_field', wp_unslash((array) $_REQUEST['bulk_hash_keys']));
            freedomtranslate_cancel_remote_job($hashes);
            foreach ($hashes as $h) {
                $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($h . '_chunk_') . '%'));
                $wpdb->delete($table, ['hash_key' => $h]);
            }
            freedomtranslate_remove_crons_by_hash($hashes);
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_cancelled', $clean_url)));
            exit;
        } elseif ($bulk_action === 'bulk_pause' && !empty($_REQUEST['bulk_hash_keys'])) {
            check_admin_referer('bulk-queue_jobs');
            $hashes = array_map('sanitize_text_field', wp_unslash((array) $_REQUEST['bulk_hash_keys']));
            foreach ($hashes as $h) {
                set_transient('freedomtranslate_pause_' . $h, '1', 600);
                $wpdb->update($table, ['status' => 'paused'], ['hash_key' => $h]);
            }
            freedomtranslate_remove_crons_by_hash($hashes);
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_paused', $clean_url)));
            exit;
        } elseif ($bulk_action === 'bulk_resume' && !empty($_REQUEST['bulk_hash_keys'])) {
            check_admin_referer('bulk-queue_jobs');
            $hashes    = array_map('sanitize_text_field', wp_unslash((array) $_REQUEST['bulk_hash_keys']));
            $site_lang = freedomtranslate_get_site_source_lang();

            foreach ($hashes as $h) {
                delete_transient('freedomtranslate_pause_' . $h);
                $job = $wpdb->get_row($wpdb->prepare("SELECT post_id, target_lang FROM $table WHERE hash_key = %s LIMIT 1", $h));
                if ($job) {
                    $wpdb->update($table, ['status' => 'pending'], ['hash_key' => $h]);
                    wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [$h, $site_lang, $job->target_lang, (int) $job->post_id, uniqid('', true)]);
                }
            }
            spawn_cron();
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_resumed', $clean_url)));
            exit;
        } elseif ($bulk_action === 'bulk_delete_strings' && !empty($_REQUEST['bulk_string_ids'])) {
            check_admin_referer('bulk-static_strings');
            $ids     = array_map('sanitize_key', wp_unslash((array) $_REQUEST['bulk_string_ids']));
            $strings = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            $changed = false;

            foreach ($ids as $id) {
                if (isset($strings[$id])) {
                    unset($strings[$id]);
                    $changed = true;
                }
            }
            if ($changed) {
                update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_strings_deleted', $clean_url)));
            exit;
        } elseif ($bulk_action === 'bulk_retranslate_strings' && !empty($_REQUEST['bulk_string_ids'])) {
            check_admin_referer('bulk-static_strings');
            $ids           = array_map('sanitize_key', wp_unslash((array) $_REQUEST['bulk_string_ids']));
            $strings       = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
            $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
            $site_lang     = freedomtranslate_get_site_source_lang();
            $delay         = 0;

            foreach ($ids as $id) {
                if (isset($strings[$id]) && is_array($enabled_langs)) {
                    $text = $strings[$id]['original'];
                    foreach ($enabled_langs as $lang) {
                        if ($lang === $site_lang) continue;
                        wp_schedule_single_event(
                            time() + $delay,
                            'freedomtranslate_async_string_translate',
                            [$id, $text, $site_lang, $lang, uniqid('', true)]
                        );
                        $delay += 15;
                    }
                }
            }
            wp_safe_redirect(esc_url_raw(add_query_arg('ft_msg', 'bulk_strings_retranslated', $clean_url)));
            exit;
        }
    }
});

/**
 * Helper to extract the correct Post ID from a translated permalink structure.
 */
function freedomtranslate_get_clean_post_id($input) {
    if (is_numeric($input)) {
        return intval($input);
    }

    $parsed = wp_parse_url($input);
    if (isset($parsed['path'])) {
        $path      = $parsed['path'];
        $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
        $site_path = $site_path ? rtrim($site_path, '/') : '';

        $relative = $site_path ? substr($path, strlen($site_path)) : $path;

        if (preg_match('#^/([a-z]{2})(?:/|$)#i', $relative, $matches)) {
            $lang_code      = $matches[1];
            $clean_relative = preg_replace('#^/' . preg_quote($lang_code, '#') . '(/|$)#i', '/', $relative);
            $new_path       = $site_path . $clean_relative;
            $input          = str_replace($path, $new_path, $input);
        }
    }

    $post_id = url_to_postid($input);
    if ($post_id === 0 && function_exists('attachment_url_to_postid')) {
        $post_id = attachment_url_to_postid($input);
    }

    return $post_id;
}

function freedomtranslate_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'freedomtranslate-wp'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    $active_jobs_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status IN ('pending', 'processing', 'timeout')");

    // --- FORM HANDLERS ---
    if (isset($_POST['freedomtranslate_direct_push'])) {
        check_admin_referer('freedomtranslate_direct_push', 'freedomtranslate_nonce_direct');

        $input          = isset($_POST['direct_post_input']) ? sanitize_text_field(wp_unslash($_POST['direct_post_input'])) : '';
        $post_id        = freedomtranslate_get_clean_post_id($input);
        $selected_langs = isset($_POST['direct_langs']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['direct_langs'])) : [];
        update_option('freedomtranslate_direct_last_langs', $selected_langs);

        if ($post_id > 0 && !empty($selected_langs)) {
            $post = get_post($post_id);
            if ($post) {
                $site_lang    = freedomtranslate_get_site_source_lang();
                $queued_count = 0;
                $delay        = 5;

                foreach ($selected_langs as $lang) {
                    if ($lang === $site_lang) continue;

                    $t_hash = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
                    $wpdb->delete($table, ['hash_key' => $t_hash]);
                    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($t_hash . '_chunk_') . '%'));

                    if (!empty($post->post_title)) {
                        freedomtranslate_update_progress($t_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$t_hash, $site_lang, $lang, $post_id, uniqid('', true)]);
                        $delay += 5;
                        $queued_count++;
                    }

                    $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
                    $wpdb->delete($table, ['hash_key' => $c_hash]);
                    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($c_hash . '_chunk_') . '%'));

                    if (!empty($post->post_content)) {
                        freedomtranslate_update_progress($c_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$c_hash, $site_lang, $lang, $post_id, uniqid('', true)]);
                        $delay += 45;
                        $queued_count++;
                    }

                    $e_hash = md5("post_{$post_id}_the_excerpt_{$lang}") . '_the_excerpt';
                    $wpdb->delete($table, ['hash_key' => $e_hash]);
                    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($e_hash . '_chunk_') . '%'));

                    if (!empty($post->post_excerpt)) {
                        freedomtranslate_update_progress($e_hash, $post_id, $lang, 0, 'pending');
                        wp_schedule_single_event(time() + $delay, 'freedomtranslate_async_translate', [$e_hash, $site_lang, $lang, $post_id, uniqid('', true)]);
                        $delay += 15;
                        $queued_count++;
                    }
                }

                echo '<div class="notice notice-success"><p>' . sprintf(
                    esc_html__('Success! Queued %d translation tasks for Post ID %d. Check the Queue Monitor!', 'freedomtranslate-wp'),
                    intval($queued_count),
                    intval($post_id)
                ) . '</p></div>';

                delete_option('freedomtranslate_last_panic_time');
                spawn_cron();
                wp_remote_get(site_url('wp-cron.php?doing_wp_cron=' . sprintf('%.22F', microtime(true))), [
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                ]);
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Post not found in the database.', 'freedomtranslate-wp') . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Please enter a valid URL/ID and select at least one language.', 'freedomtranslate-wp') . '</p></div>';
        }
    }

    // --- STATUS MESSAGES ---
    if (isset($_GET['ft_msg'])) {
        $msg      = sanitize_text_field(wp_unslash($_GET['ft_msg']));
        $messages = [
            'started'                   => __('Job forced to restart!', 'freedomtranslate-wp'),
            'cancelled'                 => __('Job killed and removed.', 'freedomtranslate-wp'),
            'deleted_cache'             => __('Cache cleared successfully.', 'freedomtranslate-wp'),
            'retranslated'              => __('String queued for retranslation!', 'freedomtranslate-wp'),
            'deleted_string'            => __('String deleted.', 'freedomtranslate-wp'),
            'string_saved'              => __('String saved and queued for translation!', 'freedomtranslate-wp'),
            'bulk_cache_cleared'        => __('Bulk cache cleared successfully.', 'freedomtranslate-wp'),
            'bulk_cancelled'            => __('Bulk jobs cancelled and removed.', 'freedomtranslate-wp'),
            'bulk_paused'               => __('Bulk jobs paused.', 'freedomtranslate-wp'),
            'bulk_resumed'              => __('Bulk jobs resumed and queued.', 'freedomtranslate-wp'),
            'bulk_strings_deleted'      => __('Bulk strings deleted successfully.', 'freedomtranslate-wp'),
            'bulk_strings_retranslated' => __('Bulk strings queued for retranslation.', 'freedomtranslate-wp'),
        ];
        if (isset($messages[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_restore_bots'])) {
        check_admin_referer('freedomtranslate_save_general', 'freedomtranslate_nonce_general');
        $default_bots = array_filter(array_map('trim', explode("\n", freedomtranslate_get_default_bots_string())));
        update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $default_bots);
        echo '<div class="notice notice-success"><p>' . esc_html__('Default bot signatures restored successfully.', 'freedomtranslate-wp') . '</p></div>';
    } elseif (isset($_POST['freedomtranslate_save_general'])) {
        check_admin_referer('freedomtranslate_save_general', 'freedomtranslate_nonce_general');

        $new_service = isset($_POST['translation_service']) ? sanitize_text_field(wp_unslash($_POST['translation_service'])) : 'libretranslate';
        $old_service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');

        if ($active_jobs_count > 0 && $new_service !== $old_service) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Error:', 'freedomtranslate-wp') . '</strong> ' . esc_html__('You cannot change the translation service while there are active jobs in the queue. Please clear or complete the queue first.', 'freedomtranslate-wp') . '</p></div>';
            $new_service = $old_service;
        }

        update_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, $new_service);

        if (isset($_POST['lang_detection_mode'])) {
            $mode = sanitize_text_field(wp_unslash($_POST['lang_detection_mode']));
            update_option(FREEDOMTRANSLATE_LANG_DETECTION_MODE_OPTION, $mode);
            if ($mode === 'manual' && isset($_POST['default_language'])) {
                update_option(FREEDOMTRANSLATE_DEFAULT_LANG_OPTION, sanitize_text_field(wp_unslash($_POST['default_language'])));
            }
        }

        if (isset($_POST['freedomtranslate_api_url'])) {
            $url = esc_url_raw(trim(wp_unslash($_POST['freedomtranslate_api_url'])));
            if (!empty($url)) update_option(FREEDOMTRANSLATE_API_URL_OPTION, $url);
        }
        if (isset($_POST['freedomtranslate_api_key'])) {
            update_option(FREEDOMTRANSLATE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_api_key'])));
        }

        if (isset($_POST['freedomtranslate_google_api_key'])) {
            update_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_google_api_key'])));
        }

        if (isset($_POST['freedomtranslate_ollama_model'])) {
            update_option('freedomtranslate_ollama_model', sanitize_text_field(wp_unslash($_POST['freedomtranslate_ollama_model'])));
        }
        if (isset($_POST['freedomtranslate_keep_alive'])) {
            update_option('freedomtranslate_keep_alive', sanitize_text_field(wp_unslash($_POST['freedomtranslate_keep_alive'])));
        }

        $disable_think = isset($_POST['freedomtranslate_ollama_disable_think']) ? '1' : '0';
        update_option('freedomtranslate_ollama_disable_think', $disable_think);

        if (isset($_POST['freedomtranslate_libre_mode'])) {
            $allowed_modes = ['sync', 'async'];
            $libre_mode    = sanitize_text_field(wp_unslash($_POST['freedomtranslate_libre_mode']));
            update_option(FREEDOMTRANSLATE_LIBRE_MODE_OPTION, in_array($libre_mode, $allowed_modes, true) ? $libre_mode : 'async');
        }

        $prewarm = isset($_POST['freedomtranslate_prewarm_on_save']) ? '1' : '0';
        update_option('freedomtranslate_prewarm_on_save', $prewarm);

        $strict_manual = isset($_POST['freedomtranslate_strict_manual']) ? '1' : '0';
        update_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, $strict_manual);

        $ttl = isset($_POST['freedomtranslate_cache_ttl_global']) ? max(0, min(365, absint(wp_unslash($_POST['freedomtranslate_cache_ttl_global'])))) : 30;
        update_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, $ttl);

        $max_concurrent = isset($_POST['freedomtranslate_max_concurrent_jobs']) ? max(1, min(50, absint(wp_unslash($_POST['freedomtranslate_max_concurrent_jobs'])))) : 2;
        update_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, $max_concurrent);

        $chunk_size = isset($_POST['freedomtranslate_chunk_size']) ? max(100, min(6000, absint(wp_unslash($_POST['freedomtranslate_chunk_size'])))) : 500;
        update_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, $chunk_size);

        $api_timeout = isset($_POST['freedomtranslate_api_timeout']) ? max(30, min(1800, absint(wp_unslash($_POST['freedomtranslate_api_timeout'])))) : 120;
        update_option('freedomtranslate_api_timeout', $api_timeout);

        $num_ctx = 4096;
        if (isset($_POST['freedomtranslate_num_ctx'])) {
            $num_ctx = max(512, min(131072, absint(wp_unslash($_POST['freedomtranslate_num_ctx']))));
        }
        update_option(FREEDOMTRANSLATE_NUM_CTX_OPTION, $num_ctx);

        if (isset($_POST['freedomtranslate_bot_signatures'])) {
            $bots = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_bot_signatures'])))));
            update_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, $bots);
        }

        if (isset($_POST['freedomtranslate_words_exclude'])) {
            $words = array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field(wp_unslash($_POST['freedomtranslate_words_exclude'])))));
            update_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, $words);
        }

        $ai_label_enable = isset($_POST['freedomtranslate_ai_label_enable']) ? '1' : '0';
        update_option(FREEDOMTRANSLATE_AI_LABEL_ENABLE_OPTION, $ai_label_enable);

        if (isset($_POST['freedomtranslate_ai_label_text'])) {
            update_option(FREEDOMTRANSLATE_AI_LABEL_TEXT_OPTION, sanitize_text_field(wp_unslash($_POST['freedomtranslate_ai_label_text'])));
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('General settings saved.', 'freedomtranslate-wp') . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_save_languages'])) {
        check_admin_referer('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages');
        $languages = isset($_POST['freedomtranslate_languages']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['freedomtranslate_languages'])) : [];
        update_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, $languages);

        $seo_permalinks = isset($_POST['freedomtranslate_seo_permalinks']) ? '1' : '0';
        update_option(FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION, $seo_permalinks);

        echo '<div class="notice notice-success"><p>' . esc_html__('Enabled languages and permalink settings saved.', 'freedomtranslate-wp') . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_purge_single'])) {
        check_admin_referer('freedomtranslate_purge_single', 'freedomtranslate_nonce_single');
        $input   = isset($_POST['single_post_input']) ? sanitize_text_field(wp_unslash($_POST['single_post_input'])) : '';
        $post_id = is_numeric($input) ? intval($input) : url_to_postid($input);

        if ($post_id > 0) {
            $deleted = $wpdb->delete($table, ['post_id' => $post_id]);
            if ($deleted) {
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Cache cleared successfully for Post ID: %1$d (%2$d translations removed).', 'freedomtranslate-wp'), intval($post_id), intval($deleted)) . '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>' . sprintf(esc_html__('No cache found for Post ID: %d.', 'freedomtranslate-wp'), intval($post_id)) . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Could not find a valid Post ID or URL.', 'freedomtranslate-wp') . '</p></div>';
        }
    }

    if (isset($_POST['freedomtranslate_purge_cache'])) {
        check_admin_referer('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache');
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="notice notice-success"><p>' . esc_html__('Entire translation database cache cleared successfully.', 'freedomtranslate-wp') . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_purge_cron'])) {
        check_admin_referer('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron');

        wp_clear_scheduled_hook('freedomtranslate_queue_watchdog');

        $active_hashes = $wpdb->get_col("SELECT hash_key FROM $table WHERE status IN ('pending','processing')");
        if (!empty($active_hashes)) {
            freedomtranslate_cancel_remote_job($active_hashes);
        }

        update_option('freedomtranslate_last_panic_time', time());

        $crons = _get_cron_array();
        $found = false;
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $cron_hooks) {
                if (isset($cron_hooks['freedomtranslate_async_translate']) ||
                    isset($cron_hooks['freedomtranslate_async_string_translate']) ||
                    isset($cron_hooks['freedomtranslate_trigger_prewarm']) ||
                    isset($cron_hooks['freedomtranslate_master_ping']) ||
                    isset($cron_hooks['freedomtranslate_queue_watchdog'])) {

                    unset($crons[$timestamp]['freedomtranslate_async_translate']);
                    unset($crons[$timestamp]['freedomtranslate_async_string_translate']);
                    unset($crons[$timestamp]['freedomtranslate_trigger_prewarm']);
                    unset($crons[$timestamp]['freedomtranslate_master_ping']);
                    unset($crons[$timestamp]['freedomtranslate_queue_watchdog']);
                    if (empty($crons[$timestamp])) unset($crons[$timestamp]);
                    $found = true;
                }
            }
        }
        if ($found) {
            _set_cron_array($crons);
        }

        $wpdb->query("DELETE FROM $table WHERE hash_key LIKE '%_chunk_%'");
        $wpdb->query("DELETE FROM $table WHERE status IN ('pending', 'processing', 'timeout', 'paused')");

        echo '<div class="notice notice-success"><p>' . esc_html__('PANIC BUTTON ACTIVATED: All active background workers killed, queue cleared, and half-baked translations removed from the database.', 'freedomtranslate-wp') . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_repair_strings'])) {
        check_admin_referer('freedomtranslate_repair_strings', 'freedomtranslate_nonce_repair');
        $strings        = get_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, []);
        $repaired_count = 0;

        if (!is_array($strings)) {
            $strings        = [];
            $repaired_count = 1;
        } else {
            foreach ($strings as $id => &$sdata) {
                if (!isset($sdata['original'])) {
                    unset($strings[$id]);
                    $repaired_count++;
                } elseif (isset($sdata['translations']) && is_array($sdata['translations'])) {
                    foreach ($sdata['translations'] as $lang => $trans) {
                        if (is_numeric($lang) || trim($trans) === '') {
                            unset($sdata['translations'][$lang]);
                            $repaired_count++;
                        }
                    }
                }
            }
            unset($sdata);
        }
        update_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, $strings);
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Repair Complete! %d corrupted elements were removed from the array.', 'freedomtranslate-wp'), intval($repaired_count)) . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_purge_strings'])) {
        check_admin_referer('freedomtranslate_purge_strings', 'freedomtranslate_nonce_purge_strings');
        delete_option(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION);
        wp_cache_delete(FREEDOMTRANSLATE_STATIC_STRINGS_OPTION, 'options');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All static strings and translations have been permanently deleted.', 'freedomtranslate-wp') . '</p></div>';
    }

    if (isset($_POST['freedomtranslate_delete_single_cron'])) {
        check_admin_referer('freedomtranslate_delete_single_cron', 'freedomtranslate_nonce_single_cron');
        $timestamp = isset($_POST['cron_timestamp']) ? intval($_POST['cron_timestamp']) : 0;
        $raw_args  = isset($_POST['cron_args']) ? sanitize_text_field(wp_unslash($_POST['cron_args'])) : '';
        // Decode JSON safely instead of unserialize() to prevent PHP Object Injection
        $args      = json_decode(base64_decode($raw_args), true);

        if ($timestamp && is_array($args) && isset($args[0])) {
            wp_unschedule_event($timestamp, 'freedomtranslate_async_translate', $args);
            $hash_key = sanitize_text_field($args[0]);

            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($hash_key . '_chunk_') . '%'));
            $wpdb->delete($table, ['hash_key' => $hash_key]);
            delete_transient('freedomtranslate_pending_' . $hash_key);

            echo '<div class="notice notice-success"><p>' . esc_html__('Specific background job cancelled and removed from the database.', 'freedomtranslate-wp') . '</p></div>';
        }
    }

    // Load Variables
    $current_service   = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    $all_languages     = freedomtranslate_get_all_languages();
    $enabled_languages = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, array_keys($all_languages));
    $api_url           = get_option(FREEDOMTRANSLATE_API_URL_OPTION, FREEDOMTRANSLATE_API_URL_DEFAULT);
    $api_key           = get_option(FREEDOMTRANSLATE_API_KEY_OPTION, '');
    $global_ttl        = get_option(FREEDOMTRANSLATE_CACHE_TTL_OPTION, 30);
    $max_concurrent    = get_option(FREEDOMTRANSLATE_MAX_CONCURRENT_OPTION, 2);
    $active_tab        = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
    $queue_ajax_nonce  = wp_create_nonce('freedomtranslate_queue_action');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('FreedomTranslate Settings', 'freedomtranslate-wp'); ?></h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=freedomtranslate&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General & API', 'freedomtranslate-wp'); ?></a>
            <a href="?page=freedomtranslate&tab=languages" class="nav-tab <?php echo $active_tab === 'languages' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Languages', 'freedomtranslate-wp'); ?></a>

            <?php if ($current_service !== 'googlehash'): ?>
                <a href="?page=freedomtranslate&tab=static_strings" class="nav-tab <?php echo $active_tab === 'static_strings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Static Strings', 'freedomtranslate-wp'); ?></a>
                <a href="?page=freedomtranslate&tab=direct_translate" class="nav-tab <?php echo $active_tab === 'direct_translate' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Direct Translate', 'freedomtranslate-wp'); ?></a>
                <a href="?page=freedomtranslate&tab=queue_monitor" class="nav-tab <?php echo $active_tab === 'queue_monitor' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Queue Monitor', 'freedomtranslate-wp'); ?></a>
                <a href="?page=freedomtranslate&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Tools & Database', 'freedomtranslate-wp'); ?></a>
            <?php endif; ?>
        </h2>

        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); margin-top:15px;">

        <?php if ($active_tab === 'general'): ?>
            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_general', 'freedomtranslate_nonce_general'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Translation Service', 'freedomtranslate-wp'); ?></th>
                        <td>
                            <label><input type="radio" name="translation_service" value="ollama" onchange="ftToggleServiceUI()" <?php checked($current_service, 'ollama'); ?>> Ollama (Local AI Server)</label><br>
                            <label><input type="radio" name="translation_service" value="libretranslate" onchange="ftToggleServiceUI()" <?php checked($current_service, 'libretranslate'); ?>> LibreTranslate API</label><br>
                            <label><input type="radio" name="translation_service" value="google_official" onchange="ftToggleServiceUI()" <?php checked($current_service, 'google_official'); ?>> Google Translate Official API</label><br>
                            <label><input type="radio" name="translation_service" value="googlehash" onchange="ftToggleServiceUI()" <?php checked($current_service, 'googlehash'); ?>> Google Hash (Free/On-the-fly)</label>
                        </td>
                    </tr>

                    <tr valign="top" class="ft-row-shared">
                        <th scope="row"><label for="freedomtranslate_api_url"><?php esc_html_e('API Server URL', 'freedomtranslate-wp'); ?></label></th>
                        <td>
                            <input type="text" id="freedomtranslate_api_url" name="freedomtranslate_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text">
                            <p class="description">Ollama default: http://localhost:11434 | LibreTranslate default: http://localhost:5000/translate</p>
                        </td>
                    </tr>
                    <tr valign="top" class="ft-row-shared">
                        <th scope="row"><label for="freedomtranslate_api_key"><?php esc_html_e('API Secret Key', 'freedomtranslate-wp'); ?></label></th>
                        <td>
                            <input type="text" id="freedomtranslate_api_key" name="freedomtranslate_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Leave blank if your local Ollama or LibreTranslate instance does not require authentication.', 'freedomtranslate-wp'); ?></p>
                        </td>
                    </tr>

                    <tr valign="top" class="ft-ollama-only">
                        <th scope="row"><label for="freedomtranslate_ollama_model">Ollama Model (LLM)</label></th>
                        <td>
                            <input type="text" id="freedomtranslate_ollama_model" name="freedomtranslate_ollama_model" value="<?php echo esc_attr(get_option('freedomtranslate_ollama_model', 'gemma4-translate')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr valign="top" class="ft-ollama-only">
                        <th scope="row"><label for="freedomtranslate_num_ctx">AI num_ctx</label></th>
                        <td>
                            <input type="number" id="freedomtranslate_num_ctx" name="freedomtranslate_num_ctx" value="<?php echo esc_attr(get_option(FREEDOMTRANSLATE_NUM_CTX_OPTION, 8192)); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr valign="top" class="ft-ollama-only">
                        <th scope="row"><label for="freedomtranslate_keep_alive">AI Keep Alive</label></th>
                        <td>
                            <input type="text" id="freedomtranslate_keep_alive" name="freedomtranslate_keep_alive" value="<?php echo esc_attr(get_option('freedomtranslate_keep_alive', '1m')); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr valign="top" class="ft-ollama-only">
                        <th scope="row"><?php esc_html_e('Disable AI Thinking', 'freedomtranslate-wp'); ?></th>
                        <td>
                            <label>
                                <?php $disable_think_val = get_option('freedomtranslate_ollama_disable_think', '1'); ?>
                                <input type="checkbox" name="freedomtranslate_ollama_disable_think" value="1" <?php checked($disable_think_val, '1'); ?>>
                                <strong>Send <code>'think': false</code> in API payload</strong>
                            </label>
                            <p class="description"><?php esc_html_e('Useful for modern reasoning models to suppress internal "Chain of Thought" outputs during translation.', 'freedomtranslate-wp'); ?></p>
                        </td>
                    </tr>
                </table>

                <div id="ui_block_google_official" class="ft-service-block" style="display:none;">
                    <h3><?php esc_html_e('Google Cloud API Configuration', 'freedomtranslate-wp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_google_api_key">Google API Key</label></th>
                            <td><input type="text" id="freedomtranslate_google_api_key" name="freedomtranslate_google_api_key" value="<?php echo esc_attr(get_option(FREEDOMTRANSLATE_GOOGLE_API_KEY_OPTION, '')); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                </div>

                <div id="ui_block_googlehash" class="ft-service-block" style="display:none;">
                    <div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong><?php esc_html_e('Google Translate (Hash-based) is active.', 'freedomtranslate-wp'); ?></strong><br><?php esc_html_e('This mode works entirely on the client side. It does not require API keys, background tasks, or local caching.', 'freedomtranslate-wp'); ?></p>
                    </div>
                </div>

                <div id="ui_block_ai_label">
                    <hr>
                    <h3><?php esc_html_e('Display Settings', 'freedomtranslate-wp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('AI Translation Label', 'freedomtranslate-wp'); ?></th>
                            <td>
                                <label>
                                    <?php $ai_label_val = get_option(FREEDOMTRANSLATE_AI_LABEL_ENABLE_OPTION, '0'); ?>
                                    <input type="checkbox" name="freedomtranslate_ai_label_enable" value="1" <?php checked($ai_label_val, '1'); ?>>
                                    <strong><?php esc_html_e('Append a label to translated posts', 'freedomtranslate-wp'); ?></strong>
                                </label>
                                <p class="description"><?php esc_html_e('If enabled, the text below will be added at the top and bottom of the content when viewing a translated post.', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_ai_label_text"><?php esc_html_e('Label Text', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <?php $label_text_val = get_option(FREEDOMTRANSLATE_AI_LABEL_TEXT_OPTION, 'This post is translated thanks to artificial intelligence'); ?>
                                <input type="text" id="freedomtranslate_ai_label_text" name="freedomtranslate_ai_label_text" value="<?php echo esc_attr($label_text_val); ?>" class="regular-text" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="ui_block_automation">
                    <hr>
                    <h3><?php esc_html_e('Cache & Performance', 'freedomtranslate-wp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Auto-Translate on Save', 'freedomtranslate-wp'); ?></th>
                            <td>
                                <label>
                                    <?php $prewarm_val = get_option('freedomtranslate_prewarm_on_save', '0'); ?>
                                    <input type="checkbox" name="freedomtranslate_prewarm_on_save" value="1" <?php checked($prewarm_val, '1'); ?>>
                                    <strong><?php esc_html_e('Automatically translate posts in background when saved', 'freedomtranslate-wp'); ?></strong>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Strict Manual Mode', 'freedomtranslate-wp'); ?></th>
                            <td>
                                <label>
                                    <?php $strict_val = get_option(FREEDOMTRANSLATE_STRICT_MANUAL_OPTION, '1'); ?>
                                    <input type="checkbox" name="freedomtranslate_strict_manual" value="1" <?php checked($strict_val, '1'); ?>>
                                    <strong style="color: #d63638;"><?php esc_html_e('Disable automatic background translations on page visit (Recommended)', 'freedomtranslate-wp'); ?></strong>
                                </label>
                                <p class="description"><?php esc_html_e('If checked, translations will ONLY start if you manually push them via the Direct Translate tab.', 'freedomtranslate-wp'); ?></p>
                                <?php if ($strict_val === '0'): ?>
                                    <div class="notice notice-warning inline" style="margin-top: 10px; padding: 10px;">
                                        <p style="margin: 0;"><?php esc_html_e('WARNING: You have enabled "On-the-fly" automatic translations. If your site has hundreds of posts and multiple languages enabled, an unexpected traffic spike or a bot crawl could overload your local AI server with thousands of background jobs. Use with caution!', 'freedomtranslate-wp'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_cache_ttl_global"><?php esc_html_e('Cache TTL (Days)', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <input type="number" id="freedomtranslate_cache_ttl_global" name="freedomtranslate_cache_ttl_global" value="<?php echo esc_attr($global_ttl); ?>" min="0" max="365" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Set to 0 to make cache permanent (it will never expire). Otherwise, set the expiration in days (e.g. 30).', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>

                        <tr class="ft-processing-limit">
                            <th scope="row"><label for="freedomtranslate_max_concurrent_jobs"><?php esc_html_e('Max Concurrent Translations', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <input type="number" id="freedomtranslate_max_concurrent_jobs" name="freedomtranslate_max_concurrent_jobs" value="<?php echo esc_attr($max_concurrent); ?>" min="1" max="50" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Limit simultaneous background AI translation jobs. Local LLMs perform best with 1 or 2 concurrent jobs to prevent RAM exhaustion and slowdowns.', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                        <tr class="ft-processing-limit">
                            <th scope="row"><label for="freedomtranslate_chunk_size"><?php esc_html_e('HTML Chunk Size', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <?php $current_chunk = get_option(FREEDOMTRANSLATE_CHUNK_SIZE_OPTION, 500); ?>
                                <input type="number" id="freedomtranslate_chunk_size" name="freedomtranslate_chunk_size" value="<?php echo esc_attr($current_chunk); ?>" min="100" max="6000" step="100" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Maximum characters per translation block. Increase this (e.g., 1000-1500) for complex page builders to preserve HTML layout. Lower it (400-500) if the AI server goes into Timeout. Max: 6000', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                        <tr class="ft-processing-limit">
                            <th scope="row"><label for="freedomtranslate_api_timeout"><?php esc_html_e('AI API Timeout (Seconds)', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <?php $current_timeout = get_option('freedomtranslate_api_timeout', 120); ?>
                                <input type="number" id="freedomtranslate_api_timeout" name="freedomtranslate_api_timeout" value="<?php echo esc_attr($current_timeout); ?>" min="30" max="1800" step="30" style="width: 80px;">
                                <p class="description"><?php esc_html_e('Maximum time WordPress will wait for the AI to reply before giving up on a chunk. For local servers (Ollama) translating large chunks, increase this to 300 (5 minutes) or 600 (10 minutes). Max: 1800.', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="ui_block_security">
                    <hr>
                    <h3><?php esc_html_e('Security, Content Protection & Anti-Bot', 'freedomtranslate-wp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="freedomtranslate_words_exclude"><?php esc_html_e('Excluded Words', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <?php $excluded = get_option(FREEDOMTRANSLATE_WORDS_EXCLUDE_OPTION, []); ?>
                                <textarea id="freedomtranslate_words_exclude" name="freedomtranslate_words_exclude" rows="4" cols="50" class="large-text"><?php echo esc_textarea(is_array($excluded) ? implode("\n", $excluded) : ''); ?></textarea>
                                <p class="description"><?php esc_html_e('List words or brand names (one per line) that should NEVER be translated.', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="freedomtranslate_bot_signatures"><?php esc_html_e('Bot / Crawler Signatures', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <?php
                                $saved_bots   = get_option(FREEDOMTRANSLATE_BOT_SIGNATURES_OPTION, false);
                                $display_bots = ($saved_bots === false || !is_array($saved_bots)) ? freedomtranslate_get_default_bots_string() : implode("\n", $saved_bots);
                                ?>
                                <textarea id="freedomtranslate_bot_signatures" name="freedomtranslate_bot_signatures" rows="6" cols="50" class="large-text"><?php echo esc_textarea($display_bots); ?></textarea>
                                <p class="description"><?php esc_html_e('List user-agent keywords (one per line) that should NOT trigger automatic background translations.', 'freedomtranslate-wp'); ?></p>

                                <button type="submit" name="freedomtranslate_restore_bots" class="button button-secondary" style="margin-top: 10px;" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to overwrite your current list and restore the default bots?', 'freedomtranslate-wp')); ?>');"><?php esc_html_e('Restore Default Bots', 'freedomtranslate-wp'); ?></button>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit"><input type="submit" name="freedomtranslate_save_general" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'freedomtranslate-wp'); ?>"></p>
            </form>

            <script type="text/javascript">
            function ftToggleServiceUI() {
                var selectedRadio = document.querySelector('input[name="translation_service"]:checked');
                if (!selectedRadio) return;
                var service = selectedRadio.value;

                var blockGoogle = document.getElementById('ui_block_google_official');
                var blockHash = document.getElementById('ui_block_googlehash');
                var blockAuto = document.getElementById('ui_block_automation');

                if (blockGoogle) blockGoogle.style.display = (service === 'google_official') ? 'block' : 'none';
                if (blockHash) blockHash.style.display = (service === 'googlehash') ? 'block' : 'none';
                if (blockAuto) blockAuto.style.display = (service === 'googlehash') ? 'none' : 'block';

                document.querySelectorAll('.ft-ollama-only').forEach(function(row) {
                    row.style.display = (service === 'ollama') ? 'table-row' : 'none';
                });

                document.querySelectorAll('.ft-row-shared').forEach(function(row) {
                    row.style.display = (service === 'ollama' || service === 'libretranslate') ? 'table-row' : 'none';
                });

                document.querySelectorAll('.ft-processing-limit').forEach(function(row) {
                    row.style.display = (service === 'ollama' || service === 'libretranslate') ? 'table-row' : 'none';
                });
            }

            document.addEventListener("DOMContentLoaded", function() {
                ftToggleServiceUI();
                document.querySelectorAll('input[name="translation_service"]').forEach(function(radio) {
                    radio.addEventListener('change', ftToggleServiceUI);
                });
            });
            </script>

        <?php elseif ($active_tab === 'languages'): ?>
            <div style="background:#e5f5fa; border-left:4px solid #00a0d2; padding:15px; margin-bottom:20px; max-width: 800px;">
                <h4 style="margin-top:0; font-size: 14px;"><?php esc_html_e('Language Selector Shortcode', 'freedomtranslate-wp'); ?></h4>
                <p style="margin-bottom:10px;"><?php esc_html_e('To display the language dropdown menu anywhere on your site (like in a widget, sidebar, header, or footer), use the following shortcode:', 'freedomtranslate-wp'); ?></p>
                <p><code style="font-size: 16px; padding: 5px 10px; background: #fff; border: 1px solid #ccc;">[freedomtranslate_selector]</code></p>
                <p class="description" style="margin-bottom:0;"><em><?php esc_html_e('If you want to force the selector to translate a specific post ID regardless of the page it is placed on, you can use:', 'freedomtranslate-wp'); ?> <code>[freedomtranslate_selector post_id="123"]</code></em></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('freedomtranslate_save_languages', 'freedomtranslate_nonce_languages'); ?>
                <h3><?php esc_html_e('Enable Languages & Set Priority', 'freedomtranslate-wp'); ?></h3>
                <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Drag and drop the items below to set the priority order for background translations. Top languages will be translated first.', 'freedomtranslate-wp'); ?></p>

                <ul id="ft-lang-sortable" style="list-style-type: none; margin: 0; padding: 0; max-width: 600px;">
                    <?php
                    if (is_array($enabled_languages)) {
                        foreach ($enabled_languages as $code) {
                            if (isset($all_languages[$code])) {
                                echo '<li style="background:#fff; border:1px solid #ccc; padding:10px 15px; margin-bottom:8px; border-radius:4px; cursor:grab; display:flex; align-items:center;">
                                        <span style="color:#aaa; margin-right:15px; cursor:grab;">&#9776;</span>
                                        <label style="cursor:pointer; flex-grow:1;">
                                            <input type="checkbox" name="freedomtranslate_languages[]" value="' . esc_attr($code) . '" checked>
                                            <strong style="margin-left:8px;">' . esc_html(strtoupper($code)) . '</strong> - ' . esc_html($all_languages[$code]) . '
                                        </label>
                                      </li>';
                            }
                        }
                    }

                    foreach ($all_languages as $code => $label) {
                        if (!is_array($enabled_languages) || !in_array($code, $enabled_languages, true)) {
                            echo '<li style="background:#f9f9f9; border:1px dashed #ccc; padding:10px 15px; margin-bottom:8px; border-radius:4px; cursor:grab; opacity:0.6; display:flex; align-items:center;">
                                    <span style="color:#ddd; margin-right:15px; cursor:grab;">&#9776;</span>
                                    <label style="cursor:pointer; flex-grow:1;">
                                        <input type="checkbox" name="freedomtranslate_languages[]" value="' . esc_attr($code) . '">
                                        <strong style="margin-left:8px;">' . esc_html(strtoupper($code)) . '</strong> - ' . esc_html($label) . '
                                    </label>
                                  </li>';
                        }
                    }
                    ?>
                </ul>

                <script>
                    jQuery(document).ready(function($) {
                        $('#ft-lang-sortable').sortable({
                            cursor: 'grabbing',
                            opacity: 0.8
                        });
                        $('#ft-lang-sortable').disableSelection();
                    });
                </script>

                <hr style="margin: 30px 0 20px 0; border: 0; border-top: 1px solid #ccc;">
                <h3><?php esc_html_e('Advanced URL Structure', 'freedomtranslate-wp'); ?></h3>
                <table class="form-table" style="margin-bottom: 20px;">
                    <tr valign="top">
                        <th scope="row" style="width: 200px; padding-left: 0;"><?php esc_html_e('Permalink Mode', 'freedomtranslate-wp'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="freedomtranslate_seo_permalinks" value="1" <?php checked(get_option(FREEDOMTRANSLATE_SEO_PERMALINKS_OPTION, '0'), '1'); ?>>
                                <strong><?php esc_html_e('Enable SEO-Friendly Subdirectory Structure', 'freedomtranslate-wp'); ?></strong> (e.g., <code>example.com/it/my-post/</code>)
                            </label>
                            <p class="description" style="margin-top: 5px;">
                                <?php esc_html_e('If disabled, the plugin will default to standard queries (?freedomtranslate_lang=it).', 'freedomtranslate-wp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><input type="submit" name="freedomtranslate_save_languages" class="button button-primary" value="<?php esc_attr_e('Save Languages & Structure Settings', 'freedomtranslate-wp'); ?>"></p>
            </form>

        <?php elseif ($active_tab === 'static_strings'): ?>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                <h3 style="margin: 0;"><?php esc_html_e('Global Static Strings Manager', 'freedomtranslate-wp'); ?></h3>

                <div style="display: flex; gap: 10px;">
                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to run the repair tool?', 'freedomtranslate-wp')); ?>');">
                        <?php wp_nonce_field('freedomtranslate_repair_strings', 'freedomtranslate_nonce_repair'); ?>
                        <input type="submit" name="freedomtranslate_repair_strings" class="button button-secondary" value="<?php esc_attr_e('Repair Strings', 'freedomtranslate-wp'); ?>">
                    </form>

                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('ATTENTION! This will PERMANENTLY DELETE all your strings and their translations from the database. This action cannot be undone. Are you absolutely sure?', 'freedomtranslate-wp')); ?>');">
                        <?php wp_nonce_field('freedomtranslate_purge_strings', 'freedomtranslate_nonce_purge_strings'); ?>
                        <input type="submit" name="freedomtranslate_purge_strings" class="button button-secondary" style="color: #d63638; border-color: #d63638;" value="<?php esc_attr_e('Purge All Strings', 'freedomtranslate-wp'); ?>">
                    </form>
                </div>
            </div>

            <p class="description" style="margin-top: 10px;"><?php esc_html_e('Use this to translate global theme elements once and for all.', 'freedomtranslate-wp'); ?></p>

            <div style="background:#f9f9f9; padding:15px; border:1px solid #ccc; margin-bottom:20px;">
                <h4><?php esc_html_e('Add New String', 'freedomtranslate-wp'); ?></h4>
                <form method="post">
                    <?php wp_nonce_field('freedomtranslate_save_static_string', 'freedomtranslate_nonce_static'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label><?php esc_html_e('String ID (e.g., footer_credits)', 'freedomtranslate-wp'); ?></label></th>
                            <td><input type="text" name="new_string_id" required pattern="[a-zA-Z0-9_-]+" title="<?php esc_attr_e('Only letters, numbers, dashes, and underscores', 'freedomtranslate-wp'); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label><?php esc_html_e('Original Text (Default Language)', 'freedomtranslate-wp'); ?></label></th>
                            <td><textarea name="new_string_text" rows="3" class="large-text" required></textarea></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="freedomtranslate_save_static_string" class="button button-primary" value="<?php esc_attr_e('Translate in all languages & Save', 'freedomtranslate-wp'); ?>"></p>
                </form>
            </div>

            <h4><?php esc_html_e('Saved Strings', 'freedomtranslate-wp'); ?></h4>
            <?php
            $strings_table = new FreedomTranslate_Strings_Table();
            $strings_table->prepare_items();

            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);

            if (!$strings_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;">' . esc_html__('No static strings added yet.', 'freedomtranslate-wp') . '</p>
                      </div>';
            } else {
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="' . esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? 'freedomtranslate'))) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';

                $strings_table->search_box(__('Search Strings', 'freedomtranslate-wp'), 'search_strings');
                $strings_table->display();

                echo '</form>';
            }
            ?>

        <?php elseif ($active_tab === 'direct_translate'): ?>
            <h3><?php esc_html_e('Manual Direct Translation', 'freedomtranslate-wp'); ?></h3>
            <p class="description"><?php esc_html_e('Save your local AI server resources! Instead of waiting for users to visit pages, paste a post URL here and push it to the background translation queue manually.', 'freedomtranslate-wp'); ?></p>

            <div style="background:#f9f9f9; padding:20px; border:1px solid #ccc; margin-top: 15px; border-radius: 5px;">
                <form method="post" id="ft_direct_push_form">
                    <?php wp_nonce_field('freedomtranslate_direct_push', 'freedomtranslate_nonce_direct'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label><?php esc_html_e('Target Post/Page', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <input type="text" name="direct_post_input" placeholder="e.g., https://yoursite.com/my-post/ OR Post ID (123)" class="large-text" required style="max-width: 600px;">
                                <p class="description"><?php esc_html_e('Paste the full URL of the post or its ID.', 'freedomtranslate-wp'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label><?php esc_html_e('Translate Into...', 'freedomtranslate-wp'); ?></label></th>
                            <td>
                                <div style="column-count: 3; background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">
                                    <?php
                                    $site_lang  = freedomtranslate_get_site_source_lang();
                                    $last_langs = get_option('freedomtranslate_direct_last_langs', $enabled_languages);
                                    if (is_array($enabled_languages)) {
                                        foreach ($enabled_languages as $code):
                                            if ($code === $site_lang || !isset($all_languages[$code])) continue;
                                            $is_checked = (is_array($last_langs) && in_array($code, $last_langs, true)) ? 'checked' : '';
                                    ?>
                                        <label style="display:block;margin-bottom:5px;">
                                            <input type="checkbox" name="direct_langs[]" value="<?php echo esc_attr($code); ?>" <?php echo $is_checked; ?>>
                                            <strong><?php echo esc_html(strtoupper($code)); ?></strong> - <?php echo esc_html($all_languages[$code]); ?>
                                        </label>
                                    <?php
                                        endforeach;
                                    }
                                    ?>
                                </div>
                                <p class="description" style="margin-top: 10px;">
                                    <a href="#" onclick="jQuery('input[name=\'direct_langs[]\']').prop('checked', true); return false;"><?php esc_html_e('Select All', 'freedomtranslate-wp'); ?></a> |
                                    <a href="#" onclick="jQuery('input[name=\'direct_langs[]\']').prop('checked', false); return false;"><?php esc_html_e('Deselect All', 'freedomtranslate-wp'); ?></a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="freedomtranslate_direct_push" class="button button-primary button-large" value="<?php esc_attr_e('Push to Translation Queue', 'freedomtranslate-wp'); ?>">
                    </p>
                </form>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('ft_direct_push_form');
                if (!form) return;

                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    var submitBtn = form.querySelector('input[type="submit"]');
                    var originalBtnText = submitBtn.value;
                    submitBtn.value = '<?php echo esc_js(__('Checking database...', 'freedomtranslate-wp')); ?>';
                    submitBtn.disabled = true;

                    var postInput = form.querySelector('input[name="direct_post_input"]').value;
                    var checkboxes = form.querySelectorAll('input[name="direct_langs[]"]:checked');
                    var selectedLangs = Array.from(checkboxes).map(function(cb) { return cb.value; });

                    if (selectedLangs.length === 0) {
                        triggerRealSubmit(form);
                        return;
                    }

                    var formData = new FormData();
                    formData.append('action', 'freedomtranslate_check_existing_translations');
                    formData.append('nonce', '<?php echo esc_js($queue_ajax_nonce); ?>');
                    formData.append('post_id', postInput);
                    selectedLangs.forEach(function(lang) { formData.append('langs[]', lang); });

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        submitBtn.value = originalBtnText;
                        submitBtn.disabled = false;

                        if (data.exists) {
                            if (confirm('<?php echo esc_js(__('Are you sure you want to overwrite this post for the selected languages? The existing cache for the selected languages will be destroyed and retranslated from scratch.', 'freedomtranslate-wp')); ?>')) {
                                triggerRealSubmit(form);
                            }
                        } else {
                            triggerRealSubmit(form);
                        }
                    })
                    .catch(function() {
                        triggerRealSubmit(form);
                    });
                });

                function triggerRealSubmit(targetForm) {
                    var hiddenTrigger = document.createElement('input');
                    hiddenTrigger.type = 'hidden';
                    hiddenTrigger.name = 'freedomtranslate_direct_push';
                    hiddenTrigger.value = '1';
                    targetForm.appendChild(hiddenTrigger);
                    targetForm.submit();
                }
            });
            </script>

        <?php elseif ($active_tab === 'queue_monitor'): ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;"><?php esc_html_e('Unified Translation Queue', 'freedomtranslate-wp'); ?></h3>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete ALL pending background translations and cronjobs?', 'freedomtranslate-wp')); ?>');">
                    <?php wp_nonce_field('freedomtranslate_purge_cron', 'freedomtranslate_nonce_cron'); ?>
                    <input type="submit" name="freedomtranslate_purge_cron" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="<?php esc_attr_e('Clear ALL Pending Jobs (Panic Button)', 'freedomtranslate-wp'); ?>">
                </form>
            </div>

            <p class="description"><?php esc_html_e('This table merges database translation intents with scheduled WP-Cron system jobs. It gives you a complete overview of what is waiting to be translated and when the next background worker will execute.', 'freedomtranslate-wp'); ?></p>

            <?php
            $queue_table = new FreedomTranslate_Queue_Table();
            $queue_table->prepare_items();

            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);

            if (!$queue_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>' . esc_html__('The translation queue is perfectly clear!', 'freedomtranslate-wp') . '</strong> ' . esc_html__('No jobs are currently pending or processing.', 'freedomtranslate-wp') . '</p>
                      </div>';
            } else {
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="' . esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? 'freedomtranslate'))) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';

                $queue_table->search_box(__('Search by Post ID or Lang', 'freedomtranslate-wp'), 'search_queue');
                $queue_table->display();

                echo '</form>';
            }
            ?>
            <script>
document.addEventListener("DOMContentLoaded", function() {
    var ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
    var queueNonce = "<?php echo esc_js($queue_ajax_nonce); ?>";

    document.body.addEventListener('click', function(e) {
        if (e.target.classList.contains('ft-ajax-start') || e.target.classList.contains('ft-ajax-cancel') || e.target.classList.contains('ft-ajax-pause')) {
            e.preventDefault();
            var btn = e.target;

            var action = '';
            if (btn.classList.contains('ft-ajax-start')) action = 'freedomtranslate_queue_start';
            else if (btn.classList.contains('ft-ajax-cancel')) action = 'freedomtranslate_queue_cancel';
            else if (btn.classList.contains('ft-ajax-pause')) action = 'freedomtranslate_queue_pause';

            if (action === 'freedomtranslate_queue_cancel' && !confirm('<?php echo esc_js(__('Are you sure you want to delete this translation? The data will be destroyed.', 'freedomtranslate-wp')); ?>')) return;

            btn.disabled = true;
            btn.textContent = '...';

            var formData = new FormData();
            formData.append('action', action);
            formData.append('hash_key', btn.getAttribute('data-hash'));
            formData.append('nonce', btn.getAttribute('data-nonce'));

            fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if ((action === 'freedomtranslate_queue_start' || action === 'freedomtranslate_queue_pause') && data.success) {
                    btn.style.display = 'none';
                }
            });
        }
    });

    var hasTableOnLoad = document.querySelector('.wp-list-table') !== null;

    setInterval(function() {
        if (!hasTableOnLoad) return;

        var searchParam = new URLSearchParams(window.location.search).get('s') || '';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajaxUrl + '?action=freedomtranslate_queue_monitor_data&nonce=' + encodeURIComponent(queueNonce) + '&s=' + encodeURIComponent(searchParam) + '&_t=' + new Date().getTime(), true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    var data = parsed && parsed.success ? parsed.data : parsed;
                    if (!data || typeof data !== 'object') return;

                    var activeCount = Object.keys(data).length;

                    if (activeCount === 0) {
                        window.location.reload();
                        return;
                    }

                    var displayNums = document.querySelectorAll('.displaying-num');
                    displayNums.forEach(function(num) {
                        num.textContent = activeCount + (activeCount === 1 ? ' item' : ' items');
                    });

                    var totalPages = Math.ceil(activeCount / 15);
                    var pageDisplays = document.querySelectorAll('.total-pages');
                    pageDisplays.forEach(function(p) {
                        p.textContent = totalPages.toString();
                    });

                    if (totalPages <= 1) {
                        var tablenav = document.querySelectorAll('.tablenav-pages');
                        tablenav.forEach(function(nav) {
                            nav.classList.add('one-page');
                            var links = nav.querySelector('.pagination-links');
                            if (links) links.style.display = 'none';
                        });
                    }

                    window.ftProgressTracker = window.ftProgressTracker || {};
                    var nowTime = Date.now();

                    var wrappers = document.querySelectorAll('div[id^="ft_status_wrap_"]');
                    wrappers.forEach(function(wrap) {
                        var safe_id = wrap.id.replace('ft_status_wrap_', '');

                        if (!data[safe_id]) {
                            var row = wrap.closest('tr');
                            if (row && row.style.opacity !== '0.2') {
                                row.style.transition = 'opacity 0.6s ease';
                                row.style.opacity = '0.2';
                                setTimeout(function(){ row.style.display = 'none'; }, 600);
                            }
                            return;
                        }

                        var job = data[safe_id];

                        var isSlow = false;
                        if (job.s === 'processing') {
                            if (!window.ftProgressTracker[safe_id]) {
                                window.ftProgressTracker[safe_id] = { p: job.p, time: nowTime };
                            } else {
                                if (window.ftProgressTracker[safe_id].p !== job.p) {
                                    window.ftProgressTracker[safe_id].p = job.p;
                                    window.ftProgressTracker[safe_id].time = nowTime;
                                } else if ((nowTime - window.ftProgressTracker[safe_id].time) > 300000) {
                                    isSlow = true;
                                }
                            }
                        } else {
                            if (window.ftProgressTracker[safe_id]) delete window.ftProgressTracker[safe_id];
                        }

                        var badge = document.getElementById('ft_status_badge_' + safe_id);
                        if (badge) {
                            badge.textContent = job.s.toUpperCase();
                            if (job.s === 'pending') badge.style.background = '#f0b849';
                            else if (job.s === 'processing') badge.style.background = '#2271b1';
                            else if (job.s === 'timeout') badge.style.background = '#d63638';
                            else if (job.s === 'paused') badge.style.background = '#8e44ad';
                            else badge.style.background = '#72777c';
                        }

                        var rowNode = wrap.closest('tr');
                        if (rowNode) {
                            var actionBtn = rowNode.querySelector('.ft-ajax-start');
                            var pauseBtn = rowNode.querySelector('.ft-ajax-pause');

                            if (actionBtn) {
                                if (job.s === 'processing') {
                                    actionBtn.style.display = 'none';
                                } else if (job.s === 'timeout' || job.s === 'pending' || job.s === 'paused') {
                                    actionBtn.style.display = 'inline-block';
                                    actionBtn.textContent = (job.s === 'timeout' || job.s === 'paused') ? 'Resume Job' : 'Start Now';
                                    actionBtn.disabled = false;
                                }
                            }
                            if (pauseBtn) {
                                if (job.s === 'processing' || job.s === 'pending') {
                                    pauseBtn.style.display = 'inline-block';
                                    pauseBtn.textContent = 'Pause';
                                    pauseBtn.disabled = false;
                                } else {
                                    pauseBtn.style.display = 'none';
                                }
                            }
                        }

                        var barFill = document.getElementById('ft_bar_fill_' + safe_id);
                        var barText = document.getElementById('ft_bar_text_' + safe_id);

                        if (barFill && barText) {
                            var visual_width = 5;
                            var label = 'Chunk: ' + job.p + ' / ? (Waiting...)';

                            if (job.s === 'timeout') {
                                barFill.style.background = '#d63638';
                                barFill.style.width = '100%';
                                barText.textContent = 'API TIMEOUT (Needs Restart)';
                                barText.style.color = '#fff';
                            } else if (job.s === 'paused') {
                                barFill.style.background = '#8e44ad';
                                barFill.style.width = (job.t > 0) ? Math.max(5, Math.round((job.p / job.t) * 100)) + '%' : '100%';
                                barText.textContent = 'PAUSED (Chunk ' + job.p + '/' + job.t + ')';
                                barText.style.color = '#fff';
                            } else {
                                barFill.style.background = isSlow ? '#f39c12' : '#27ae60';

                                if (job.t > 0) {
                                    var percent = Math.round((job.p / job.t) * 100);
                                    visual_width = Math.max(5, percent);
                                    label = 'Chunk: ' + job.p + ' / ' + job.t + ' (' + percent + '%)';
                                    if (isSlow) label += ' (Slow AI)';
                                } else if (isSlow) {
                                    label += ' (Slow AI)';
                                }

                                barFill.style.width = visual_width + '%';
                                barText.textContent = label;
                                barText.style.color = (visual_width > 50 || isSlow) ? '#fff' : '#000';
                            }
                            var typeLabel = document.getElementById('ft_type_label_' + safe_id);
                            if (typeLabel && job.type) {
                                var badgeColor = '#46b450';
                                if (job.type.toUpperCase() === 'TITLE') badgeColor = '#0073aa';
                                if (job.type.toUpperCase() === 'EXCERPT') badgeColor = '#e67e22';
                                typeLabel.textContent = '';
                                var span = document.createElement('span');
                                span.style.cssText = 'background:' + badgeColor + ';color:white;padding:2px 6px;border-radius:4px;font-size:10px;font-style:normal;font-weight:bold;display:inline-block;';
                                span.textContent = job.type.toUpperCase();
                                typeLabel.appendChild(span);
                            }
                        }
                    });
                } catch (e) {}
            }
        };
        xhr.send();
    }, 3000);
});
</script>

        <?php elseif ($active_tab === 'tools'): ?>
            <h3><?php esc_html_e('Database Cache Management', 'freedomtranslate-wp'); ?></h3>

            <div style="margin-bottom:30px;">
                <h4><?php esc_html_e('Clear ALL Cache (Danger Zone)', 'freedomtranslate-wp'); ?></h4>
                <p class="description"><?php esc_html_e('Clear the entire translation custom database to force re-translation of ALL content across the site.', 'freedomtranslate-wp'); ?></p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js(__('WARNING: Clearing the entire database means the AI will have to re-translate EVERY SINGLE PAGE on your site from scratch! Are you absolutely sure you want to proceed?', 'freedomtranslate-wp')); ?>');">
                    <?php wp_nonce_field('freedomtranslate_purge_cache', 'freedomtranslate_nonce_cache'); ?>
                    <input type="submit" name="freedomtranslate_purge_cache" class="button button-secondary" style="border-color: #d63638; color: #d63638;" value="<?php esc_attr_e('Clear ALL Database Cache', 'freedomtranslate-wp'); ?>">
                </form>
            </div>

            <hr style="margin: 40px 0;">
            <h3><?php esc_html_e('Translated Posts Registry', 'freedomtranslate-wp'); ?></h3>
            <p class="description"><?php esc_html_e('This table lists all posts/pages that have been successfully cached in the custom database.', 'freedomtranslate-wp'); ?></p>

            <?php
            $registry_table = new FreedomTranslate_Registry_Table();
            $registry_table->prepare_items();

            $is_searching = isset($_REQUEST['s']) && !empty($_REQUEST['s']);

            if (!$registry_table->has_items() && !$is_searching) {
                echo '<div style="padding:15px; background:#e5f5fa; border-left:4px solid #00a0d2; margin-top:20px;">
                        <p style="margin:0;"><strong>' . esc_html__('The custom translation database is currently empty.', 'freedomtranslate-wp') . '</strong> ' . esc_html__('Visit a page or use auto-prewarm to start caching.', 'freedomtranslate-wp') . '</p>
                      </div>';
            } else {
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="' . esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? 'freedomtranslate'))) . '" />';
                echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '" />';

                $registry_table->search_box(__('Search Posts', 'freedomtranslate-wp'), 'search_id');
                $registry_table->display();
                echo '</form>';
            }
            ?>
        <?php endif; ?>

        </div>
    </div>
    <?php
}

// FT CRON CLEANUP & COOKIE HANDLER
add_action('init', function() {
    $lang = '';
    if (!empty($GLOBALS['freedomtranslate_pretty_lang'])) {
        $lang = $GLOBALS['freedomtranslate_pretty_lang'];
    } elseif (isset($_GET['freedomtranslate_lang']) && is_scalar($_GET['freedomtranslate_lang'])) {
        $lang = sanitize_text_field(wp_unslash($_GET['freedomtranslate_lang']));
    }

    if (!empty($lang) && freedomtranslate_is_language_enabled($lang)) {
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie('freedomtranslate_lang', $lang, time() + (DAY_IN_SECONDS * 30), '/', $domain, is_ssl(), false);
        $_COOKIE['freedomtranslate_lang'] = $lang;
    }
});

// Safe Cron Repair on Admin Init to sanitize corrupted cron structures
add_action('admin_init', function() {
    if (get_transient('freedomtranslate_cron_repaired_v4')) return;
    set_transient('freedomtranslate_cron_repaired_v4', '1', DAY_IN_SECONDS);

    $crons = _get_cron_array();
    if (!is_array($crons)) return;

    $changed = false;
    foreach ($crons as $timestamp => $hooks) {
        if (!is_array($hooks)) {
            unset($crons[$timestamp]);
            $changed = true;
            continue;
        }

        foreach ($hooks as $hook_name => $events) {
            if (!is_array($events)) {
                unset($crons[$timestamp][$hook_name]);
                $changed = true;
                continue;
            }

            foreach ($events as $sig => $event) {
                if (!is_array($event)) {
                    unset($crons[$timestamp][$hook_name][$sig]);
                    $changed = true;
                } elseif (!isset($event['schedule'])) {
                    $crons[$timestamp][$hook_name][$sig]['schedule'] = false;
                    $changed = true;
                }
            }

            if (empty($crons[$timestamp][$hook_name])) {
                unset($crons[$timestamp][$hook_name]);
                $changed = true;
            }
        }

        if (empty($crons[$timestamp])) {
            unset($crons[$timestamp]);
            $changed = true;
        }
    }

    if ($changed) {
        _set_cron_array($crons);
    }
});

/**
 * AUTO-PREWARM ON SAVE & METABOX DATA SAVING
 */
add_action('save_post', function($post_id) {
    if (isset($_POST['ft_meta_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ft_meta_nonce'])), 'ft_meta_save')) {
        if (!defined('DOING_AUTOSAVE') || !DOING_AUTOSAVE) {
            if (current_user_can('edit_post', $post_id)) {
                $exclude = isset($_POST['ft_exclude_post']) ? '1' : '0';
                update_post_meta($post_id, '_freedomtranslate_exclude', $exclude);

                $inject = isset($_POST['ft_inject_selector']) ? '1' : '0';
                update_post_meta($post_id, '_freedomtranslate_inject_selector', $inject);
            }
        }
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (isset($_GET['meta-box-loader'])) return;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if (strpos($req_uri, 'meta-box-loader') !== false) return;

    if (get_option('freedomtranslate_prewarm_on_save', '0') !== '1') return;
    if (get_post_meta($post_id, '_freedomtranslate_exclude', true) === '1') return;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $service = get_option(FREEDOMTRANSLATE_TRANSLATION_SERVICE_OPTION, 'libretranslate');
    if (!in_array($service, ['libretranslate', 'ollama'], true)) return;

    $site_lang     = freedomtranslate_get_site_source_lang();
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $content       = is_string($post->post_content) ? $post->post_content : '';
    $title         = is_string($post->post_title)   ? $post->post_title   : '';

    if (!is_array($enabled_langs)) return;

    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;

        if (!empty($content)) {
            $c_hash      = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
            $status_data = freedomtranslate_get_status_db($c_hash);
            if (!$status_data) {
                freedomtranslate_update_progress($c_hash, $post_id, $lang, 0, 'pending');
                wp_schedule_single_event(time() + 5, 'freedomtranslate_async_translate', [$c_hash, $site_lang, $lang, $post_id, uniqid('', true)]);
            }
        }

        if (!empty($title)) {
            $t_hash      = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
            $status_data = freedomtranslate_get_status_db($t_hash);
            if (!$status_data) {
                freedomtranslate_update_progress($t_hash, $post_id, $lang, 0, 'pending');
                wp_schedule_single_event(time() + 5, 'freedomtranslate_async_translate', [$t_hash, $site_lang, $lang, $post_id, uniqid('', true)]);
            }
        }
    }
});

add_action('freedomtranslate_master_ping', function($post_id) {
    $site_lang     = freedomtranslate_get_site_source_lang();
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $url           = get_permalink($post_id);

    if (!$url || !is_array($enabled_langs)) return;

    foreach ($enabled_langs as $lang) {
        if ($lang === $site_lang) continue;
        $ping_url = add_query_arg('freedomtranslate_lang', $lang, $url);
        wp_remote_get($ping_url, ['timeout' => 0.1, 'blocking' => false, 'sslverify' => false]);
    }
});

// ==========================================
// WATCHDOG QUEUE (SELF-HEALING)
// ==========================================
add_filter('cron_schedules', function($schedules) {
    $schedules['ft_five_minutes'] = ['interval' => 300, 'display' => __('Every 5 Minutes', 'freedomtranslate-wp')];
    return $schedules;
});

add_action('init', function() {
    if (!wp_next_scheduled('freedomtranslate_queue_watchdog')) {
        wp_schedule_event(time(), 'ft_five_minutes', 'freedomtranslate_queue_watchdog');
    }
});

add_action('freedomtranslate_queue_watchdog', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    $api_timeout_setting = (int) get_option('freedomtranslate_api_timeout', 120);
    $timeout_seconds     = $api_timeout_setting > 0 ? ($api_timeout_setting + 30) : 600;

    $active_jobs  = $wpdb->get_results("SELECT hash_key, progress FROM $table WHERE status = 'processing'");
    $current_time = time();

    foreach ($active_jobs as $job) {
        $transient_key = 'freedomtranslate_watchdog_tracker_' . md5($job->hash_key);
        $tracker       = get_transient($transient_key);

        if ($tracker === false || (int) $tracker['progress'] !== (int) $job->progress) {
            $new_tracker = [
                'progress'  => (int) $job->progress,
                'timestamp' => $current_time
            ];
            set_transient($transient_key, $new_tracker, 14400);
        } else {
            $time_elapsed = $current_time - (int) $tracker['timestamp'];
            if ($time_elapsed >= $timeout_seconds) {
                $wpdb->update(
                    $table,
                    ['status' => 'timeout'],
                    ['hash_key' => $job->hash_key]
                );
                delete_transient($transient_key);
            }
        }
    }
});

// Auto-purge cron
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('freedomtranslate_auto_purge')) {
        wp_schedule_event(time(), 'daily', 'freedomtranslate_auto_purge');
    }
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('freedomtranslate_auto_purge');
    wp_clear_scheduled_hook('freedomtranslate_queue_watchdog');
});
add_action('freedomtranslate_auto_purge', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    $wpdb->query("DELETE FROM $table WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()");
    $wpdb->query("UPDATE $table SET status = 'pending', progress = 0 WHERE status = 'processing'");
});

// ========================================================================
// 7. AJAX QUEUE MONITOR
// ========================================================================
add_action('wp_ajax_freedomtranslate_queue_monitor_data', function() {
    check_ajax_referer('freedomtranslate_queue_action', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    $has_pending = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
    $has_running = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'");
    if ((int) $has_pending > 0 && (int) $has_running === 0) {
        spawn_cron();
    }

    $db_jobs = $wpdb->get_results("SELECT hash_key, post_id, target_lang, status, progress, total_chunks FROM $table WHERE status IN ('pending', 'processing', 'timeout', 'paused')");
    $unified = [];

    foreach ($db_jobs as $job) {
        $is_string = (strpos($job->hash_key, 'string_job_') === 0);
        $safe_id   = md5($job->hash_key);

        $content_type = 'Body Content';
        if (strpos($job->hash_key, '_the_title') !== false) $content_type = 'Title';
        elseif (strpos($job->hash_key, '_the_excerpt') !== false) $content_type = 'Excerpt';
        elseif ($is_string) $content_type = 'Static String';

        if (!isset($unified[$safe_id])) {
            $unified[$safe_id] = [
                's'       => $job->status,
                'p'       => (int) $job->progress,
                't'       => (int) $job->total_chunks,
                'type'    => $content_type,
                'post_id' => $job->post_id,
                'lang'    => $job->target_lang
            ];
        } else {
            $unified[$safe_id]['p'] += (int) $job->progress;
            $unified[$safe_id]['t'] += (int) $job->total_chunks;

            if ($job->status === 'processing') {
                $unified[$safe_id]['s']    = 'processing';
                $unified[$safe_id]['type'] = $content_type;
            }
        }
    }

    $crons = _get_cron_array();
    if (is_array($crons)) {
        foreach ($crons as $timestamp => $cron_hooks) {
            if (isset($cron_hooks['freedomtranslate_async_translate']) && is_array($cron_hooks['freedomtranslate_async_translate'])) {
                foreach ($cron_hooks['freedomtranslate_async_translate'] as $sig => $event) {
                    $hash_key = isset($event['args'][0]) ? $event['args'][0] : '';
                    if (empty($hash_key)) continue;

                    $safe_id = md5($hash_key);
                    if (!isset($unified[$safe_id])) {
                        $lang    = isset($event['args'][2]) ? $event['args'][2] : 'Unknown';
                        $post_id = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';

                        $unified[$safe_id] = [
                            's' => 'pending', 'p' => 0, 't' => 0,
                            'post_id' => $post_id, 'lang' => $lang
                        ];
                    }
                }
            }

            if (isset($cron_hooks['freedomtranslate_async_string_translate']) && is_array($cron_hooks['freedomtranslate_async_string_translate'])) {
                foreach ($cron_hooks['freedomtranslate_async_string_translate'] as $sig => $event) {
                    $string_id   = isset($event['args'][0]) ? $event['args'][0] : 'Unknown';
                    $target_lang = isset($event['args'][3]) ? $event['args'][3] : 'Unknown';
                    $hash_key    = 'string_job_' . $string_id . '_' . $target_lang . '_' . $sig;
                    $safe_id     = md5($hash_key);

                    if (!isset($unified[$safe_id])) {
                        $unified[$safe_id] = [
                            's' => 'pending', 'p' => 0, 't' => 0,
                            'post_id' => 'String: ' . $string_id, 'lang' => $target_lang
                        ];
                    }
                }
            }
        }
    }

    $search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    if (!empty($search_query)) {
        $unified = array_filter($unified, function($item) use ($search_query) {
            return (stripos((string) $item['post_id'], $search_query) !== false || stripos((string) $item['lang'], $search_query) !== false);
        });
    }

    wp_send_json_success($unified);
});

add_action('wp_ajax_freedomtranslate_queue_start', function() {
    check_ajax_referer('freedomtranslate_queue_action', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    $hash_key = isset($_POST['hash_key']) ? sanitize_text_field(wp_unslash($_POST['hash_key'])) : '';
    if (empty($hash_key)) {
        wp_send_json_error('Invalid hash key');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    delete_transient('freedomtranslate_pause_' . $hash_key);
    delete_option('freedomtranslate_last_panic_time');

    $job = $wpdb->get_row($wpdb->prepare("SELECT post_id, target_lang FROM $table WHERE hash_key = %s AND status IN ('pending', 'timeout', 'paused') LIMIT 1", $hash_key));
    if ($job) {
        $wpdb->update($table, ['status' => 'pending'], ['hash_key' => $hash_key]);
        $site_lang = freedomtranslate_get_site_source_lang();

        freedomtranslate_remove_crons_by_hash($hash_key);
        wp_schedule_single_event(time(), 'freedomtranslate_async_translate', [$hash_key, $site_lang, $job->target_lang, (int) $job->post_id, uniqid('', true)]);

        spawn_cron();
        wp_remote_get(site_url('wp-cron.php?doing_wp_cron=' . sprintf('%.22F', microtime(true))), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
        ]);
    }
    wp_send_json_success();
});

add_action('wp_ajax_freedomtranslate_queue_pause', function() {
    check_ajax_referer('freedomtranslate_queue_action', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    $hash_key = isset($_POST['hash_key']) ? sanitize_text_field(wp_unslash($_POST['hash_key'])) : '';
    if (empty($hash_key)) {
        wp_send_json_error('Invalid hash key');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    set_transient('freedomtranslate_pause_' . $hash_key, '1', 600);
    $wpdb->update($table, ['status' => 'paused'], ['hash_key' => $hash_key]);

    freedomtranslate_remove_crons_by_hash($hash_key);
    wp_send_json_success();
});
add_action('wp_ajax_freedomtranslate_queue_cancel', function() {
    check_ajax_referer('freedomtranslate_queue_action', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    $hash_key = isset($_POST['hash_key']) ? sanitize_text_field(wp_unslash($_POST['hash_key'])) : '';
    if (empty($hash_key)) {
        wp_send_json_error('Invalid hash key');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'freedomtranslate_cache';

    freedomtranslate_cancel_remote_job($hash_key);
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE hash_key LIKE %s", $wpdb->esc_like($hash_key . '_chunk_') . '%'));
    $wpdb->delete($table, ['hash_key' => $hash_key]);

    freedomtranslate_remove_crons_by_hash($hash_key);
    wp_send_json_success();
});

add_action('wp_ajax_freedomtranslate_check_existing_translations', function() {
    check_ajax_referer('freedomtranslate_queue_action', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    $post_id_raw = isset($_POST['post_id']) ? sanitize_text_field(wp_unslash($_POST['post_id'])) : '';
    $langs       = isset($_POST['langs']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['langs'])) : [];
    $post_id     = freedomtranslate_get_clean_post_id($post_id_raw);

    $existing = false;
    if ($post_id > 0 && !empty($langs)) {
        global $wpdb;
        $table = $wpdb->prefix . 'freedomtranslate_cache';

        foreach ($langs as $lang) {
            $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
            $row    = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table WHERE hash_key = %s AND status = 'completed'", $c_hash));
            if ($row) {
                $existing = true;
                break;
            }
        }
    }

    wp_send_json(['exists' => $existing]);
});

// ========================================================================
// 8. MANUAL TRANSLATION EDITOR (METABOX & AJAX) - VISUAL EDITOR
// ========================================================================

add_action('add_meta_boxes', function() {
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $type) {
        add_meta_box(
            'ft_manual_translation_metabox',
            __('FreedomTranslate - Manual Editor', 'freedomtranslate-wp'),
            'freedomtranslate_manual_editor_html',
            $type,
            'normal',
            'high',
            [
                '__block_editor_compatible_meta_box' => true
            ]
        );
    }
});

function freedomtranslate_manual_editor_html($post) {
    $enabled_langs = get_option(FREEDOMTRANSLATE_LANGUAGES_OPTION, []);
    $site_lang     = freedomtranslate_get_site_source_lang();
    $all_langs     = freedomtranslate_get_all_languages();
    $nonce         = wp_create_nonce('freedomtranslate_manual_editor_nonce');
    ?>
    <div id="ft_manual_editor_container" style="margin-top: 10px;">
        <p>
            <label for="ft_manual_lang" style="font-weight: bold; margin-right: 10px;"><?php esc_html_e('Select Language to Edit:', 'freedomtranslate-wp'); ?></label>
            <select id="ft_manual_lang">
                <option value=""><?php echo esc_html(strtoupper($site_lang)); ?> (<?php esc_html_e('Default Language - Use main editor', 'freedomtranslate-wp'); ?>)</option>
                <?php
                if (is_array($enabled_langs)) {
                    foreach ($enabled_langs as $lang) {
                        if ($lang === $site_lang) continue;
                        $lang_name = isset($all_langs[$lang]) ? $all_langs[$lang] : strtoupper($lang);
                        echo '<option value="' . esc_attr($lang) . '">' . esc_html($lang_name) . '</option>';
                    }
                }
                ?>
            </select>
            <span id="ft_manual_loading" style="display:none; color: #2271b1; font-weight: bold; margin-left: 10px;">
                <span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> <?php esc_html_e('Fetching...', 'freedomtranslate-wp'); ?>
            </span>
        </p>

        <div id="ft_manual_fields" style="display:none; margin-top: 15px;">
            <div class="notice notice-warning inline" style="margin-bottom: 15px;">
                <p><strong><?php esc_html_e('Warning:', 'freedomtranslate-wp'); ?></strong> <?php esc_html_e('These edits are saved directly to the translation cache. If you trigger an automatic background translation for this language, these manual changes will be overwritten.', 'freedomtranslate-wp'); ?></p>
            </div>

            <p>
                <label for="ft_manual_title"><strong><?php esc_html_e('Translated Title:', 'freedomtranslate-wp'); ?></strong></label><br>
                <input type="text" id="ft_manual_title" style="width: 100%;" class="large-text">
            </p>

            <p>
                <label for="ft_manual_excerpt"><strong><?php esc_html_e('Translated Excerpt:', 'freedomtranslate-wp'); ?></strong></label><br>
                <textarea id="ft_manual_excerpt" style="width: 100%;" class="large-text" rows="3"></textarea>
            </p>

            <div style="margin-top: 15px;">
                <label><strong><?php esc_html_e('Translated Content:', 'freedomtranslate-wp'); ?></strong></label><br>
                <?php
                wp_editor('', 'ft_manual_content', [
                    'textarea_rows' => 12,
                    'media_buttons' => true,
                    'tinymce'       => true,
                    'quicktags'     => true,
                ]);
                ?>
            </div>

            <div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                <button type="button" id="ft_manual_save" class="button button-primary button-large"><?php esc_html_e('Save Manual Translation', 'freedomtranslate-wp'); ?></button>
                <span id="ft_manual_save_status" style="font-weight: bold;"></span>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const langSelect = document.getElementById('ft_manual_lang');
        const fieldsContainer = document.getElementById('ft_manual_fields');
        const loadingIndicator = document.getElementById('ft_manual_loading');
        const saveBtn = document.getElementById('ft_manual_save');
        const saveStatus = document.getElementById('ft_manual_save_status');

        const inputTitle = document.getElementById('ft_manual_title');
        const inputExcerpt = document.getElementById('ft_manual_excerpt');

        const postId = <?php echo intval($post->ID); ?>;
        const nonce = '<?php echo esc_js($nonce); ?>';

        function setEditorContent(content) {
            if (typeof tinymce !== 'undefined' && tinymce.get('ft_manual_content')) {
                tinymce.get('ft_manual_content').setContent(content);
            }
            document.getElementById('ft_manual_content').value = content;
        }

        function getEditorContent() {
            if (typeof tinymce !== 'undefined' && tinymce.get('ft_manual_content') && !tinymce.get('ft_manual_content').isHidden()) {
                return tinymce.get('ft_manual_content').getContent();
            }
            return document.getElementById('ft_manual_content').value;
        }

        langSelect.addEventListener('change', function() {
            const selectedLang = this.value;
            saveStatus.textContent = '';

            if (!selectedLang) {
                fieldsContainer.style.display = 'none';
                return;
            }

            fieldsContainer.style.display = 'none';
            loadingIndicator.style.display = 'inline-block';

            const formData = new FormData();
            formData.append('action', 'freedomtranslate_get_manual_translation');
            formData.append('nonce', nonce);
            formData.append('post_id', postId);
            formData.append('lang', selectedLang);

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    loadingIndicator.style.display = 'none';
                    if (res.success) {
                        inputTitle.value = res.data.title || '';
                        inputExcerpt.value = res.data.excerpt || '';
                        setEditorContent(res.data.content || '');
                        fieldsContainer.style.display = 'block';
                    } else {
                        alert('<?php echo esc_js(__('Error fetching translation.', 'freedomtranslate-wp')); ?>');
                    }
                })
                .catch(() => {
                    loadingIndicator.style.display = 'none';
                    alert('<?php echo esc_js(__('Network error while fetching.', 'freedomtranslate-wp')); ?>');
                });
        });

        saveBtn.addEventListener('click', function() {
            const selectedLang = langSelect.value;
            if (!selectedLang) return;

            saveBtn.disabled = true;
            saveStatus.textContent = '<?php echo esc_js(__('Saving...', 'freedomtranslate-wp')); ?>';
            saveStatus.style.color = '#2271b1';

            const currentContent = getEditorContent();

            const formData = new FormData();
            formData.append('action', 'freedomtranslate_save_manual_translation');
            formData.append('nonce', nonce);
            formData.append('post_id', postId);
            formData.append('lang', selectedLang);
            formData.append('title', inputTitle.value);
            formData.append('excerpt', inputExcerpt.value);
            formData.append('content', currentContent);

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    saveBtn.disabled = false;
                    if (res.success) {
                        saveStatus.textContent = '<?php echo esc_js(__('Saved successfully!', 'freedomtranslate-wp')); ?>';
                        saveStatus.style.color = '#27ae60';
                        setTimeout(() => { saveStatus.textContent = ''; }, 3000);
                    } else {
                        saveStatus.textContent = '<?php echo esc_js(__('Error saving.', 'freedomtranslate-wp')); ?>';
                        saveStatus.style.color = '#d63638';
                    }
                })
                .catch(() => {
                    saveBtn.disabled = false;
                    saveStatus.textContent = '<?php echo esc_js(__('Network error.', 'freedomtranslate-wp')); ?>';
                    saveStatus.style.color = '#d63638';
                });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_freedomtranslate_get_manual_translation', function() {
    check_ajax_referer('freedomtranslate_manual_editor_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Permission denied', 403);
    }

    $lang = isset($_POST['lang']) ? sanitize_text_field(wp_unslash($_POST['lang'])) : '';
    if (empty($lang) || !freedomtranslate_is_language_enabled($lang)) {
        wp_send_json_error('Invalid language', 400);
    }

    // Reconstruct the exact hashes used by the translation engine
    $t_hash = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
    $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
    $e_hash = md5("post_{$post_id}_the_excerpt_{$lang}") . '_the_excerpt';

    // Retrieve data from DB (returns false if missing/expired)
    $title   = freedomtranslate_get_cache($t_hash);
    $content = freedomtranslate_get_cache($c_hash);
    $excerpt = freedomtranslate_get_cache($e_hash);

    wp_send_json_success([
        'title'   => $title !== false ? $title : '',
        'content' => $content !== false ? $content : '',
        'excerpt' => $excerpt !== false ? $excerpt : ''
    ]);
});

/**
 * AJAX Handler: Save edited translation strings back to the database.
 */
add_action('wp_ajax_freedomtranslate_save_manual_translation', function() {
    check_ajax_referer('freedomtranslate_manual_editor_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    // Security check: ensure user has permission to edit this post
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Permission denied', 403);
    }

    $lang = isset($_POST['lang']) ? sanitize_text_field(wp_unslash($_POST['lang'])) : '';
    if (empty($lang) || !freedomtranslate_is_language_enabled($lang)) {
        wp_send_json_error('Invalid language', 400);
    }

    // Sanitize user inputs based on field type and user capabilities
    $title       = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $excerpt     = isset($_POST['excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['excerpt'])) : '';
    $content_raw = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
    $content     = current_user_can('unfiltered_html') ? $content_raw : wp_kses_post($content_raw);

    // Reconstruct the exact hashes
    $t_hash = md5("post_{$post_id}_the_title_{$lang}") . '_the_title';
    $c_hash = md5("post_{$post_id}_the_content_{$lang}") . '_the_content';
    $e_hash = md5("post_{$post_id}_the_excerpt_{$lang}") . '_the_excerpt';

    // Calculate dynamic TTL based on plugin settings or post meta
    $ttl_days    = freedomtranslate_get_ttl_days($post_id);
    $ttl_seconds = $ttl_days > 0 ? (DAY_IN_SECONDS * $ttl_days) : 0;

    // Inject manual edits directly into custom DB, forcing status to 'completed'
    freedomtranslate_set_cache($t_hash, $title, $post_id, $lang, $ttl_seconds, 'completed');
    freedomtranslate_set_cache($c_hash, $content, $post_id, $lang, $ttl_seconds, 'completed');
    freedomtranslate_set_cache($e_hash, $excerpt, $post_id, $lang, $ttl_seconds, 'completed');

    wp_send_json_success();
});

// ========================================================================
// RECOVERY: METABOX FOR EXCLUDING POSTS
// ========================================================================

add_action('add_meta_boxes', function() {
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $type) {
        add_meta_box(
            'ft_exclude_metabox',
            __('FreedomTranslate Options', 'freedomtranslate-wp'),
            'freedomtranslate_exclude_metabox_html',
            $type,
            'side',
            'default',
            [
                '__block_editor_compatible_meta_box' => true
            ]
        );
    }
});

function freedomtranslate_exclude_metabox_html($post) {
    $exclude_val = get_post_meta($post->ID, '_freedomtranslate_exclude', true);
    $inject_val  = get_post_meta($post->ID, '_freedomtranslate_inject_selector', true);

    wp_nonce_field('ft_meta_save', 'ft_meta_nonce');
    ?>
    <p>
        <label>
            <input type="checkbox" name="ft_exclude_post" value="1" <?php checked($exclude_val, '1'); ?>>
            <strong><?php esc_html_e('Exclude from translation', 'freedomtranslate-wp'); ?></strong>
        </label>
    </p>
    <p class="description"><?php esc_html_e('If active, the plugin will not show the translations.', 'freedomtranslate-wp'); ?></p>
    <hr>
    <p>
        <label>
            <input type="checkbox" name="ft_inject_selector" value="1" <?php checked($inject_val, '1'); ?>>
            <strong><?php esc_html_e('Show language selector', 'freedomtranslate-wp'); ?></strong>
        </label>
    </p>
    <?php
}
