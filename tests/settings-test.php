<?php
/** Run: php tests/settings-test.php. Browser JSON bridge: php tests/settings-test.php --bridge.
 * Includes the actual settings template with isolated, typed WordPress stubs and real MO translations.
 */
require_once __DIR__ . '/i18n-test.php';
$GLOBALS['settings_fixture'] = array();
class Settings_Test_Die extends RuntimeException {}
function current_user_can($cap) {
    i18n_assert($cap === 'manage_options', 'Unexpected capability: ' . $cap);
    $GLOBALS['settings_fixture']['checks'][] = 'cap';
    return $GLOBALS['settings_fixture']['permission'];
}
function wp_die($message) { throw new Settings_Test_Die($message); }
function wp_unslash($value) {
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value);
}
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $value) ? $value : null; }
function sanitize_text_field($value) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', preg_replace('/%[a-f0-9]{2}/i', '', strip_tags($value))));
}
function sanitize_textarea_field($value) { return trim(preg_replace('/%[a-f0-9]{2}/i', '', strip_tags($value))); }
function sanitize_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
function get_option($key, $default = false) { return $GLOBALS['settings_fixture']['options'][$key] ?? $default; }
function update_option($key, $value) {
    $GLOBALS['settings_fixture']['options'][$key] = $value;
    $GLOBALS['settings_fixture']['writes']++;
    return true;
}
function wp_roles() { return (object) array('roles' => array('administrator' => array('name' => 'Administrator'), 'editor' => array('name' => 'Editor'), 'subscriber' => array('name' => 'Subscriber'))); }
function translate_user_role($value) { return $value; }
function do_action($hook, ...$args) {
    $GLOBALS['settings_fixture']['actions'][] = $hook;
    foreach ($GLOBALS['i18n_fixture']['hooks'][$hook] ?? array() as $callbacks) foreach ($callbacks as $callback) $callback(...$args);
}
function apply_filters($hook, $value) { return $hook === 'dialogawp_settings_tabs' ? $GLOBALS['settings_fixture']['tabs'] : $value; }
function check_admin_referer($action) {
    i18n_assert($action === 'dialogawp_settings', 'Unexpected nonce action');
    $GLOBALS['settings_fixture']['checks'][] = 'nonce';
    if (($_POST['_wpnonce'] ?? null) !== 'settings-test-nonce') wp_die('Invalid nonce');
    return 1;
}
function wp_nonce_field($action) {
    i18n_assert($action === 'dialogawp_settings', 'Rendered nonce action');
    echo '<input type="hidden" name="_wpnonce" value="settings-test-nonce"><input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php?page=dialogawp-settings">';
}
function wp_next_scheduled($hook) { return $GLOBALS['settings_fixture']['scheduled'][$hook] ?? false; }
function wp_schedule_event($timestamp, $frequency, $hook) {
    $GLOBALS['settings_fixture']['cron'][] = array('schedule', $timestamp, $frequency, $hook);
    $GLOBALS['settings_fixture']['scheduled'][$hook] = $timestamp;
    return true;
}
function wp_unschedule_event($timestamp, $hook) {
    $GLOBALS['settings_fixture']['cron'][] = array('unschedule', $timestamp, $hook);
    unset($GLOBALS['settings_fixture']['scheduled'][$hook]);
    return true;
}
function checked($one, $two = true, $echo = true) { $text = (string) $one === (string) $two ? ' checked="checked"' : ''; if ($echo) echo $text; return $text; }
function selected($one, $two = true, $echo = true) { $text = (string) $one === (string) $two ? ' selected="selected"' : ''; if ($echo) echo $text; return $text; }
function admin_url($path) { return '/wp-admin/' . $path; }
function human_time_diff($from, $to) { return '1 hour'; }
$wpdb = new class {
    public $prefix = 'wp_';
    public function get_var($sql) {
        i18n_assert($sql === 'SELECT COUNT(*) FROM wp_dialogawp_flows WHERE is_active = 1', 'Unexpected settings SQL');
        return $GLOBALS['settings_fixture']['flows'];
    }
};
function settings_reset($options = array()) {
    $_GET = $_POST = array();
    $GLOBALS['settings_fixture'] = array('permission' => true, 'options' => array('admin_email' => 'admin@example.com', 'dialogawp_settings' => $options),
        'checks' => array(), 'writes' => 0, 'scheduled' => array(), 'cron' => array(), 'actions' => array(), 'tabs' => array(), 'flows' => 0);
    i18n_locale('en_US');
}
function settings_render() {
    ob_start();
    try { include ABSPATH . 'templates/admin/settings.php'; return ob_get_clean(); }
    catch (Throwable $error) { ob_end_clean(); throw $error; }
}
function settings_post() {
    return array('dialogawp_save_settings' => '1', '_wpnonce' => 'settings-test-nonce', 'enabled' => '1', 'test_mode' => '1',
        'bubble_nudge' => '1', 'include_admin_sessions' => '1', 'bubble_unread_badge' => '1', 'bubble_bounce' => '1',
        'fresh_session_roles' => array('administrator', 'unknown', 'administrator'), 'visible_roles' => array('editor'),
        'visibility_mode' => 'specific_roles', 'primary_color' => '#123456', 'chat_position' => 'bottom-left', 'session_duration' => '14',
        'chat_header_title' => addslashes("Visitor's \"support\""),
        'welcome_message' => addslashes("Visitor's \"welcome\"\nSecond line \\ path"), 'offline_message' => addslashes("We're offline\nLeave details"),
        'business_hours_enabled' => '1', 'timezone' => 'America/Chicago', 'email_notifications_enabled' => '1',
        'email_notifications_recipient' => 'one@example.com, invalid, two@example.com', 'email_notifications_subject' => addslashes("Lead's \"subject\""),
        'lead_retention_enabled' => '1', 'lead_retention_days' => '45', 'conversation_retention_enabled' => '1', 'conversation_retention_days' => '60',
        'preview_message_enabled' => '1', 'preview_message_text' => addslashes("Visitor's preview"), 'preview_message_delay' => '8', 'bubble_size' => 'lg');
}
function settings_run_tests() {
    $GLOBALS['i18n_passed'] = $GLOBALS['i18n_failed'] = 0;
    i18n_test('actual template: one form, all inputs, seven panels, no-JS fallback and danger isolation', static function () {
        settings_reset(); $_GET['tab'] = array('malicious');
        $html = settings_render();
        $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new DOMXPath($dom);
        i18n_assert($xpath->query('//form')->length === 1, 'Exactly one form');
        i18n_assert($xpath->query('//form//section')->length === 7, 'All seven settings panels belong to the form');
        i18n_assert($xpath->query('//form//nav/a')->length === 8, 'Eight fragment links');
        i18n_assert($xpath->query('//*[@id="dialogawp-settings-danger-zone" and not(ancestor::form)]')->length === 1, 'Danger Zone is a tab panel outside the save form');
        i18n_assert($xpath->query('//*[@hidden]')->length === 0, 'No-JS must expose every panel and role selector');
        i18n_assert($xpath->query('//form//*[@id="dialogawp-clear-all-leads-btn" or @id="dialogawp-clear-all-convs-btn" or @id="dialogawp-reset-analytics-btn"]')->length === 0, 'Destructive controls outside save form');
        i18n_assert($xpath->query('//form//button[@type="submit" and @name="dialogawp_save_settings"]')->length === 2, 'Both save buttons submit same form');
        foreach (array_keys(settings_post()) as $name) {
            if (in_array($name, array('fresh_session_roles', 'visible_roles'), true)) $name .= '[]';
            i18n_assert($xpath->query('//form//*[@name="' . $name . '"]')->length > 0, 'Missing form field: ' . $name);
        }
        foreach ($xpath->query('//form//*[@aria-describedby]') as $control) foreach (explode(' ', $control->getAttribute('aria-describedby')) as $id) {
            i18n_assert($xpath->query('//*[@id="' . $id . '"]')->length === 1, 'Broken help reference: ' . $id);
        }
    });
    i18n_test('cross-tab save preserves every field, extension keys, quotes/newlines and schedules daily cron once', static function () {
        settings_reset(array('extension_token' => array('secret' => 'keep'), 'multi_flow_strategy' => 'ab',
            'business_hours' => array('extension_hours' => 'keep'), 'preview_message' => array('extension_preview' => 'keep'),
            'email_notifications' => array('extension_email' => 'keep'), 'lead_retention' => array('extension_retention' => 'keep')));
        i18n_locale('es_MX'); $_POST = settings_post(); $_POST['extension_token'] = 'overwrite';
        $html = settings_render(); $saved = $GLOBALS['settings_fixture']['options']['dialogawp_settings'];
        i18n_assert($GLOBALS['settings_fixture']['checks'] === array('cap', 'nonce'), 'Capability then nonce before save');
        foreach (array('enabled', 'test_mode', 'bubble_nudge', 'include_admin_sessions', 'bubble_unread_badge', 'bubble_bounce') as $key) i18n_assert($saved[$key] === 1, 'Checkbox: ' . $key);
        foreach (array('visibility_mode', 'primary_color', 'chat_position', 'bubble_size') as $key) i18n_assert($saved[$key] === $_POST[$key], 'Cross-tab scalar: ' . $key);
        i18n_assert($saved['chat_header_title'] === "Visitor's \"support\"", 'Chat header title sanitized and unslashed');
        i18n_assert($saved['session_duration'] === 14, 'Typed session duration');
        i18n_assert($saved['fresh_session_roles'] === array('administrator') && $saved['visible_roles'] === array('editor'), 'Roles allowlisted/deduplicated');
        foreach (array('welcome_message', 'offline_message') as $key) {
            i18n_assert($saved[$key] === stripslashes($_POST[$key]), 'Exact unslashed message, never localized: ' . $key);
            i18n_assert(strpos($html, esc_textarea($saved[$key])) !== false, 'Message escaped in actual textarea');
        }
        i18n_assert($saved['business_hours'] === array('enabled' => 1, 'timezone' => 'America/Chicago', 'extension_hours' => 'keep'), 'Business hours saved/preserved');
        i18n_assert($saved['email_notifications'] === array('enabled' => 1, 'recipient' => 'one@example.com, two@example.com', 'subject' => "Lead's \"subject\"", 'extension_email' => 'keep'), 'Notification fields saved/preserved');
        i18n_assert($saved['preview_message'] === array('enabled' => 1, 'text' => "Visitor's preview", 'delay' => 8, 'extension_preview' => 'keep'), 'Engagement fields saved/preserved');
        i18n_assert($saved['lead_retention']['days'] === 45 && $saved['conversation_retention']['days'] === 60, 'Both retention fields saved');
        i18n_assert($saved['lead_retention']['extension_retention'] === 'keep' && $saved['extension_token'] === array('secret' => 'keep') && $saved['multi_flow_strategy'] === 'ab', 'Unknown extension keys and absent extension field survive');
        i18n_assert(count($GLOBALS['settings_fixture']['cron']) === 2, 'Two retention jobs scheduled');
        foreach ($GLOBALS['settings_fixture']['cron'] as $event) i18n_assert($event[0] === 'schedule' && $event[2] === 'daily' && abs($event[1] - time() - HOUR_IN_SECONDS) <= 2, 'Daily cron starts in one hour');
        $scheduled = $GLOBALS['settings_fixture']['scheduled']; settings_render();
        i18n_assert($GLOBALS['settings_fixture']['scheduled'] === $scheduled && count($GLOBALS['settings_fixture']['cron']) === 2, 'Repeated save never duplicates cron');
        $_POST = array('dialogawp_save_settings' => '1', '_wpnonce' => 'settings-test-nonce'); settings_render();
        i18n_assert(!$GLOBALS['settings_fixture']['scheduled'] && count($GLOBALS['settings_fixture']['cron']) === 4, 'Disabling unschedules both jobs');
        $saved = $GLOBALS['settings_fixture']['options']['dialogawp_settings'];
        i18n_assert($saved['welcome_message'] === "Visitor's \"welcome\"\nSecond line \\ path" && $saved['multi_flow_strategy'] === 'ab', 'Absent message/extension fields preserved');
        i18n_assert($saved['enabled'] === 0 && $saved['visible_roles'] === array(), 'Unchecked checkboxes/roles reset');
    });
    foreach (array_merge(array_keys(settings_post()), array('multi_flow_strategy')) as $key) {
        if (in_array($key, array('dialogawp_save_settings', '_wpnonce'), true)) continue;
        foreach (array(array('attack'), 42, false) as $bad) {
            if (in_array($key, array('fresh_session_roles', 'visible_roles'), true)) $bad = is_array($bad) ? array(array('attack')) : $bad;
            i18n_test('typed malicious POST rejected atomically: ' . $key . ' / ' . gettype($bad), static function () use ($key, $bad) {
                settings_reset(array('sentinel' => 'untouched')); $_POST = settings_post(); $_POST[$key] = $bad;
                $before = $GLOBALS['settings_fixture']['options']; $html = settings_render();
                i18n_assert($GLOBALS['settings_fixture']['writes'] === 0 && $GLOBALS['settings_fixture']['options'] === $before && !$GLOBALS['settings_fixture']['cron'], 'Malformed field caused option/cron mutation');
                i18n_assert(strpos($html, 'notice-error') !== false, 'Malformed field needs error notice');
            });
        }
    }
    foreach (array('missing', 'invalid', 'array') as $nonce) i18n_test('nonce rejected without mutation: ' . $nonce, static function () use ($nonce) {
        settings_reset(); $_POST = settings_post();
        if ($nonce === 'missing') unset($_POST['_wpnonce']); else $_POST['_wpnonce'] = $nonce === 'array' ? array('attack') : 'wrong';
        $died = false; try { settings_render(); } catch (Settings_Test_Die $error) { $died = true; }
        i18n_assert($died && !$GLOBALS['settings_fixture']['writes'] && !$GLOBALS['settings_fixture']['cron'], 'Nonce must fail closed');
    });
    foreach (array(false, true) as $post) i18n_test('capability denied for ' . ($post ? 'save' : 'render'), static function () use ($post) {
        settings_reset(); $GLOBALS['settings_fixture']['permission'] = false; $_POST = $post ? settings_post() : array();
        $died = false; try { settings_render(); } catch (Settings_Test_Die $error) { $died = true; }
        i18n_assert($died && $GLOBALS['settings_fixture']['checks'] === array('cap') && !$GLOBALS['settings_fixture']['writes'] && !$GLOBALS['settings_fixture']['cron'], 'Permission denial before nonce/render/write');
    });
    i18n_test('invalid enums/colors/numeric values cannot replace safe settings; numeric limits clamp', static function () {
        settings_reset(array('visibility_mode' => 'logged_in', 'primary_color' => '#abcdef', 'chat_position' => 'bottom-left', 'bubble_size' => 'sm', 'multi_flow_strategy' => 'newest'));
        $_POST = settings_post();
        foreach (array('visibility_mode', 'primary_color', 'chat_position', 'bubble_size', 'multi_flow_strategy', 'timezone') as $key) $_POST[$key] = '<script>alert(1)</script>';
        $_POST['session_duration'] = '1e9'; $_POST['lead_retention_days'] = '999999'; $_POST['conversation_retention_days'] = '0'; $_POST['preview_message_delay'] = '999';
        settings_render(); $saved = $GLOBALS['settings_fixture']['options']['dialogawp_settings'];
        i18n_assert($saved['visibility_mode'] === 'logged_in' && $saved['primary_color'] === '#abcdef' && $saved['chat_position'] === 'bottom-left' && $saved['bubble_size'] === 'sm' && $saved['multi_flow_strategy'] === 'newest', 'Invalid enums/color must preserve safe stored values');
        i18n_assert($saved['business_hours']['timezone'] === 'America/New_York' && $saved['session_duration'] === 7, 'Invalid timezone/numeric notation rejected');
        i18n_assert($saved['lead_retention']['days'] === 3650 && $saved['conversation_retention']['days'] === 1 && $saved['preview_message']['delay'] === 60, 'Numeric bounds');
    });
    i18n_test('Spanish/English actual template lookups are complete and saved content stays literal', static function () {
        $messages = i18n_dictionaries();
        foreach (array('es_ES', 'es_MX', 'es_AR', 'en_US') as $locale) {
            settings_reset(array('welcome_message' => "Saved visitor's message\nSecond line", 'enabled' => true, 'test_mode' => true));
            i18n_locale($locale); $html = settings_render();
            foreach ($GLOBALS['i18n_fixture']['lookups'] as $id) i18n_assert(isset($messages[$id]), 'Actual template missing catalog string: ' . $id);
            i18n_assert(strpos($html, esc_html($locale === 'en_US' ? 'Appearance' : $messages['Appearance'])) !== false, 'Actual tab translation: ' . $locale);
            i18n_assert(strpos($html, esc_textarea("Saved visitor's message\nSecond line")) !== false, 'Saved content must never translate');
        }
    });
    i18n_test('license extension routing and labels are escaped', static function () {
        settings_reset(); $GLOBALS['settings_fixture']['tabs'] = array('license' => '<script>Extension</script>');
        $html = settings_render(); i18n_assert(strpos($html, '&lt;script&gt;Extension&lt;/script&gt;') !== false, 'Extension label escaped');
        $_GET['tab'] = 'license'; $html = settings_render();
        i18n_assert($html === '' && in_array('dialogawp_settings_license_tab', $GLOBALS['settings_fixture']['actions'], true), 'License delegates to extension without core form');
    });
    i18n_test('hostile stored messages/attributes are escaped and posted HTML is sanitized', static function () {
        settings_reset(array('chat_header_title' => '<script>Support</script>', 'welcome_message' => '</textarea><script>alert(1)</script>', 'preview_message' => array('text' => '\" autofocus onfocus=\"alert(1)'),
            'email_notifications' => array('subject' => '\"><script>alert(2)</script>')));
        $html = settings_render();
        i18n_assert(strpos($html, '<script>') === false && strpos($html, '&lt;/textarea&gt;&lt;script&gt;') !== false, 'Stored textarea content escaped, not executable');
        $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
        i18n_assert($xpath->query('//*[@autofocus or @onfocus]')->length === 0, 'Stored values cannot break out of attributes');
        $_POST = settings_post(); $_POST['chat_header_title'] = '<b>Support team</b>'; $_POST['welcome_message'] = addslashes("<b>Visitor's text</b>\nSecond line");
        $_POST['email_notifications_subject'] = '<b>Subject</b>'; settings_render();
        $saved = $GLOBALS['settings_fixture']['options']['dialogawp_settings'];
        i18n_assert($saved['chat_header_title'] === 'Support team' && $saved['welcome_message'] === "Visitor's text\nSecond line" && $saved['email_notifications']['subject'] === 'Subject', 'POST strips markup while preserving textarea lines');
    });
    echo 'RESULT: ' . $GLOBALS['i18n_passed'] . ' passed, ' . $GLOBALS['i18n_failed'] . " failed\n";
    return $GLOBALS['i18n_failed'] ? 1 : 0;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (in_array('--bridge', $argv, true)) {
        try {
            $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            settings_reset($input['settings'] ?? array());
            i18n_locale($input['site_locale'] ?? 'en_US', $input['user_locale'] ?? null);
            $_GET = $input['get'] ?? array();
            // Browser submits unslashed HTTP fields; WordPress adds slashes before reaching the template.
            $_POST = $input['post'] ?? array();
            $slash = static function ($value) use (&$slash) { return is_array($value) ? array_map($slash, $value) : (is_string($value) ? addslashes($value) : $value); };
            $_POST = $slash($_POST);
            $html = settings_render();
            echo json_encode(array('html' => $html, 'dictionary' => DialogaWP_I18n::script_messages(), 'settings' => $GLOBALS['settings_fixture']['options']['dialogawp_settings'],
                'writes' => $GLOBALS['settings_fixture']['writes'], 'cron' => $GLOBALS['settings_fixture']['cron']), JSON_THROW_ON_ERROR);
        } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
    } else exit(settings_run_tests());
}
