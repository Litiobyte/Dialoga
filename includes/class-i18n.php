<?php
/** WordPress locale selection and administrative script translations. */
if (!defined('ABSPATH')) {
    exit;
}

class DialogaWP_I18n {
    public static function init() {
        load_plugin_textdomain('dialogawp', false, dirname(plugin_basename(DIALOGAWP_PATH . 'dialogawp.php')) . '/languages');
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (preg_match('/^es(?:_|$)/', $locale)) {
            // WordPress does not automatically fall back from es_MX, es_AR, etc. to es_ES.
            $global = WP_LANG_DIR . '/plugins/dialogawp-' . $locale . '.mo';
            if (is_readable($global) && load_textdomain('dialogawp', $global)) {
                return;
            }
            load_textdomain('dialogawp', DIALOGAWP_PATH . 'languages/dialogawp-es_ES.mo');
        }
    }

    public static function script_messages() {
        $messages = array();
        $plural_messages = array(
            "You've handled %s visitor conversations so far!" => "You've handled %s visitor conversation so far!",
            '%d admin test leads hidden' => '%d admin test lead hidden',
            '%d leads' => '%d lead',
            '%d link clicks' => '%d link click',
            'across %1$d visitors (%2$s avg)' => 'across %1$d visitor (%2$s avg)',
            'across %d visitors' => 'across %d visitor',
            '%d active flows' => '%d active flow',
            '%d conversations with lead data' => '%d conversation with lead data',
            '%d conversations with conversion link clicks' => '%d conversation with a conversion link click',
            '%d lead records shown' => '%d lead record shown',
            'Lead records: %d results' => 'Lead records: %d result',
        );
        foreach (array('scripts', 'settings', 'templates', 'backend', 'core', 'wizard', 'fixes', 'admin-ui') as $part) {
            $path = DIALOGAWP_PATH . 'languages/' . $part . '-es.json';
            if (!is_readable($path)) {
                continue;
            }
            $catalog = json_decode(file_get_contents($path), true);
            if (is_array($catalog)) {
                foreach ($catalog as $msgid => $unused) {
                    $messages[$msgid] = isset($plural_messages[$msgid])
                        ? _n($plural_messages[$msgid], $msgid, 2, 'dialogawp') : __($msgid, 'dialogawp');
                }
            }
        }
        return $messages;
    }
}
