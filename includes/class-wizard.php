<?php
/**
 * Dialoga Getting Started Wizard
 *
 * Full-screen onboarding wizard that walks new users through
 * creating their first chatbot flow.
 */

if (!defined('ABSPATH')) exit;

class Dialoga_Wizard {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_wizard_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        // Wizard state AJAX
        add_action('wp_ajax_dialoga_wizard_save_state', array(__CLASS__, 'ajax_save_state'));
        add_action('wp_ajax_dialoga_wizard_get_state', array(__CLASS__, 'ajax_get_state'));
        add_action('wp_ajax_dialoga_wizard_complete', array(__CLASS__, 'ajax_complete'));
        add_action('wp_ajax_dialoga_wizard_skip', array(__CLASS__, 'ajax_skip'));
        add_action('wp_ajax_dialoga_wizard_reset', array(__CLASS__, 'ajax_reset'));
        add_action('wp_ajax_dialoga_wizard_dismiss_state', array(__CLASS__, 'ajax_dismiss_state'));
    }

    /**
     * AJAX: Dismiss the Resume-Setup card from the dashboard without marking
     * the wizard as fully skipped. Clears the saved state so the card stops
     * appearing, but leaves the wizard page reachable via the builder sidebar
     * or direct URL in case the admin changes their mind.
     */
    public static function ajax_dismiss_state() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        delete_user_meta(get_current_user_id(), 'dialoga_wizard_state');
        wp_send_json_success();
    }

    /**
     * Add hidden wizard page (no menu parent).
     */
    public static function add_wizard_page() {
        add_submenu_page(
            null,
            esc_html__('Getting Started - Dialoga', 'dialoga'),
            esc_html__('Setup Wizard', 'dialoga'),
            'manage_options',
            'dialoga-wizard',
            array(__CLASS__, 'render_wizard')
        );
    }

    /**
     * Redirect to wizard on first visit to any Dialoga page after activation.
     */
    public static function maybe_redirect() {
        if (!current_user_can('manage_options')) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only admin screen routing (GET page param), no form data processed.
        $page = sanitize_key($_GET['page'] ?? '');

        // Only trigger on Dialoga pages (not the wizard itself)
        $dialoga_pages = array('dialoga-builder', 'dialoga-analytics', 'dialoga-leads', 'dialoga-settings', 'dialoga-ai-settings');
        if (!in_array($page, $dialoga_pages)) return;

        // Check if this is the first time visiting after activation
        $first_run = get_option('dialoga_wizard_first_run', false);
        if (!$first_run) return;

        $user_id = get_current_user_id();

        // Don't redirect if wizard was completed or skipped
        if (get_user_meta($user_id, 'dialoga_wizard_completed', true)) return;
        if (get_user_meta($user_id, 'dialoga_wizard_skipped', true)) return;

        // Clear the first run flag so it only redirects once
        delete_option('dialoga_wizard_first_run');

        wp_safe_redirect(admin_url('admin.php?page=dialoga-wizard'));
        exit;
    }

    /**
     * Enqueue wizard assets only on the wizard page.
     */
    public static function enqueue_assets($hook) {
        if (strpos($hook, 'dialoga-wizard') === false) return;

        wp_enqueue_style(
            'dialoga-wizard-css',
            DIALOGA_URL . 'assets/css/wizard.css',
            array(),
            DIALOGA_VERSION
        );

        // v1.0.0 — Removed the Google Fonts stylesheet. wp.org guideline 7
        // prohibits phoning home / loading third-party resources without
        // explicit opt-in. Wizard now uses the system font stack defined in
        // wizard.css (falls back to -apple-system / Segoe UI / Roboto).

        wp_enqueue_script('dialoga-modal', DIALOGA_URL . 'assets/js/modal.js', array(), DIALOGA_VERSION, true);

        wp_enqueue_script(
            'dialoga-wizard-js',
            DIALOGA_URL . 'assets/js/wizard.js',
            array('dialoga-modal'),
            DIALOGA_VERSION,
            true
        );

        wp_localize_script('dialoga-wizard-js', 'dialogaWizard', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('dialoga_nonce'),
            'adminUrl'   => admin_url(),
            'builderUrl' => admin_url('admin.php?page=dialoga-builder'),
            'siteUrl'    => home_url(),
            'siteName'   => get_bloginfo('name'),
            'isPro'      => dialoga_is_pro(),
            'logoUrl'    => DIALOGA_LOGO_URL,
            'i18n'       => Dialoga_I18n::script_messages(),
        ));
    }

    /**
     * Render the wizard page.
     */
    public static function render_wizard() {
        include DIALOGA_PATH . 'templates/admin/wizard.php';
    }

    /**
     * AJAX: Save wizard state to user meta.
     */
    public static function ajax_save_state() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        $raw_data = isset($_POST['data']) ? sanitize_textarea_field(wp_unslash($_POST['data'])) : '{}';
        $decoded  = json_decode($raw_data, true);
        if (!is_array($decoded)) $decoded = array();

        $state = array(
            'version'     => 1,
            'currentStep' => intval($_POST['currentStep'] ?? 0),
            'data'        => dialoga_sanitize_json($decoded),
            'updated_at'  => current_time('mysql'),
        );

        update_user_meta(get_current_user_id(), 'dialoga_wizard_state', $state);
        wp_send_json_success(array('message' => __('State saved.', 'dialoga')));
    }

    /**
     * AJAX: Get saved wizard state.
     */
    public static function ajax_get_state() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        $state = get_user_meta(get_current_user_id(), 'dialoga_wizard_state', true);

        // Also check if AI settings already exist
        $ai_settings = get_option('dialoga_ai_settings', array());
        $has_api_key = !empty($ai_settings['api_key']);

        // Check if knowledge base has items
        global $wpdb;
        $kb_count = (int) dialoga_query_cached('wizard_kb_count', static function () {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $wpdb->prefix . 'dialoga_ai_knowledge'));
        }, 300);

        wp_send_json_success(array(
            'state'      => $state ?: null,
            'hasApiKey'  => $has_api_key,
            'kbCount'    => intval($kb_count),
            'aiProvider' => $ai_settings['provider'] ?? '',
            'aiModel'    => $ai_settings['model'] ?? '',
        ));
    }

    /**
     * AJAX: Mark wizard as completed.
     */
    public static function ajax_complete() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        $user_id = get_current_user_id();
        update_user_meta($user_id, 'dialoga_wizard_completed', 1);
        delete_user_meta($user_id, 'dialoga_wizard_state');

        $flow_id = intval($_POST['flow_id'] ?? 0);
        $redirect = admin_url('admin.php?page=dialoga-builder');
        if ($flow_id) {
            $redirect .= '&flow_id=' . $flow_id;
        }

        wp_send_json_success(array('redirect' => $redirect));
    }

    /**
     * AJAX: Skip the wizard.
     */
    public static function ajax_skip() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        $user_id = get_current_user_id();
        update_user_meta($user_id, 'dialoga_wizard_skipped', 1);
        delete_user_meta($user_id, 'dialoga_wizard_state');

        wp_send_json_success(array('redirect' => admin_url('admin.php?page=dialoga-builder')));
    }

    /**
     * AJAX: Reset wizard (re-trigger from settings).
     */
    public static function ajax_reset() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'dialoga'));

        $user_id = get_current_user_id();
        delete_user_meta($user_id, 'dialoga_wizard_completed');
        delete_user_meta($user_id, 'dialoga_wizard_skipped');
        delete_user_meta($user_id, 'dialoga_wizard_state');

        wp_send_json_success(array('redirect' => admin_url('admin.php?page=dialoga-wizard')));
    }
}
