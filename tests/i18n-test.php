<?php
/** Run: php tests/i18n-test.php. Optional DIALOGAWP_WP_POMO points to wp-includes/pomo/mo.php.
 * Also supplies the isolated WordPress/gettext fixture for settings tests; no WordPress DB is used.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
define('ABSPATH', dirname(__DIR__) . '/');
// A deliberately nonexistent language directory makes regional fallback independent of this machine.
define('WP_LANG_DIR', __DIR__ . '/.nonexistent-wp-languages');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['i18n_fixture'] = array('site' => 'en_US', 'user' => null, 'admin' => true,
    'catalog' => array(), 'loads' => array(), 'lookups' => array(), 'menus' => array(), 'hooks' => array());

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'http://dialogawp.test/wp-content/plugins/dialogawp/'; }
function plugin_basename($file) { return 'dialogawp/' . basename($file); }
function is_admin() { return $GLOBALS['i18n_fixture']['admin']; }
function get_locale() { return $GLOBALS['i18n_fixture']['site']; }
function determine_locale() {
    $state = $GLOBALS['i18n_fixture'];
    return $state['admin'] && $state['user'] ? $state['user'] : $state['site'];
}
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['i18n_fixture']['hooks'][$hook][$priority][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { add_action($hook, $callback, $priority, $args); }
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function add_menu_page($title, $label, $cap, $slug, ...$rest) {
    $GLOBALS['i18n_fixture']['menus'][$slug] = array($title, $label, $cap);
}
function add_submenu_page($parent, $title, $label, $cap, $slug, ...$rest) {
    add_menu_page($title, $label, $cap, $slug);
}

function i18n_read_mo($path) {
    $bytes = file_get_contents($path);
    if (strlen($bytes) < 28) throw new RuntimeException('Truncated MO: ' . $path);
    $magic = unpack('V', substr($bytes, 0, 4))[1];
    $format = $magic === 0x950412de ? 'V' : ($magic === 0xde120495 ? 'N' : null);
    if (!$format) throw new RuntimeException('Invalid MO magic: ' . $path);
    $header = array_values(unpack($format . '7', substr($bytes, 0, 28)));
    $result = array();
    for ($i = 0; $i < $header[2]; $i++) {
        $pair = array();
        foreach (array($header[3], $header[4]) as $offset) {
            if ($offset + $i * 8 + 8 > strlen($bytes)) throw new RuntimeException('Truncated MO table');
            $entry = array_values(unpack($format . '2', substr($bytes, $offset + $i * 8, 8)));
            if ($entry[1] + $entry[0] >= strlen($bytes) || $bytes[$entry[1] + $entry[0]] !== "\0") {
                throw new RuntimeException('Invalid MO string bounds/terminator');
            }
            $pair[] = substr($bytes, $entry[1], $entry[0]);
        }
        if (isset($result[$pair[0]])) throw new RuntimeException('Duplicate MO msgid');
        $result[$pair[0]] = $pair[1];
    }
    return $result;
}
function load_textdomain($domain, $path) {
    $GLOBALS['i18n_fixture']['loads'][] = array($domain, $path);
    if (!is_readable($path)) return false;
    $GLOBALS['i18n_fixture']['catalog'] = i18n_read_mo($path);
    $pomo = getenv('DIALOGAWP_WP_POMO');
    if (!$pomo && is_readable(ABSPATH . 'wp-includes/pomo/mo.php')) $pomo = ABSPATH . 'wp-includes/pomo/mo.php';
    $GLOBALS['i18n_fixture']['pomo'] = null;
    if ($pomo) {
        require_once $pomo;
        $mo = new MO();
        if (!$mo->import_from_file($path)) throw new RuntimeException('POMO rejected MO');
        $GLOBALS['i18n_fixture']['pomo'] = $mo;
    }
    return true;
}
function load_plugin_textdomain($domain, $deprecated, $relative) {
    $GLOBALS['i18n_fixture']['plugin_load'] = array($domain, $deprecated, $relative);
    $file = ABSPATH . 'languages/' . $domain . '-' . determine_locale() . '.mo';
    return is_readable($file) ? load_textdomain($domain, $file) : false;
}
function __($msgid, $domain = 'default') {
    if ($domain !== 'dialogawp') return $msgid;
    $GLOBALS['i18n_fixture']['lookups'][] = $msgid;
    if (!empty($GLOBALS['i18n_fixture']['pomo'])) return $GLOBALS['i18n_fixture']['pomo']->translate($msgid);
    if (isset($GLOBALS['i18n_fixture']['catalog'][$msgid])) return $GLOBALS['i18n_fixture']['catalog'][$msgid];
    // POMO indexes plural entries by the singular msgid; __() returns their first translation.
    foreach ($GLOBALS['i18n_fixture']['catalog'] as $id => $value) {
        if (strpos($id, "\0") !== false && explode("\0", $id)[0] === $msgid) return explode("\0", $value)[0];
    }
    return $msgid;
}
function _n($single, $plural, $number, $domain = 'default') {
    if ($domain !== 'dialogawp') return $number == 1 ? $single : $plural;
    if (!empty($GLOBALS['i18n_fixture']['pomo'])) return $GLOBALS['i18n_fixture']['pomo']->translate_plural($single, $plural, $number);
    $key = $single . "\0" . $plural;
    if (!isset($GLOBALS['i18n_fixture']['catalog'][$key])) {
        return $number == 1 ? __($single, $domain) : $plural;
    }
    $forms = explode("\0", $GLOBALS['i18n_fixture']['catalog'][$key] ?? ($single . "\0" . $plural));
    // This fallback reader supports only the Spanish two-form rule, asserted below; it never evals catalog data.
    return $forms[$number == 1 ? 0 : 1];
}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_html__($text, $domain = 'default') { return esc_html(__($text, $domain)); }
function esc_attr__($text, $domain = 'default') { return esc_attr(__($text, $domain)); }
require_once ABSPATH . 'dialogawp.php';

function i18n_locale($site, $user = null, $admin = true) {
    $GLOBALS['i18n_fixture'] = array_replace($GLOBALS['i18n_fixture'], array('site' => $site, 'user' => $user,
        'admin' => $admin, 'catalog' => array(), 'loads' => array(), 'lookups' => array(), 'menus' => array(), 'pomo' => null));
    DialogaWP_I18n::init();
}
function i18n_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function i18n_test($name, $run) {
    try { $run(); echo 'PASS: ' . $name . "\n"; $GLOBALS['i18n_passed']++; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL: ' . $name . "\n  " . $error->getMessage() . "\n"); $GLOBALS['i18n_failed']++; }
}
function i18n_dictionaries() {
    $messages = array();
    foreach (array('scripts', 'settings', 'templates', 'backend', 'core', 'wizard', 'fixes', 'admin-ui') as $part) {
        $catalog = json_decode(file_get_contents(ABSPATH . 'languages/' . $part . '-es.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($catalog as $id => $value) {
            i18n_assert(is_string($value) && $value !== '', 'Empty/non-string translation: ' . $id);
            // Match the compiler's and script_messages()'s last-dictionary-wins precedence.
            $messages[$id] = $value;
        }
    }
    return $messages;
}
function i18n_placeholders($text) {
    preg_match_all('/%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]/', str_replace('%%', '', $text), $matches);
    sort($matches[0]);
    return $matches[0];
}
function i18n_php_calls($file) {
    $tokens = token_get_all(file_get_contents($file));
    $calls = array();
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || !in_array($tokens[$i][1], array('__', 'esc_html__', 'esc_attr__', '_n'), true)) continue;
        $name = $tokens[$i][1];
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (($tokens[$j] ?? null) !== '(') continue;
        $strings = array();
        $depth = 1;
        while (isset($tokens[++$j]) && $depth) {
            $token = $tokens[$j];
            if ($token === '(') $depth++;
            if ($token === ')') $depth--;
            if ($depth === 1 && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $token[1];
                $value = substr($literal, 1, -1);
                $strings[] = $literal[0] === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), $value) : stripcslashes($value);
            }
        }
        if (end($strings) === 'dialogawp' && count($strings) >= ($name === '_n' ? 3 : 2)) {
            $calls[] = array('ids' => array_slice($strings, 0, $name === '_n' ? 2 : 1), 'line' => $tokens[$i][2]);
        }
    }
    return $calls;
}

function i18n_run_tests() {
    $GLOBALS['i18n_passed'] = $GLOBALS['i18n_failed'] = 0;
    $messages = i18n_dictionaries();
    $mo = i18n_read_mo(ABSPATH . 'languages/dialogawp-es_ES.mo');
    i18n_test('compiled MO header, dictionary equality and printf placeholders', static function () use ($messages, $mo) {
        i18n_assert(in_array(array('DialogaWP_I18n', 'init'), $GLOBALS['i18n_fixture']['hooks']['init'][0] ?? array(), true), 'Actual plugin must register i18n init at priority zero');
        i18n_assert(strpos($mo[''], 'charset=UTF-8') !== false, 'MO must be UTF-8');
        i18n_assert(strpos($mo[''], 'nplurals=2; plural=(n != 1);') !== false, 'Unsupported Spanish plural rule');
        $flattened = array();
        foreach ($mo as $id => $translation) {
            if ($id === '') continue;
            $ids = explode("\0", $id);
            $values = explode("\0", $translation);
            i18n_assert(count($ids) === count($values), 'MO plural form count: ' . $ids[0]);
            foreach ($ids as $index => $singular) $flattened[$singular] = $values[$index];
        }
        ksort($flattened); $expected = $messages; ksort($expected);
        i18n_assert($flattened === $expected, 'Compiled MO is stale or differs from reviewed JSON dictionaries; regenerate with node tools/build-translations.cjs');
        $errors = array();
        foreach ($messages as $id => $value) if (i18n_placeholders($id) !== i18n_placeholders($value)) $errors[] = $id;
        i18n_assert(!$errors, 'Placeholder mismatches: ' . implode('; ', $errors));
    });
    foreach (array(array('es_ES', null, true), array('es_MX', null, true), array('es_AR', null, true),
        array('en_US', 'es_MX', true), array('es_ES', 'en_US', true), array('es_AR', 'en_US', false), array('en_US', null, true)) as $case) {
        i18n_test('real init, menu and script dictionary: site=' . $case[0] . ' user=' . ($case[1] ?? 'site') . ' admin=' . (int) $case[2], static function () use ($case, $messages) {
            i18n_locale(...$case);
            $spanish = strpos(determine_locale(), 'es_') === 0;
            i18n_assert($GLOBALS['i18n_fixture']['plugin_load'] === array('dialogawp', false, 'dialogawp/languages'), 'Plugin textdomain registration');
            if ($spanish) i18n_assert(!empty($GLOBALS['i18n_fixture']['loads']) && !empty($GLOBALS['i18n_fixture']['catalog']), 'Spanish regional fallback did not load bundled MO');
            else i18n_assert(!$GLOBALS['i18n_fixture']['loads'], 'English preference must not load Spanish MO');
            DialogaWP::get_instance()->add_admin_menu();
            DialogaWP::get_instance()->add_analytics_menu();
            foreach (array('DialogaWP' => 'Dashboard', 'dialogawp-builder' => 'Flow Builder', 'dialogawp-leads' => 'Leads',
                'dialogawp-settings' => 'Settings', 'dialogawp-ai-settings' => 'AI Settings', 'dialogawp-analytics' => 'Analytics') as $slug => $id) {
                $expected = $spanish ? $messages[$id] : $id;
                i18n_assert($GLOBALS['i18n_fixture']['menus'][$slug] === array($expected, $expected, 'manage_options'), 'Menu translation/capability: ' . $slug);
            }
            $dictionary = DialogaWP_I18n::script_messages();
            i18n_assert(count($dictionary) === count($messages), 'Missing script dictionary keys');
            $missing = array();
            foreach ($messages as $id => $value) if (($dictionary[$id] ?? null) !== ($spanish ? $value : $id)) $missing[] = $id;
            i18n_assert(!$missing, 'Script dictionary translations missing/mismatched: ' . implode('; ', $missing));
        });
    }
    i18n_test('all actual PHP gettext literals have catalog entries, including plural forms', static function () use ($messages, $mo) {
        $files = array_merge(array(ABSPATH . 'dialogawp.php'), glob(ABSPATH . 'includes/*.php'), glob(ABSPATH . 'templates/admin/*.php'));
        $errors = array(); $count = 0;
        i18n_locale('es_ES');
        foreach ($files as $file) foreach (i18n_php_calls($file) as $call) {
            $count++;
            foreach ($call['ids'] as $id) if (!isset($messages[$id])) $errors[] = basename($file) . ':' . $call['line'] . ' missing ' . $id;
            if (count($call['ids']) === 2) {
                list($one, $many) = $call['ids'];
                if (!isset($mo[$one . "\0" . $many])) $errors[] = basename($file) . ':' . $call['line'] . ' missing compiled plural pair: ' . $one;
                foreach (array(0, 1, 2, 21) as $n) {
                    $id = $n === 1 ? $one : $many;
                    if (isset($messages[$id]) && _n($one, $many, $n, 'dialogawp') !== $messages[$id]) $errors[] = basename($file) . ':' . $call['line'] . ' untranslated _n at n=' . $n;
                }
            }
        }
        i18n_assert($count > 100, 'Gettext scanner did not inspect actual production calls');
        i18n_assert(!$errors, implode("\n  ", array_unique($errors)));
    });
    i18n_test('actual admin JavaScript t literals match translated dictionary', static function () use ($messages) {
        $errors = array(); $count = 0;
        $files = array_merge(
            glob(ABSPATH . 'assets/js/*.js'),
            glob(ABSPATH . 'src/canvas/*.js'),
            glob(ABSPATH . 'src/canvas/*.jsx')
        );
        foreach ($files as $file) {
            preg_match_all('/\bt\(\s*([\'\"])((?:\\\\.|(?!\1)[^\\\\])*)\1/', file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $count++;
                $id = stripcslashes($match[2]);
                if (!isset($messages[$id])) $errors[] = basename($file) . ': ' . $id;
            }
        }
        i18n_assert($count > 20, 'JavaScript scanner did not inspect actual translation calls');
        i18n_assert(!$errors, 'Missing JavaScript catalog strings: ' . implode('; ', $errors));
    });
    echo 'RESULT: ' . $GLOBALS['i18n_passed'] . ' passed, ' . $GLOBALS['i18n_failed'] . ' failed (' . (!empty($GLOBALS['i18n_fixture']['pomo']) ? 'WordPress POMO' : 'minimal MO reader') . ")\n";
    return $GLOBALS['i18n_failed'] ? 1 : 0;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(i18n_run_tests());
