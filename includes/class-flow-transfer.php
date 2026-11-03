<?php
/** Portable, admin-only flow packages. No credentials or local connector bindings. */
if (!defined('ABSPATH')) {
    exit;
}

class Dialoga_Flow_Transfer {
    const MAX_BYTES = 5242880;
    const MAX_FLOWS = 100;

    public static function init() {
        add_action('wp_ajax_dialoga_export_flows', array(__CLASS__, 'ajax_export'));
        add_action('wp_ajax_dialoga_validate_flow_import', array(__CLASS__, 'ajax_validate'));
        add_action('wp_ajax_dialoga_import_flows', array(__CLASS__, 'ajax_import'));
    }

    private static function authorize() {
        if (!check_ajax_referer('dialoga_nonce', 'nonce', false)) {
            throw new RuntimeException(__('Security check failed.', 'dialoga'), 403);
        }
        if (!current_user_can('manage_options')) {
            throw new RuntimeException(__('Insufficient permissions.', 'dialoga'), 403);
        }
    }

    private static function fail($message) {
        throw new InvalidArgumentException($message, 400);
    }

    private static function decode($raw, $field) {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            // translators: %s: JSON field name.
            self::fail(sprintf(__('%s must be JSON no larger than 5 MiB.', 'dialoga'), $field));
        }
        // Decode objects as objects so {} cannot masquerade as [], including empty containers.
        $value = json_decode($raw, false, 64);
        if (JSON_ERROR_NONE !== json_last_error()) {
            // translators: %s: JSON field name.
            self::fail(sprintf(__('Invalid %s JSON.', 'dialoga'), $field));
        }
        return $value;
    }

    private static function request_json($field) {
        // Nonce is verified by every ajax endpoint that reaches this helper;
        // the JSON payload is decoded and then run through dialoga_sanitize_json().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $field_value = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : null;
        if (!is_string($field_value)) {
            // translators: %s: JSON field name.
            self::fail(sprintf(__('%s must be a JSON string.', 'dialoga'), $field));
        }
        return self::decode($field_value, $field);
    }

    private static function node_types() {
        $types = array('question', 'branch', 'action', 'ai_chat', 'loop');
        if (class_exists('DIALOGA_PRO_Webhooks')) {
            $types[] = 'webhook';
        }
        /** Extensions must also implement runtime/editor support for the types they add. */
        $types = apply_filters('dialoga_import_node_types', $types);
        return is_array($types) ? array_values(array_filter($types, 'is_string')) : array();
    }

    private static function identifier($value, $path) {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $value)
            || in_array(strtolower($value), array('__proto__', 'prototype', 'constructor'), true)) {
            // translators: %s: JSON field path.
            self::fail(sprintf(__('%s must be a nonempty safe string ID.', 'dialoga'), $path));
        }
    }

    private static function finite_number($value) {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function secret_key($key) {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key));
        return in_array($key, array('customheaders', 'authorization', 'proxyauthorization',
            'password', 'passwd', 'pwd', 'apikey', 'token', 'accesstoken', 'refreshtoken',
            'authtoken', 'bearertoken', 'idtoken', 'secret', 'clientsecret', 'apisecret',
            'webhooksecret', 'signingsecret', 'privatekey', 'credentials', 'credential',
            'auth', 'authentication', 'cookie', 'cookies', 'xapikey', 'headers', 'httpheaders'), true)
            || (bool) preg_match('/(?:authorization|password|passwd|apikey|token|secret|privatekey|credentials|customheaders)$/', $key);
    }

    private static function warn(&$warnings, $message) {
        if (!in_array($message, $warnings, true)) {
            $warnings[] = $message;
        }
    }

    /** Validate JSON types before any consumer can treat a container as text, or vice versa. */
    private static function clean($value, &$warnings, $path = 'package', $depth = 0) {
        if ($depth > 48) {
            self::fail(__('Flow data is nested too deeply.', 'dialoga'));
        }
        if (is_object($value) || is_array($value)) {
            $out = is_array($value) ? array() : new stdClass();
            foreach ($value as $key => $item) {
                if (is_object($value)) {
                    if (!preg_match('/\A[A-Za-z0-9_-]+\z/', (string) $key)
                        || in_array(strtolower((string) $key), array('__proto__', 'prototype', 'constructor'), true)) {
                        self::fail(__('Unsafe JSON object key.', 'dialoga'));
                    }
                    if (self::secret_key($key)) {
                        self::warn($warnings, __('Credential fields and all custom headers were removed.', 'dialoga'));
                        continue;
                    }
                    if ('connectorid' === strtolower(str_replace('_', '', $key))) {
                        if ('' !== $item && 0 !== $item && null !== $item) {
                            self::warn($warnings, __('Site-local connector bindings were cleared; configure connectors on the destination site.', 'dialoga'));
                        }
                        $out->$key = '';
                        continue;
                    }
                    $strings = array('label', 'message', 'question', 'nodeType', 'action_type',
                        'target_node_id', 'operator', 'logic', 'greeting', 'placeholder', 'instructions',
                        'text_placeholder', 'success_message', 'email_subject', 'email_intro',
                        'email_body', 'email_message', 'email_to', 'email_recipients', 'url',
                        'redirect_url', 'button_url', 'button_label', 'method', 'variable_name',
                        'variable_value', 'contact_type', 'tags_input', 'target', 'to', 'subject',
                        'body', 'cc', 'bcc', 'value_type', 'wa_phone', 'wa_message', 'tg_handle',
                        'fb_handle', 'ig_handle', 'sms_phone', 'sms_body', 'tel_phone');
                    $booleans = array('is_start', 'auto_continue', 'allow_multiple', 'allow_text_input',
                        'enabled', 'required', 'include_lead_data', 'include_flow_data',
                        'override_email_settings', 'is_conversion', 'auto_extract_lead');
                    if (in_array($key, $strings, true) && !is_string($item)) {
                        // translators: %s: JSON field path.
                        self::fail(sprintf(__('%s must be a string.', 'dialoga'), $path . '.' . $key));
                    }
                    if (in_array($key, $booleans, true) && !is_bool($item)) {
                        // translators: %s: JSON field path.
                        self::fail(sprintf(__('%s must be a boolean.', 'dialoga'), $path . '.' . $key));
                    }
                    if ('delay' === $key) {
                        // Existing node forms persist number inputs as strings.
                        if ('' === $item) {
                            $item = 0;
                        }
                        if (is_string($item) && is_numeric($item)) {
                            $item = (float) $item;
                        }
                        if (!self::finite_number($item) || $item < 0) {
                            // translators: %s: JSON field path, including delay.
                            self::fail(sprintf(__('%s must be a finite nonnegative number.', 'dialoga'), $path . '.delay'));
                        }
                    }
                    if (in_array($key, array('config', 'fields', 'lead_fields'), true)) {
                        // The existing associative save sanitizer serializes empty objects as [].
                        if (array() === $item) {
                            $item = new stdClass();
                        }
                        if (!is_object($item)) {
                            // translators: %s: JSON field path.
                            self::fail(sprintf(__('%s must be an object.', 'dialoga'), $path . '.' . $key));
                        }
                    }
                    if (in_array($key, array('options', 'output_groups', 'conditions', 'tags', 'buttons'), true)
                        && !is_array($item)) {
                        // translators: %s: JSON field path.
                        self::fail(sprintf(__('%s must be an array.', 'dialoga'), $path . '.' . $key));
                    }
                }
                $item = self::clean($item, $warnings, $path . '.' . $key, $depth + 1);
                if (is_array($out)) {
                    $out[] = $item;
                } else {
                    $out->$key = $item;
                }
            }
            return $out;
        }
        if (is_string($value)) {
            // Reject markup rather than silently rewriting authored copy. Quotes/newlines stay intact.
            if (preg_match('/<\s*(?:\/?[a-zA-Z][^>]*|![^>]*|\?[^>]*)>/s', $value)
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                // translators: %s: JSON field path.
                self::fail(sprintf(__('%s contains HTML or unsafe control characters; use plain text.', 'dialoga'), $path));
            }
            if (preg_match('/\.(?:url|redirect_url|button_url)$/', $path) && '' !== $value) {
                $scheme = parse_url($value, PHP_URL_SCHEME);
                if (false === $scheme || (null !== $scheme && !in_array(strtolower($scheme), array('http', 'https', 'mailto', 'tel', 'sms'), true))) {
                    // translators: %s: JSON field path.
                    self::fail(sprintf(__('%s uses an unsupported URL scheme.', 'dialoga'), $path));
                }
            }
            // Credentials embedded in URLs cannot be safely redacted as arbitrary authored text.
            if (preg_match('~https?://[^\s/]+@~i', $value)
                || preg_match('/[?&](?:api[_-]?key|(?:access[_-]?|auth[_-]?|refresh[_-]?)?token|password|secret|signature)=/i', $value)) {
                self::fail(__('Remove embedded URL credentials before transferring this flow.', 'dialoga'));
            }
            if (preg_match('/(?:https?:\/\/|mailto:|[^\s@]+@[^\s@]+\.[^\s@]+)/i', $value)) {
                self::warn($warnings, __('Review external URLs, email recipients, AI settings and other site dependencies before activating imported flows. Secrets in free-form text must be removed manually.', 'dialoga'));
            }
            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            // translators: %s: JSON field path.
            self::fail(sprintf(__('%s must contain only finite numbers.', 'dialoga'), $path));
        }
        return $value;
    }

    private static function entries($entries, $path, $conditions = false) {
        $seen = array();
        foreach ($entries as $entry) {
            if (!is_object($entry) || !isset($entry->id)) {
                // translators: %s: JSON field path.
                self::fail(sprintf(__('%s entries must be objects with string IDs.', 'dialoga'), $path));
            }
            self::identifier($entry->id, $path . '.id');
            if (isset($seen[$entry->id])) {
                // translators: %s: JSON field path.
                self::fail(sprintf(__('%s contains duplicate IDs.', 'dialoga'), $path));
            }
            $seen[$entry->id] = true;
            if ($conditions) {
                if (!isset($entry->operator, $entry->value) || !is_string($entry->value)
                    || !in_array($entry->operator, array('equals', 'contains', 'starts_with', 'not_empty'), true)) {
                    // translators: %s: JSON field path.
                    self::fail(sprintf(__('%s has an invalid condition operator or value.', 'dialoga'), $path));
                }
            } elseif (!isset($entry->label) || !is_string($entry->label)) {
                // translators: %s: JSON field path.
                self::fail(sprintf(__('%s entries require string labels.', 'dialoga'), $path));
            }
        }
    }

    private static function flow($flow, &$warnings, $export = false) {
        if (!is_object($flow) || !isset($flow->flow_name, $flow->visibility_mode, $flow->visible_roles, $flow->flow_data)
            || !is_string($flow->flow_name) || '' === trim($flow->flow_name) || strlen($flow->flow_name) > 255
            || !in_array($flow->visibility_mode, array('everyone', 'logged_in', 'specific_roles'), true)
            || !is_array($flow->visible_roles) || !is_object($flow->flow_data)
            || !isset($flow->flow_data->nodes, $flow->flow_data->edges)
            || !is_array($flow->flow_data->nodes) || !is_array($flow->flow_data->edges)) {
            self::fail(__('Each flow requires a name (1-255 bytes), valid visibility, roles array and flow_data with nodes/edges arrays.', 'dialoga'));
        }
        $roles = wp_roles()->get_names();
        foreach ($flow->visible_roles as $role) {
            self::identifier($role, 'visible_roles');
            if (!array_key_exists($role, $roles)) {
                if (!$export) {
                    // translators: %s: unknown WordPress role slug.
                    self::fail(sprintf(__('Unknown visibility role: %s. Install or map this role before importing.', 'dialoga'), $role));
                }
                self::warn($warnings, __('Saved flow contains roles absent from this site; import requires those roles to exist.', 'dialoga'));
            }
        }
        if ('specific_roles' === $flow->visibility_mode && !$flow->visible_roles) {
            self::fail(__('specific_roles visibility requires at least one role.', 'dialoga'));
        }
        // Explicit portable allowlist: ignore database IDs, active state, timestamps and other metadata.
        $portable = (object) array('flow_name' => $flow->flow_name, 'visibility_mode' => $flow->visibility_mode,
            'visible_roles' => array_values(array_unique($flow->visible_roles)),
            'flow_data' => (object) array('nodes' => $flow->flow_data->nodes, 'edges' => $flow->flow_data->edges));
        $portable = self::clean($portable, $warnings);
        $nodes = array();
        $types = self::node_types();
        foreach ($portable->flow_data->nodes as $node) {
            if (!is_object($node) || !isset($node->id, $node->type, $node->position, $node->data)
                || !is_string($node->type) || '' === $node->type || !is_object($node->position)
                || !isset($node->position->x, $node->position->y)
                || !self::finite_number($node->position->x) || !self::finite_number($node->position->y)
                || !is_object($node->data) || !isset($node->data->nodeType) || !is_string($node->data->nodeType)) {
                self::fail(__('Nodes require string id/type/nodeType, a data object and finite numeric x/y positions.', 'dialoga'));
            }
            self::identifier($node->id, 'node.id');
            self::identifier($node->type, 'node.type');
            self::identifier($node->data->nodeType, 'node.data.nodeType');
            if ('__start__' === $node->id || isset($nodes[$node->id])) {
                self::fail(__('Duplicate or reserved node ID.', 'dialoga'));
            }
            $nodes[$node->id] = $node;
            if (!in_array($node->data->nodeType, $types, true)) {
                if (!$export) {
                    // translators: %s: unsupported node type slug.
                    self::fail(sprintf(__('Unsupported node type: %s. Enable its extension or register runtime support via dialoga_import_node_types.', 'dialoga'), $node->data->nodeType));
                }
                self::warn($warnings, __('Saved flow contains unsupported node types; enable the required extension before importing.', 'dialoga'));
            }
            foreach (array('options', 'buttons') as $key) {
                if (isset($node->data->$key)) {
                    self::entries($node->data->$key, 'node.data.' . $key);
                }
            }
            if (isset($node->data->output_groups)) {
                self::entries($node->data->output_groups, 'output_groups');
                foreach ($node->data->output_groups as $group) {
                    if (!isset($group->conditions, $group->logic) || !is_array($group->conditions)
                        || !in_array($group->logic, array('and', 'or'), true)) {
                        self::fail(__('Output groups require conditions arrays and and/or logic.', 'dialoga'));
                    }
                    self::entries($group->conditions, 'conditions', true);
                }
            }
            if (isset($node->data->config->fields)) {
                foreach ($node->data->config->fields as $field) {
                    if (!is_object($field) || !isset($field->enabled, $field->label, $field->required)) {
                        self::fail(__('Capture fields require enabled, label and required values.', 'dialoga'));
                    }
                }
            }
            if (isset($node->data->config->tags)) {
                foreach ($node->data->config->tags as $tag) {
                    if (!is_string($tag)) {
                        self::fail(__('Tags must be strings.', 'dialoga'));
                    }
                }
            }
            if (isset($node->data->config->lead_fields)) {
                foreach ($node->data->config->lead_fields as $enabled) {
                    if (!is_bool($enabled)) {
                        self::fail(__('AI lead_fields values must be booleans.', 'dialoga'));
                    }
                }
            }
            if ('ai_chat' === $node->data->nodeType || (isset($node->data->action_type) && 'ai_chat' === $node->data->action_type)) {
                self::warn($warnings, __('Review external URLs, email recipients, AI settings and other site dependencies before activating imported flows. Secrets in free-form text must be removed manually.', 'dialoga'));
            }
        }
        $edges = array();
        foreach ($portable->flow_data->edges as $edge) {
            if (!is_object($edge) || !isset($edge->id, $edge->source, $edge->target)) {
                self::fail(__('Edges require string id, source and target.', 'dialoga'));
            }
            foreach (array('id', 'source', 'target') as $key) {
                self::identifier($edge->$key, 'edge.' . $key);
            }
            if (isset($edges[$edge->id]) || ('__start__' !== $edge->source && !isset($nodes[$edge->source]))
                || !isset($nodes[$edge->target])) {
                self::fail(__('Duplicate edge ID or dangling edge reference (only edge sources may use __start__).', 'dialoga'));
            }
            $edges[$edge->id] = true;
            foreach (array('sourceHandle', 'targetHandle') as $key) {
                if (isset($edge->$key)) {
                    self::identifier($edge->$key, 'edge.' . $key);
                }
            }
        }
        foreach ($nodes as $node) {
            if (isset($node->data->target_node_id) && '' !== $node->data->target_node_id
                && !isset($nodes[$node->data->target_node_id])) {
                self::fail(__('Dangling legacy loop target_node_id reference.', 'dialoga'));
            }
        }
        return $portable;
    }

    private static function package(&$warnings) {
        $package = self::request_json('package');
        if (!is_object($package) || !isset($package->format, $package->version, $package->flows)
            || !in_array($package->format, array('dialoga-flows', 'dravio-flows'), true) || 1 !== $package->version
            || !is_array($package->flows) || !$package->flows || count($package->flows) > self::MAX_FLOWS) {
            self::fail(__('Expected dialoga-flows version 1 with 1-100 flows.', 'dialoga'));
        }
        $flows = array();
        foreach ($package->flows as $flow) {
            $flows[] = self::flow($flow, $warnings);
        }
        return $flows;
    }

    private static function error_response($error) {
        wp_send_json_error(array('message' => $error->getMessage()), $error->getCode() ?: 400);
    }

    public static function ajax_export() {
        try {
            self::authorize();
            $ids = self::request_json('flow_ids');
            if (!is_array($ids) || !$ids || count($ids) > self::MAX_FLOWS) {
                self::fail(__('flow_ids must contain 1-100 positive integer IDs.', 'dialoga'));
            }
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    self::fail(__('flow_ids must contain positive integers.', 'dialoga'));
                }
            }
            $ids = array_values(array_unique($ids));
            $override = null;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::request_json() only runs after self::authorize() verified the nonce.
            if (isset($_POST['current_flow'])) {
                $override = self::request_json('current_flow');
                if (!is_object($override) || !isset($override->id) || !is_int($override->id)
                    || !in_array($override->id, $ids, true)) {
                    self::fail(__('current_flow.id must be an integer in the selected flow_ids.', 'dialoga'));
                }
            }
            global $wpdb;
            $table = $wpdb->prefix . 'dialoga_flows';
            $warnings = array();
            $flows = array();
            $previous = $wpdb->suppress_errors(true);
            try {
                foreach ($ids as $id) {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id));
                    if ($wpdb->last_error) {
                        throw new RuntimeException(__('Could not read flows.', 'dialoga'), 500);
                    }
                    if (!$row) {
                        self::fail(__('A selected flow no longer exists.', 'dialoga'));
                    }
                    if ($override && $override->id === $id) {
                        $flow = $override;
                    } else {
                        $flow = (object) array('flow_name' => $row->flow_name, 'visibility_mode' => $row->visibility_mode,
                            'visible_roles' => self::decode($row->visible_roles ?: '[]', 'saved roles'),
                            'flow_data' => self::decode($row->flow_data, 'saved flow_data'));
                    }
                    $flows[] = self::flow($flow, $warnings, true);
                }
            } finally {
                $wpdb->suppress_errors($previous);
            }
            $package = array('format' => 'dialoga-flows', 'version' => 1, 'plugin_version' => DIALOGA_VERSION, 'flows' => $flows);
            if (strlen(wp_json_encode($package)) > self::MAX_BYTES) {
                self::fail(__('Export package exceeds 5 MiB; select fewer flows.', 'dialoga'));
            }
            wp_send_json_success(array('package' => $package, 'warnings' => $warnings));
        } catch (InvalidArgumentException $error) {
            self::error_response($error);
        } catch (RuntimeException $error) {
            self::error_response($error);
        }
    }

    public static function ajax_validate() {
        self::import_request(true);
    }

    public static function ajax_import() {
        self::import_request(false);
    }

    private static function import_request($dry_run) {
        try {
            self::authorize();
            $warnings = array();
            $flows = self::package($warnings);
            $summaries = array();
            $rows = array();
            $now = current_time('mysql');
            foreach ($flows as $flow) {
                $summaries[] = array('flow_name' => $flow->flow_name, 'node_count' => count($flow->flow_data->nodes),
                    'edge_count' => count($flow->flow_data->edges));
                $rows[] = array('flow_name' => $flow->flow_name, 'flow_data' => wp_json_encode($flow->flow_data),
                    'is_active' => 0, 'visibility_mode' => $flow->visibility_mode,
                    'visible_roles' => wp_json_encode($flow->visible_roles), 'updated_at' => $now, 'created_at' => $now);
            }
            if ($dry_run) {
                wp_send_json_success(array('flows' => $summaries, 'warnings' => $warnings));
                return;
            }
            global $wpdb;
            $table = $wpdb->prefix . 'dialoga_flows';
            $inserted = array();
            $previous = $wpdb->suppress_errors(true);
            try {
                foreach ($rows as $row) {
                    if (false === $wpdb->insert($table, $row, array('%s', '%s', '%d', '%s', '%s', '%s', '%s'))) {
                        // Compensating rollback works even when the table engine does not support transactions.
                        $remaining = array();
                        foreach (array_reverse($inserted) as $id) {
                            if (false === $wpdb->delete($table, array('id' => $id), array('%d'))) {
                                $remaining[] = $id;
                            }
                            Dialoga_Frontend::invalidate_flow_cache($id);
                        }
                        if ($remaining) {
                            wp_send_json_error(array('message' => __('Import failed and cleanup was incomplete. These newly created inactive flows require manual removal.', 'dialoga'),
                                'cleanup_failed' => true, 'remaining_flow_ids' => array_reverse($remaining)), 500);
                            return;
                        }
                        throw new RuntimeException(__('Import failed. All flows created by this request were removed.', 'dialoga'), 500);
                    }
                    $inserted[] = (int) $wpdb->insert_id;
                    Dialoga_Frontend::invalidate_flow_cache((int) $wpdb->insert_id);
                }
            } finally {
                $wpdb->suppress_errors($previous);
            }
            wp_send_json_success(array('flow_ids' => $inserted, 'flows' => $summaries, 'warnings' => $warnings));
        } catch (InvalidArgumentException $error) {
            self::error_response($error);
        } catch (RuntimeException $error) {
            self::error_response($error);
        }
    }
}
