<?php
/**
 * Settings Admin Page
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have permission to manage these settings.', 'dialogawp'));
}

$current_tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
// License rendering remains owned by the extension.
if ($current_tab === 'license') {
    do_action('dialogawp_settings_license_tab');
    return;
}

// These are content defaults, not interface translations. Never localize saved messages.
$defaults = array(
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
    'welcome_message' => 'Hi! How can we help you today?',
    'offline_message' => 'We\'re currently offline. Please leave your contact information and we\'ll get back to you.',
    'business_hours' => array('enabled' => false, 'timezone' => 'America/New_York'),
    'email_notifications' => array('enabled' => false, 'recipient' => get_option('admin_email'), 'subject' => 'New Lead from DialogaWP'),
    'lead_retention' => array('enabled' => false, 'days' => 30),
    'conversation_retention' => array('enabled' => false, 'days' => 30),
    'include_admin_sessions' => false,
    'preview_message' => array('enabled' => false, 'text' => 'Hi! 👋 Got a question?', 'delay' => 5),
    'bubble_size' => 'md',
    'bubble_unread_badge' => true,
    'bubble_bounce' => true,
);
$stored_settings = get_option('dialogawp_settings', array());
$settings = array_replace($defaults, is_array($stored_settings) ? $stored_settings : array());
foreach ($defaults as $key => $default) {
    if (is_array($default) && !in_array($key, array('fresh_session_roles', 'visible_roles'), true)) {
        $settings[$key] = array_replace($default, is_array($settings[$key]) ? $settings[$key] : array());
    }
}

$all_roles = wp_roles()->roles;
$saved = false;
$save_error = false;
if (isset($_POST['dialogawp_save_settings'])) {
    // Reject unexpected shapes before unslashing or invoking any sanitizers.
    if (!isset($_POST['_wpnonce']) || !is_string($_POST['_wpnonce'])) {
        wp_die(esc_html__('Invalid settings request. Please reload the page and try again.', 'dialogawp'));
    }
    check_admin_referer('dialogawp_settings');
    $scalar_fields = array(
        'enabled', 'test_mode', 'bubble_nudge', 'visibility_mode', 'multi_flow_strategy',
        'primary_color', 'chat_position', 'session_duration', 'welcome_message', 'offline_message',
        'business_hours_enabled', 'timezone', 'email_notifications_enabled',
        'email_notifications_recipient', 'email_notifications_subject', 'lead_retention_enabled',
        'lead_retention_days', 'conversation_retention_enabled', 'conversation_retention_days',
        'include_admin_sessions', 'preview_message_enabled', 'preview_message_text',
        'preview_message_delay', 'bubble_size', 'bubble_unread_badge', 'bubble_bounce',
    );
    $posted = array();
    foreach ($scalar_fields as $key) {
        if (isset($_POST[$key])) {
            if (!is_string($_POST[$key])) {
                $save_error = true;
                break;
            }
            $posted[$key] = wp_unslash($_POST[$key]);
        }
    }
    foreach (array('fresh_session_roles', 'visible_roles') as $key) {
        $posted[$key] = array();
        if (!isset($_POST[$key])) {
            continue;
        }
        if (!is_array($_POST[$key])) {
            $save_error = true;
            break;
        }
        foreach ($_POST[$key] as $role) {
            if (!is_string($role)) {
                $save_error = true;
                break;
            }
            $role = sanitize_key(wp_unslash($role));
            if (isset($all_roles[$role])) {
                $posted[$key][] = $role;
            }
        }
        $posted[$key] = array_values(array_unique($posted[$key]));
    }

    if (!$save_error) {
        // Start from existing options so extension-owned and nested keys survive.
        foreach (array('enabled', 'test_mode', 'bubble_nudge', 'include_admin_sessions', 'bubble_unread_badge', 'bubble_bounce') as $key) {
            $settings[$key] = isset($posted[$key]) && $posted[$key] === '1' ? 1 : 0;
        }
        $settings['fresh_session_roles'] = $posted['fresh_session_roles'];
        $settings['visible_roles'] = $posted['visible_roles'];
        $enums = array(
            'visibility_mode' => array('everyone', 'logged_in', 'specific_roles'),
            'chat_position' => array('bottom-right', 'bottom-left'),
            'bubble_size' => array('sm', 'md', 'lg'),
        );
        foreach ($enums as $key => $allowed) {
            if (isset($posted[$key]) && in_array($posted[$key], $allowed, true)) {
                $settings[$key] = $posted[$key];
            }
        }
        // This field is rendered only by extensions; absence must not reset it.
        if (isset($posted['multi_flow_strategy']) && in_array($posted['multi_flow_strategy'], array('specific', 'ab', 'newest'), true)) {
            $settings['multi_flow_strategy'] = $posted['multi_flow_strategy'];
        }
        if (isset($posted['primary_color'])) {
            $color = sanitize_hex_color($posted['primary_color']);
            if ($color) {
                $settings['primary_color'] = $color;
            }
        }
        if (isset($posted['session_duration']) && preg_match('/^\d+$/', $posted['session_duration'])) {
            $settings['session_duration'] = min(90, max(1, intval($posted['session_duration'])));
        }
        foreach (array('welcome_message', 'offline_message') as $key) {
            if (isset($posted[$key])) {
                $settings[$key] = sanitize_textarea_field($posted[$key]);
            }
        }
        $settings['business_hours']['enabled'] = isset($posted['business_hours_enabled']) && $posted['business_hours_enabled'] === '1' ? 1 : 0;
        if (isset($posted['timezone']) && in_array($posted['timezone'], timezone_identifiers_list(), true)) {
            $settings['business_hours']['timezone'] = $posted['timezone'];
        }
        $settings['email_notifications']['enabled'] = isset($posted['email_notifications_enabled']) && $posted['email_notifications_enabled'] === '1' ? 1 : 0;
        if (isset($posted['email_notifications_recipient'])) {
            $settings['email_notifications']['recipient'] = implode(', ', array_filter(array_map(
                static function ($email) { return sanitize_email(trim($email)); },
                explode(',', $posted['email_notifications_recipient'])
            )));
        }
        if (isset($posted['email_notifications_subject'])) {
            $settings['email_notifications']['subject'] = sanitize_text_field($posted['email_notifications_subject']);
        }
        foreach (array('lead_retention', 'conversation_retention') as $key) {
            $settings[$key]['enabled'] = isset($posted[$key . '_enabled']) && $posted[$key . '_enabled'] === '1' ? 1 : 0;
            if (isset($posted[$key . '_days']) && preg_match('/^\d+$/', $posted[$key . '_days'])) {
                $settings[$key]['days'] = min(3650, max(1, intval($posted[$key . '_days'])));
            }
        }
        $settings['preview_message']['enabled'] = isset($posted['preview_message_enabled']) && $posted['preview_message_enabled'] === '1' ? 1 : 0;
        if (isset($posted['preview_message_text'])) {
            $settings['preview_message']['text'] = sanitize_text_field($posted['preview_message_text']);
        }
        if (isset($posted['preview_message_delay']) && preg_match('/^\d+$/', $posted['preview_message_delay'])) {
            $settings['preview_message']['delay'] = min(60, max(0, intval($posted['preview_message_delay'])));
        }
        update_option('dialogawp_settings', $settings);

        // Keep the existing daily retention scheduling behavior.
        foreach (array('lead_retention', 'conversation_retention') as $key) {
            $hook = 'dialogawp_' . $key . '_cleanup';
            $next = wp_next_scheduled($hook);
            if (!empty($settings[$key]['enabled'])) {
                if (!$next) {
                    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
                }
            } elseif ($next) {
                wp_unschedule_event($next, $hook);
            }
        }
        $saved = true;
    }
}

// Interface strings are literal gettext msgids; escape them in their output context below.
$tabs = array(
    'general' => __('General', 'dialogawp'),
    'appearance' => __('Appearance', 'dialogawp'),
    'messages' => __('Messages', 'dialogawp'),
    'business-hours' => __('Business Hours', 'dialogawp'),
    'notifications' => __('Notifications', 'dialogawp'),
    'engagement' => __('Engagement', 'dialogawp'),
    'data-privacy' => __('Data & Privacy', 'dialogawp'),
);
$sections = array(
    'general' => array(
        array('enabled', __('Enable Chatbot', 'dialogawp'), __('Turn the chatbot on or off on your website.', 'dialogawp'), 'checkbox', __('Enable chatbot on frontend', 'dialogawp')),
        array('test_mode', __('Test Mode', 'dialogawp'), __('When enabled, chat history carries over as you navigate between pages, but clears automatically on page refresh. Use this to test flows without residual sessions interfering.', 'dialogawp'), 'checkbox', __('Enable test mode', 'dialogawp')),
        array('fresh_session_roles', __('Fresh Session for Roles', 'dialogawp'), __('Users with any of the selected roles will always start with a fresh chat on every page load. Useful for flow designers who need to test without residual sessions.', 'dialogawp'), 'roles', __('Leave all unchecked to let every user\'s session persist normally, except when Test Mode is enabled.', 'dialogawp')),
        array('visibility_mode', __('Visibility Mode', 'dialogawp'), __('Control who can see the chatbot. Perfect for testing before going live!', 'dialogawp'), 'select', array('everyone' => __('Everyone (Public)', 'dialogawp'), 'logged_in' => __('Logged-in Users Only', 'dialogawp'), 'specific_roles' => __('Specific User Roles', 'dialogawp'))),
        array('visible_roles', __('Visible Roles', 'dialogawp'), __('Select which roles can see the chatbot.', 'dialogawp'), 'roles', __('Testing workflow: select Specific User Roles and Administrator, test while logged in, then switch to Everyone when ready to go live.', 'dialogawp')),
        array('session_duration', __('Session Duration', 'dialogawp'), __('How long to remember a conversation (in days).', 'dialogawp'), 'number', array(1, 90)),
    ),
    'appearance' => array(
        array('chat_position', __('Chat Position', 'dialogawp'), __('Where the chat widget appears on your site.', 'dialogawp'), 'select', array('bottom-right' => __('Bottom Right', 'dialogawp'), 'bottom-left' => __('Bottom Left', 'dialogawp'))),
        array('primary_color', __('Primary Color', 'dialogawp'), __('Main color for the chat widget buttons and header.', 'dialogawp'), 'color'),
    ),
    'messages' => array(
        array('welcome_message', __('Welcome Message', 'dialogawp'), __('First message visitors see when opening the chat.', 'dialogawp'), 'textarea'),
        array('offline_message', __('Offline Message', 'dialogawp'), __('Message shown when outside business hours.', 'dialogawp'), 'textarea'),
    ),
    'business-hours' => array(
        array('business_hours_enabled', __('Enable Business Hours', 'dialogawp'), __('Show different messages during and after business hours.', 'dialogawp'), 'checkbox', __('Use business hours', 'dialogawp')),
        array('timezone', __('Timezone', 'dialogawp'), __('Your business timezone.', 'dialogawp'), 'select', array('America/New_York' => __('Eastern Time', 'dialogawp'), 'America/Chicago' => __('Central Time', 'dialogawp'), 'America/Denver' => __('Mountain Time', 'dialogawp'), 'America/Los_Angeles' => __('Pacific Time', 'dialogawp'))),
    ),
    'notifications' => array(
        array('email_notifications_enabled', __('Enable Notifications', 'dialogawp'), __('Send an email whenever a new lead is captured.', 'dialogawp'), 'checkbox', __('Send email on new lead', 'dialogawp')),
        array('email_notifications_recipient', __('Recipient Email(s)', 'dialogawp'), __('Where to send lead notifications. Separate multiple addresses with commas.', 'dialogawp'), 'text'),
        array('email_notifications_subject', __('Email Subject', 'dialogawp'), __('Subject line for notification emails.', 'dialogawp'), 'text'),
    ),
    'engagement' => array(
        array('bubble_nudge', __('Bubble Nudge', 'dialogawp'), __('A pulsing ripple plays after a few seconds on pages where the visitor has not started a chat yet.', 'dialogawp'), 'checkbox', __('Enable nudge animation', 'dialogawp')),
        array('preview_message_enabled', __('Preview message bubble', 'dialogawp'), __('Show a small floating text bubble above the chat icon after a delay to help visitors notice the chatbot.', 'dialogawp'), 'checkbox', __('Enable preview message bubble', 'dialogawp')),
        array('preview_message_text', __('Preview message text', 'dialogawp'), __('Short, friendly, conversational. Emojis help.', 'dialogawp'), 'text'),
        array('preview_message_delay', __('Show after', 'dialogawp'), __('Seconds to wait before the preview bubble appears.', 'dialogawp'), 'number', array(0, 60)),
        array('bubble_size', __('Bubble size', 'dialogawp'), __('Bigger bubbles get noticed. Medium is the recommended default; use Large on image-heavy sites where the widget can get lost.', 'dialogawp'), 'select', array('sm' => __('Small', 'dialogawp'), 'md' => __('Medium (default)', 'dialogawp'), 'lg' => __('Large', 'dialogawp'))),
        array('bubble_unread_badge', __('Unread notification badge', 'dialogawp'), __('Show a red "1" badge on the bubble when the visitor first lands. It disappears once they open the chat.', 'dialogawp'), 'checkbox', __('Enable unread notification badge', 'dialogawp')),
        array('bubble_bounce', __('Periodic bounce', 'dialogawp'), __('Every 30 seconds, if the visitor has not opened the chat, the bubble does a small hop without interrupting them.', 'dialogawp'), 'checkbox', __('Enable periodic bounce', 'dialogawp')),
    ),
    'data-privacy' => array(
        array('include_admin_sessions', __('Include admin sessions in analytics', 'dialogawp'), __('By default, sessions started by users with the manage_options capability are hidden from every analytics view, including leads, conversations, dashboard tiles, conversion rate, funnel, heatmap, path flow, time-of-day chart, top pages, and daily trend. Enable this to include admin testing in real-visitor numbers.', 'dialogawp'), 'checkbox', __('Include admin testing in analytics', 'dialogawp')),
        array('lead_retention_enabled', __('Auto-delete old leads', 'dialogawp'), __('Automatically delete old leads and their conversations to satisfy data-retention policies (GDPR, CCPA, business policy). Runs once a day.', 'dialogawp'), 'checkbox', __('Enable lead data retention', 'dialogawp')),
        array('lead_retention_days', __('Delete leads after', 'dialogawp'), __('Leads older than this many days are removed during the daily cleanup.', 'dialogawp'), 'number', array(1, 3650)),
        array('conversation_retention_enabled', __('Auto-delete abandoned conversations', 'dialogawp'), __('Automatically delete abandoned chat sessions from visitors who never submitted contact information. Conversations that captured a lead use the Lead Data Retention setting. Runs once a day.', 'dialogawp'), 'checkbox', __('Enable conversation retention', 'dialogawp')),
        array('conversation_retention_days', __('Delete abandoned conversations after', 'dialogawp'), __('Abandoned conversations older than this many days are removed during the daily cleanup. This reduces stored visitor-typed text without a business justification.', 'dialogawp'), 'number', array(1, 3650)),
    ),
);
$values = $settings;
foreach (array('business_hours', 'email_notifications', 'lead_retention', 'conversation_retention', 'preview_message') as $key) {
    foreach ($settings[$key] as $child => $value) {
        $values[$key . '_' . $child] = $value;
    }
}
$values['timezone'] = $settings['business_hours']['timezone'];
// Preserve valid existing non-US zones when saving an unrelated tab.
$current_timezone = $values['timezone'];
if (is_string($current_timezone) && in_array($current_timezone, timezone_identifiers_list(), true)) {
    $sections['business-hours'][1][4][$current_timezone] = $sections['business-hours'][1][4][$current_timezone] ?? $current_timezone;
}
global $wpdb;
$active_flows_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dialogawp_flows WHERE is_active = 1");
$widget_enabled = !empty($settings['enabled']);
?>
<div class="wrap dialogawp-settings-wrap">
    <h1><?php echo esc_html__('DIALOGAWP Settings', 'dialogawp'); ?></h1>
    <?php $extra_tabs = apply_filters('dialogawp_settings_tabs', array()); ?>
    <?php if (!empty($extra_tabs) && is_array($extra_tabs)) : ?>
        <nav class="nav-tab-wrapper dialogawp-settings-extension-nav" aria-label="<?php echo esc_attr__('Settings pages', 'dialogawp'); ?>">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings&tab=general')); ?>" class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('General', 'dialogawp'); ?></a>
            <?php foreach ($extra_tabs as $tab_slug => $tab_label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings&tab=' . urlencode($tab_slug))); ?>" class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($tab_label); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($saved) : ?>
        <div class="notice notice-success"><p><?php echo esc_html__('Settings saved successfully!', 'dialogawp'); ?></p></div>
    <?php elseif ($save_error) : ?>
        <div class="notice notice-error"><p><?php echo esc_html__('Settings were not saved because the request contained invalid fields. Please reload the page and try again.', 'dialogawp'); ?></p></div>
    <?php endif; ?>
    <?php if ($widget_enabled && $active_flows_count === 0) : ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html__('Chat Widget Enabled But No Active Flow', 'dialogawp'); ?></strong></p>
            <p><?php echo esc_html__('The chat widget is enabled, but you do not have any active flows. Visitors will not see the chat bubble until you create and activate a flow.', 'dialogawp'); ?></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-builder')); ?>" class="button"><?php echo esc_html__('Create a Flow', 'dialogawp'); ?></a></p>
        </div>
    <?php elseif (!$widget_enabled && $active_flows_count > 0) : ?>
        <div class="notice notice-info">
            <p><strong><?php echo esc_html__('Chat Widget Disabled', 'dialogawp'); ?></strong></p>
            <p><?php echo esc_html__('You have active flows, but the chat widget is disabled. Enable it below to show the chat bubble on your website.', 'dialogawp'); ?></p>
        </div>
    <?php elseif ($widget_enabled && $active_flows_count > 0) : ?>
        <div class="notice notice-success">
            <p><strong><?php echo esc_html__('Chat Widget Active', 'dialogawp'); ?></strong></p>
            <p><?php echo esc_html__('The chat widget is enabled with active flows. Visit your site to see it in action!', 'dialogawp'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="dialogawp-settings-form">
        <?php wp_nonce_field('dialogawp_settings'); ?>
        <div class="dialogawp-settings-top-actions">
            <button type="submit" name="dialogawp_save_settings" value="1" class="button button-primary button-large"><?php echo esc_html__('Save Settings', 'dialogawp'); ?></button>
        </div>
        <nav class="dialogawp-settings-tabs" aria-label="<?php echo esc_attr__('Settings sections', 'dialogawp'); ?>">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a id="dialogawp-settings-tab-<?php echo esc_attr($slug); ?>" href="#dialogawp-settings-<?php echo esc_attr($slug); ?>" class="dialogawp-settings-tab"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php foreach ($sections as $slug => $fields) : ?>
            <section id="dialogawp-settings-<?php echo esc_attr($slug); ?>" class="dialogawp-settings-section dialogawp-settings-panel" aria-labelledby="dialogawp-settings-heading-<?php echo esc_attr($slug); ?>">
                <h2 id="dialogawp-settings-heading-<?php echo esc_attr($slug); ?>"><?php echo esc_html($tabs[$slug]); ?></h2>
                <?php foreach ($fields as $field) :
                    list($name, $label, $help, $type) = $field;
                    $value = $values[$name] ?? '';
                    $id = 'dialogawp-setting-' . $name;
                    ?>
                    <?php if ($name === 'include_admin_sessions') : ?>
                        <h3 class="dialogawp-settings-subheading"><?php echo esc_html__('Data Quality', 'dialogawp'); ?></h3>
                    <?php elseif ($name === 'lead_retention_enabled') : ?>
                        <h3 class="dialogawp-settings-subheading"><?php echo esc_html__('Lead Data Retention', 'dialogawp'); ?></h3>
                    <?php elseif ($name === 'conversation_retention_enabled') : ?>
                        <h3 class="dialogawp-settings-subheading"><?php echo esc_html__('Conversation Retention', 'dialogawp'); ?></h3>
                    <?php endif; ?>
                    <div class="dialogawp-setting-row"<?php if ($name === 'visible_roles') : ?> id="dialogawp-roles-selector"<?php endif; ?>>
                        <div class="dialogawp-setting-label">
                            <?php if ($type === 'roles') : ?>
                                <h4 id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($label); ?></h4>
                            <?php else : ?>
                                <h4><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></h4>
                            <?php endif; ?>
                            <p id="<?php echo esc_attr($id); ?>-help"><?php echo esc_html($help); ?></p>
                        </div>
                        <div class="dialogawp-setting-control">
                            <?php if ($type === 'checkbox') : ?>
                                <label><input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" aria-describedby="<?php echo esc_attr($id); ?>-help" <?php checked(!empty($value)); ?> /> <?php echo esc_html($field[4]); ?></label>
                            <?php elseif ($type === 'roles') : ?>
                                <div class="dialogawp-settings-roles" role="group" aria-labelledby="<?php echo esc_attr($id); ?>-label" aria-describedby="<?php echo esc_attr($id); ?>-help">
                                    <?php foreach ($all_roles as $role_slug => $role_info) : ?>
                                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[]" value="<?php echo esc_attr($role_slug); ?>" <?php checked(in_array($role_slug, is_array($value) ? $value : array(), true)); ?> /> <span><?php echo esc_html(translate_user_role($role_info['name'])); ?></span> <code><?php echo esc_html($role_slug); ?></code></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description"><?php echo esc_html($field[4]); ?></p>
                            <?php elseif ($type === 'select') : ?>
                                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help">
                                    <?php foreach ($field[4] as $option => $option_label) : ?>
                                        <option value="<?php echo esc_attr($option); ?>" <?php selected(is_scalar($value) ? $value : '', $option); ?>><?php echo esc_html($option_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'textarea') : ?>
                                <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="3" aria-describedby="<?php echo esc_attr($id); ?>-help"><?php echo esc_textarea(is_scalar($value) ? $value : ''); ?></textarea>
                            <?php elseif ($type === 'color') : ?>
                                <div class="dialogawp-color-picker">
                                    <input type="color" id="<?php echo esc_attr($id); ?>" name="primary_color" value="<?php echo esc_attr(is_scalar($value) ? $value : $defaults['primary_color']); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help" />
                                    <input type="text" name="primary_color_text" value="<?php echo esc_attr(is_scalar($value) ? $value : $defaults['primary_color']); ?>" readonly aria-label="<?php echo esc_attr__('Primary color hex value', 'dialogawp'); ?>" />
                                </div>
                            <?php else : ?>
                                <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr(is_scalar($value) ? $value : ''); ?>" aria-describedby="<?php echo esc_attr($id); ?>-help"
                                    <?php if ($type === 'number') : ?> min="<?php echo esc_attr($field[4][0]); ?>" max="<?php echo esc_attr($field[4][1]); ?>" step="1"<?php endif; ?>
                                    <?php if ($name === 'preview_message_text') : ?> maxlength="120" placeholder="<?php echo esc_attr__('Hi! 👋 Got a question?', 'dialogawp'); ?>"<?php endif; ?> />
                                <?php if ($type === 'number') : ?>
                                    <span><?php echo $name === 'preview_message_delay' ? esc_html__('seconds', 'dialogawp') : esc_html__('days', 'dialogawp'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($name === 'test_mode' && !empty($settings['test_mode'])) : ?>
                                <p class="dialogawp-settings-test-notice"><?php echo esc_html__('Test mode is active. Sessions reset on page refresh for all visitors. Turn off before going live.', 'dialogawp'); ?></p>
                            <?php endif; ?>
                            <?php if (in_array($name, array('lead_retention_days', 'conversation_retention_days'), true)) :
                                $retention_key = substr($name, 0, -5);
                                if (!empty($settings[$retention_key]['enabled'])) :
                                    $next = wp_next_scheduled('dialogawp_' . $retention_key . '_cleanup');
                                    ?>
                                    <p class="dialogawp-settings-retention-status">
                                        <?php if ($next) : ?>
                                            <?php /* translators: %s: time until the next scheduled cleanup. */ ?>
                                            <?php echo esc_html(sprintf(__('Active - next cleanup in %s.', 'dialogawp'), human_time_diff(time(), $next))); ?>
                                        <?php else : ?>
                                            <?php echo esc_html__('Active - scheduled.', 'dialogawp'); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php
                if ($slug === 'general') {
                    do_action('dialogawp_settings_multi_flow_row', $settings);
                } elseif ($slug === 'appearance') {
                    do_action('dialogawp_settings_widget_extra_rows', $settings);
                }
                ?>
            </section>
        <?php endforeach; ?>
        <p class="submit"><button type="submit" name="dialogawp_save_settings" value="1" class="button button-primary button-large"><?php echo esc_html__('Save Settings', 'dialogawp'); ?></button></p>
    </form>

    <section class="dialogawp-settings-danger" aria-labelledby="dialogawp-settings-danger-heading">
        <h2 id="dialogawp-settings-danger-heading"><?php echo esc_html__('Danger Zone', 'dialogawp'); ?></h2>
        <div>
            <h3><?php echo esc_html__('Delete All Leads', 'dialogawp'); ?></h3>
            <p><?php echo esc_html__('Removes every lead, clears each conversation\'s lead_data snapshot, and deletes every lead_captured analytics event. Conversation transcripts are kept.', 'dialogawp'); ?></p>
            <button type="button" id="dialogawp-clear-all-leads-btn" class="button"><?php echo esc_html__('Delete All Leads', 'dialogawp'); ?></button>
        </div>
        <div>
            <h3><?php echo esc_html__('Delete All Conversations', 'dialogawp'); ?></h3>
            <p><?php echo esc_html__('Deletes every conversation, every lead, and every analytics event. This is a full reset, useful for wiping test data.', 'dialogawp'); ?></p>
            <button type="button" id="dialogawp-clear-all-convs-btn" class="button"><?php echo esc_html__('Delete All Conversations', 'dialogawp'); ?></button>
        </div>
        <div>
            <h3><?php echo esc_html__('Reset Analytics Events', 'dialogawp'); ?></h3>
            <p><?php echo esc_html__('Deletes only the event log: bubble impressions, node views, path data, and drop-off events. Conversations and leads are kept. Use this after cleaning up test data to avoid inflated Engagement Funnel impression counts.', 'dialogawp'); ?></p>
            <button type="button" id="dialogawp-reset-analytics-btn" class="button"><?php echo esc_html__('Reset Analytics Events', 'dialogawp'); ?></button>
        </div>
        <noscript><p><?php echo esc_html__('JavaScript is required for these destructive actions. All settings above can be saved without JavaScript.', 'dialogawp'); ?></p></noscript>
    </section>
</div>
