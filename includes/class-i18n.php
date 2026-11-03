<?php
/** WordPress locale selection and administrative script translations. */
if (!defined('ABSPATH')) {
    exit;
}

class Dialoga_I18n {
    public static function init() {
        load_plugin_textdomain('dialoga', false, dirname(plugin_basename(DIALOGA_PATH . 'dialoga.php')) . '/languages');
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (preg_match('/^es(?:_|$)/', $locale)) {
            // WordPress does not automatically fall back from es_MX, es_AR, etc. to es_ES.
            $global = WP_LANG_DIR . '/plugins/dialoga-' . $locale . '.mo';
            if (is_readable($global) && load_textdomain('dialoga', $global)) {
                return;
            }
            load_textdomain('dialoga', DIALOGA_PATH . 'languages/dialoga-es_ES.mo');
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
            $path = DIALOGA_PATH . 'languages/' . $part . '-es.json';
            if (!is_readable($path)) {
                continue;
            }
            $catalog = json_decode(file_get_contents($path), true);
            if (is_array($catalog)) {
                foreach ($catalog as $msgid => $unused) {
                    // _n() and __() require string literals — the checker (and
                    // sane translators) cannot parse dynamic strings. Every
                    // key of $plural_messages and every $msgid is a literal
                    // from the reviewed JSON catalogs, so switch on the
                    // known set instead of calling with variables.
                    if (isset($plural_messages[$msgid])) {
                        switch ($msgid) {
                            case "You've handled %s visitor conversations so far!":
                                // translators: %s: number of visitor conversations handled.
                                $messages[$msgid] = _n("You've handled %s visitor conversation so far!", "You've handled %s visitor conversations so far!", 2, 'dialoga');
                                break;
                            case '%d admin test leads hidden':
                                // translators: %d: number of admin test leads hidden.
                                $messages[$msgid] = _n('%d admin test lead hidden', '%d admin test leads hidden', 2, 'dialoga');
                                break;
                            case '%d leads':
                                // translators: %d: number of leads.
                                $messages[$msgid] = _n('%d lead', '%d leads', 2, 'dialoga');
                                break;
                            case '%d link clicks':
                                // translators: %d: number of link clicks.
                                $messages[$msgid] = _n('%d link click', '%d link clicks', 2, 'dialoga');
                                break;
                            case 'across %1$d visitors (%2$s avg)':
                                // translators: %1$d: number of visitors; %2$s: average time.
                                $messages[$msgid] = _n('across %1$d visitor (%2$s avg)', 'across %1$d visitors (%2$s avg)', 2, 'dialoga');
                                break;
                            case 'across %d visitors':
                                // translators: %d: number of visitors.
                                $messages[$msgid] = _n('across %d visitor', 'across %d visitors', 2, 'dialoga');
                                break;
                            case '%d active flows':
                                // translators: %d: number of active flows.
                                $messages[$msgid] = _n('%d active flow', '%d active flows', 2, 'dialoga');
                                break;
                            case '%d conversations with lead data':
                                // translators: %d: number of conversations with lead data.
                                $messages[$msgid] = _n('%d conversation with lead data', '%d conversations with lead data', 2, 'dialoga');
                                break;
                            case '%d conversations with conversion link clicks':
                                // translators: %d: number of conversations with a conversion link click.
                                $messages[$msgid] = _n('%d conversation with a conversion link click', '%d conversations with conversion link clicks', 2, 'dialoga');
                                break;
                            case '%d lead records shown':
                                // translators: %d: number of lead records shown.
                                $messages[$msgid] = _n('%d lead record shown', '%d lead records shown', 2, 'dialoga');
                                break;
                            case 'Lead records: %d results':
                                // translators: %d: number of lead search results.
                                $messages[$msgid] = _n('Lead records: %d result', 'Lead records: %d results', 2, 'dialoga');
                                break;
                            default:
                                $messages[$msgid] = __($msgid, 'dialoga');
                        }
                    } else {
                        $messages[$msgid] = __($msgid, 'dialoga');
                    }
                }
            }
        }
        return $messages;
    }
}
