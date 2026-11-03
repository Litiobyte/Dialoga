<?php
/**
 * Plugin Name: Dialoga - Chatbot Flows Made Easy, With or Without AI
 * Plugin URI: https://www.litiobyte.com/labs-detalle.php?slug=dialoga
 * Description: Visual chatbot flow builder for WordPress with easy conversational flows, with or without AI.
 * Version: 1.0.0
 * Author: Litiobyte SpA
 * Author URI: https://www.litiobyte.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dialoga
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache an expensive scalar/value read so Plugin Check's DirectDB.NoCaching
 * "discouraged but direct" queries stay cheap on repeat admin page loads.
 *
 * @param string   $key Cache key; namespaced under the 'dialoga' group.
 * @param callable $cb  Callback that returns the uncached value.
 * @param int      $ttl Object-cache lifetime in seconds (default 60).
 * @return mixed
 */
function dialoga_query_cached($key, $cb, $ttl = 60) {
    $cache_key = 'dialoga_q_' . $key;
    $cached = wp_cache_get($cache_key, 'dialoga');
    if (false !== $cached && null !== $cached) {
        return $cached;
    }
    $value = $cb();
    wp_cache_set($cache_key, $value, 'dialoga', $ttl);
    return $value;
}

// Plugin constants
define('DIALOGA_VERSION', '1.0.0');
define('DIALOGA_PATH', plugin_dir_path(__FILE__));
define('DIALOGA_URL', plugin_dir_url(__FILE__));
// v1.0.0 — use the bundled logo asset. Was previously hardcoded to a local
// dev URL which Plugin Check flags as a wp.org blocker (Localhost.Found).
define('DIALOGA_LOGO_URL', DIALOGA_URL . 'assets/images/dialoga-logo.png');

/**
 * Check if current install has an active Pro license.
 * Returns false on the free tier. A Pro add-on/license plugin
 * can override this via the filter.
 */
function dialoga_is_pro() {
    return (bool) apply_filters('dialoga_is_pro', false);
}

/**
 * Canonical URL for "Upgrade to Pro" CTAs. Use this everywhere
 * (readme, wizard, dashboard, settings, analytics) so we can change
 * the destination in one place.
 */
function dialoga_upgrade_url() {
    return apply_filters('dialoga_upgrade_url', 'https://www.litiobyte.com/labs-detalle.php?slug=dialoga');
}

/**
 * Recursively sanitize a decoded JSON payload. String values pass through
 * sanitize_text_field(); scalars pass through unchanged; arrays recurse.
 * Use after json_decode() on any $_POST payload we accept from admin flows,
 * chat widget history, and the flow builder.
 */
function dialoga_sanitize_json($value) {
    if (is_array($value)) {
        $out = array();
        foreach ($value as $k => $v) {
            $out[dialoga_sanitize_json_key($k)] = dialoga_sanitize_json($v);
        }
        return $out;
    }
    if (is_string($value)) {
        /*
         * v1.0.4 — was sanitize_text_field(), which collapses every newline
         * into a space. Chat messages are authored as multi-line text in the
         * builder, so that silently reflowed every message the moment a flow
         * was re-saved. sanitize_textarea_field() strips the same tags and
         * control characters but keeps line breaks.
         */
        return sanitize_textarea_field($value);
    }
    // bool / int / float / null pass through unchanged
    return $value;
}

/**
 * Sanitize one JSON object key WITHOUT changing its case.
 *
 * v1.0.4 — this used to be plain sanitize_key(), and that was a data-loss bug.
 * sanitize_key() lowercases, so every camelCase key in a saved flow was
 * renamed on write: nodeType -> nodetype, sourceHandle -> sourcehandle,
 * targetHandle -> targethandle. The frontend reads $node['data']['nodeType'],
 * found nothing, fell through format_node_response()'s default branch and
 * served the visitor "Configuration error. Please contact support." as the
 * very first chat message. Every flow saved under v1.0.0-v1.0.3 is affected;
 * Dialoga_Database::repair_flow_key_case() fixes the rows already on disk.
 *
 * Keys are structural, not user copy, so the safe treatment is to strip
 * anything outside the identifier character set rather than normalise case.
 */
function dialoga_sanitize_json_key($key) {
    if (is_int($key)) {
        return $key;
    }
    return preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
}

/**
 * Debug-only logger.
 *
 * v1.0.5 — the branch evaluator used to call error_log() unconditionally on
 * every condition it tested, which meant a production site wrote a dozen trace
 * lines per visitor per branch node, including the visitor's own typed answer.
 * Trace output belongs behind WP_DEBUG; genuine failures (API errors, failed
 * DB writes) still log unconditionally, because those are worth seeing.
 */
function dialoga_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Dialoga] ' . $message);
    }
}

// Include core classes
require_once DIALOGA_PATH . 'includes/class-database.php';
require_once DIALOGA_PATH . 'includes/class-flow-engine.php';
require_once DIALOGA_PATH . 'includes/class-flow-transfer.php';
require_once DIALOGA_PATH . 'includes/class-analytics.php';
require_once DIALOGA_PATH . 'includes/class-frontend.php';
require_once DIALOGA_PATH . 'includes/class-ai.php';
require_once DIALOGA_PATH . 'includes/class-wizard.php';
require_once DIALOGA_PATH . 'includes/class-review-nudge.php';
require_once DIALOGA_PATH . 'includes/class-i18n.php';
add_action('init', array('Dialoga_I18n', 'init'), 0);

// Initialize frontend and AI
add_action('init', array('Dialoga_Frontend', 'init'));
add_action('init', array('Dialoga_AI', 'init'));
add_action('init', array('Dialoga_Flow_Transfer', 'init'));
add_action('init', array('Dialoga_Wizard', 'init'));

// v0.41.1 — WP.org review nudge (admin-only, guarded by milestones)
if (is_admin()) {
    Dialoga_Review_Nudge::init();
}

/**
 * Main DIALOGA Class
 */
class Dialoga {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Add "Run Setup Wizard" link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

        // Auto-run table creation on version change (handles existing installs)
        add_action('plugins_loaded', array($this, 'maybe_upgrade'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        // Analytics is registered at priority 15 so it appears just before the Pro "Advanced Analytics" entry (priority 20)
        add_action('admin_menu', array($this, 'add_analytics_menu'), 15);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Handle CSV export before any HTML is output
        add_action('admin_init', array($this, 'maybe_export_leads_csv'));

        // AJAX handlers
        add_action('wp_ajax_dialoga_save_flow', array($this, 'ajax_save_flow'));
        add_action('wp_ajax_dialoga_get_flows', array($this, 'ajax_get_flows'));
        add_action('wp_ajax_dialoga_delete_flow', array($this, 'ajax_delete_flow'));
    }

    public function maybe_upgrade() {
        $installed_version = get_option('dialoga_db_version', '0');
        if (version_compare($installed_version, DIALOGA_VERSION, '<')) {
            Dialoga_Database::create_tables();
            Dialoga_Database::migrate();

            // v1.0.4 — one-shot repair for flows whose camelCase JSON keys
            // were lowercased on save by v1.0.0-v1.0.3. See the method docblock.
            if (version_compare($installed_version, '1.0.4', '<')) {
                Dialoga_Database::repair_flow_key_case();
            }

            update_option('dialoga_db_version', DIALOGA_VERSION);
        }
    }
    
    public function activate() {
        Dialoga_Database::create_tables();
        Dialoga_Database::migrate(); // Run migration for existing tables
        
        // Create default settings if they don't exist
        $settings = get_option('dialoga_settings');
        if (!$settings) {
            $default_settings = array(
                'enabled' => true, // Enable by default so users can test immediately
                'bubble_nudge' => 1, // Animate the bubble to draw attention by default
                'fresh_session_roles' => array('administrator'),
                'visibility_mode' => 'everyone', // Changed from specific_roles - now per-flow
                'visible_roles' => array('administrator'),
                'primary_color' => '#190F4F',
                'chat_position' => 'bottom-right',
                'session_duration' => 7,
                'chat_header_title' => 'Chat with us',
                'welcome_message' => 'Hi! How can we help you today?',
                'offline_message' => 'We\'re currently offline. Please leave your contact information and we\'ll get back to you.',
                'business_hours' => array(
                    'enabled' => false,
                    'timezone' => 'America/New_York'
                )
            );
            update_option('dialoga_settings', $default_settings);
        }
        
        flush_rewrite_rules();
    }

    public function add_action_links($links) {
        $wizard_link = '<a href="' . esc_url(admin_url('admin.php?page=dialoga-wizard')) . '" style="font-weight:600;">' . esc_html__('Run Setup Wizard', 'dialoga') . '</a>';
        array_unshift($links, $wizard_link);
        return $links;
    }

    public function deactivate() {
        flush_rewrite_rules();
        // Unschedule the daily retention crons so they don't fire after deactivation
        foreach (array('dialoga_lead_retention_cleanup', 'dialoga_conversation_retention_cleanup') as $hook) {
            $next = wp_next_scheduled($hook);
            if ($next) wp_unschedule_event($next, $hook);
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Dialoga',
            'Dialoga',
            'manage_options',
            'Dialoga',
            array($this, 'render_dashboard'),
            'dashicons-format-chat',
            30
        );
        
        add_submenu_page(
            'Dialoga',
            __('Dashboard', 'dialoga'),
            __('Dashboard', 'dialoga'),
            'manage_options',
            'Dialoga',
            array($this, 'render_dashboard')
        );
        
        add_submenu_page(
            'Dialoga',
            __('Flows', 'dialoga'),
            __('Flows', 'dialoga'),
            'manage_options',
            'dialoga-builder',
            array($this, 'render_builder')
        );
        
        add_submenu_page(
            'Dialoga',
            __('Leads', 'dialoga'),
            __('Leads', 'dialoga'),
            'manage_options',
            'dialoga-leads',
            array($this, 'render_leads')
        );
        
        add_submenu_page(
            'Dialoga',
            __('Analytics', 'dialoga'),
            __('Analytics', 'dialoga'),
            'manage_options',
            'dialoga-analytics',
            array($this, 'render_analytics')
        );

        add_submenu_page(
            'Dialoga',
            __('AI Settings', 'dialoga'),
            __('AI Settings', 'dialoga'),
            'manage_options',
            'dialoga-ai-settings',
            array($this, 'render_ai_settings')
        );

        add_submenu_page(
            'Dialoga',
            __('Settings', 'dialoga'),
            __('Settings', 'dialoga'),
            'manage_options',
            'dialoga-settings',
            array($this, 'render_settings')
        );
    }

    public function add_analytics_menu() {
        // Analytics is registered with the primary submenu sequence above.
    }
    
    public function enqueue_admin_assets($hook) {
        if (stripos($hook, 'dialoga') === false) {
            return;
        }

        $dialoga_screen_key = strtolower($hook);
        $dialoga_is_builder = 'dialoga_page_dialoga-builder' === $dialoga_screen_key;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen routing (GET page param), no form data processed.
        $dialoga_builder_flow_id = $dialoga_is_builder && isset($_GET['flow_id']) ? absint(wp_unslash($_GET['flow_id'])) : 0;
        $dialoga_is_builder_editor = false;

        if ($dialoga_is_builder) {
            global $wpdb;
        }

        if ($dialoga_builder_flow_id) {
            $dialoga_is_builder_editor = (bool) dialoga_query_cached('builder_flow_' . $dialoga_builder_flow_id, static function () use ($dialoga_builder_flow_id) {
                global $wpdb;
                return (bool) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM %i WHERE id = %d",
                        $wpdb->prefix . 'dialoga_flows',
                        $dialoga_builder_flow_id
                    )
                );
            }, 120);
        }
        
        // Dialoga Modal (global — used by all admin JS)
        wp_enqueue_script('dialoga-modal', DIALOGA_URL . 'assets/js/modal.js', array(), DIALOGA_VERSION, true);

        $dialoga_admin_dependencies = array('jquery', 'dialoga-modal');
        $dialoga_use_xyflow = false;

        if ($dialoga_is_builder_editor) {
            // Temporary development switch. Remove with the legacy canvas before release.
            $dialoga_use_xyflow = (bool) apply_filters('dialoga_use_xyflow_canvas', true);
            $dialoga_canvas_file = $dialoga_use_xyflow
                ? 'assets/js/build/flow-canvas.js'
                : 'assets/js/flow-builder-react.js';

            // React and ReactDOM are provided and patched by WordPress core.
            wp_enqueue_script(
                'dialoga-react',
                DIALOGA_URL . $dialoga_canvas_file,
                array('react', 'react-dom'),
                DIALOGA_VERSION,
                true
            );
            $dialoga_admin_dependencies[] = 'dialoga-react';

        }
        
        // Enqueue admin JS
        wp_enqueue_script(
            'dialoga-admin',
            DIALOGA_URL . 'assets/js/admin.js',
            $dialoga_admin_dependencies,
            (string) filemtime(DIALOGA_PATH . 'assets/js/admin.js'),
            true
        );
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'dialoga-admin',
            DIALOGA_URL . 'assets/css/admin.css',
            array(),
            DIALOGA_VERSION
        );

        if ($dialoga_is_builder_editor && $dialoga_use_xyflow) {
            wp_enqueue_style(
                'dialoga-flow-canvas',
                DIALOGA_URL . 'assets/js/build/flow-canvas.css',
                array('dialoga-admin'),
                DIALOGA_VERSION
            );
        }

        /*
         * v1.0.4 — per-screen admin CSS.
         *
         * These used to be nowdoc blocks inside the page templates, handed to
         * wp_add_inline_style('dialoga-admin', ...). A page template runs while
         * the BODY is rendering, long after admin-header.php printed the head
         * styles and pushed 'dialoga-admin' into WP_Styles::$done. do_items()
         * skips anything already done, so print_late_styles() never emitted
         * them and every Dialoga admin screen rendered with no styling at all —
         * most visibly the flow-builder palette, whose colour-coded node
         * buttons fell back to the browser's default grey button chrome.
         *
         * Real stylesheets enqueued here instead: fixes the bug and is what
         * wp.org wanted in the first place.
         */
        $dialoga_screen_styles = array(
            'toplevel_page_dialoga'           => 'screen-dashboard.css',
            'dialoga_page_dialoga-builder'     => 'screen-builder.css',
            'dialoga_page_dialoga-leads'       => 'screen-leads.css',
            'dialoga_page_dialoga-ai-settings' => 'screen-ai-settings.css',
            'dialoga_page_dialoga-analytics'   => 'screen-analytics.css',
            'dialoga_page_dialoga-settings'    => 'screen-settings.css',
        );

        // The top-level menu slug is 'Dialoga', so the hook suffix keeps that
        // capital D. Match case-insensitively rather than relying on it.
        if (isset($dialoga_screen_styles[$dialoga_screen_key])) {
            $dialoga_screen_file = $dialoga_screen_styles[$dialoga_screen_key];
            wp_enqueue_style(
                'dialoga-screen-' . basename($dialoga_screen_file, '.css'),
                DIALOGA_URL . 'assets/css/' . $dialoga_screen_file,
                array('dialoga-admin'),
                (string) filemtime(DIALOGA_PATH . 'assets/css/' . $dialoga_screen_file)
            );
        }
        
        // Localize script for AJAX
        $dialoga_admin_data = array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'adminUrl' => admin_url(''),
            'nonce'    => wp_create_nonce('dialoga_nonce'),
            'logoUrl'  => DIALOGA_LOGO_URL,
            'isPro'    => dialoga_is_pro(),
            'debug'    => defined('WP_DEBUG') && WP_DEBUG,
            'i18n'     => Dialoga_I18n::script_messages(),
        );

        if ($dialoga_is_builder) {
            $dialoga_admin_data['builderMode'] = $dialoga_is_builder_editor ? 'editor' : 'list';
            $dialoga_admin_data['builderUrl'] = admin_url('admin.php?page=dialoga-builder');
            $dialoga_admin_data['flowId'] = $dialoga_builder_flow_id;
            $dialoga_admin_data['flowCount'] = (int) dialoga_query_cached('admin_flow_count', static function () {
                global $wpdb;
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $wpdb->prefix . 'dialoga_flows'));
            }, 120);
        }

        wp_localize_script('dialoga-admin', 'dialogaAdmin', $dialoga_admin_data);

        if ($dialoga_screen_key === 'dialoga_page_dialoga-settings') {
            wp_enqueue_script('dialoga-settings', DIALOGA_URL . 'assets/js/settings.js', array('dialoga-admin'), DIALOGA_VERSION, true);
        }

        // v1.0.1 — flow-builder HTML templates. Previously emitted as
        // <script type="text/template"> in builder.php; now injected via
        // wp_localize_script for wp.org compliance. Loaded only on the
        // builder screen since that's the only page admin.js uses them.
        if ($hook === 'dialoga_page_dialoga-builder') {
            wp_enqueue_script('dialoga-flow-transfer', DIALOGA_URL . 'assets/js/flow-transfer.js', array('dialoga-admin', 'dialoga-modal'), (string) filemtime(DIALOGA_PATH . 'assets/js/flow-transfer.js'), true);
            if ($dialoga_is_builder_editor) {
                require_once DIALOGA_PATH . 'templates/admin/builder-templates.php';
                // Unlike wp_localize_script, JSON transport preserves context-specific HTML escaping.
                wp_add_inline_script('dialoga-admin', 'window.dialogaTemplates = ' . wp_json_encode(dialoga_builder_templates(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
            }
        }

        // Chart.js + analytics charts — loaded only on the free Analytics page
        if ($hook === 'dialoga_page_dialoga-analytics') {
            wp_enqueue_script(
                'dialoga-chartjs',
                DIALOGA_URL . 'assets/js/vendor/chart.umd.min.js',
                array(),
                '4.5.1',
                true
            );
            wp_enqueue_script(
                'dialoga-analytics-charts',
                DIALOGA_URL . 'assets/js/analytics-charts.js',
                array('dialoga-chartjs'),
                DIALOGA_VERSION,
                true
            );
        }
    }
    
    public function maybe_export_leads_csv() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'dialoga-leads') return;
        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') return;
        if (!current_user_can('manage_options')) return;

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'dialoga_export_leads')) {
            wp_die(esc_html__('Security check failed. Please refresh the Leads page and try the export again.', 'dialoga'), 403);
        }

        global $wpdb;
        $leads_table = $wpdb->prefix . 'dialoga_leads';
        $tag_filter  = isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '';

        if ($tag_filter) {
            $tag_like = '%' . $wpdb->esc_like('"' . $tag_filter . '"') . '%';
            $leads = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i WHERE tags LIKE %s ORDER BY created_at DESC LIMIT 200",
                $leads_table,
                $tag_like
            ));
        } else {
            $leads = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i ORDER BY created_at DESC LIMIT 200",
                $leads_table
            ));
        }

        // This handler streams a CSV download and then exits, so any buffer
        // WordPress or another plugin opened earlier must be discarded before
        // the file headers go out. Dialoga opens no buffers of its own — the
        // loop below only closes pre-existing ones.
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dialoga-leads-' . gmdate('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Name', 'Email', 'Phone', 'Status', 'Tags', 'Created Date'));
        foreach ($leads as $lead) {
            $tags_arr = $lead->tags ? json_decode($lead->tags, true) : array();
            fputcsv($output, array(
                $lead->id,
                $lead->name,
                $lead->email,
                $lead->phone,
                $lead->status,
                is_array($tags_arr) ? implode(', ', $tags_arr) : '',
                $lead->created_at
            ));
        }
        // WP_Filesystem doesn't support streaming to php://output for CSV downloads;
        // fclose() on an fopen('php://output') stream is the canonical pattern here.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
        exit;
    }

    public function render_dashboard() {
        require_once DIALOGA_PATH . 'templates/admin/dashboard.php';
    }
    
    public function render_builder() {
        require_once DIALOGA_PATH . 'templates/admin/builder.php';
    }
    
    public function render_analytics() {
        require_once DIALOGA_PATH . 'templates/admin/analytics.php';
    }
    
    public function render_leads() {
        require_once DIALOGA_PATH . 'templates/admin/leads.php';
    }
    
    public function render_settings() {
        require_once DIALOGA_PATH . 'templates/admin/settings.php';
    }

    public function render_ai_settings() {
        require_once DIALOGA_PATH . 'templates/admin/ai-settings.php';
    }

    public function ajax_save_flow() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_flows';
        
        $flow_id         = isset($_POST['flow_id'])         ? intval($_POST['flow_id'])                        : 0;
        $flow_name       = isset($_POST['flow_name'])       ? sanitize_text_field(wp_unslash($_POST['flow_name']))          : 'Untitled Flow';
        $is_active       = isset($_POST['is_active'])       ? intval($_POST['is_active'])                       : 0;

        // v1.0.0 — flow_data is a large JSON payload describing nodes/edges. We
        // decode, walk it through dialoga_sanitize_json() (recursive
        // sanitize_text_field on every string), then re-encode for storage.
        $flow_data_raw = isset($_POST['flow_data']) ? sanitize_textarea_field(wp_unslash($_POST['flow_data'])) : '';
        $flow_data_arr = json_decode($flow_data_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // translators: %s: the JSON error message returned by json_last_error_msg().
            wp_send_json_error(sprintf(__('Invalid flow data JSON: %s', 'dialoga'), json_last_error_msg()));
            return;
        }
        $flow_data = wp_json_encode(dialoga_sanitize_json($flow_data_arr));

        $visibility_mode = isset($_POST['visibility_mode']) ? sanitize_text_field(wp_unslash($_POST['visibility_mode'])) : 'everyone';

        // v1.0.0 — visible_roles is a JSON array of role slugs. Decode,
        // sanitize each role key, and re-encode.
        $visible_roles_raw = isset($_POST['visible_roles']) ? sanitize_textarea_field(wp_unslash($_POST['visible_roles'])) : '[]';
        $visible_roles_arr = json_decode($visible_roles_raw, true);
        if (!is_array($visible_roles_arr)) $visible_roles_arr = array();
        $visible_roles_arr = array_map('sanitize_key', array_filter($visible_roles_arr, 'is_string'));
        $visible_roles     = wp_json_encode(array_values($visible_roles_arr));
        
        // (JSON validity was checked above when we decoded for sanitization)

        $data = array(
            'flow_name'       => $flow_name,
            'flow_data'       => $flow_data,
            'is_active'       => $is_active,
            'visibility_mode' => $visibility_mode,
            'visible_roles'   => $visible_roles,
            'updated_at'      => current_time('mysql')
        );

        if ($flow_id > 0) {
            // Update existing flow
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- admin write/update op; $wpdb->update() prepares values internally.
            $result = $wpdb->update(
                $table,
                $data,
                array('id' => $flow_id),
                array('%s', '%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );

            if ($result === false) {
                wp_send_json_error(__('Could not save the flow. Please try again.', 'dialoga'));
                return;
            }

            Dialoga_Frontend::invalidate_flow_cache($flow_id);
            wp_send_json_success(array('flow_id' => $flow_id, 'message' => __('Flow updated', 'dialoga')));
        } else {
            // Create new flow
            $data['created_at'] = current_time('mysql');

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- admin write/insert op; $wpdb->insert() prepares values internally.
            $result = $wpdb->insert(
                $table,
                $data,
                array('%s', '%s', '%d', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                wp_send_json_error(__('Could not save the flow. Please try again.', 'dialoga'));
                return;
            }

            Dialoga_Frontend::invalidate_flow_cache((int) $wpdb->insert_id);
            wp_send_json_success(array('flow_id' => $wpdb->insert_id, 'message' => __('Flow created', 'dialoga')));
        }
    }
    
    public function ajax_get_flows() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_flows';

        $flows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i ORDER BY updated_at DESC",
            $table
        ));
        
        wp_send_json_success($flows);
    }
    
    public function ajax_delete_flow() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }
        
        $flow_id = isset($_POST['flow_id']) ? intval($_POST['flow_id']) : 0;
        
        if ($flow_id <= 0) {
            wp_send_json_error(__('Invalid flow ID', 'dialoga'));
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_flows';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- admin write/delete op; $wpdb->delete() prepares values internally.
        $result = $wpdb->delete($table, array('id' => $flow_id), array('%d'));

        if ($result === false) {
            wp_send_json_error(__('Could not delete the flow. Please try again.', 'dialoga'));
            return;
        }

        Dialoga_Frontend::invalidate_flow_cache($flow_id);
        
        wp_send_json_success(__('Flow deleted', 'dialoga'));
    }
}

// Initialize plugin
Dialoga::get_instance();
