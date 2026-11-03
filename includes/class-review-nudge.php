<?php
/**
 * v0.41.1 — WP.org review nudge.
 *
 * Shows a dismissible admin notice on Dialoga's own admin pages when a user
 * has actually gotten value from the plugin. Standard WP-ecosystem pattern
 * (used by Elementor, Yoast, WooCommerce): a well-timed review ask can
 * lift review conversion from ~0.5% to 2-5% of active installs, which is
 * the difference between a listing that ranks and one that doesn't.
 *
 * Milestone gate (ALL required — otherwise silent):
 *   - Plugin has been installed for MIN_DAYS+ days
 *   - At least one flow has been saved
 *   - At least MIN_CONVERSATIONS real (non-admin) conversations have happened
 *
 * User's response is persisted per-user (not globally) so a multi-admin
 * site respects each admin's choice independently. Three responses:
 *   - "thanked"  → never show again to this user
 *   - "later"    → snooze SNOOZE_DAYS, then show once more
 *   - "never"    → never show again
 *
 * Testing utilities (admins only):
 *   - ?dialoga_force_review=1  → bypass milestones + dismissal state
 *   - ?dialoga_reset_review=1  → clear this user's state (then redirects
 *     back so the notice can re-evaluate)
 */

if (!defined('ABSPATH')) exit;

class Dialoga_Review_Nudge {

    const OPTION_ACTIVATED   = 'dialoga_activated_at';       // int timestamp — when the plugin first became active on this site
    const META_KEY           = 'dialoga_review_state';       // per-user state array
    const MIN_DAYS           = 7;                            // wait period before we ask
    const MIN_CONVERSATIONS  = 10;                           // real-visitor threshold
    const SNOOZE_DAYS        = 14;                           // "Maybe later" snooze length
    // v1.0.2 — points at the full, unfiltered reviews page. Pre-filtering the
    // reviews list by star rating hides critical feedback and is not permitted
    // by the plugin directory guidelines.
    const REVIEW_URL         = 'https://wordpress.org/support/plugin/dialoga/reviews/';

    public static function init() {
        add_action('admin_init',    array(__CLASS__, 'maybe_stamp_activation'));
        add_action('admin_init',    array(__CLASS__, 'handle_test_params'));
        add_action('admin_notices',  array(__CLASS__, 'render_notice'));
        add_action('wp_ajax_dialoga_review_action', array(__CLASS__, 'ajax_action'));
    }

    /**
     * Record when Dialoga was first active on this site. Used for the
     * "N days installed" milestone. Written once, then left alone.
     */
    public static function maybe_stamp_activation() {
        if (!get_option(self::OPTION_ACTIVATED)) {
            update_option(self::OPTION_ACTIVATED, time(), false);
        }
    }

    /**
     * Testing endpoints — for admins only. `?dialoga_reset_review=1`
     * clears this user's state; `?dialoga_force_review=1` is honored
     * inside should_show() and doesn't need handling here.
     */
    public static function handle_test_params() {
        if (!current_user_can('manage_options')) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin-initiated GET action link (confirmation button), no form data processed.
        if (!empty($_GET['dialoga_reset_review'])) {
            delete_user_meta(get_current_user_id(), self::META_KEY);
            $back = remove_query_arg('dialoga_reset_review');
            wp_safe_redirect($back);
            exit;
        }
    }

    /**
     * All the guards that decide whether the notice should render on
     * this request. Any false short-circuit here means silence.
     */
    public static function should_show() {
        if (!current_user_can('manage_options')) return false;

        // Only on Dialoga admin screens — otherwise we're spamming WP admin.
        if (!function_exists('get_current_screen')) return false;
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, 'dialoga') === false) return false;

        // Respect user's dismissal choice.
        $state = get_user_meta(get_current_user_id(), self::META_KEY, true);
        if (is_array($state)) {
            $status = isset($state['status']) ? $state['status'] : 'pending';
            if ($status === 'never' || $status === 'thanked') return false;
            if ($status === 'later' && !empty($state['snooze_until'])) {
                if (time() < intval($state['snooze_until'])) return false;
            }
        }

        // Force display for testing — bypasses milestones + snooze.
        // Note: still respects "never" / "thanked" above so force can't
        // spam a user who's explicitly opted out. Use ?dialoga_reset_review=1
        // first if you need to see it after opting out.
        if (!empty($_GET['dialoga_force_review'])) return true;

        return self::milestones_met();
    }

    /**
     * The value-delivered gate. Only show the notice once the admin has
     * clearly gotten real value: plugin active a while, has built a flow,
     * has captured meaningful traffic through it.
     */
    public static function milestones_met() {
        $activated_at = intval(get_option(self::OPTION_ACTIVATED, 0));
        if (!$activated_at) return false;
        if ((time() - $activated_at) < (self::MIN_DAYS * DAY_IN_SECONDS)) return false;

        global $wpdb;
        $flow_count = (int) dialoga_query_cached('nudge_flow_count', static function () {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $wpdb->prefix . 'dialoga_flows'));
        }, 300);
        if ($flow_count < 1) return false;

        // Count real visitors only — admin-test sessions don't earn a review ask.
        $conv_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE is_admin_session = 0 OR is_admin_session IS NULL",
                $wpdb->prefix . 'dialoga_conversations'
            )
        );
        if ($conv_count < self::MIN_CONVERSATIONS) return false;

        return true;
    }

    /**
     * Read the current real-conversation count for the notice copy.
     * Cached in a static so repeat calls within a request don't re-query.
     */
    protected static function get_conversation_count() {
        static $cached = null;
        if ($cached !== null) return $cached;
        global $wpdb;
        $cached = (int) dialoga_query_cached('nudge_conv_count', static function () {
            global $wpdb;
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE is_admin_session = 0 OR is_admin_session IS NULL",
                    $wpdb->prefix . 'dialoga_conversations'
                )
            );
        }, 300);
        return $cached;
    }

    public static function render_notice() {
        if (!self::should_show()) return;

        $conv_count = self::get_conversation_count();
        $nonce      = wp_create_nonce('dialoga_review_nudge');
        ?>
        <div class="notice dialoga-review-nudge" style="border-left-color:#6366F1;background:#fff;padding:16px 20px;position:relative;">
            <div style="display:flex;align-items:flex-start;gap:16px;">
                <div style="font-size:28px;line-height:1;">🎉</div>
                <div style="flex:1;min-width:0;">
                    <p style="margin:0 0 6px;font-size:15px;font-weight:600;color:#26233A;">
                        <?php echo esc_html__('Loving Dialoga?', 'dialoga'); ?>
                        <?php if ($conv_count > 0) : ?>
                            <?php
                            // translators: %s: formatted number of visitor conversations.
                            echo esc_html(sprintf(_n("You've handled %s visitor conversation so far!", "You've handled %s visitor conversations so far!", $conv_count, 'dialoga'), number_format_i18n($conv_count)));
                            ?>
                        <?php endif; ?>
                    </p>
                    <p style="margin:0 0 12px;color:#475569;font-size:13px;line-height:1.5;">
                        <?php echo esc_html__('Would you take 30 seconds to leave a review on WordPress.org? It genuinely helps us reach more small businesses.', 'dialoga'); ?>
                        <?php // Reviews are the #1 factor for WP.org search ranking — this ask is the single most valuable admin action right now. ?>
                    </p>
                    <p style="margin:0;">
                        <a href="<?php echo esc_url(self::REVIEW_URL); ?>"
                           target="_blank"
                           rel="noopener"
                           class="button button-primary dialoga-review-btn"
                           data-action="thanked"
                           style="margin-right:6px;">
                            👍 <?php echo esc_html__('Sure, happy to', 'dialoga'); ?>
                        </a>
                        <button type="button" class="button dialoga-review-btn" data-action="later" style="margin-right:6px;">
                            <?php echo esc_html__('Maybe later', 'dialoga'); ?>
                        </button>
                        <button type="button" class="button-link dialoga-review-btn" data-action="never" style="color:#94A3B8;text-decoration:none;">
                            <?php echo esc_html__('No thanks', 'dialoga'); ?>
                        </button>
                    </p>
                </div>
            </div>
        </div>

        <?php
        // v1.0.0 — attach nudge JS via enqueue system rather than inline tag
        $dialoga_nudge_js = "(function($){\n"
            . "  $('.dialoga-review-nudge .dialoga-review-btn').on('click', function(e){\n"
            . "    var action = $(this).data('action');\n"
            . "    if (action !== 'thanked') e.preventDefault();\n"
            . "    $.post(ajaxurl, {\n"
            . "      action: 'dialoga_review_action',\n"
            . "      review_action: action,\n"
            . "      nonce: " . wp_json_encode($nonce) . "\n"
            . "    }).always(function(){ $('.dialoga-review-nudge').fadeOut(250); });\n"
            . "  });\n"
            . "})(jQuery);";
        wp_add_inline_script('jquery-core', $dialoga_nudge_js);
        ?>
        <?php
    }

    public static function ajax_action() {
        check_ajax_referer('dialoga_review_nudge', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'dialoga'));
        }

        $action = isset($_POST['review_action']) ? sanitize_key($_POST['review_action']) : '';
        $state  = array('status' => 'pending', 'updated_at' => time());

        switch ($action) {
            case 'thanked':
                $state['status'] = 'thanked';
                break;
            case 'later':
                $state['status']       = 'later';
                $state['snooze_until'] = time() + (self::SNOOZE_DAYS * DAY_IN_SECONDS);
                break;
            case 'never':
                $state['status'] = 'never';
                break;
            default:
                wp_send_json_error(__('Invalid action.', 'dialoga'));
        }

        update_user_meta(get_current_user_id(), self::META_KEY, $state);
        wp_send_json_success($state);
    }
}
