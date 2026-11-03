<?php
/**
 * Uninstall DIALOGA
 *
 * Fired when the plugin is uninstalled (deleted, not just deactivated).
 *
 * v0.39.6 — DATA PRESERVATION BY DEFAULT.
 * Prior to this, uninstall dropped all 4 Dialoga tables (flows, conversations,
 * leads, analytics) unconditionally. That's a data-loss trap during routine
 * plugin updates: uploading a new zip via WP Admin's "Delete → Upload → Activate"
 * workflow triggers this file and destroys real business data (flows painstakingly
 * built in the visual builder, thousands of captured leads, months of analytics).
 *
 * New behavior: this file NO-OPS unless the admin has explicitly opted in via
 * the setting `dialoga_delete_data_on_uninstall = 1`. That option is off by
 * default; admins who genuinely want a clean wipe (dev cleanup, site
 * migration, GDPR total-erasure request) can flip it in Dialoga → Settings →
 * Advanced before deleting the plugin.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// v0.39.6: guard rail — preserve all Dialoga data unless the admin has
// explicitly opted in. This makes the "Deactivate → Delete → Upload → Activate"
// update workflow safe by default.
$settings = get_option('dialoga_settings', array());
$opt_in   = isset($settings['delete_data_on_uninstall']) && (int) $settings['delete_data_on_uninstall'] === 1;

if (!$opt_in) {
    // Bail out — leave every table, option, and transient intact so the next
    // reinstall/activate finds all flows, leads, conversations, and analytics
    // exactly as they were.
    return;
}

global $wpdb;

// Drop all DIALOGA tables — ONLY reached when the admin opted in above.
$tables = array(
    $wpdb->prefix . 'dialoga_flows',
    $wpdb->prefix . 'dialoga_conversations',
    $wpdb->prefix . 'dialoga_leads',
    $wpdb->prefix . 'dialoga_analytics'
);

foreach ($tables as $table) {
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
}

// Delete all plugin options
delete_option('dialoga_settings');
delete_option('dialoga_version');
delete_option('dialoga_pro_tracking_options'); // Pro settings, if present

// Clear any transients
delete_transient('dialoga_cache');
