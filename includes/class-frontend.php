<?php
/**
 * Frontend functionality for DIALOGAWP
 * Handles chat widget display and interaction
 */

if (!defined('ABSPATH')) {
    exit;
}

class DialogaWP_Frontend {
    
    /**
     * Initialize frontend
     */
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('wp_footer', array(__CLASS__, 'render_chat_widget'));
        
        // AJAX handlers for frontend
        add_action('wp_ajax_dialogawp_start_conversation', array(__CLASS__, 'ajax_start_conversation'));
        add_action('wp_ajax_nopriv_dialogawp_start_conversation', array(__CLASS__, 'ajax_start_conversation'));
        
        add_action('wp_ajax_dialogawp_process_response', array(__CLASS__, 'ajax_process_response'));
        add_action('wp_ajax_nopriv_dialogawp_process_response', array(__CLASS__, 'ajax_process_response'));
        
        add_action('wp_ajax_dialogawp_submit_lead', array(__CLASS__, 'ajax_submit_lead'));
        add_action('wp_ajax_nopriv_dialogawp_submit_lead', array(__CLASS__, 'ajax_submit_lead'));

        add_action('wp_ajax_dialogawp_submit_survey', array(__CLASS__, 'ajax_submit_survey'));
        add_action('wp_ajax_nopriv_dialogawp_submit_survey', array(__CLASS__, 'ajax_submit_survey'));

        add_action('wp_ajax_dialogawp_track_event', array(__CLASS__, 'ajax_track_event'));
        add_action('wp_ajax_nopriv_dialogawp_track_event', array(__CLASS__, 'ajax_track_event'));
        add_action('wp_ajax_dialogawp_track_bubble_shown', array(__CLASS__, 'ajax_track_bubble_shown'));
        add_action('wp_ajax_nopriv_dialogawp_track_bubble_shown', array(__CLASS__, 'ajax_track_bubble_shown'));
        add_action('wp_ajax_dialogawp_save_transcript', array(__CLASS__, 'ajax_save_transcript'));
        add_action('wp_ajax_nopriv_dialogawp_save_transcript', array(__CLASS__, 'ajax_save_transcript'));

        add_action('wp_ajax_dialogawp_update_lead_tags', array(__CLASS__, 'ajax_update_lead_tags'));
        add_action('wp_ajax_dialogawp_get_lead_details', array(__CLASS__, 'ajax_get_lead_details'));
        add_action('wp_ajax_dialogawp_delete_lead', array(__CLASS__, 'ajax_delete_lead'));
        add_action('wp_ajax_dialogawp_delete_all_leads', array(__CLASS__, 'ajax_delete_all_leads'));
        add_action('wp_ajax_dialogawp_delete_conversation', array(__CLASS__, 'ajax_delete_conversation'));
        add_action('wp_ajax_dialogawp_delete_all_conversations', array(__CLASS__, 'ajax_delete_all_conversations'));
        add_action('wp_ajax_dialogawp_reset_analytics_events', array(__CLASS__, 'ajax_reset_analytics_events'));
        add_action('dialogawp_conversation_retention_cleanup', array(__CLASS__, 'cron_apply_conversation_retention_policy'));

        // Lead retention cron handler
        add_action('dialogawp_lead_retention_cleanup', array(__CLASS__, 'cron_apply_retention_policy'));
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public static function enqueue_scripts() {
        // Only load if chatbot is enabled
        $settings = get_option('dialogawp_settings', array('enabled' => false));
        if (empty($settings['enabled'])) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'dialogawp-widget',
            plugins_url('assets/css/chatbot-widget.css', dirname(__FILE__)),
            array(),
            DIALOGAWP_VERSION
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'dialogawp-widget',
            plugins_url('assets/js/chatbot-widget.js', dirname(__FILE__)),
            array('jquery'),
            DIALOGAWP_VERSION,
            true
        );
        
        // Localize script with settings
        $preview = isset($settings['preview_message']) ? $settings['preview_message'] : array();
        wp_localize_script('dialogawp-widget', 'dialogawpWidget', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dialogawp_widget'),
            'settings' => array(
                'primaryColor'       => isset($settings['primary_color']) ? $settings['primary_color'] : '#190F4F',
                'position'           => isset($settings['chat_position']) ? $settings['chat_position'] : 'bottom-right',
                'welcomeMessage'     => isset($settings['welcome_message']) ? $settings['welcome_message'] : 'Hi! How can we help you today?',
                // v1.0.0 — Default to no widget branding on the public site
                // (wp.org guideline 10 prohibits credits without opt-in). Pro
                // plugin filters this via `dialogawp_widget_branding` if the site
                // owner explicitly enables it there.
                'showBranding'       => false,
                'testMode'           => !empty($settings['test_mode']),
                'bubbleNudge'        => !isset($settings['bubble_nudge']) || !empty($settings['bubble_nudge']),
                'bubbleBounce'       => !isset($settings['bubble_bounce']) || !empty($settings['bubble_bounce']),
                'freshSessionForUser' => self::current_user_gets_fresh_session($settings),
                'previewMessage'     => array(
                    'enabled' => !empty($preview['enabled']),
                    'text'    => isset($preview['text']) ? $preview['text'] : '',
                    'delay'   => isset($preview['delay']) ? max(0, intval($preview['delay'])) : 5,
                ),
            ),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));

        // v1.0.1 — wp.org compliance: attach the header-button + bubble-nudge
        // helper JS via wp_add_inline_script instead of emitting a raw <script>
        // tag from render_chat_widget(). All PHP-computed values are read from
        // the dialogawpWidget object above.
        $inline_js = self::widget_inline_js();
        wp_add_inline_script('dialogawp-widget', $inline_js, 'after');
    }

    /**
     * Returns the JS body attached to the dialogawp-widget handle. Broken out so
     * enqueue_scripts() stays readable. All PHP-computed values are pulled
     * from window.dialogawpWidget (populated by wp_localize_script above).
     */
    private static function widget_inline_js() {
        return <<<'JS'
/* Global functions for header buttons. Inline onclick on the buttons calls
   these so the click works regardless of jQuery / TenWeb timing. */
window.dialogawpRestartChat = window.dialogawpRestartChat || function() {
    if (!window.confirm('Start a new conversation? Your current chat history will be cleared.')) return;
    if (window.dialogawpWidget && typeof window.dialogawpWidget.restartChat === 'function') {
        window.dialogawpWidget.restartChat();
    } else {
        try { localStorage.removeItem('dialogawp_session'); } catch (e) {}
        window.location.reload();
    }
};
window.dialogawpCloseChat = window.dialogawpCloseChat || function() {
    if (window.dialogawpWidget && typeof window.dialogawpWidget.closeChat === 'function') {
        window.dialogawpWidget.closeChat();
    } else {
        var win = document.getElementById('dialogawp-window');
        var bub = document.getElementById('dialogawp-bubble');
        if (win) win.style.display = 'none';
        if (bub) bub.style.display = '';
    }
};

/* Bubble-shown impression tracking + preview message + bubble bounce.
   Runs synchronously without jQuery so TenWeb / Breeze deferring scripts
   can't break attention-grabbers. */
(function() {
    var cfg = (window.dialogawpWidget && window.dialogawpWidget) || {};
    // dialogawpWidget is used for two things: (1) the localized settings object
    // this file reads, (2) a widget instance the main widget script assigns.
    // The localized object always has ajaxUrl + nonce; if we don't see those,
    // bail on impression tracking.
    var localized = (typeof window.dialogawpWidget === 'object' && window.dialogawpWidget && window.dialogawpWidget.ajaxUrl)
        ? window.dialogawpWidget
        : null;

    if (localized) {
        setTimeout(function() {
            if (document.hidden) return;
            try {
                var body = new FormData();
                body.append('action',   'dialogawp_track_bubble_shown');
                body.append('nonce',    localized.nonce);
                body.append('flow_id',  0);
                body.append('page_url', window.location.href);
                fetch(localized.ajaxUrl, { method: 'POST', body: body, keepalive: true });
            } catch (e) { /* best-effort */ }
        }, 2000);
    }

    var bounceEnabled = !!(localized && localized.settings && localized.settings.bubbleBounce);
    var hasSession = false;
    try { hasSession = !!localStorage.getItem('dialogawp_session'); } catch (e) {}

    var bubble = document.getElementById('dialogawp-bubble');
    if (hasSession && bubble) {
        bubble.classList.remove('dialogawp-bubble--nudge');
    }

    var badge = document.getElementById('dialogawp-bubble-badge');
    if (badge) {
        var acked = false;
        try { acked = localStorage.getItem('dialogawp_badge_ack') === '1'; } catch (e) {}
        if (acked || hasSession) {
            badge.style.display = 'none';
        } else if (bubble) {
            bubble.addEventListener('click', function() {
                badge.style.display = 'none';
                try { localStorage.setItem('dialogawp_badge_ack', '1'); } catch (e) {}
            }, { once: true });
        }
    }

    if (bounceEnabled && bubble && !hasSession) {
        var bounceInterval = null;
        var stopBounce = function() {
            if (bounceInterval) { clearInterval(bounceInterval); bounceInterval = null; }
        };
        bounceInterval = setInterval(function() {
            if (bubble.matches(':hover')) return;
            bubble.classList.remove('dialogawp-bubble--bounce');
            void bubble.offsetWidth;
            bubble.classList.add('dialogawp-bubble--bounce');
            setTimeout(function() { bubble.classList.remove('dialogawp-bubble--bounce'); }, 1000);
        }, 30000);
        bubble.addEventListener('click', stopBounce, { once: true });
    }

    var preview = document.getElementById('dialogawp-preview-message');
    if (!preview) return;

    try {
        if (localStorage.getItem('dialogawp_preview_dismissed') === '1' || hasSession) {
            preview.style.display = 'none';
            return;
        }
    } catch (e) {}

    var closeBtn = preview.querySelector('.dialogawp-preview-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            try { localStorage.setItem('dialogawp_preview_dismissed', '1'); } catch (err) {}
            preview.style.display = 'none';
        }, true);
    }

    preview.addEventListener('click', function(e) {
        if (!e.isTrusted) return;
        if (closeBtn && (e.target === closeBtn || closeBtn.contains(e.target))) return;
        preview.style.display = 'none';
        var bubble2 = document.getElementById('dialogawp-bubble');
        if (bubble2) bubble2.click();
    }, true);
})();
JS;
    }

    /**
     * Returns true when the currently logged-in user should get a fresh chat
     * session on every page load (based on the Fresh Session Roles setting).
     */
    /**
     * Shared visibility check. Returns true if the current visitor
     * satisfies the given visibility mode + roles.
     *
     * @param string $mode  'everyone' | 'logged_in' | 'specific_roles'
     * @param array  $roles List of role slugs (used only for 'specific_roles')
     */
    private static function visitor_passes_visibility_gate($mode, $roles) {
        switch ($mode) {
            case 'logged_in':
                return is_user_logged_in();

            case 'specific_roles':
                if (!is_user_logged_in()) return false;
                $user = wp_get_current_user();
                return !empty(array_intersect($user->roles, $roles));

            case 'everyone':
            default:
                return true;
        }
    }

    /**
     * Returns all currently-active flows that the visitor is eligible to see.
     * Applies BOTH the global site-wide gate (from dialogawp_settings) and each
     * flow's own per-flow visibility gate. Used by render_chat_widget() to
     * decide whether to render at all, and by ajax_start_conversation() to
     * hand a pre-filtered eligible list to the dialogawp_select_active_flow
     * filter — so Pro A/B selection can't assign a visitor to a flow they
     * aren't allowed to see.
     *
     * @return array Flow rows (stdClass) ordered newest-first. Empty if
     *               none match or the global gate fails.
     */
    public static function get_eligible_active_flows() {
        $settings = get_option('dialogawp_settings', array());

        // Global gate — if the visitor fails the site-wide baseline, no flows apply.
        $global_roles = isset($settings['visible_roles']) && is_array($settings['visible_roles'])
            ? $settings['visible_roles']
            : array();
        $global_mode = isset($settings['visibility_mode']) ? $settings['visibility_mode'] : 'everyone';
        if (!self::visitor_passes_visibility_gate($global_mode, $global_roles)) {
            return array();
        }

        $active_flows = self::get_active_flows_cached();
        if (empty($active_flows)) return array();

        $eligible = array();
        foreach ($active_flows as $flow) {
            $flow_mode      = isset($flow->visibility_mode) ? $flow->visibility_mode : 'everyone';
            $flow_roles_raw = isset($flow->visible_roles)   ? $flow->visible_roles   : '[]';
            $flow_roles     = json_decode($flow_roles_raw, true);
            if (!is_array($flow_roles)) $flow_roles = array();

            if (self::visitor_passes_visibility_gate($flow_mode, $flow_roles)) {
                $eligible[] = $flow;
            }
        }
        return $eligible;
    }

    private static function current_user_gets_fresh_session($settings) {
        if (!is_user_logged_in()) {
            return false;
        }
        // Legacy-safe default: if the setting has never been saved on this install,
        // treat administrators as the implicit fresh-session role.
        if (!isset($settings['fresh_session_roles'])) {
            return current_user_can('manage_options');
        }
        $roles = is_array($settings['fresh_session_roles']) ? $settings['fresh_session_roles'] : array();
        if (empty($roles)) {
            return false;
        }
        $user = wp_get_current_user();
        if (empty($user->roles) || !is_array($user->roles)) {
            return false;
        }
        return count(array_intersect($user->roles, $roles)) > 0;
    }

    /**
     * Render chat widget HTML
     */
    public static function render_chat_widget() {
        // Never render inside the WP admin. The setup wizard template calls
        // wp_footer() directly (it's a full-page admin layout), which would
        // otherwise trigger this hook and leak the widget into admin UI.
        if (is_admin()) {
            return;
        }

        // Only render if enabled
        $settings = get_option('dialogawp_settings', array('enabled' => false));

        if (empty($settings['enabled'])) {
            return;
        }

        // Resolve which flows the current visitor is eligible to see.
        // If none match, we bail — no widget rendered at all.
        if (empty(self::get_eligible_active_flows())) {
            return;
        }

        // Position class — used to flip the widget to bottom-left when configured
        $position       = isset($settings['chat_position']) ? $settings['chat_position'] : 'bottom-right';
        $position_class = 'dialogawp-widget--' . sanitize_html_class($position);

        // v0.38.10 — server-rendered preview message bubble + bubble nudge.
        // Pure HTML + CSS animation so they appear on schedule even when
        // page-speed optimizers (TenWeb, Breeze) defer JavaScript.
        $preview = isset($settings['preview_message']) ? $settings['preview_message'] : array();
        $preview_enabled = !empty($preview['enabled']) && !empty($preview['text']);
        $preview_delay   = isset($preview['delay']) ? max(0, intval($preview['delay'])) : 5;
        $preview_text    = isset($preview['text']) ? $preview['text'] : '';

        // Bubble nudge — server-rendered class so the pulse + ripple animation
        // starts on first paint, not after JS loads.
        $bubble_nudge_enabled = !isset($settings['bubble_nudge']) || !empty($settings['bubble_nudge']);

        // v0.38.19 — visibility levers: bubble size, unread badge, periodic bounce
        $bubble_size = isset($settings['bubble_size']) && in_array($settings['bubble_size'], array('sm', 'md', 'lg'), true)
            ? $settings['bubble_size']
            : 'md';
        $bubble_unread_badge = !isset($settings['bubble_unread_badge']) || !empty($settings['bubble_unread_badge']);
        $bubble_bounce_enabled = !isset($settings['bubble_bounce']) || !empty($settings['bubble_bounce']);

        $bubble_class = 'dialogawp-bubble dialogawp-bubble--size-' . $bubble_size
            . ($bubble_nudge_enabled ? ' dialogawp-bubble--nudge' : '');
        ?>
        <div id="dialogawp-widget" class="dialogawp-widget <?php echo esc_attr($position_class); ?>">
            <?php if ($preview_enabled) : ?>
            <div id="dialogawp-preview-message" class="dialogawp-preview-message" role="status"
                 style="animation-delay: <?php echo esc_attr($preview_delay); ?>s;">
                <button type="button" class="dialogawp-preview-close" aria-label="Dismiss">&times;</button>
                <div class="dialogawp-preview-text"><?php echo esc_html($preview_text); ?></div>
            </div>
            <?php endif; ?>
            <!-- Chat Bubble (Closed State) -->
            <div id="dialogawp-bubble" class="<?php echo esc_attr($bubble_class); ?>">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="pointer-events:none;">
                    <path d="M20 2H4C2.9 2 2 2.9 2 4V22L6 18H20C21.1 18 22 17.1 22 16V4C22 2.9 21.1 2 20 2Z" fill="white"/>
                </svg>
                <span class="dialogawp-bubble-text">Chat</span>
                <?php if ($bubble_unread_badge) : ?>
                <span id="dialogawp-bubble-badge" class="dialogawp-bubble__badge" aria-hidden="true">1</span>
                <?php endif; ?>
            </div>
            
            <!-- Chat Window (Expanded State) -->
            <div id="dialogawp-window" class="dialogawp-window" style="display: none;">
                <!-- Header -->
                <div class="dialogawp-header">
                    <div class="dialogawp-header-title">
                        <strong>Chat with us</strong>
                        <span class="dialogawp-header-status">● Online</span>
                    </div>
                    <div class="dialogawp-header-actions">
                        <?php // v0.42.0 — header restart button removed; Reset now lives
                              // in the unified footer bar below (matching CAASI/modern
                              // chatbot UX). Frees up header space + more discoverable. ?>
                        <button type="button" class="dialogawp-close-btn" id="dialogawp-close"
                                aria-label="Close chat"
                                onclick="var w=document.getElementById('dialogawp-window');var b=document.getElementById('dialogawp-bubble');if(w)w.style.display='none';if(b)b.style.display='';">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="pointer-events:none;">
                                <path d="M15 5L5 15M5 5L15 15" stroke="white" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Messages Container -->
                <div class="dialogawp-messages" id="dialogawp-messages">
                    <!-- Messages will be added here dynamically -->
                </div>

                <!-- Input Area -->
                <div class="dialogawp-input-area" id="dialogawp-input-area">
                    <!-- Dynamic input (text or buttons) will be added here -->
                </div>

                <?php
                // v0.42.0 — unified footer bar: Reset button (left) + optional
                // branding link (right). v0.42.1 adds an optional `logo` field
                // for a small icon next to the text. Pro plugins may filter
                // `dialogawp_widget_branding` to replace text/logo/URL with their
                // own (white-label) or return empty text to hide the link
                // entirely. Free default: DialogaWP logo (bundled locally in
                // assets/images/ for WP.org compliance — no external URLs).
                //
                // v0.42.5 — hide_branding checkbox handling reverted back to
                // Pro's `apply_widget_branding` filter (DIALOGAWP_PRO_Branding).
                // Rendering the check here in Free bypassed the license gate,
                // which broke the intended Pro-only nature of the feature.
                // If the checkbox isn't hiding branding, it's because Pro's
                // filter isn't running — either license check failing or
                // Pro plugin outdated.
                // v1.0.0 — No default branding on the public widget. Site
                // owner must explicitly opt in (via the Pro plugin, which
                // hooks this filter). wp.org guideline 10 compliance.
                $branding = apply_filters('dialogawp_widget_branding', array(
                    'text' => '',
                    'url'  => '',
                    'logo' => '',
                ));
                ?>
                <div class="dialogawp-footer-bar">
                    <button type="button" class="dialogawp-footer-reset" id="dialogawp-restart-footer"
                            title="<?php esc_attr_e('Start a new conversation', 'dialogawp'); ?>"
                            aria-label="<?php esc_attr_e('Start a new conversation', 'dialogawp'); ?>"
                            onclick="if(window.confirm('Start a new conversation? Your current chat history will be cleared.')){try{localStorage.removeItem('dialogawp_session');}catch(e){}window.location.reload();}">
                        <svg viewBox="0 0 20 20" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4v5h5"/>
                            <path d="M4 9a7 7 0 1 1 2 4.9"/>
                        </svg>
                        <span>Reset</span>
                    </button>
                    <?php if (!empty($branding['text']) || !empty($branding['logo'])) :
                        // v0.42.2 — logo now renders AFTER the text (matches how
                        // people scan "brand phrase → mark"). Size bumped so the
                        // logo reads at a glance instead of feeling ornamental.
                        $logo_img = !empty($branding['logo'])
                            ? '<img src="' . esc_url($branding['logo']) . '" alt="" class="dialogawp-branding-logo" />'
                            : '';
                        $text_html = !empty($branding['text']) ? esc_html($branding['text']) : '';
                    ?>
                        <div class="dialogawp-branding">
                            <?php if (!empty($branding['url'])) : ?>
                                <a href="<?php echo esc_url($branding['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo wp_kses_post($text_html . $logo_img); ?></a>
                            <?php else : ?>
                                <span><?php echo wp_kses_post($text_html . $logo_img); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
        </div>
        <?php
    }

    /**
     * AJAX: Start new conversation
     */
    public static function ajax_start_conversation() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        $page_url   = isset($_POST['page_url'])   ? esc_url_raw($_POST['page_url'])                      : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip_address = isset($_SERVER['REMOTE_ADDR'])     ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))     : '';

        global $wpdb;

        // Only flows the current visitor can actually see. This prevents logged-out
        // visitors from being handed an admin-only flow via the A/B selector, and
        // keeps the render-time gate consistent with the conversation-start gate.
        $active_flows = self::get_eligible_active_flows();
        $flow = apply_filters('dialogawp_select_active_flow', !empty($active_flows) ? $active_flows[0] : null, $active_flows);

        if (!$flow) {
            wp_send_json_error(array('message' => 'No active chatbot found. Please contact the site administrator.'));
            return;
        }
        
        // Parse flow data
        $flow_data = json_decode($flow->flow_data, true);
        
        // Get start node
        $start_node_id = DialogaWP_Engine::get_start_node($flow_data);
        if (!$start_node_id) {
            wp_send_json_error(array('message' => 'No start node found'));
            return;
        }
        
        // Find start node
        $start_node = null;
        foreach ($flow_data['nodes'] as $node) {
            if ($node['id'] === $start_node_id) {
                $start_node = $node;
                break;
            }
        }
        
        if (!$start_node) {
            wp_send_json_error(array('message' => 'Start node not found in flow'));
            return;
        }
        
        // Create session and persist conversation record
        $session_id      = 'session_' . uniqid();
        $conversation_id = DialogaWP_Database::save_conversation($session_id, $flow->id, $page_url, $ip_address, $user_agent);

        // Track conversation start
        if ($conversation_id) {
            DialogaWP_Analytics::track_event($conversation_id, 'conversation_started', array(
                'flow_id'  => $flow->id,
                'page_url' => $page_url,
            ), $flow->id);

            // v0.40.7 — Also fire node_viewed for the start node so it appears
            // in the Funnel and Journey reports as the true entry point.
            // Prior versions only fired node_viewed on flow advancement (in
            // ajax_process_response), which made the very first node the
            // visitor sees invisible to funnel reports. Consequence:
            //   1. Funnel started at whatever came AFTER the start (usually
            //      a branch's first content node), completely omitting the
            //      real entry question.
            //   2. In branching flows, one branch's second-node counts got
            //      labelled as "Entry point of the flow" — badly wrong.
            // v0.40.17 — capture the start node's proper display label so
            // historical reports can preserve past wording (see comment on
            // ajax_process_response's tracking call for A/B-testing context).
            $start_label = DialogaWP_Analytics::get_node_display_label($start_node, 0);
            DialogaWP_Analytics::track_event($conversation_id, 'node_viewed', array(
                'node_id'   => $start_node_id,
                'node_type' => isset($start_node['data']['nodeType']) ? $start_node['data']['nodeType'] : '',
                'node_label'=> substr($start_label, 0, 200),
                'flow_id'   => $flow->id,
            ), $flow->id);
        }

        // Get first message
        $response = self::format_node_response($start_node, $flow_data);

        wp_send_json_success(array(
            'session_id'      => $session_id,
            'conversation_id' => $conversation_id,
            'flow_id'         => $flow->id,
            'current_node'    => $start_node_id,
            'message'         => $response
        ));
    }
    
    /**
     * AJAX: Process user response
     */
    public static function ajax_process_response() {
        check_ajax_referer('dialogawp_widget', 'nonce');
        
        $session_id      = isset($_POST['session_id'])   ? sanitize_text_field(wp_unslash($_POST['session_id']))   : '';
        $current_node_id = isset($_POST['current_node']) ? sanitize_text_field(wp_unslash($_POST['current_node'])) : '';
        $user_response   = isset($_POST['response'])     ? sanitize_text_field(wp_unslash($_POST['response']))     : '';
        $flow_id         = isset($_POST['flow_id'])      ? intval($_POST['flow_id'])                               : 0;
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;

        // Load variables from JS session state — decode + recursively sanitize
        $variables_json = isset($_POST['variables']) ? wp_unslash($_POST['variables']) : '{}';
        $variables      = dialogawp_sanitize_json(json_decode($variables_json, true) ?: array());
        if (!is_array($variables)) $variables = array();
        
        // v1.0.0 — fetch through the cache. Runs on every AJAX chat step; on
        // a busy site this saves 1 DB roundtrip per visitor per node.
        $flow = self::get_flow_by_id_cached($flow_id);
        
        if (!$flow) {
            wp_send_json_error(array('message' => 'Flow not found'));
            return;
        }
        
        $flow_data = json_decode($flow->flow_data, true);
        
        // Check if the answered node saves a variable
        $save_variable = null;
        foreach ($flow_data['nodes'] as $node) {
            if ($node['id'] === $current_node_id && !empty($node['data']['save_as'])) {
                $key = sanitize_key($node['data']['save_as']);
                $variables[$key] = $user_response;
                $save_variable = array('key' => $key, 'value' => $user_response);
                break;
            }
        }

        // Process response and get next node
        $next_node_id = DialogaWP_Engine::process_response($flow_data, $current_node_id, $user_response);
        
        if (!$next_node_id) {
            // End of conversation
            wp_send_json_success(array(
                'end' => true,
                'message' => array(
                    'type' => 'message',
                    'text' => 'Thank you for chatting with us!'
                )
            ));
            return;
        }
        
        // Find next node
        $next_node = null;
        foreach ($flow_data['nodes'] as $node) {
            if ($node['id'] === $next_node_id) {
                $next_node = $node;
                break;
            }
        }
        
        if (!$next_node) {
            wp_send_json_error(array('message' => 'Next node not found'));
            return;
        }
        
        // Handle loop node — jump to target node and continue from there
        // Loop target is determined by the connecting wire (edge.source = loop node id)
        // Fall back to legacy target_node_id field for backward compatibility
        $loop_message = '';
        $loop_delay_ms = 0;
        if ($next_node['data']['nodeType'] === 'loop') {
            // v1.0.2 — loopback message + pre-loop delay come from the node's
            // own config, exactly as the Flow Builder saved them. The filters
            // remain as extension points but no longer gate the feature.
            $loop_message  = isset($next_node['data']['message'])
                ? self::interpolate_variables($next_node['data']['message'], $variables)
                : '';
            $loop_delay_ms = isset($next_node['data']['delay_ms'])
                ? max(0, intval($next_node['data']['delay_ms']))
                : 0;
            $loop_message  = apply_filters('dialogawp_loop_message', $loop_message, $next_node, $variables);
            $loop_delay_ms = apply_filters('dialogawp_loop_delay_ms', $loop_delay_ms, $next_node);

            $target_node_id = '';

            // First: look for an edge from this loop node (the wire = source of truth)
            if (isset($flow_data['edges']) && is_array($flow_data['edges'])) {
                foreach ($flow_data['edges'] as $edge) {
                    if (isset($edge['source']) && $edge['source'] === $next_node['id']) {
                        $target_node_id = $edge['target'];
                        break;
                    }
                }
            }

            // Fallback to legacy target_node_id field
            if (!$target_node_id && isset($next_node['data']['target_node_id'])) {
                $target_node_id = $next_node['data']['target_node_id'];
            }

            if ($target_node_id) {
                foreach ($flow_data['nodes'] as $node) {
                    if ($node['id'] === $target_node_id) {
                        $next_node    = $node;
                        $next_node_id = $target_node_id;
                        break;
                    }
                }
            } else {
                wp_send_json_success(array('end' => true, 'message' => array('type' => 'message', 'text' => 'End of flow.')));
                return;
            }
        }

        // ai_chat top-level node is frontend-rendered — fall through to format_node_response

        // Accumulate pending tags from tag_lead nodes
        $pending_tags = array();

        // First-class Webhook node (Pro): fire server-side, then advance to the next node.
        // No frontend render — the visitor never sees this step.
        if ($next_node['data']['nodeType'] === 'webhook' && class_exists('DIALOGAWP_PRO_Webhooks')) {
            $config = isset($next_node['data']['config']) ? $next_node['data']['config'] : array();
            DIALOGAWP_PRO_Webhooks::fire_node_webhook($config, $variables, $flow_id);

            $after_webhook = self::find_next_node($next_node, null, $flow_data);
            if ($after_webhook) {
                $response = self::format_node_response($after_webhook, $flow_data, $variables);
                $response['current_node'] = $after_webhook['id'];
                wp_send_json_success($response);
                return;
            }
            // No next node after webhook — end the flow
            wp_send_json_success(array('end' => true, 'message' => array('type' => 'message', 'text' => 'End of flow.')));
            return;
        }

        // Execute action if it's a silent action node (not frontend-rendered ones)
        if ($next_node['data']['nodeType'] === 'action') {
            $action_type = isset($next_node['data']['action_type']) ? $next_node['data']['action_type'] : '';
            $frontend_rendered = in_array($action_type, array('capture_lead', 'ai_chat', 'redirect', 'survey', 'qualify', 'link_button'));

            // Webhook nodes fire server-side then continue to next node
            if ($action_type === 'webhook' && class_exists('DIALOGAWP_PRO_Webhooks')) {
                $config = isset($next_node['data']['config']) ? $next_node['data']['config'] : array();
                DIALOGAWP_PRO_Webhooks::fire_node_webhook($config, $variables, $flow_id);
                // Continue to next node after webhook fires
                $after_webhook = self::find_next_node($next_node, null, $flow_data);
                if ($after_webhook) {
                    $response = self::format_node_response($after_webhook, $flow_data, $variables);
                    $response['current_node'] = $after_webhook['id'];
                    wp_send_json_success($response);
                    return;
                }
            }

            if (!$frontend_rendered) {
                // Collect tags from tag_lead node; execute all other silent actions
                if ($action_type === 'tag_lead') {
                    $tags_config = isset($next_node['data']['config']['tags']) ? $next_node['data']['config']['tags'] : array();
                    if (is_array($tags_config)) {
                        $pending_tags = array_map('sanitize_text_field', $tags_config);
                    }
                } else {
                    DialogaWP_Engine::execute_action($next_node, array(
                        'session_id'    => $session_id,
                        'user_response' => $user_response,
                    ));
                }

                // Advance past the silent action node
                $next_node_id = DialogaWP_Engine::get_next_node($flow_data['edges'], $next_node_id);
                if ($next_node_id) {
                    foreach ($flow_data['nodes'] as $node) {
                        if ($node['id'] === $next_node_id) {
                            $next_node = $node;
                            break;
                        }
                    }
                } else {
                    wp_send_json_success(array(
                        'end'     => true,
                        'message' => array(
                            'type' => 'message',
                            'text' => 'Thank you! We\'ll be in touch soon.'
                        )
                    ));
                    return;
                }
            }
            // Frontend-rendered actions fall through to format_node_response below
        }
        
        // CRITICAL FIX: If next node is a branch, process it automatically!
        if ($next_node['data']['nodeType'] === 'branch') {
            error_log('🔀 Branch node detected, processing automatically with user response: ' . $user_response);
            
            // Process the branch with the user's response
            $branch_result_id = DialogaWP_Engine::process_response($flow_data, $next_node_id, $user_response);
            
            error_log('✅ Branch result: ' . ($branch_result_id ? $branch_result_id : 'NULL'));
            
            if (!$branch_result_id) {
                // Branch had no matching conditions - end conversation
                wp_send_json_success(array(
                    'end' => true,
                    'message' => array(
                        'type' => 'message',
                        'text' => 'Thank you for your interest!'
                    )
                ));
                return;
            }
            
            // Get the node after the branch
            foreach ($flow_data['nodes'] as $node) {
                if ($node['id'] === $branch_result_id) {
                    $next_node = $node;
                    $next_node_id = $branch_result_id;
                    break;
                }
            }
            
            // If the result is another branch, process it too (recursive)
            while ($next_node['data']['nodeType'] === 'branch') {
                error_log('🔀 Chained branch detected, processing...');
                $branch_result_id = DialogaWP_Engine::process_response($flow_data, $next_node_id, $user_response);

                if (!$branch_result_id) {
                    wp_send_json_success(array(
                        'end' => true,
                        'message' => array(
                            'type' => 'message',
                            'text' => 'Thank you for your interest!'
                        )
                    ));
                    return;
                }

                foreach ($flow_data['nodes'] as $node) {
                    if ($node['id'] === $branch_result_id) {
                        $next_node = $node;
                        $next_node_id = $branch_result_id;
                        break;
                    }
                }
            }

            // If branch resolved to a Loop node, follow the loop wire to its target
            if ($next_node['data']['nodeType'] === 'loop') {
                error_log('↩ Branch resolved to Loop node — following loop target');

                // v1.0.2 — read the loopback message + delay straight from the
                // node config the builder saved. Filters stay as extension points.
                if (isset($next_node['data']['message']) && $next_node['data']['message'] !== '') {
                    $loop_message = self::interpolate_variables($next_node['data']['message'], $variables);
                }
                if (isset($next_node['data']['delay_ms'])) {
                    $loop_delay_ms = max(0, intval($next_node['data']['delay_ms']));
                }
                $loop_message  = apply_filters('dialogawp_loop_message', $loop_message, $next_node, $variables);
                $loop_delay_ms = apply_filters('dialogawp_loop_delay_ms', $loop_delay_ms, $next_node);

                $loop_target_id = '';

                // Wire is source of truth
                if (isset($flow_data['edges']) && is_array($flow_data['edges'])) {
                    foreach ($flow_data['edges'] as $edge) {
                        if (isset($edge['source']) && $edge['source'] === $next_node['id']) {
                            $loop_target_id = $edge['target'];
                            break;
                        }
                    }
                }

                // Fallback to legacy field
                if (!$loop_target_id && isset($next_node['data']['target_node_id'])) {
                    $loop_target_id = $next_node['data']['target_node_id'];
                }

                if ($loop_target_id) {
                    foreach ($flow_data['nodes'] as $node) {
                        if ($node['id'] === $loop_target_id) {
                            $next_node = $node;
                            $next_node_id = $loop_target_id;
                            error_log('↩ Loop target resolved: ' . $loop_target_id);
                            break;
                        }
                    }
                } else {
                    wp_send_json_success(array(
                        'end' => true,
                        'message' => array('type' => 'message', 'text' => 'End of flow.')
                    ));
                    return;
                }
            }
        }
        
        $response = self::format_node_response($next_node, $flow_data, $variables);

        // Track node view
        if ($conversation_id) {
            // v0.40.17 — use per-type label extraction so Action nodes
            // ("🔗 Book Massage") and Branch nodes get their meaningful
            // labels captured rather than falling back to the raw type
            // slug. Enables historical A/B testing of question wording:
            // reports pull labels from event_data at report time, so
            // editing a Question's text going forward doesn't rewrite
            // the labels for events already captured.
            $raw_label = DialogaWP_Analytics::get_node_display_label($next_node, 0);
            $node_label = substr(self::interpolate_variables($raw_label, $variables), 0, 200);
            DialogaWP_Analytics::track_event($conversation_id, 'node_viewed', array(
                'node_id'   => $next_node_id,
                'node_type' => $next_node['data']['nodeType'],
                'node_label'=> $node_label,
                'flow_id'   => $flow_id,
            ), $flow_id);
        }

        $result = array(
            'current_node' => $next_node_id,
            'message'      => $response,
        );
        if ($save_variable) {
            $result['save_variable'] = $save_variable;
        }
        if (!empty($pending_tags)) {
            $result['pending_tags'] = $pending_tags;
        }
        // Show a message and/or wait before jumping back through a Loop node
        if (!empty($loop_message)) {
            $result['loop_message'] = $loop_message;
        }
        if (!empty($loop_delay_ms)) {
            $result['loop_delay_ms'] = $loop_delay_ms;
        }
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Submit lead from capture form
     */
    public static function ajax_submit_lead() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        global $wpdb;

        $session_id = sanitize_text_field(wp_unslash($_POST['session_id']));
        $flow_id = intval($_POST['flow_id']);

        // Sanitize lead data
        $lead_data = array();
        if (isset($_POST['lead_data']) && is_array($_POST['lead_data'])) {
            foreach ($_POST['lead_data'] as $key => $value) {
                $lead_data[sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        // Validate required fields (basic validation)
        if (empty($lead_data)) {
            wp_send_json_error(array('message' => 'No lead data provided'));
            return;
        }

        // Build tags: auto-tag every captured lead with the flow name (free) +
        // pending tags from flow nodes (free) + any Pro extras.
        $tags = array();
        if ($flow_id) {
            $flow_name = $wpdb->get_var($wpdb->prepare(
                "SELECT flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE id = %d", $flow_id
            ));
            if ($flow_name) {
                $tags[] = sanitize_text_field($flow_name);
            }
        }
        $pending_tags_json = isset($_POST['pending_tags']) ? wp_unslash($_POST['pending_tags']) : '[]';
        $pending_tags = json_decode($pending_tags_json, true);
        if (is_array($pending_tags)) {
            $tags = array_merge($tags, array_map('sanitize_text_field', $pending_tags));
        }

        // Save lead to database — pass session_id so lead row links to its conversation
        $lead_id = DialogaWP_Database::save_lead($lead_data, $tags, $session_id);

        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Failed to save lead data'));
            return;
        }
        
        // Update conversation with lead data
        DialogaWP_Database::update_conversation_lead($session_id, $lead_data);

        // Save conversation transcript if provided. Decode → recursively
        // sanitize every key/value → re-encode before storing, so nothing
        // untrusted from the widget lands in wp_dialogawp_conversations verbatim.
        $history_json = isset($_POST['history']) ? wp_unslash($_POST['history']) : '';
        if ($history_json) {
            $history_decoded = json_decode($history_json, true);
            if (is_array($history_decoded)) {
                $history_clean = dialogawp_sanitize_json($history_decoded);
                $wpdb->update(
                    $wpdb->prefix . 'dialogawp_conversations',
                    array('conversation_data' => wp_json_encode($history_clean), 'updated_at' => current_time('mysql')),
                    array('session_id' => $session_id),
                    array('%s', '%s'),
                    array('%s')
                );
            }
        }

        // Fire action for Pro features (webhooks, etc.)
        $variables_json = isset($_POST['variables']) ? wp_unslash($_POST['variables']) : '{}';
        $variables_decoded = json_decode($variables_json, true);
        if (!is_array($variables_decoded)) $variables_decoded = array();
        $variables = dialogawp_sanitize_json($variables_decoded);
        // 6th arg is the node ID that captured the lead — used by the webhook walker
        // to start from the right place. For the standard form path it's the current
        // Capture Lead node (may not be tracked here — pass null to fall back to lookup).
        $source_node_id = isset($_POST['node_id']) ? sanitize_text_field(wp_unslash($_POST['node_id'])) : null;
        do_action('dialogawp_lead_captured', $lead_id, $lead_data, $flow_id, $variables, $tags, $source_node_id);

        // Track lead capture event
        if ($flow_id) {
            $conv_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dialogawp_conversations WHERE session_id = %s LIMIT 1",
                $session_id
            ));
            $conv_id = $conv_row ? intval($conv_row->id) : 0;
            if ($conv_id) {
                DialogaWP_Analytics::track_event($conv_id, 'lead_captured', array(
                    'flow_id' => $flow_id,
                    'fields'  => array_keys($lead_data),
                ), $flow_id);
            }
        }

        // Send email notification if enabled — include conversation transcript when available.
        // v0.38.7: skip email when the lead came from an admin testing session, unless the
        // "Include admin sessions in analytics" toggle is on, otherwise inbox fills with
        // your own test submissions.
        $settings = get_option('dialogawp_settings', array());
        $email_cfg = isset($settings['email_notifications']) ? $settings['email_notifications'] : array();
        $is_admin_lead = (is_user_logged_in() && current_user_can('manage_options'));
        $include_admin = !empty($settings['include_admin_sessions']);
        if (!empty($email_cfg['enabled']) && (!$is_admin_lead || $include_admin)) {
            $history_for_email = array();
            if (!empty($history_json)) {
                $decoded_history = json_decode($history_json, true);
                if (is_array($decoded_history)) $history_for_email = dialogawp_sanitize_json($decoded_history);
            }

            // v0.39.3: append the two bubbles that fire client-side AFTER this AJAX
            // completes — a user-side acknowledgment and the flow's success message —
            // so the email transcript reads coherently instead of cutting off at the
            // Capture Lead intro bubble. The client sends `success_message` alongside
            // the history for exactly this purpose; falls back to a generic string.
            $projected_success = isset($_POST['success_message'])
                ? sanitize_text_field(wp_unslash($_POST['success_message']))
                : "Thanks! We'll be in touch soon.";
            $now_stamp = current_time('g:i A');
            $history_for_email[] = array(
                'type'      => 'user',
                'text'      => '✓ Contact details submitted',
                'timestamp' => $now_stamp,
            );
            $history_for_email[] = array(
                'type'      => 'bot',
                'message'   => array('type' => 'message', 'text' => $projected_success),
                'timestamp' => $now_stamp,
            );

            // v0.39.11 — per-node email overrides. Look up the current Capture Lead
            // node's config; if `override_email_settings` is true, pass its recipient/
            // subject/intro to send_lead_notification so it replaces the global values.
            // Empty array by default → send_lead_notification uses $email_cfg globals.
            $node_overrides = self::get_capture_lead_email_overrides($flow_id, $source_node_id);

            self::send_lead_notification(
                $lead_data,
                $flow_id,
                $email_cfg,
                $history_for_email,
                $node_overrides,
                $variables
            );
        }

        // Return success — includes a `tracking` payload the widget uses for
        // dataLayer.push (GTM) + a `dialogawp:lead-captured` DOM event (Pro consumers).
        wp_send_json_success(array(
            'message'  => 'Lead captured successfully',
            'lead_id'  => $lead_id,
            'tracking' => self::build_tracking_payload($lead_id, $lead_data, $flow_id, $source_node_id ?: 'capture_lead'),
        ));
    }

    /**
     * Build the conversion-tracking payload attached to lead-capture AJAX responses.
     *
     * The widget pushes this object to `window.dataLayer` (for GTM listeners) AND
     * dispatches it as a `dialogawp:lead-captured` DOM event (which the Pro plugin's
     * tracking module listens to for direct-fire to GA4 / Google Ads / Meta Pixel).
     *
     * Filter `dialogawp_lead_conversion_payload` to override any field — e.g., set a
     * non-zero default `value` for lead scoring, or add custom fields for GTM tags.
     *
     * @param int    $lead_id
     * @param array  $lead_data
     * @param int    $flow_id
     * @param string $source_node_id  'capture_lead' | 'ai_chat' | actual node id
     * @return array
     */
    public static function build_tracking_payload($lead_id, $lead_data, $flow_id, $source_node_id = 'capture_lead') {
        global $wpdb;
        $flow_name = '';
        if ($flow_id) {
            $flow_name = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE id = %d LIMIT 1",
                intval($flow_id)
            ));
        }
        $payload = array(
            'event'              => 'dialogawp_lead_captured',
            'dialogawp_lead_id'     => intval($lead_id),
            'dialogawp_flow_id'     => intval($flow_id),
            'dialogawp_flow_name'   => $flow_name,
            'dialogawp_source_node' => (string) $source_node_id,
            'value'              => 0,
            'currency'           => 'USD',
        );
        return apply_filters('dialogawp_lead_conversion_payload', $payload, $lead_id, $lead_data, $flow_id);
    }

    /**
     * AJAX: Track a client-side event (e.g. conversation abandoned)
     */
    public static function ajax_track_event() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $event_type      = isset($_POST['event_type'])      ? sanitize_key($_POST['event_type'])  : '';
        $flow_id         = isset($_POST['flow_id'])         ? intval($_POST['flow_id'])            : 0;

        // v0.39.4: `link_clicked` added — fired by the Link Button action node when a
        // visitor clicks the CTA. Payload carries the URL and node_id so admins can see
        // which specific button was clicked in analytics.
        $allowed_events = array('conversation_abandoned', 'link_clicked');
        if (!$conversation_id || !in_array($event_type, $allowed_events, true)) {
            wp_send_json_error('Invalid');
            return;
        }

        $meta = array('flow_id' => $flow_id);
        if ($event_type === 'link_clicked') {
            $meta['url']     = isset($_POST['url'])     ? esc_url_raw(wp_unslash($_POST['url']))         : '';
            $meta['node_id'] = isset($_POST['node_id']) ? sanitize_text_field(wp_unslash($_POST['node_id'])) : '';
            $meta['label']   = isset($_POST['label'])   ? sanitize_text_field(wp_unslash($_POST['label']))   : '';
            // v0.40.0 — no per-event `is_conversion` storage. Conversion status
            // is looked up at metric-query time from the current flow config
            // (dialogawp_analytics::get_conversion_link_node_ids). This makes
            // toggling the checkbox retroactive: past clicks on a node count
            // as soon as the flag is enabled, stop counting when it's off.
        }

        DialogaWP_Analytics::track_event($conversation_id, $event_type, $meta, $flow_id);
        wp_send_json_success();
    }

    /**
     * v0.38.7 — AJAX: log a bubble-shown impression. Fired once per page-load by
     * the widget so the admin can see real top-of-funnel data:
     *   impressions → opens → engagements → leads
     *
     * Lighter than ajax_track_event because there's no conversation yet; we pass
     * conversation_id = 0. Skipped for admin users unless they've opted to include
     * admin sessions in analytics (Settings → Data Quality).
     */
    public static function ajax_track_bubble_shown() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        // Don't log admin impressions by default
        $settings = get_option('dialogawp_settings', array());
        $include_admin = !empty($settings['include_admin_sessions']);
        if (is_user_logged_in() && current_user_can('manage_options') && !$include_admin) {
            wp_send_json_success(array('logged' => false, 'reason' => 'admin'));
            return;
        }

        $flow_id  = isset($_POST['flow_id'])  ? intval($_POST['flow_id'])           : 0;
        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url'])      : '';

        DialogaWP_Analytics::track_event(0, 'bubble_shown', array('page_url' => $page_url), $flow_id);
        wp_send_json_success(array('logged' => true));
    }

    /**
     * Persist the in-flight conversation transcript. Called on chat close, flow end,
     * and page unload (via sendBeacon) so we capture transcripts even when no lead is submitted.
     */
    public static function ajax_save_transcript() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        $session_id   = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $history_json = isset($_POST['history'])    ? wp_unslash($_POST['history'])             : '';

        if (!$session_id || !$history_json) {
            wp_send_json_error('Missing data');
            return;
        }

        // Validate + recursively sanitize the transcript before persisting.
        $decoded = json_decode($history_json, true);
        if (!is_array($decoded)) {
            wp_send_json_error('Invalid history');
            return;
        }
        // Skip saves for trivially-short histories (nothing useful to keep)
        if (count($decoded) < 2) {
            wp_send_json_success(array('saved' => false, 'reason' => 'too_short'));
            return;
        }

        $clean_history = dialogawp_sanitize_json($decoded);
        $history_to_store = wp_json_encode($clean_history);

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'dialogawp_conversations',
            array('conversation_data' => $history_to_store, 'updated_at' => current_time('mysql')),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );

        if ($result === false) {
            wp_send_json_error('DB error: ' . $wpdb->last_error);
            return;
        }
        wp_send_json_success(array('saved' => true));
    }

    /**
     * v0.39.11 — Look up the Capture Lead node's config for email overrides.
     * Returns array with keys `enabled`, `recipients`, `subject`, `intro` when the
     * node opted in via the "Override global email settings" checkbox in the
     * flow builder. Returns empty array otherwise so callers fall back to globals.
     *
     * @param int         $flow_id
     * @param string|null $node_id  The Capture Lead node's ID (from ajax_submit_lead's POST)
     * @return array
     */
    private static function get_capture_lead_email_overrides($flow_id, $node_id) {
        if (!$flow_id || !$node_id) return array();

        global $wpdb;
        $flow_row = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialogawp_flows WHERE id = %d",
            intval($flow_id)
        ));
        if (!$flow_row || empty($flow_row->flow_data)) return array();

        $flow_data = json_decode($flow_row->flow_data, true);
        if (!is_array($flow_data) || empty($flow_data['nodes'])) return array();

        foreach ($flow_data['nodes'] as $node) {
            if (!isset($node['id']) || $node['id'] !== $node_id) continue;
            $config = isset($node['data']['config']) ? $node['data']['config'] : array();
            if (empty($config['override_email_settings'])) return array();

            return array(
                'enabled'    => true,
                'recipients' => isset($config['email_recipients']) ? (string) $config['email_recipients'] : '',
                'subject'    => isset($config['email_subject'])    ? (string) $config['email_subject']    : '',
                'intro'      => isset($config['email_intro'])      ? (string) $config['email_intro']      : '',
            );
        }
        return array();
    }

    /**
     * v0.39.11 — Substitute {variable_name} tokens in a template string using
     * a merge of captured lead data and flow variables. Lead fields expose
     * both `name` and `user_name` (etc.) for admin convenience. Unmatched
     * tokens are left in place so admins can spot typos.
     */
    private static function substitute_variables($template, $lead_data, $variables) {
        if (!is_string($template) || $template === '') return $template;
        $merged = array();
        foreach ((array) $variables as $k => $v) {
            $merged[(string) $k] = is_scalar($v) ? (string) $v : '';
        }
        foreach ((array) $lead_data as $k => $v) {
            if (!is_scalar($v)) continue;
            $merged[(string) $k]         = (string) $v;
            $merged['user_' . (string) $k] = (string) $v;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_\-]+)\}/', function($m) use ($merged) {
            return isset($merged[$m[1]]) ? $merged[$m[1]] : $m[0];
        }, $template);
    }

    /**
     * Send email notification when a lead is captured.
     *
     * @param array  $lead_data       Captured lead fields (name, email, phone, etc.)
     * @param int    $flow_id         Flow that produced the lead
     * @param array  $email_cfg       Global email settings — `recipient` may be a comma-separated list
     * @param array  $history         Optional conversation history array (rendered into the email)
     * @param array  $node_overrides  v0.39.11 — Optional per-Capture-Lead-node overrides:
     *                                {enabled, recipients, subject, intro}. When enabled, these
     *                                replace the global recipient/subject and add an intro block.
     * @param array  $variables       v0.39.11 — Flow variables (from Question node "Save As")
     *                                available for {token} substitution in subject + intro.
     */
    private static function send_lead_notification($lead_data, $flow_id, $email_cfg, $history = array(), $node_overrides = array(), $variables = array()) {
        // v0.39.11 — resolve override or fallback for each field
        $use_override = !empty($node_overrides['enabled']);
        $raw_recipients = $use_override && !empty($node_overrides['recipients'])
            ? $node_overrides['recipients']
            : (!empty($email_cfg['recipient']) ? $email_cfg['recipient'] : get_option('admin_email'));

        // Normalize recipients — accept comma-separated string, return array of valid addresses
        $recipients = array_values(array_filter(array_map(function ($e) {
            return sanitize_email(trim($e));
        }, explode(',', $raw_recipients))));
        if (empty($recipients)) {
            return; // Nothing to send to
        }

        $subject_template = $use_override && !empty($node_overrides['subject'])
            ? $node_overrides['subject']
            : (!empty($email_cfg['subject']) ? $email_cfg['subject'] : 'New Lead from DialogaWP');
        $subject = self::substitute_variables($subject_template, $lead_data, $variables);

        // Optional per-node intro block (prepended to standard body). Supports variables.
        $intro_html = '';
        if ($use_override && !empty($node_overrides['intro'])) {
            $intro_raw = self::substitute_variables($node_overrides['intro'], $lead_data, $variables);
            $intro_html = '<p style="margin:0 0 20px;padding:12px 16px;background:#E6F7F6;border-left:4px solid #00ADA1;font-size:14px;color:#26233A;border-radius:4px;">'
                        . nl2br(esc_html($intro_raw))
                        . '</p>';
        }

        // Build lead rows for the email
        $field_labels = array(
            'name'    => 'Name',
            'email'   => 'Email',
            'phone'   => 'Phone',
            'company' => 'Company',
        );
        $rows = '';
        foreach ($lead_data as $key => $value) {
            $label  = isset($field_labels[$key]) ? $field_labels[$key] : ucfirst($key);
            $rows  .= '<tr>';
            $rows  .= '<td style="padding:8px 12px;font-weight:600;color:#374151;background:#F7F8FC;width:120px;">' . esc_html($label) . '</td>';
            $rows  .= '<td style="padding:8px 12px;color:#26233A;">' . esc_html($value) . '</td>';
            $rows  .= '</tr>';
        }

        // Flow name
        global $wpdb;
        $flow_name = $wpdb->get_var($wpdb->prepare(
            "SELECT flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE id = %d",
            $flow_id
        ));
        $flow_label = $flow_name ? esc_html($flow_name) : 'Unknown Flow';

        $site_name = get_bloginfo('name');
        $site_url  = home_url();
        $leads_url = admin_url('admin.php?page=dialogawp-leads');
        $time      = current_time('F j, Y \a\t g:i a');

        // Build conversation transcript HTML (if history provided)
        $transcript_html = '';
        if (!empty($history) && is_array($history)) {
            $bubbles = '';
            foreach ($history as $item) {
                $is_user = isset($item['type']) && $item['type'] === 'user';
                $text    = '';
                if ($is_user) {
                    // User entries store the answer either directly on `text` or under
                    // various keys depending on input type — try a few common ones.
                    if (isset($item['text']))            $text = $item['text'];
                    elseif (isset($item['answer']))      $text = $item['answer'];
                    elseif (isset($item['value']))       $text = $item['value'];
                    elseif (isset($item['message']['text'])) $text = $item['message']['text'];
                } else {
                    if (isset($item['message']['text'])) $text = $item['message']['text'];
                    elseif (isset($item['text']))        $text = $item['text'];
                }
                if (!is_string($text) || $text === '') continue;
                $text = wp_strip_all_tags($text);

                if ($is_user) {
                    $bubbles .= '<tr><td style="padding:4px 0;text-align:right;">'
                              . '<div style="display:inline-block;max-width:75%;background:#190F4F;color:#fff;padding:8px 12px;border-radius:14px 14px 4px 14px;font-size:13px;line-height:1.4;text-align:left;">'
                              . esc_html($text)
                              . '</div></td></tr>';
                } else {
                    $bubbles .= '<tr><td style="padding:4px 0;text-align:left;">'
                              . '<div style="display:inline-block;max-width:75%;background:#F7F8FC;color:#26233A;padding:8px 12px;border-radius:14px 14px 14px 4px;font-size:13px;line-height:1.4;">'
                              . esc_html($text)
                              . '</div></td></tr>';
                }
            }
            if ($bubbles !== '') {
                $transcript_html =
                    '<p style="margin:28px 0 8px;font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:1px;">Conversation</p>'
                  . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#F7F8FC;border:1px solid #E5E7EF;border-radius:6px;padding:12px;">'
                  . $bubbles
                  . '</table>';
            }
        }

        $body = '
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#F7F8FC;font-family:sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F7F8FC;padding:32px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);">
      <tr><td style="background:#190F4F;padding:24px 32px;">
        <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;">&#128233; New Lead Captured</p>
        <p style="margin:6px 0 0;font-size:13px;color:#8FE3DD;">' . $site_name . ' &mdash; ' . $time . '</p>
      </td></tr>
      <tr><td style="padding:24px 32px;">
        ' . $intro_html . '
        <p style="margin:0 0 16px;font-size:14px;color:#6b7280;">A new lead was captured via the <strong>' . $flow_label . '</strong> flow on <a href="' . esc_url($site_url) . '" style="color:#190F4F;">' . esc_html($site_name) . '</a>.</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E5E7EF;border-radius:6px;overflow:hidden;font-size:14px;">
          ' . $rows . '
        </table>
        ' . $transcript_html . '
        <p style="margin:24px 0 0;text-align:center;">
          <a href="' . esc_url($leads_url) . '" style="background:#FD6A4D;color:#26233A;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;">View All Leads &rarr;</a>
        </p>
      </td></tr>
      <tr><td style="padding:16px 32px;border-top:1px solid #E5E7EF;text-align:center;">
        <p style="margin:0;font-size:12px;color:#9ca3af;">Sent by <a href="https://www.litiobyte.com/labs-detalle.php?slug=dialogawp" style="color:#190F4F;text-decoration:none;">DialogaWP</a> on ' . esc_html($site_name) . '</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($recipients, $subject, $body, $headers);
    }

    /**
     * AJAX: Update tags on a lead (admin only)
     */
    public static function ajax_update_lead_tags() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'dialogawp'));
            return;
        }

        $lead_id = intval($_POST['lead_id']);
        $tags    = isset($_POST['tags']) && is_array($_POST['tags'])
            ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $_POST['tags']))))
            : array();

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dialogawp_leads',
            array('tags' => !empty($tags) ? json_encode($tags) : null),
            array('id' => $lead_id),
            array('%s'),
            array('%d')
        );

        wp_send_json_success(array('tags' => $tags));
    }

    /**
     * AJAX: Return the full detail payload for a single lead (admin only).
     * Used by the Leads admin page "View" button to populate the detail modal.
     */
    public static function ajax_get_lead_details() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'dialogawp'));
            return;
        }

        global $wpdb;
        $lead_id = intval($_POST['lead_id']);

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dialogawp_leads WHERE id = %d",
            $lead_id
        ));

        if (!$lead) {
            wp_send_json_error(array('message' => __('Lead not found', 'dialogawp')));
            return;
        }

        // Pull the linked conversation (if any) for context + transcript
        $conversation = null;
        if (!empty($lead->conversation_id)) {
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT id, session_id, flow_id, conversation_data, lead_data, ip_address, user_agent, page_url, created_at, updated_at
                 FROM {$wpdb->prefix}dialogawp_conversations WHERE id = %d",
                $lead->conversation_id
            ));
        }

        // Flow name (nice-to-have context)
        $flow_name = '';
        if ($conversation && !empty($conversation->flow_id)) {
            $flow_name = $wpdb->get_var($wpdb->prepare(
                "SELECT flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE id = %d",
                $conversation->flow_id
            ));
        }

        // Decode lead_data (captured form fields beyond name/email/phone)
        $lead_data = !empty($lead->lead_data) ? json_decode($lead->lead_data, true) : array();
        if (!is_array($lead_data)) $lead_data = array();

        // Decode tags
        $tags = !empty($lead->tags) ? json_decode($lead->tags, true) : array();
        if (!is_array($tags)) $tags = array();

        // Decode transcript
        $transcript = array();
        if ($conversation && !empty($conversation->conversation_data)) {
            $decoded = json_decode($conversation->conversation_data, true);
            if (is_array($decoded)) $transcript = $decoded;
        }

        wp_send_json_success(array(
            'lead'         => array(
                'id'         => (int) $lead->id,
                'name'       => $lead->name,
                'email'      => $lead->email,
                'phone'      => $lead->phone,
                'status'     => $lead->status,
                'tags'       => $tags,
                'lead_data'  => $lead_data,
                'created_at' => $lead->created_at,
            ),
            'conversation' => $conversation ? array(
                'id'         => (int) $conversation->id,
                'session_id' => $conversation->session_id,
                'flow_id'    => (int) $conversation->flow_id,
                'flow_name'  => $flow_name,
                'page_url'   => $conversation->page_url,
                'ip_address' => $conversation->ip_address,
                'user_agent' => $conversation->user_agent,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ) : null,
            'transcript'   => $transcript,
        ));
    }

    /**
     * Interpolate {{variable}} placeholders in text
     */
    private static function interpolate_variables($text, $variables) {
        if (empty($variables) || empty($text)) return $text;
        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Walk an action node's config and interpolate {{var}} tokens in any string
     * value (including values inside nested arrays). Non-string values pass
     * through untouched. Used to expand saved variables inside AI Chat greetings,
     * redirect messages, capture-lead success messages, etc.
     */
    private static function interpolate_config_strings($config, $variables) {
        if (!is_array($config) || empty($variables)) return $config;
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = self::interpolate_variables($value, $variables);
            } elseif (is_array($value)) {
                $config[$key] = self::interpolate_config_strings($value, $variables);
            }
        }
        return $config;
    }

    /**
     * Format node response for frontend
     */
    /**
     * AJAX: Submit survey responses.
     */
    public static function ajax_submit_survey() {
        check_ajax_referer('dialogawp_widget', 'nonce');

        $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        $flow_id    = intval($_POST['flow_id'] ?? 0);

        $responses_raw = isset($_POST['responses']) ? wp_unslash($_POST['responses']) : '{}';
        $decoded_responses = json_decode($responses_raw, true);
        if (!is_array($decoded_responses)) $decoded_responses = array();
        $responses = dialogawp_sanitize_json($decoded_responses);

        if (empty($responses)) {
            wp_send_json_error(array('message' => 'No responses provided.'));
        }

        // Save as a lead with type 'survey'
        $lead_data = array(
            'name' => 'Survey Response',
            'email' => '',
            'phone' => '',
            'custom_fields' => $responses,
        );

        $lead_id = DialogaWP_Database::save_lead($lead_data, array('survey'));

        if ($lead_id) {
            // Track the event
            if (class_exists('DialogaWP_Analytics')) {
                DialogaWP_Analytics::track_event('survey_submitted', $flow_id, array(
                    'session_id' => $session_id,
                    'response_count' => count($responses),
                ));
            }
        }

        wp_send_json_success(array('message' => 'Survey submitted.'));
    }

    private static function format_node_response($node, $flow_data, $variables = array()) {
        $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : array();

        /*
         * v1.0.4 — read the type defensively.
         *
         * This was a bare $node['data']['nodeType'], which on PHP 8 emits a
         * warning and evaluates to null whenever the key is absent. Flows
         * saved by v1.0.0-v1.0.3 store it as 'nodetype' (the old sanitizer
         * lowercased every key), so the very first node of every such flow
         * fell through to the default branch below and the visitor's opening
         * message was "Configuration error. Please contact support."
         *
         * DialogaWP_Database::repair_flow_key_case() rewrites those rows on
         * upgrade; the lowercase fallback here covers flows that arrive some
         * other way — an import, a restored backup, a manual DB edit.
         */
        $node_type = '';
        if (isset($data['nodeType']) && is_string($data['nodeType'])) {
            $node_type = $data['nodeType'];
        } elseif (isset($data['nodetype']) && is_string($data['nodetype'])) {
            $node_type = $data['nodetype'];
        }

        // Handle question nodes
        if ($node_type === 'question') {
            $message = isset($node['data']['message']) ? $node['data']['message'] :
                      (isset($node['data']['question']) ? $node['data']['question'] : '');

            $response = array(
                'type' => 'question',
                'text' => self::interpolate_variables($message, $variables),
                'auto_continue' => isset($node['data']['auto_continue']) ? $node['data']['auto_continue'] : false,
                'delay' => isset($node['data']['delay']) ? $node['data']['delay'] : 0,
                'allow_text' => isset($node['data']['allow_text_input']) ? $node['data']['allow_text_input'] : false,
                'allow_multiple' => !empty($node['data']['allow_multiple']),
                'placeholder' => isset($node['data']['text_placeholder']) ? $node['data']['text_placeholder'] : 'Type your message...',
                'options' => isset($node['data']['options']) ? $node['data']['options'] : array()
            );

            return $response;
        }
        
        // Handle message nodes (legacy - treated as question with auto_continue)
        if ($node_type === 'message') {
            $message = isset($node['data']['message']) ? $node['data']['message'] : '';

            return array(
                'type' => 'question',
                'text' => self::interpolate_variables($message, $variables),
                'auto_continue' => true,
                'delay' => isset($node['data']['delay']) ? $node['data']['delay'] : 1,
                'allow_text' => false,
                'options' => array()
            );
        }
        
        // Handle action nodes
        if ($node_type === 'action') {
            $action_type = isset($node['data']['action_type']) ? $node['data']['action_type'] : 'capture_lead';
            $config = isset($node['data']['config']) ? $node['data']['config'] : array();

            return array(
                'type' => 'action',
                'action_type' => $action_type,
                'config' => self::interpolate_config_strings($config, $variables)
            );
        }

        // Handle ai_chat top-level node
        if ($node_type === 'ai_chat') {
            $config = isset($node['data']['config']) ? $node['data']['config'] : array();
            return array(
                'type'        => 'action',
                'action_type' => 'ai_chat',
                'config'      => self::interpolate_config_strings($config, $variables)
            );
        }

        /*
         * Default fallback. Reaching here means a node carries a type this
         * build cannot render — so say so in the log, because the visitor-
         * facing string alone gives nobody anything to debug with.
         */
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'DialogaWP: unrenderable node %s with nodeType "%s" — falling back to the configuration-error message.',
                isset($node['id']) ? $node['id'] : '(no id)',
                $node_type
            ));
        }

        return array(
            'type' => 'message',
            'text' => 'Configuration error. Please contact support.'
        );
    }

    // -------------------------------------------------------------------------
    // Lead deletion + retention
    // -------------------------------------------------------------------------

    /**
     * Atomic per-lead delete. Cleans up all three places lead data lives:
     *  - the row in dialogawp_leads
     *  - the lead_data snapshot on dialogawp_conversations (so conversion rate drops too)
     *  - the lead_captured analytics events on dialogawp_analytics
     *
     * v0.39.5: instead of clearing `lead_data` to NULL, we now write a TOMBSTONE
     * marker so the conversation retains proof that a lead WAS captured historically.
     * The Pro Conversations tab displays these as "🗑 Lead removed" rather than the
     * misleading "⚠ Abandoned" label. Analytics conversion rate math stays clean
     * because it filters on `lead_data IS NOT NULL AND lead_data != '{}'` (tombstone
     * has `_deleted:true` which is truthy — but we also drop the lead_captured
     * analytics events, so conversion rate queries against dialogawp_analytics see
     * the drop correctly).
     */
    private static function purge_lead_data($lead_id, $conversation_id) {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'dialogawp_leads',
            array('id' => $lead_id),
            array('%d')
        );

        if ($conversation_id) {
            $tombstone = wp_json_encode(array(
                '_deleted'    => true,
                '_deleted_at' => current_time('mysql'),
            ));
            $wpdb->update(
                $wpdb->prefix . 'dialogawp_conversations',
                array('lead_data' => $tombstone),
                array('id' => $conversation_id),
                array('%s'),
                array('%d')
            );
            $wpdb->delete(
                $wpdb->prefix . 'dialogawp_analytics',
                array(
                    'conversation_id' => $conversation_id,
                    'event_type'      => 'lead_captured',
                ),
                array('%d', '%s')
            );
        }
    }

    public static function ajax_delete_lead() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden', 'dialogawp')));
            return;
        }

        $lead_id = isset($_POST['lead_id']) ? intval($_POST['lead_id']) : 0;
        if (!$lead_id) {
            wp_send_json_error(array('message' => __('Invalid lead id', 'dialogawp')));
            return;
        }

        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, conversation_id FROM {$wpdb->prefix}dialogawp_leads WHERE id = %d",
            $lead_id
        ));
        if (!$lead) {
            wp_send_json_error(array('message' => __('Lead not found', 'dialogawp')));
            return;
        }

        self::purge_lead_data((int) $lead->id, (int) $lead->conversation_id);

        wp_send_json_success(array('deleted_lead_id' => (int) $lead->id));
    }

    /**
     * Bulk "Clear All Leads" — used to reset test data on dev installs.
     * Requires the user to type the literal string "DELETE" as confirmation.
     */
    public static function ajax_delete_all_leads() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden', 'dialogawp')));
            return;
        }

        $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
        if ($confirm !== 'DELETE') {
            wp_send_json_error(array('message' => __('Type DELETE to confirm', 'dialogawp')));
            return;
        }

        global $wpdb;
        $leads_table     = $wpdb->prefix . 'dialogawp_leads';
        $conv_table      = $wpdb->prefix . 'dialogawp_conversations';
        $analytics_table = $wpdb->prefix . 'dialogawp_analytics';

        // Capture conversation_ids tied to leads BEFORE wiping the leads table,
        // so we can clean up the related conversation+analytics rows after.
        $conv_ids = $wpdb->get_col("SELECT conversation_id FROM {$leads_table} WHERE conversation_id IS NOT NULL");
        $count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table}");

        $wpdb->query("TRUNCATE TABLE {$leads_table}");

        if (!empty($conv_ids)) {
            $conv_ids = array_map('intval', $conv_ids);
            $placeholders = implode(',', array_fill(0, count($conv_ids), '%d'));
            // v0.39.5: write tombstone marker so conversations show as "Lead removed"
            // in the Pro Conversations tab rather than misleadingly-labeled Abandoned.
            $tombstone = wp_json_encode(array(
                '_deleted'    => true,
                '_deleted_at' => current_time('mysql'),
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$conv_table} SET lead_data = %s WHERE id IN ($placeholders)",
                array_merge(array($tombstone), $conv_ids)
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$analytics_table} WHERE event_type = 'lead_captured' AND conversation_id IN ($placeholders)",
                $conv_ids
            ));
        }

        wp_send_json_success(array('deleted_count' => $count));
    }

    /**
     * Daily cron — delete leads older than the configured retention window.
     * Hooked on `dialogawp_lead_retention_cleanup` event scheduled at settings save.
     */
    public static function cron_apply_retention_policy() {
        $settings  = get_option('dialogawp_settings', array());
        $retention = isset($settings['lead_retention']) ? $settings['lead_retention'] : array();

        if (empty($retention['enabled'])) {
            return;
        }

        $days = isset($retention['days']) ? max(1, intval($retention['days'])) : 30;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        global $wpdb;
        $old = $wpdb->get_results($wpdb->prepare(
            "SELECT id, conversation_id FROM {$wpdb->prefix}dialogawp_leads WHERE created_at < %s",
            $cutoff
        ));

        foreach ($old as $lead) {
            self::purge_lead_data((int) $lead->id, (int) $lead->conversation_id);
        }
    }

    // -------------------------------------------------------------------------
    // Conversation deletion + retention (v0.38.18)
    //
    // A conversation is the parent record — deleting one cascades to its lead
    // (if captured) and all analytics events tied to it. Same 3-table pattern
    // as purge_lead_data, but rooted from the conversation side.
    // -------------------------------------------------------------------------

    /**
     * Atomic per-conversation delete. Cleans up all three tables at once:
     *  - the row in dialogawp_conversations
     *  - any linked lead in dialogawp_leads
     *  - all analytics events tied to this conversation
     */
    private static function purge_conversation_data($conversation_id) {
        global $wpdb;
        $conversation_id = (int) $conversation_id;
        if (!$conversation_id) return;

        $wpdb->delete(
            $wpdb->prefix . 'dialogawp_leads',
            array('conversation_id' => $conversation_id),
            array('%d')
        );
        $wpdb->delete(
            $wpdb->prefix . 'dialogawp_analytics',
            array('conversation_id' => $conversation_id),
            array('%d')
        );
        $wpdb->delete(
            $wpdb->prefix . 'dialogawp_conversations',
            array('id' => $conversation_id),
            array('%d')
        );
    }

    public static function ajax_delete_conversation() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden', 'dialogawp')));
            return;
        }

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        if (!$conversation_id) {
            wp_send_json_error(array('message' => __('Invalid conversation id', 'dialogawp')));
            return;
        }

        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dialogawp_conversations WHERE id = %d",
            $conversation_id
        ));
        if (!$exists) {
            wp_send_json_error(array('message' => __('Conversation not found', 'dialogawp')));
            return;
        }

        self::purge_conversation_data($conversation_id);

        wp_send_json_success(array('deleted_conversation_id' => $conversation_id));
    }

    /**
     * Bulk "Clear All Conversations" — reset every conversation, lead, and
     * analytics event in one shot. Requires typed DELETE confirmation.
     */
    public static function ajax_delete_all_conversations() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden', 'dialogawp')));
            return;
        }

        $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
        if ($confirm !== 'DELETE') {
            wp_send_json_error(array('message' => __('Type DELETE to confirm', 'dialogawp')));
            return;
        }

        global $wpdb;
        $conv_table      = $wpdb->prefix . 'dialogawp_conversations';
        $leads_table     = $wpdb->prefix . 'dialogawp_leads';
        $analytics_table = $wpdb->prefix . 'dialogawp_analytics';

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$conv_table}");

        // TRUNCATE resets AUTO_INCREMENT and is faster than DELETE for full wipes.
        $wpdb->query("TRUNCATE TABLE {$conv_table}");
        $wpdb->query("TRUNCATE TABLE {$leads_table}");
        $wpdb->query("TRUNCATE TABLE {$analytics_table}");

        wp_send_json_success(array('deleted_count' => $count));
    }

    /**
     * Daily cron — delete abandoned conversations older than the configured
     * retention window. Abandoned = conversation with no captured lead
     * (lead_data IS NULL OR '{}'). Conversations WITH leads are preserved
     * — those get cleaned by the separate lead-retention cron based on the
     * lead's retention rules.
     */
    public static function cron_apply_conversation_retention_policy() {
        $settings  = get_option('dialogawp_settings', array());
        $retention = isset($settings['conversation_retention']) ? $settings['conversation_retention'] : array();

        if (empty($retention['enabled'])) {
            return;
        }

        $days = isset($retention['days']) ? max(1, intval($retention['days'])) : 30;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        global $wpdb;
        $conv_table  = $wpdb->prefix . 'dialogawp_conversations';

        // Only abandoned — anything with real lead_data (name/email captured)
        // is kept. That data is still recoverable value.
        $old = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$conv_table}
             WHERE created_at < %s
               AND (lead_data IS NULL OR lead_data = '' OR lead_data = '{}')",
            $cutoff
        ));

        foreach ($old as $conv_id) {
            self::purge_conversation_data((int) $conv_id);
        }
    }

    /**
     * v0.38.22 — reset the aggregated analytics event log without touching
     * conversations or leads. Wipes: bubble impressions, node_viewed events,
     * conversation_started markers, conversation_abandoned events,
     * lead_captured events, path_taken events. Anything the Engagement Funnel,
     * Path Flow, and Heatmap views count.
     *
     * Use this to zero out counters after cleaning up test data — otherwise
     * impressions stay high while opens/leads drop, giving a broken funnel.
     */
    public static function ajax_reset_analytics_events() {
        check_ajax_referer('dialogawp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden', 'dialogawp')));
            return;
        }

        $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash($_POST['confirm'])) : '';
        if ($confirm !== 'DELETE') {
            wp_send_json_error(array('message' => __('Type DELETE to confirm', 'dialogawp')));
            return;
        }

        global $wpdb;
        $analytics_table = $wpdb->prefix . 'dialogawp_analytics';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analytics_table}");
        $wpdb->query("TRUNCATE TABLE {$analytics_table}");

        wp_send_json_success(array('deleted_count' => $count));
    }

    /* ------------------------------------------------------------------ */
    /*  v1.0.0 — Flow cache. The two frontend-hot queries — "list active   */
    /*  flows" and "fetch flow by id" — run on every page load / every     */
    /*  chat step. On a busy site that is thousands of DB roundtrips per   */
    /*  day for data that changes maybe a handful of times. Cache them in  */
    /*  the `dialogawp_flows` object-cache group and bust explicitly on save  */
    /*  / delete / active-toggle. See invalidate_flow_cache() below.       */
    /* ------------------------------------------------------------------ */

    const CACHE_GROUP           = 'dialogawp_flows';
    const CACHE_KEY_ACTIVE_LIST = 'active_flows';
    const CACHE_TTL             = 12 * HOUR_IN_SECONDS;

    public static function get_active_flows_cached() {
        $cached = wp_cache_get(self::CACHE_KEY_ACTIVE_LIST, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        global $wpdb;
        $flows_table = $wpdb->prefix . 'dialogawp_flows';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results("SELECT * FROM {$flows_table} WHERE is_active = 1 ORDER BY id DESC");
        if (!is_array($rows)) $rows = array();
        wp_cache_set(self::CACHE_KEY_ACTIVE_LIST, $rows, self::CACHE_GROUP, self::CACHE_TTL);
        return $rows;
    }

    public static function get_flow_by_id_cached($flow_id) {
        $flow_id = (int) $flow_id;
        if ($flow_id <= 0) return null;
        $key    = 'flow_' . $flow_id;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        global $wpdb;
        $flows_table = $wpdb->prefix . 'dialogawp_flows';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$flows_table} WHERE id = %d", $flow_id));
        // Cache the null too so a missing flow doesn't hammer the DB on every
        // conversation step that references a deleted flow_id.
        wp_cache_set($key, $row, self::CACHE_GROUP, self::CACHE_TTL);
        return $row;
    }

    /**
     * Bust the cache. Call from any code path that writes to dialogawp_flows:
     * save, delete, is_active toggle, visibility change, etc.
     *
     * $flow_id is optional — pass it when you know which row changed to only
     * bust that key + the active-list key. Omit to nuke everything (safer
     * during bulk operations).
     */
    public static function invalidate_flow_cache($flow_id = 0) {
        wp_cache_delete(self::CACHE_KEY_ACTIVE_LIST, self::CACHE_GROUP);
        if ($flow_id) {
            wp_cache_delete('flow_' . (int) $flow_id, self::CACHE_GROUP);
        }
    }
}
