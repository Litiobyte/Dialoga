<?php
/** Deterministic standalone regression suite: php tests/flow-transfer-test.php */
define('ABSPATH', __DIR__ . '/');
define('DIALOGAWP_VERSION', 'test-version');

class Transfer_Response extends Exception {
    public $success;
    public $data;
    public $status;
    public function __construct($success, $data, $status) {
        $this->success = $success;
        $this->data = $data;
        $this->status = $status;
    }
}
function wp_send_json_success($data, $status = 200) { throw new Transfer_Response(true, $data, $status); }
function wp_send_json_error($data, $status = 200) { throw new Transfer_Response(false, $data, $status); }
function wp_json_encode($value) { return json_encode($value); }
function __($text, $domain = 'default') { return $text; }
function wp_unslash($value) { return stripslashes($value); }
function current_time($type) { return '2026-10-31 12:00:00'; }
function check_ajax_referer($action, $field, $die = true) {
    $GLOBALS['nonce_calls'][] = array($action, $field, $die);
    return $GLOBALS['nonce_ok'];
}
function current_user_can($capability) {
    $GLOBALS['cap_calls'][] = $capability;
    return $GLOBALS['cap_ok'];
}
function wp_roles() {
    return new class {
        public function get_names() { return array('administrator' => 'Administrator', 'subscriber' => 'Subscriber'); }
    };
}
function apply_filters($name, $value) {
    return 'dialogawp_import_node_types' === $name ? array_merge($value, $GLOBALS['extra_types']) : $value;
}
function add_action($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
class DialogaWP_Frontend {
    public static function invalidate_flow_cache($id = 0) { $GLOBALS['invalidations'][] = $id; }
}
class Transfer_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $rows = array();
    public $inserts = 0;
    public $reads = 0;
    public $deletes = array();
    public $fail_insert = 0;
    public $fail_delete = array();
    public $fail_read = false;
    public $suppressed = false;
    public function suppress_errors($value) {
        $old = $this->suppressed;
        $this->suppressed = $value;
        return $old;
    }
    public function prepare($sql, $id) {
        assert_test(false !== strpos($sql, '%d'), 'export IDs use prepared placeholders');
        return $id;
    }
    public function get_row($id) {
        ++$this->reads;
        $this->last_error = $this->fail_read ? 'PRIVATE SQL ERROR' : '';
        return isset($this->rows[$id]) ? $this->rows[$id] : null;
    }
    public function get_results($query) {
        return array_values($this->rows);
    }
    public function insert($table, $data, $formats) {
        assert_test('wp_dialogawp_flows' === $table && count($formats) === count($data), 'insert table and formats');
        ++$this->inserts;
        if ($this->inserts === $this->fail_insert) {
            $this->last_error = 'PRIVATE SQL ERROR';
            return false;
        }
        $this->insert_id = 100 + $this->inserts;
        $data['id'] = $this->insert_id;
        $this->rows[$this->insert_id] = (object) $data;
        return 1;
    }
    public function delete($table, $where, $formats) {
        assert_test(array('id') === array_keys($where) && array('%d') === $formats, 'cleanup limited to owned ID');
        $id = $where['id'];
        $this->deletes[] = $id;
        if (in_array($id, $this->fail_delete, true)) { return false; }
        $exists = isset($this->rows[$id]);
        unset($this->rows[$id]);
        return $exists ? 1 : 0;
    }
}
require_once dirname(__DIR__) . '/includes/class-flow-transfer.php';

$checks = 0;
function assert_test($condition, $message) {
    ++$GLOBALS['checks'];
    if (!$condition) { throw new Exception('FAIL: ' . $message); }
}
function reset_transfer() {
    $GLOBALS['wpdb'] = new Transfer_DB();
    $GLOBALS['nonce_ok'] = true;
    $GLOBALS['cap_ok'] = true;
    $GLOBALS['nonce_calls'] = array();
    $GLOBALS['cap_calls'] = array();
    $GLOBALS['extra_types'] = array();
    $GLOBALS['invalidations'] = array();
    $_POST = array();
}
function endpoint($method, $payload = array()) {
    $_POST = array();
    foreach ($payload as $key => $value) {
        $_POST[$key] = is_string($value) ? addslashes($value) : addslashes(json_encode($value));
    }
    try {
        call_user_func(array('DialogaWP_Flow_Transfer', $method));
    } catch (Transfer_Response $response) {
        return $response;
    }
    throw new Exception('Endpoint did not send JSON.');
}
function fixture() {
    return json_decode('{"format":"dialogawp-flows","version":1,"plugin_version":"elsewhere","flows":[{"flow_name":"Sales flow","visibility_mode":"specific_roles","visible_roles":["administrator"],"flow_data":{"nodes":[{"id":"n1","type":"custom","position":{"x":12.5,"y":-20},"data":{"nodeType":"question","message":"Hello","is_start":true,"auto_continue":false,"allow_multiple":false,"delay":0,"options":[{"id":"o1","label":"Yes"}]}},{"id":"n2","type":"custom","position":{"x":100,"y":200},"data":{"nodeType":"branch","output_groups":[{"id":"g1","label":"Match","logic":"and","conditions":[{"id":"c1","operator":"equals","value":"Yes"}]}]}},{"id":"n3","type":"custom","position":{"x":20,"y":300},"data":{"nodeType":"loop","target_node_id":"n1"}}],"edges":[{"id":"e1","source":"__start__","sourceHandle":null,"target":"n1","type":"smoothstep","animated":true},{"id":"e2","source":"n1","target":"n2"},{"id":"e3","source":"n2","sourceHandle":"g1","target":"n3"}]}}]}');
}
function rejection($mutate, $fragment) {
    reset_transfer();
    $package = fixture();
    $mutate($package);
    $response = endpoint('ajax_import', array('package' => $package));
    assert_test(!$response->success && 400 === $response->status, 'malformed package rejected: ' . $fragment);
    assert_test(false !== strpos($response->data['message'], $fragment), 'clear error: ' . $fragment);
    assert_test(0 === $GLOBALS['wpdb']->inserts, 'no inserts before complete validation');
}

reset_transfer();
DialogaWP_Flow_Transfer::init();
assert_test(3 === count($GLOBALS['hooks']), 'three authenticated AJAX hooks');
foreach (array('ajax_export', 'ajax_validate', 'ajax_import') as $method) {
    reset_transfer();
    $GLOBALS['nonce_ok'] = false;
    $response = endpoint($method);
    assert_test(!$response->success && 403 === $response->status, 'nonce denied: ' . $method);
    assert_test(array(array('dialogawp_nonce', 'nonce', false)) === $GLOBALS['nonce_calls'], 'nonce contract');
    assert_test(!$GLOBALS['cap_calls'] && !$GLOBALS['wpdb']->reads && !$GLOBALS['wpdb']->inserts, 'nonce fails closed');
    reset_transfer();
    $GLOBALS['cap_ok'] = false;
    $response = endpoint($method);
    assert_test(!$response->success && 403 === $response->status, 'capability denied: ' . $method);
    assert_test(array('manage_options') === $GLOBALS['cap_calls'], 'capability contract');
    assert_test(!$GLOBALS['wpdb']->reads && !$GLOBALS['wpdb']->inserts, 'capability fails closed');
}

reset_transfer();
$numeric_delay = fixture();
$numeric_delay->flows[0]->flow_data->nodes[0]->data->delay = '0.5';
$response = endpoint('ajax_import', array('package' => $numeric_delay));
assert_test($response->success, 'existing numeric-string delay accepted');
$stored_delay = json_decode($GLOBALS['wpdb']->rows[$response->data['flow_ids'][0]]->flow_data)->nodes[0]->data->delay;
assert_test(0.5 === $stored_delay, 'numeric-string delay normalized');

reset_transfer();
$legacy_empty = fixture();
$legacy_empty->flows[0]->flow_data->nodes[0]->data->delay = '';
$legacy_empty->flows[0]->flow_data->nodes[0]->data->config = array();
$response = endpoint('ajax_import', array('package' => $legacy_empty));
assert_test($response->success, 'saved empty config and cleared delay accepted');
$stored_node = json_decode($GLOBALS['wpdb']->rows[$response->data['flow_ids'][0]]->flow_data)->nodes[0];
assert_test(0 === $stored_node->data->delay, 'cleared optional delay defaults to zero');
assert_test(is_object($stored_node->data->config), 'empty saved config restored to object');

reset_transfer();
$package = fixture();
$text = "MixedCase \"quotes\" and 'apostrophes'\nSecond line\n{CamelCase} \\ backslash";
$package->flows[0]->flow_data->nodes[0]->data->message = $text;
$package->flows[0]->flow_data->nodes[0]->data->config = (object) array(
    'connector_id' => 'local-connector', 'custom_headers' => 'X-Anything: private',
    'nested' => (object) array('Authorization' => 'private', 'apiKey' => 'private',
        'deep' => array((object) array('refresh_token' => 'private', 'password' => 'private', 'safeKey' => $text))),
    'url' => 'https://example.com/review', 'email_to' => 'review@example.com');
$package->flows[0]->id = 666;
$package->flows[0]->is_active = 1;
$package->flows[0]->created_at = 'fake';
$response = endpoint('ajax_validate', array('package' => $package));
assert_test($response->success && 3 === count($response->data['warnings']), 'dry run scrub/review warnings');
assert_test(array(array('flow_name' => 'Sales flow', 'node_count' => 3, 'edge_count' => 3)) === $response->data['flows'], 'dry run exact summary');
assert_test(0 === $GLOBALS['wpdb']->inserts && !$GLOBALS['invalidations'], 'dry run does not mutate');
$response = endpoint('ajax_import', array('package' => $package));
assert_test($response->success && array(101) === $response->data['flow_ids'], 'new server IDs');
$row = $GLOBALS['wpdb']->rows[101];
assert_test(0 === $row->is_active && '2026-10-31 12:00:00' === $row->created_at && $row->created_at === $row->updated_at, 'inactive and server timestamps');
$graph = json_decode($row->flow_data);
assert_test($graph->nodes[0]->data->message === $text && isset($graph->nodes[0]->data->nodeType), 'quotes multiline and camelCase preserved');
$config = $graph->nodes[0]->data->config;
assert_test('' === $config->connector_id && !isset($config->custom_headers, $config->nested->Authorization, $config->nested->apiKey), 'connector/header/credentials scrubbed');
assert_test(array('safeKey') === array_keys(get_object_vars($config->nested->deep[0])), 'recursive credential scrub');
assert_test(array(101) === $GLOBALS['invalidations'], 'insert cache invalidation');
assert_test(!$GLOBALS['wpdb']->suppressed, 'DB error suppression restored');

rejection(function($p) { $p->version = '1'; }, 'version 1');
rejection(function($p) { $p->format = 'other'; }, 'version 1');
rejection(function($p) { $p->flows = array(); }, '1-100');
rejection(function($p) { $p->flows = array_fill(0, 101, $p->flows[0]); }, '1-100');
rejection(function($p) { $p->flows[0]->visible_roles = array('missing'); }, 'Unknown visibility role');
rejection(function($p) { $p->flows[0]->visible_roles = array(); }, 'requires at least');
rejection(function($p) { $p->flows[0]->visibility_mode = 'public'; }, 'valid visibility');
rejection(function($p) { $p->flows[0]->flow_data->nodes = new stdClass(); }, 'nodes/edges arrays');
rejection(function($p) { $p->flows[0]->flow_data->edges = new stdClass(); }, 'nodes/edges arrays');
rejection(function($p) { $p->flows[0]->flow_data->nodes[1]->id = 'n1'; }, 'Duplicate');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->id = '__start__'; }, 'reserved');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->id = 123; }, 'string ID');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->id = 'x" onclick="bad'; }, 'string ID');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->position->x = '1'; }, 'numeric x/y');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->type = array(); }, 'id/type/nodeType');
rejection(function($p) { unset($p->flows[0]->flow_data->nodes[0]->data->nodeType); }, 'id/type/nodeType');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->nodeType = 'webhook'; }, 'Unsupported node type: webhook');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->message = '<img src=x onerror=bad>'; }, 'contains HTML');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->message = new stdClass(); }, 'must be a string');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->__proto__ = new stdClass(); }, 'Unsafe JSON');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->nested = (object) array('constructor' => 'bad'); }, 'Unsafe JSON');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->{'node.Type'} = 'bad'; }, 'Unsafe JSON');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->options = new stdClass(); }, 'must be an array');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->options = array('bad'); }, 'entries must be objects');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->options[] = $p->flows[0]->flow_data->nodes[0]->data->options[0]; }, 'duplicate IDs');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = array('bad'); }, 'must be an object');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('fields' => (object) array('email' => 'bad')); }, 'Capture fields');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('url' => array()); }, 'must be a string');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('to' => array()); }, 'must be a string');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('lead_fields' => (object) array('email' => 'true')); }, 'must be booleans');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('button_url' => 'javascript:alert(1)'); }, 'URL scheme');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('url' => 'https://user:pass@example.com'); }, 'embedded URL credentials');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->config = (object) array('url' => 'https://example.com/?api_key=private'); }, 'embedded URL credentials');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->delay = -1; }, 'nonnegative');
rejection(function($p) { $p->flows[0]->flow_data->nodes[0]->data->auto_continue = 'false'; }, 'must be a boolean');
rejection(function($p) { $p->flows[0]->flow_data->nodes[1]->data->output_groups[0]->conditions = array('bad'); }, 'entries must be objects');
rejection(function($p) { $p->flows[0]->flow_data->nodes[1]->data->output_groups[0]->logic = 'xor'; }, 'and/or');
rejection(function($p) { $p->flows[0]->flow_data->nodes[1]->data->output_groups[0]->conditions[0]->operator = 'execute'; }, 'invalid condition');
rejection(function($p) { $p->flows[0]->flow_data->edges[1]->id = 'e1'; }, 'Duplicate edge');
rejection(function($p) { $p->flows[0]->flow_data->edges[1]->source = 'missing'; }, 'dangling edge');
rejection(function($p) { $p->flows[0]->flow_data->edges[1]->target = '__start__'; }, 'dangling edge');
rejection(function($p) { $p->flows[0]->flow_data->edges[1]->sourceHandle = new stdClass(); }, 'string ID');
rejection(function($p) { $p->flows[0]->flow_data->nodes[2]->data->target_node_id = 'missing'; }, 'legacy loop');
rejection(function($p) { $p->flows[] = clone $p->flows[0]; $p->flows[1]->flow_name = ''; }, 'requires a name');

reset_transfer();
$p = fixture();
$p->flows[0]->flow_data->nodes[0]->data->nodeType = 'survey';
$GLOBALS['extra_types'] = array('survey');
assert_test(endpoint('ajax_validate', array('package' => $p))->success, 'extension filter admits installed type');
foreach (array('question', 'branch', 'action', 'ai_chat', 'loop') as $type) {
    reset_transfer();
    $p = fixture();
    $p->flows[0]->flow_data->nodes[0]->data->nodeType = $type;
    $p->flows[0]->flow_data->nodes[0]->data->config = new stdClass();
    assert_test(endpoint('ajax_validate', array('package' => $p))->success, 'core node supported: ' . $type);
}
reset_transfer();
$p = fixture();
$p->flows = array_fill(0, 100, $p->flows[0]);
assert_test(endpoint('ajax_validate', array('package' => $p))->success, '100 flow boundary accepted');
reset_transfer();
$p = fixture();
$p->flows[0]->flow_data->nodes = array();
$p->flows[0]->flow_data->edges = array();
assert_test(endpoint('ajax_validate', array('package' => $p))->success, 'empty draft graph accepted');
reset_transfer();
$p = fixture();
$p->flows[0]->flow_data->nodes[0]->data->config = (object) array('headers' => array('private'),
    'nested' => (object) array('smtpPassword' => 'private', 'providerToken' => 'private',
        'webhook_secret' => 'private', 'customHeaders' => 'private', 'api_key' => 'private',
        'cookie' => 'private', 'client_secret' => 'private', 'private_key' => 'private'));
$response = endpoint('ajax_import', array('package' => $p));
$config = json_decode($GLOBALS['wpdb']->rows[101]->flow_data)->nodes[0]->data->config;
assert_test($response->success && !isset($config->headers) && array() === get_object_vars($config->nested), 'all credential aliases and headers scrubbed recursively');
reset_transfer();
$p = fixture();
$raw = json_encode($p);
$response = endpoint('ajax_validate', array('package' => $raw . str_repeat(' ', DialogaWP_Flow_Transfer::MAX_BYTES - strlen($raw))));
assert_test($response->success, 'exact 5 MiB boundary accepted');
reset_transfer();
$raw = str_replace('12.5', '1e999', json_encode(fixture()));
assert_test(!endpoint('ajax_validate', array('package' => $raw))->success, 'nonfinite position rejected');
reset_transfer();
$GLOBALS['wpdb']->fail_insert = 1;
$response = endpoint('ajax_import', array('package' => fixture()));
assert_test(!$response->success && !$GLOBALS['wpdb']->deletes && !$GLOBALS['wpdb']->rows, 'first insert failure has no cleanup targets');
foreach (array('{bad', str_repeat(' ', DialogaWP_Flow_Transfer::MAX_BYTES + 1), '{"format":"dialogawp-flows","version":1,"flows":[],"n":1e999}') as $raw) {
    reset_transfer();
    $response = endpoint('ajax_import', array('package' => $raw));
    assert_test(!$response->success && !$GLOBALS['wpdb']->inserts, 'malformed/oversized/nonfinite JSON rejected');
}

reset_transfer();
$p = fixture();
$GLOBALS['wpdb']->rows[7] = (object) array('id' => 7, 'flow_name' => 'Saved flow', 'visibility_mode' => 'everyone',
    'visible_roles' => '["retired_role"]', 'flow_data' => json_encode($p->flows[0]->flow_data));
$GLOBALS['wpdb']->rows[7]->flow_data = str_replace('question', 'unknown_type', $GLOBALS['wpdb']->rows[7]->flow_data);
$response = endpoint('ajax_export', array('flow_ids' => array(7, 7)));
assert_test($response->success && 1 === count($response->data['package']['flows']) && 2 === count($response->data['warnings']), 'export-only unknown roles/types preserved, duplicate IDs deduplicated');
assert_test(array('format', 'version', 'plugin_version', 'flows') === array_keys($response->data['package']), 'exact export envelope');
assert_test(DIALOGAWP_VERSION === $response->data['package']['plugin_version'], 'export plugin version');
assert_test(!isset($response->data['package']['flows'][0]->id), 'no DB IDs exported');
$override = $p->flows[0];
$override->id = 7;
$override->flow_name = 'Unsaved override';
$override->flow_data->nodes[0]->data->config = (object) array('token' => 'private', 'connector_id' => 'local');
$response = endpoint('ajax_export', array('flow_ids' => array(7), 'current_flow' => $override));
assert_test($response->success && 'Unsaved override' === $response->data['package']['flows'][0]->flow_name, 'current selected flow override');
assert_test(!isset($response->data['package']['flows'][0]->flow_data->nodes[0]->data->config->token), 'export also scrubs credentials');
assert_test('Saved flow' === $GLOBALS['wpdb']->rows[7]->flow_name && !$GLOBALS['wpdb']->inserts && !$GLOBALS['invalidations'], 'override never persisted');
foreach (array(array(), array(0), array(-1), array('7'), array(7.5), array_fill(0, 101, 7), new stdClass()) as $ids) {
    $response = endpoint('ajax_export', array('flow_ids' => $ids));
    assert_test(!$response->success && 400 === $response->status, 'strict export ID validation');
}
$override->id = 8;
assert_test(!endpoint('ajax_export', array('flow_ids' => array(7), 'current_flow' => $override))->success, 'unselected override rejected');
assert_test(!endpoint('ajax_export', array('flow_ids' => array(8)))->success, 'missing DB row rejected');
$GLOBALS['wpdb']->fail_read = true;
$response = endpoint('ajax_export', array('flow_ids' => array(7)));
assert_test(!$response->success && 500 === $response->status && false === strpos(json_encode($response->data), 'PRIVATE'), 'generic DB read error');
assert_test(!$GLOBALS['wpdb']->suppressed, 'read failure restores suppression');

foreach (array(false, true) as $cleanup_failure) {
    reset_transfer();
    $p = fixture();
    $p->flows = array_fill(0, 3, $p->flows[0]);
    $GLOBALS['wpdb']->rows[5] = (object) array('id' => 5, 'flow_name' => 'Not ours');
    $GLOBALS['wpdb']->fail_insert = 3;
    $GLOBALS['wpdb']->fail_delete = $cleanup_failure ? array(101) : array();
    $response = endpoint('ajax_import', array('package' => $p));
    assert_test(!$response->success && 500 === $response->status, 'insert failure reports error');
    assert_test(array(102, 101) === $GLOBALS['wpdb']->deletes && isset($GLOBALS['wpdb']->rows[5]), 'rollback only owned rows, continues cleanup');
    assert_test(!$GLOBALS['wpdb']->suppressed && false === strpos(json_encode($response->data), 'PRIVATE'), 'failure restores suppression and hides SQL');
    assert_test(array(101, 102, 102, 101) === $GLOBALS['invalidations'], 'rollback cache invalidation');
    if ($cleanup_failure) {
        assert_test(true === $response->data['cleanup_failed'] && array(101) === $response->data['remaining_flow_ids'], 'accurate partial cleanup reporting');
        assert_test(isset($GLOBALS['wpdb']->rows[101]) && 0 === $GLOBALS['wpdb']->rows[101]->is_active, 'remaining row inactive');
    } else {
        assert_test(1 === count($GLOBALS['wpdb']->rows) && false !== strpos($response->data['message'], 'All flows'), 'complete rollback');
    }
}
reset_transfer();
$p = fixture();
$p->flows = array_fill(0, 3, $p->flows[0]);
$response = endpoint('ajax_import', array('package' => $p));
assert_test($response->success && array(101, 102, 103) === $response->data['flow_ids'], 'batch inserts ordered new flows');
print 'PASS: ' . $checks . " assertions\n";
