<?php
/**
 * Settings Admin Page
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have permission to manage these settings.', 'dialoga'));
}

$dialoga_current_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
// License rendering remains owned by the extension.
if ($dialoga_current_tab === 'license') {
    do_action('dialoga_settings_license_tab');
    return;
}

// These are content defaults, not interface translations. Never localize saved messages.
$dialoga_defaults = array(
    'enabled' => false,
    'test_mode' => false,
    'bubble_nudge' => true,
    'fresh_session_roles' => array('administrator'),
    'visibility_mode' => 'everyone',
    'visible_roles' => array('administrator'),
    'multi_flow_strategy' => 'specific',
    'primary_color' => '#190F4F',
    'chat_position' => 'bottom-right',
    'session_duration' => 7,
    'chat_header_title' => 'Chat with us',
    'welcome_message' => 'Hi! How can we help you today?',
    'offline_message' => 'We\'re currently offline. Please leave your contact information and we\'ll get back to you.',
    'business_hours' => array('enabled' => false, 'timezone' => 'America/New_York'),
    'email_notifications' => array('enabled' => false, 'recipient' => get_option('admin_email'), 'subject' => 'New Lead from Dialoga'),
    'lead_retention' => array('enabled' => false, 'days' => 30),
    'conversation_retention' => array('enabled' => false, 'days' => 30),
    'include_admin_sessions' => false,
    'preview_message' => array('enabled' => false, 'text' => 'Hi! 👋 Got a question?', 'delay' => 5),
    'bubble_size' => 'md',
    'bubble_unread_badge' => true,
    'bubble_bounce' => true,
);
$dialoga_stored_settings = get_option('dialoga_settings', array());
$dialoga_settings = array_replace($dialoga_defaults, is_array($dialoga_stored_settings) ? $dialoga_stored_settings : array());
foreach ($dialoga_defaults as $dialoga_key => $dialoga_default) {
    if (is_array($dialoga_default) && !in_array($dialoga_key, array('fresh_session_roles', 'visible_roles'), true)) {
        $dialoga_settings[$dialoga_key] = array_replace($dialoga_default, is_array($dialoga_settings[$dialoga_key]) ? $dialoga_settings[$dialoga_key] : array());
    }
}

$dialoga_all_roles = wp_roles()->roles;
$dialoga_saved = false;
$dialoga_save_error = false;
if (isset($_POST['dialoga_save_settings'])) {
    // Reject unexpected shapes before unslashing or invoking any sanitizers.
    if (!isset($_POST['_wpnonce']) || !is_string($_POST['_wpnonce'])) {
        wp_die(esc_html__('Invalid settings request. Please reload the page and try again.', 'dialoga'));
    }
    check_admin_referer('dialoga_settings');
    $dialoga_scalar_fields = array(
        'enabled', 'test_mode', 'bubble_nudge', 'visibility_mode', 'multi_flow_strategy',
        'primary_color', 'chat_position', 'session_duration', 'chat_header_title', 'welcome_message', 'offline_message',
        'business_hours_enabled', 'timezone', 'email_notifications_enabled',
        'email_notifications_recipient', 'email_notifications_subject', 'lead_retention_enabled',
        'lead_retention_days', 'conversation_retention_enabled', 'conversation_retention_days',
        'include_admin_sessions', 'preview_message_enabled', 'preview_message_text',
        'preview_message_delay', 'bubble_size', 'bubble_unread_badge', 'bubble_bounce',
    );
    $dialoga_posted = array();
    foreach ($dialoga_scalar_fields as $dialoga_key) {
        if (isset($_POST[$dialoga_key])) {
            if (!is_string($_POST[$dialoga_key])) {
                $dialoga_save_error = true;
                break;
            }
            // sanitize_textarea_field strips tags but preserves the newlines
            // that the offline/welcome multiline inputs legitimately contain;
            // type-specific sanitizers below narrow each field further.
            $dialoga_posted[$dialoga_key] = sanitize_textarea_field(wp_unslash($_POST[$dialoga_key]));
        }
    }
    foreach (array('fresh_session_roles', 'visible_roles') as $dialoga_key) {
        $dialoga_posted[$dialoga_key] = array();
        if (!isset($_POST[$dialoga_key])) {
            continue;
        }
        if (!is_array($_POST[$dialoga_key])) {
            $dialoga_save_error = true;
            break;
        }
        foreach ($_POST[$dialoga_key] as $dialoga_role) {
            if (!is_string($dialoga_role)) {
                $dialoga_save_error = true;
                break;
            }
            $dialoga_role = sanitize_key(wp_unslash($dialoga_role));
            if (isset($dialoga_all_roles[$dialoga_role])) {
                $dialoga_posted[$dialoga_key][] = $dialoga_role;
            }
        }
        $dialoga_posted[$dialoga_key] = array_values(array_unique($dialoga_posted[$dialoga_key]));
    }

    if (!$dialoga_save_error) {
        // Start from existing options so extension-owned and nested keys survive.
        foreach (array('enabled', 'test_mode', 'bubble_nudge', 'include_admin_sessions', 'bubble_unread_badge', 'bubble_bounce') as $dialoga_key) {
            $dialoga_settings[$dialoga_key] = isset($dialoga_posted[$dialoga_key]) && $dialoga_posted[$dialoga_key] === '1' ? 1 : 0;
        }
        $dialoga_settings['fresh_session_roles'] = $dialoga_posted['fresh_session_roles'];
        $dialoga_settings['visible_roles'] = $dialoga_posted['visible_roles'];
        $dialoga_enums = array(
            'visibility_mode' => array('everyone', 'logged_in', 'specific_roles'),
            'chat_position' => array('bottom-right', 'bottom-left'),
            'bubble_size' => array('sm', 'md', 'lg'),
        );
        foreach ($dialoga_enums as $dialoga_key => $dialoga_allowed) {
            if (isset($dialoga_posted[$dialoga_key]) && in_array($dialoga_posted[$dialoga_key], $dialoga_allowed, true)) {
                $dialoga_settings[$dialoga_key] = $dialoga_posted[$dialoga_key];
            }
        }
        // This field is rendered only by extensions; absence must not reset it.
        if (isset($dialoga_posted['multi_flow_strategy']) && in_array($dialoga_posted['multi_flow_strategy'], array('specific', 'ab', 'newest'), true)) {
            $dialoga_settings['multi_flow_strategy'] = $dialoga_posted['multi_flow_strategy'];
        }
        if (isset($dialoga_posted['primary_color'])) {
            $dialoga_color = sanitize_hex_color($dialoga_posted['primary_color']);
            if ($dialoga_color) {
                $dialoga_settings['primary_color'] = $dialoga_color;
            }
        }
        if (isset($dialoga_posted['session_duration']) && preg_match('/^\d+$/', $dialoga_posted['session_duration'])) {
            $dialoga_settings['session_duration'] = min(90, max(1, intval($dialoga_posted['session_duration'])));
        }
        if (isset($dialoga_posted['chat_header_title'])) {
            $dialoga_header_title = sanitize_text_field($dialoga_posted['chat_header_title']);
            $dialoga_settings['chat_header_title'] = $dialoga_header_title !== '' ? $dialoga_header_title : $dialoga_defaults['chat_header_title'];
        }
        foreach (array('welcome_message', 'offline_message') as $dialoga_key) {
            if (isset($dialoga_posted[$dialoga_key])) {
                $dialoga_settings[$dialoga_key] = sanitize_textarea_field($dialoga_posted[$dialoga_key]);
            }
        }
        $dialoga_settings['business_hours']['enabled'] = isset($dialoga_posted['business_hours_enabled']) && $dialoga_posted['business_hours_enabled'] === '1' ? 1 : 0;
        if (isset($dialoga_posted['timezone']) && in_array($dialoga_posted['timezone'], timezone_identifiers_list(), true)) {
            $dialoga_settings['business_hours']['timezone'] = $dialoga_posted['timezone'];
        }
        $dialoga_settings['email_notifications']['enabled'] = isset($dialoga_posted['email_notifications_enabled']) && $dialoga_posted['email_notifications_enabled'] === '1' ? 1 : 0;
        if (isset($dialoga_posted['email_notifications_recipient'])) {
            $dialoga_settings['email_notifications']['recipient'] = implode(', ', array_filter(array_map(
                static function ($dialoga_email) { return sanitize_email(trim($dialoga_email)); },
                explode(',', $dialoga_posted['email_notifications_recipient'])
            )));
        }
        if (isset($dialoga_posted['email_notifications_subject'])) {
            $dialoga_settings['email_notifications']['subject'] = sanitize_text_field($dialoga_posted['email_notifications_subject']);
        }
        foreach (array('lead_retention', 'conversation_retention') as $dialoga_key) {
            $dialoga_settings[$dialoga_key]['enabled'] = isset($dialoga_posted[$dialoga_key . '_enabled']) && $dialoga_posted[$dialoga_key . '_enabled'] === '1' ? 1 : 0;
            if (isset($dialoga_posted[$dialoga_key . '_days']) && preg_match('/^\d+$/', $dialoga_posted[$dialoga_key . '_days'])) {
                $dialoga_settings[$dialoga_key]['days'] = min(3650, max(1, intval($dialoga_posted[$dialoga_key . '_days'])));
            }
        }
        $dialoga_settings['preview_message']['enabled'] = isset($dialoga_posted['preview_message_enabled']) && $dialoga_posted['preview_message_enabled'] === '1' ? 1 : 0;
        if (isset($dialoga_posted['preview_message_text'])) {
            $dialoga_settings['preview_message']['text'] = sanitize_text_field($dialoga_posted['preview_message_text']);
        }
        if (isset($dialoga_posted['preview_message_delay']) && preg_match('/^\d+$/', $dialoga_posted['preview_message_delay'])) {
            $dialoga_settings['preview_message']['delay'] = min(60, max(0, intval($dialoga_posted['preview_message_delay'])));
        }
        update_option('dialoga_settings', $dialoga_settings);

        // Keep the existing daily retention scheduling behavior.
        foreach (array('lead_retention', 'conversation_retention') as $dialoga_key) {
            $dialoga_hook = 'dialoga_' . $dialoga_key . '_cleanup';
            $dialoga_next = wp_next_scheduled($dialoga_hook);
            if (!empty($dialoga_settings[$dialoga_key]['enabled'])) {
                if (!$dialoga_next) {
                    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $dialoga_hook);
                }
            } elseif ($dialoga_next) {
                wp_unschedule_event($dialoga_next, $dialoga_hook);
            }
        }
        $dialoga_saved = true;
    }
}

// Interface strings are literal gettext msgids; escape them in their output context below.
$dialoga_tabs = array(
    'general' => __('General', 'dialoga'),
    'appearance' => __('Appearance', 'dialoga'),
    'messages' => __('Messages', 'dialoga'),
    'business-hours' => __('Business Hours', 'dialoga'),
    'notifications' => __('Notifications', 'dialoga'),
    'engagement' => __('Engagement', 'dialoga'),
    'data-privacy' => __('Data & Privacy', 'dialoga'),
    'danger-zone' => __('Danger Zone', 'dialoga'),
);
$dialoga_sections = array(
    'general' => array(
        array('enabled', __('Enable Chatbot', 'dialoga'), __('Turn the chatbot on or off on your website.', 'dialoga'), 'checkbox', __('Enable chatbot on frontend', 'dialoga')),
        array('test_mode', __('Test Mode', 'dialoga'), __('When enabled, chat history carries over as you navigate between pages, but clears automatically on page refresh. Use this to test flows without residual sessions interfering.', 'dialoga'), 'checkbox', __('Enable test mode', 'dialoga')),
        array('fresh_session_roles', __('Fresh Session for Roles', 'dialoga'), __('Users with any of the selected roles will always start with a fresh chat on every page load. Useful for flow designers who need to test without residual sessions.', 'dialoga'), 'roles', __('Leave all unchecked to let every user\'s session persist normally, except when Test Mode is enabled.', 'dialoga')),
        array('visibility_mode', __('Visibility Mode', 'dialoga'), __('Control who can see the chatbot. Perfect for testing before going live!', 'dialoga'), 'select', array('everyone' => __('Everyone (Public)', 'dialoga'), 'logged_in' => __('Logged-in Users Only', 'dialoga'), 'specific_roles' => __('Specific User Roles', 'dialoga'))),
        array('visible_roles', __('Visible Roles', 'dialoga'), __('Select which roles can see the chatbot.', 'dialoga'), 'roles', __('Testing workflow: select Specific User Roles and Administrator, test while logged in, then switch to Everyone when ready to go live.', 'dialoga')),
        array('session_duration', __('Session Duration', 'dialoga'), __('How long to remember a conversation (in days).', 'dialoga'), 'number', array(1, 90)),
    ),
    'appearance' => array(
        array('chat_position', __('Chat Position', 'dialoga'), __('Where the chat widget appears on your site.', 'dialoga'), 'select', array('bottom-right' => __('Bottom Right', 'dialoga'), 'bottom-left' => __('Bottom Left', 'dialoga'))),
        array('primary_color', __('Primary Color', 'dialoga'), __('Main color for the chat widget buttons and header.', 'dialoga'), 'color'),
    ),
    'messages' => array(
        array('chat_header_title', __('Chat Header Title', 'dialoga'), __('Text displayed at the top of the open chat window.', 'dialoga'), 'text'),
        array('welcome_message', __('Welcome Message', 'dialoga'), __('First message visitors see when opening the chat.', 'dialoga'), 'textarea'),
        array('offline_message', __('Offline Message', 'dialoga'), __('Message shown when outside business hours.', 'dialoga'), 'textarea'),
    ),
    'business-hours' => array(
        array('business_hours_enabled', __('Enable Business Hours', 'dialoga'), __('Show different messages during and after business hours.', 'dialoga'), 'checkbox', __('Use business hours', 'dialoga')),
        array('timezone', __('Timezone', 'dialoga'), __('Your business timezone.', 'dialoga'), 'select', array('America/New_York' => __('Eastern Time', 'dialoga'), 'America/Chicago' => __('Central Time', 'dialoga'), 'America/Denver' => __('Mountain Time', 'dialoga'), 'America/Los_Angeles' => __('Pacific Time', 'dialoga'))),
    ),
    'notifications' => array(
        array('email_notifications_enabled', __('Enable Notifications', 'dialoga'), __('Send an email whenever a new lead is captured.', 'dialoga'), 'checkbox', __('Send email on new lead', 'dialoga')),
        array('email_notifications_recipient', __('Recipient Email(s)', 'dialoga'), __('Where to send lead notifications. Separate multiple addresses with commas.', 'dialoga'), 'text'),
        array('email_notifications_subject', __('Email Subject', 'dialoga'), __('Subject line for notification emails.', 'dialoga'), 'text'),
    ),
    'engagement' => array(
        array('bubble_nudge', __('Bubble Nudge', 'dialoga'), __('A pulsing ripple plays after a few seconds on pages where the visitor has not started a chat yet.', 'dialoga'), 'checkbox', __('Enable nudge animation', 'dialoga')),
        array('preview_message_enabled', __('Preview message bubble', 'dialoga'), __('Show a small floating text bubble above the chat icon after a delay to help visitors notice the chatbot.', 'dialoga'), 'checkbox', __('Enable preview message bubble', 'dialoga')),
        array('preview_message_text', __('Preview message text', 'dialoga'), __('Short, friendly, conversational. Emojis help.', 'dialoga'), 'text'),
        array('preview_message_delay', __('Show after', 'dialoga'), __('Seconds to wait before the preview bubble appears.', 'dialoga'), 'number', array(0, 60)),
        array('bubble_size', __('Bubble size', 'dialoga'), __('Bigger bubbles get noticed. Medium is the recommended default; use Large on image-heavy sites where the widget can get lost.', 'dialoga'), 'select', array('sm' => __('Small', 'dialoga'), 'md' => __('Medium (default)', 'dialoga'), 'lg' => __('Large', 'dialoga'))),
        array('bubble_unread_badge', __('Unread notification badge', 'dialoga'), __('Show a red "1" badge on the bubble when the visitor first lands. It disappears once they open the chat.', 'dialoga'), 'checkbox', __('Enable unread notification badge', 'dialoga')),
        array('bubble_bounce', __('Periodic bounce', 'dialoga'), __('Every 30 seconds, if the visitor has not opened the chat, the bubble does a small hop without interrupting them.', 'dialoga'), 'checkbox', __('Enable periodic bounce', 'dialoga')),
    ),
    'data-privacy' => array(
        array('include_admin_sessions', __('Include admin sessions in analytics', 'dialoga'), __('By default, sessions started by users with the manage_options capability are hidden from every analytics view, including leads, conversations, dashboard tiles, conversion rate, funnel, heatmap, path flow, time-of-day chart, top pages, and daily trend. Enable this to include admin testing in real-visitor numbers.', 'dialoga'), 'checkbox', __('Include admin testing in analytics', 'dialoga')),
        array('lead_retention_enabled', __('Auto-delete old leads', 'dialoga'), __('Automatically delete old leads and their conversations to satisfy data-retention policies (GDPR, CCPA, business policy). Runs once a day.', 'dialoga'), 'checkbox', __('Enable lead data retention', 'dialoga')),
        array('lead_retention_days', __('Delete leads after', 'dialoga'), __('Leads older than this many days are removed during the daily cleanup.', 'dialoga'), 'number', array(1, 3650)),
        array('conversation_retention_enabled', __('Auto-delete abandoned conversations', 'dialoga'), __('Automatically delete abandoned chat sessions from visitors who never submitted contact information. Conversations that captured a lead use the Lead Data Retention setting. Runs once a day.', 'dialoga'), 'checkbox', __('Enable conversation retention', 'dialoga')),
        array('conversation_retention_days', __('Delete abandoned conversations after', 'dialoga'), __('Abandoned conversations older than this many days are removed during the daily cleanup. This reduces stored visitor-typed text without a business justification.', 'dialoga'), 'number', array(1, 3650)),
    ),
);
$dialoga_values = $dialoga_settings;
foreach (array('business_hours', 'email_notifications', 'lead_retention', 'conversation_retention', 'preview_message') as $dialoga_key) {
    foreach ($dialoga_settings[$dialoga_key] as $dialoga_child => $dialoga_value) {
        $dialoga_values[$dialoga_key . '_' . $dialoga_child] = $dialoga_value;
    }
}
$dialoga_values['timezone'] = $dialoga_settings['business_hours']['timezone'];
// Preserve valid existing non-US zones when saving an unrelated tab.
$dialoga_current_timezone = $dialoga_values['timezone'];
if (is_string($dialoga_current_timezone) && in_array($dialoga_current_timezone, timezone_identifiers_list(), true)) {
    $dialoga_sections['business-hours'][1][4][$dialoga_current_timezone] = $dialoga_sections['business-hours'][1][4][$dialoga_current_timezone] ?? $dialoga_current_timezone;
}
global $wpdb;
$dialoga_active_flows_count = (int) dialoga_query_cached('settings_active_flows', static function () {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE is_active = 1", $wpdb->prefix . 'dialoga_flows'));
}, 120);
$dialoga_widget_enabled = !empty($dialoga_settings['enabled']);
?>
<div class="wrap dialoga-settings-wrap">
    <div class="dialoga-screen-header">
        <div>
            <h1><?php echo esc_html__('Settings', 'dialoga'); ?></h1>
            <p><?php echo esc_html__('Control your widget appearance, messaging, visibility, and data preferences.', 'dialoga'); ?></p>
        </div>
        <div class="dialoga-settings-summary" role="status">
            <strong><?php echo $dialoga_widget_enabled ? esc_html__('Widget active', 'dialoga') : esc_html__('Widget paused', 'dialoga'); ?></strong>
            <?php // translators: %d: number of active flows. ?>
            <span><?php echo esc_html(sprintf(_n('%d active flow', '%d active flows', $dialoga_active_flows_count, 'dialoga'), $dialoga_active_flows_count)); ?></span>
        </div>
    </div>
    <?php $dialoga_extra_tabs = apply_filters('dialoga_settings_tabs', array()); ?>
    <?php if (!empty($dialoga_extra_tabs) && is_array($dialoga_extra_tabs)) : ?>
        <nav class="nav-tab-wrapper dialoga-settings-extension-nav" aria-label="<?php echo esc_attr__('Settings pages', 'dialoga'); ?>">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-settings&tab=general')); ?>" class="nav-tab <?php echo $dialoga_current_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('General', 'dialoga'); ?></a>
            <?php foreach ($dialoga_extra_tabs as $dialoga_tab_slug => $dialoga_tab_label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-settings&tab=' . urlencode($dialoga_tab_slug))); ?>" class="nav-tab <?php echo $dialoga_current_tab === $dialoga_tab_slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($dialoga_tab_label); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($dialoga_saved) : ?>
        <div class="notice notice-success" role="status"><p><?php echo esc_html__('Settings saved successfully!', 'dialoga'); ?></p></div>
    <?php elseif ($dialoga_save_error) : ?>
        <div class="notice notice-error" role="alert"><p><?php echo esc_html__('Settings were not saved because the request contained invalid fields. Please reload the page and try again.', 'dialoga'); ?></p></div>
    <?php endif; ?>
    <?php if ($dialoga_widget_enabled && $dialoga_active_flows_count === 0) : ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html__('Chat Widget Enabled But No Active Flow', 'dialoga'); ?></strong></p>
            <p><?php echo esc_html__('The chat widget is enabled, but you do not have any active flows. Visitors will not see the chat bubble until you create and activate a flow.', 'dialoga'); ?></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-builder')); ?>" class="button"><?php echo esc_html__('Create a Flow', 'dialoga'); ?></a></p>
        </div>
    <?php elseif (!$dialoga_widget_enabled && $dialoga_active_flows_count > 0) : ?>
        <div class="notice notice-info">
            <p><strong><?php echo esc_html__('Chat Widget Disabled', 'dialoga'); ?></strong></p>
            <p><?php echo esc_html__('You have active flows, but the chat widget is disabled. Enable it below to show the chat bubble on your website.', 'dialoga'); ?></p>
        </div>
    <?php elseif ($dialoga_widget_enabled && $dialoga_active_flows_count > 0) : ?>
        <div class="notice notice-success">
            <p><strong><?php echo esc_html__('Chat Widget Active', 'dialoga'); ?></strong></p>
            <p><?php echo esc_html__('The chat widget is enabled with active flows. Visit your site to see it in action!', 'dialoga'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="dialoga-settings-form">
        <?php wp_nonce_field('dialoga_settings'); ?>
        <div class="dialoga-settings-top-actions">
            <button type="submit" name="dialoga_save_settings" value="1" class="button button-primary button-large"><?php echo esc_html__('Save Settings', 'dialoga'); ?></button>
        </div>
        <nav class="dialoga-settings-tabs" aria-label="<?php echo esc_attr__('Settings sections', 'dialoga'); ?>">
            <?php foreach ($dialoga_tabs as $dialoga_slug => $dialoga_label) : ?>
                <a id="dialoga-settings-tab-<?php echo esc_attr($dialoga_slug); ?>" href="#dialoga-settings-<?php echo esc_attr($dialoga_slug); ?>" class="dialoga-settings-tab"><?php echo esc_html($dialoga_label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($dialoga_sections as $dialoga_slug => $dialoga_fields) : ?>
            <section id="dialoga-settings-<?php echo esc_attr($dialoga_slug); ?>" class="dialoga-settings-section dialoga-settings-panel" aria-labelledby="dialoga-settings-heading-<?php echo esc_attr($dialoga_slug); ?>">
                <h2 id="dialoga-settings-heading-<?php echo esc_attr($dialoga_slug); ?>"><?php echo esc_html($dialoga_tabs[$dialoga_slug]); ?></h2>
                <?php foreach ($dialoga_fields as $dialoga_field) :
                    list($dialoga_name, $dialoga_label, $dialoga_help, $type) = $dialoga_field;
                    $dialoga_value = $dialoga_values[$dialoga_name] ?? '';
                    $id = 'dialoga-setting-' . $dialoga_name;
                    ?>
                    <?php if ($dialoga_name === 'include_admin_sessions') : ?>
                        <h3 class="dialoga-settings-subheading"><?php echo esc_html__('Data Quality', 'dialoga'); ?></h3>
                    <?php elseif ($dialoga_name === 'lead_retention_enabled') : ?>
                        <h3 class="dialoga-settings-subheading"><?php echo esc_html__('Lead Data Retention', 'dialoga'); ?></h3>
                    <?php elseif ($dialoga_name === 'conversation_retention_enabled') : ?>
                        <h3 class="dialoga-settings-subheading"><?php echo esc_html__('Conversation Retention', 'dialoga'); ?></h3>
                    <?php endif; ?>
                    <div class="dialoga-setting-row"<?php if ($dialoga_name === 'visible_roles') : ?> id="dialoga-roles-selector"<?php endif; ?>>
                        <div class="dialoga-setting-label">
                            <?php if ($type === 'roles') : ?>
                                <h4 id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($dialoga_label); ?></h4>
                            <?php else : ?>
                                <h4><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($dialoga_label); ?></label></h4>
                            <?php endif; ?>
                            <p id="<?php echo esc_attr($id); ?>-help"><?php echo esc_html($dialoga_help); ?></p>
                        </div>
                        <div class="dialoga-setting-control">
                            <?php if ($type === 'checkbox') : ?>
                                <label><input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($dialoga_name); ?>" value="1" aria-describedby="<?php echo esc_attr($id); ?>-help" <?php checked(!empty($dialoga_value)); ?> /> <?php echo esc_html($dialoga_field[4]); ?></label>
                            <?php elseif ($type === 'roles') : ?>
                                <div class="dialoga-settings-roles" role="group" aria-labelledby="<?php echo esc_attr($id); ?>-label" aria-describedby="<?php echo esc_attr($id); ?>-help">
                                    <?php foreach ($dialoga_all_roles as $dialoga_role_slug => $dialoga_role_info) : ?>
                                        <label><input type="checkbox" name="<?php echo esc_attr($dialoga_name); ?>[]" value="<?php echo esc_attr($dialoga_role_slug); ?>" <?php checked(in_array($dialoga_role_slug, is_array($dialoga_value) ? $dialoga_value : array(), true)); ?> /> <span><?php echo esc_html(translate_user_role($dialoga_role_info['name'])); ?></span> <code><?php echo esc_html($dialoga_role_slug); ?></code></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description"><?php echo esc_html($dialoga_field[4]); ?></p>
                            <?php elseif ($type === 'select') : ?>
                                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($dialoga_name); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help">
                                    <?php foreach ($dialoga_field[4] as $dialoga_option => $dialoga_option_label) : ?>
                                        <option value="<?php echo esc_attr($dialoga_option); ?>" <?php selected(is_scalar($dialoga_value) ? $dialoga_value : '', $dialoga_option); ?>><?php echo esc_html($dialoga_option_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'textarea') : ?>
                                <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($dialoga_name); ?>" rows="3" aria-describedby="<?php echo esc_attr($id); ?>-help"><?php echo esc_textarea(is_scalar($dialoga_value) ? $dialoga_value : ''); ?></textarea>
                            <?php elseif ($type === 'color') : ?>
                                <div class="dialoga-color-picker">
                                    <input type="color" id="<?php echo esc_attr($id); ?>" name="primary_color" value="<?php echo esc_attr(is_scalar($dialoga_value) ? $dialoga_value : $dialoga_defaults['primary_color']); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help" />
                                    <input type="text" name="primary_color_text" value="<?php echo esc_attr(is_scalar($dialoga_value) ? $dialoga_value : $dialoga_defaults['primary_color']); ?>" readonly aria-label="<?php echo esc_attr__('Primary color hex value', 'dialoga'); ?>" />
                                </div>
                            <?php else : ?>
                                <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($dialoga_name); ?>" value="<?php echo esc_attr(is_scalar($dialoga_value) ? $dialoga_value : ''); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help"
                                    <?php if ($type === 'number') : ?> min="<?php echo esc_attr($dialoga_field[4][0]); ?>" max="<?php echo esc_attr($dialoga_field[4][1]); ?>" step="1"<?php endif; ?>
                                    <?php if ($dialoga_name === 'chat_header_title') : ?> maxlength="80"<?php endif; ?>
                                    <?php if ($dialoga_name === 'preview_message_text') : ?> maxlength="120" placeholder="<?php echo esc_attr__('Hi! 👋 Got a question?', 'dialoga'); ?>"<?php endif; ?> />
                                <?php if ($type === 'number') : ?>
                                    <span><?php echo $dialoga_name === 'preview_message_delay' ? esc_html__('seconds', 'dialoga') : esc_html__('days', 'dialoga'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($dialoga_name === 'test_mode' && !empty($dialoga_settings['test_mode'])) : ?>
                                <p class="dialoga-settings-test-notice"><?php echo esc_html__('Test mode is active. Sessions reset on page refresh for all visitors. Turn off before going live.', 'dialoga'); ?></p>
                            <?php endif; ?>
                            <?php if (in_array($dialoga_name, array('lead_retention_days', 'conversation_retention_days'), true)) :
                                $dialoga_retention_key = substr($dialoga_name, 0, -5);
                                if (!empty($dialoga_settings[$dialoga_retention_key]['enabled'])) :
                                    $dialoga_next = wp_next_scheduled('dialoga_' . $dialoga_retention_key . '_cleanup');
                                    ?>
                                    <p class="dialoga-settings-retention-status">
                                        <?php if ($dialoga_next) : ?>
                                            <?php /* translators: %s: time until the next scheduled cleanup. */ ?>
                                            <?php echo esc_html(sprintf(__('Active - next cleanup in %s.', 'dialoga'), human_time_diff(time(), $dialoga_next))); ?>
                                        <?php else : ?>
                                            <?php echo esc_html__('Active - scheduled.', 'dialoga'); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php
                if ($dialoga_slug === 'general') {
                    do_action('dialoga_settings_multi_flow_row', $dialoga_settings);
                } elseif ($dialoga_slug === 'appearance') {
                    do_action('dialoga_settings_widget_extra_rows', $dialoga_settings);
                }
                ?>
            </section>
        <?php endforeach; ?>
        <p class="submit"><button type="submit" name="dialoga_save_settings" value="1" class="button button-primary button-large"><?php echo esc_html__('Save Settings', 'dialoga'); ?></button></p>
    </form>

    <section id="dialoga-settings-danger-zone" class="dialoga-settings-panel dialoga-settings-danger" aria-labelledby="dialoga-settings-danger-heading">
        <h2 id="dialoga-settings-danger-heading"><?php echo esc_html__('Danger Zone', 'dialoga'); ?></h2>
        <div>
            <h3><?php echo esc_html__('Delete All Leads', 'dialoga'); ?></h3>
            <p><?php echo esc_html__('Removes every lead, clears each conversation\'s lead_data snapshot, and deletes every lead_captured analytics event. Conversation transcripts are kept.', 'dialoga'); ?></p>
            <button type="button" id="dialoga-clear-all-leads-btn" class="button"><?php echo esc_html__('Delete All Leads', 'dialoga'); ?></button>
        </div>
        <div>
            <h3><?php echo esc_html__('Delete All Conversations', 'dialoga'); ?></h3>
            <p><?php echo esc_html__('Deletes every conversation, every lead, and every analytics event. This is a full reset, useful for wiping test data.', 'dialoga'); ?></p>
            <button type="button" id="dialoga-clear-all-convs-btn" class="button"><?php echo esc_html__('Delete All Conversations', 'dialoga'); ?></button>
        </div>
        <div>
            <h3><?php echo esc_html__('Reset Analytics Events', 'dialoga'); ?></h3>
            <p><?php echo esc_html__('Deletes only the event log: bubble impressions, node views, path data, and drop-off events. Conversations and leads are kept. Use this after cleaning up test data to avoid inflated Engagement Funnel impression counts.', 'dialoga'); ?></p>
            <button type="button" id="dialoga-reset-analytics-btn" class="button"><?php echo esc_html__('Reset Analytics Events', 'dialoga'); ?></button>
        </div>
        <noscript><p><?php echo esc_html__('JavaScript is required for these destructive actions. All settings above can be saved without JavaScript.', 'dialoga'); ?></p></noscript>
    </section>
</div>
