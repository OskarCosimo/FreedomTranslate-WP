<?php
// Exit if accessed directly or if not uninstalling via WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// list of saved options
$plugin_options = [
    'freedomtranslate_enabled_languages',
    'freedomtranslate_default_language',
    'freedomtranslate_exclude_words',
    'freedomtranslate_api_url',
    'freedomtranslate_api_key',
];

// remove all the saved options
foreach ( $plugin_options as $option ) {
    delete_option( $option );
}

// Remove all the cache of freedomtranslate
global $wpdb;
$prefix = 'freedomtranslate_cache_';
$prefix_esc = esc_sql( $prefix );
$option_names = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $prefix_esc . '%'
    )
);

if ( ! empty( $option_names ) ) {
    foreach ( $option_names as $option_name ) {
        delete_option( $option_name );
    }
}
?>