<?php
/** Browser fixture bridge. Reuses the standalone suite's WordPress/DB doubles. */
ob_start();
require __DIR__ . '/flow-transfer-test.php';
ob_end_clean();

$input = json_decode(stream_get_contents(STDIN), true);
if (isset($input['render'])) {
    define('DIALOGAWP_LOGO_URL', 'data:,');
    function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_html__($value, $domain) { return esc_html($value); }
    function esc_attr__($value, $domain) { return esc_attr($value); }
    function translate_user_role($value) { return $value; }
    function admin_url($value) { return '/' . $value; }
    function do_action($hook) {}
    ob_start();
    require dirname(__DIR__) . '/templates/admin/builder.php';
    $html = ob_get_clean();
    require dirname(__DIR__) . '/templates/admin/builder-templates.php';
    print json_encode(array('html' => $html, 'templates' => dialogawp_builder_templates()));
    exit;
}

reset_transfer();
foreach ($input['rows'] as $row) {
    $GLOBALS['wpdb']->rows[$row['id']] = (object) $row;
}
$methods = array('dialogawp_export_flows' => 'ajax_export',
    'dialogawp_validate_flow_import' => 'ajax_validate', 'dialogawp_import_flows' => 'ajax_import');
if (!isset($methods[$input['action']])) {
    throw new Exception('Unexpected browser fixture action');
}
$response = endpoint($methods[$input['action']], $input['fields']);
print json_encode(array('status' => $response->status,
    'body' => array('success' => $response->success, 'data' => $response->data),
    'rows' => array_values($GLOBALS['wpdb']->rows)));
