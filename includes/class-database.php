<?php
/**
 * Database management for DIALOGA
 *
 * SQL safety note: table names are all $wpdb->prefix-derived (Dialoga's own
 * custom tables). Schema changes (CREATE/ALTER/DROP) inherently require
 * literal SQL — placeholders are not supported by MySQL DDL. All dynamic
 * values pass through $wpdb->prepare() properly.
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

if (!defined('ABSPATH')) {
    exit;
}

class Dialoga_Database {

    /**
     * Migration from the pre-1.0 "DialogaWP" era: table names carried the
     * "dialogawp_" prefix. WordPress.org cannot accept plugins with "wp" in
     * the slug, so the plugin was renamed to Dialoga at 1.0.0 and its tables
     * renamed accordingly. RENAME preserves every row, index, and column.
     * Idempotent — does nothing when legacy tables are absent/gone.
     */
    public static function rename_legacy_tables() {
        global $wpdb;
        $tables = array(
            'dialoga_flows',
            'dialoga_conversations',
            'dialoga_leads',
            'dialoga_analytics',
            'dialoga_ai_knowledge',
        );
        foreach ($tables as $table) {
            $legacy = $wpdb->prefix . 'dialogawp_' . substr($table, strlen('dialoga_'));
            $current = $wpdb->prefix . $table;
            $legacy_exists  = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy));
            $current_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $current));
            if ($legacy_exists && !$current_exists) {
                $wpdb->query($wpdb->prepare("RENAME TABLE %i TO %i", $legacy, $current));
            }
        }
    }

    /**
     * Migration from the pre-1.0 "DialogaWP" era: option names also carried
     * the "dialogawp_" prefix (settings, AI settings, transients, etc.).
     * One UPDATE per storage table renames everything in place; idempotent
     * because REPLACE() is a no-op once no "dialogawp_" substring remains.
     */
    public static function rename_legacy_options() {
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->options}
             SET option_name = REPLACE(option_name, 'dialogawp_', 'dialoga_')
             WHERE option_name LIKE '%dialogawp\\_%'"
        );
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Flows table
        $flows_table = $wpdb->prefix . 'dialoga_flows';
        $flows_sql = "CREATE TABLE IF NOT EXISTS $flows_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            flow_name varchar(255) NOT NULL,
            flow_data longtext NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            visibility_mode varchar(50) DEFAULT 'everyone',
            visible_roles text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Conversations table
        $conversations_table = $wpdb->prefix . 'dialoga_conversations';
        $conversations_sql = "CREATE TABLE IF NOT EXISTS $conversations_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            flow_id bigint(20) DEFAULT NULL,
            conversation_data longtext NOT NULL,
            lead_data longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            page_url varchar(500) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY flow_id (flow_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Leads table
        $leads_table = $wpdb->prefix . 'dialoga_leads';
        $leads_sql = "CREATE TABLE IF NOT EXISTS $leads_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            service_interest varchar(100) DEFAULT NULL,
            message text DEFAULT NULL,
            lead_data longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'new',
            tags varchar(500) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY email (email),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Analytics table
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';
        $analytics_sql = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) DEFAULT NULL,
            flow_id bigint(20) DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY flow_id (flow_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        // AI knowledge base table
        $ai_knowledge_table = $wpdb->prefix . 'dialoga_ai_knowledge';
        $ai_knowledge_sql = "CREATE TABLE IF NOT EXISTS $ai_knowledge_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content_type varchar(50) NOT NULL DEFAULT 'page',
            content_id bigint(20) DEFAULT NULL,
            title varchar(255) NOT NULL,
            url varchar(500) DEFAULT NULL,
            content longtext NOT NULL,
            embedding longtext DEFAULT NULL,
            token_estimate int(11) DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY content_type (content_type),
            KEY content_id (content_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($flows_sql);
        dbDelta($conversations_sql);
        dbDelta($leads_sql);
        dbDelta($analytics_sql);
        dbDelta($ai_knowledge_sql);
    }
    
    /**
     * Drop all tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'dialoga_flows',
            $wpdb->prefix . 'dialoga_conversations',
            $wpdb->prefix . 'dialoga_leads',
            $wpdb->prefix . 'dialoga_analytics'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
    
    /**
     * Migrate database to latest version
     * Adds new columns to existing tables
     */
    /**
     * True if the given column exists on the given table in the CURRENT database.
     *
     * v0.38.16 — this replaces the old INFORMATION_SCHEMA.COLUMNS checks used
     * throughout migrate(). On shared MySQL hosts (Cloudways is a big one),
     * INFORMATION_SCHEMA queries without a TABLE_SCHEMA filter can match a
     * same-named column in a different database on the same instance, giving
     * a false positive that caused migrations to be silently skipped. SHOW
     * COLUMNS is scoped to the current database automatically.
     */
    private static function column_exists($table, $column) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ));
        return !empty($exists);
    }

    public static function migrate() {
        global $wpdb;
        $flows_table     = $wpdb->prefix . 'dialoga_flows';
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';
        $leads_table     = $wpdb->prefix . 'dialoga_leads';
        $ai_table        = $wpdb->prefix . 'dialoga_ai_knowledge';

        // Visibility columns on flows
        if (!self::column_exists($flows_table, 'visibility_mode')) {
            $wpdb->query("ALTER TABLE {$flows_table}
                         ADD COLUMN visibility_mode varchar(50) DEFAULT 'everyone' AFTER is_active,
                         ADD COLUMN visible_roles text DEFAULT NULL AFTER visibility_mode");
        }

        // embedding on AI knowledge (only if the table exists at all)
        $ai_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$ai_table}'");
        if ($ai_table_exists && !self::column_exists($ai_table, 'embedding')) {
            $wpdb->query("ALTER TABLE {$ai_table} ADD COLUMN embedding longtext DEFAULT NULL AFTER content");
        }

        // page_url on conversations
        if (!self::column_exists($conv_table, 'page_url')) {
            $wpdb->query("ALTER TABLE {$conv_table} ADD COLUMN page_url varchar(500) DEFAULT NULL AFTER user_agent");
        }

        // flow_id on analytics
        if (!self::column_exists($analytics_table, 'flow_id')) {
            $wpdb->query("ALTER TABLE {$analytics_table} ADD COLUMN flow_id bigint(20) DEFAULT NULL AFTER conversation_id");
        }

        // tags on leads
        if (!self::column_exists($leads_table, 'tags')) {
            $wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN tags varchar(500) DEFAULT NULL AFTER status");
        }

        // Drop legacy flow-level webhook columns (v0.37.0 moved webhooks to per-node)
        if (self::column_exists($flows_table, 'webhook_url')) {
            $wpdb->query("ALTER TABLE {$flows_table} DROP COLUMN webhook_url, DROP COLUMN webhook_secret");
        }
        if (self::column_exists($flows_table, 'webhook_connector_id')) {
            $wpdb->query("ALTER TABLE {$flows_table} DROP COLUMN webhook_connector_id");
        }

        // v0.38.7 — admin-test session flag on conversations + leads
        if (!self::column_exists($conv_table, 'is_admin_session')) {
            $wpdb->query("ALTER TABLE {$conv_table} ADD COLUMN is_admin_session tinyint(1) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE {$conv_table} ADD INDEX is_admin_session (is_admin_session)");
        }
        if (!self::column_exists($leads_table, 'is_admin_session')) {
            $wpdb->query("ALTER TABLE {$leads_table} ADD COLUMN is_admin_session tinyint(1) NOT NULL DEFAULT 0");
            $wpdb->query("ALTER TABLE {$leads_table} ADD INDEX is_admin_session (is_admin_session)");
        }

        // v0.40.9 — one-time backfill: every pre-v0.40.7 conversation is
        // missing a `node_viewed` event for its flow's start node (because
        // ajax_start_conversation didn't fire that event until v0.40.7).
        // Result: the Funnel report showed step 1 as "1 visitor" while
        // downstream steps showed dozens, because only the new post-fix
        // conversations had the entry event.
        //
        // Backfill by synthesizing a node_viewed event per conversation
        // at the conversation's created_at timestamp. Idempotent — the
        // NOT EXISTS guard ensures we never insert a duplicate if the
        // migration runs twice (e.g. plugin re-activation). Uses each
        // flow's CURRENT start node — if the start node was changed since
        // the historical conversation, the backfilled event reflects the
        // present flow structure, which is what all the analytics queries
        // already assume.
        self::backfill_start_node_views();
    }

    /**
     * v0.40.9 — Backfill node_viewed events for the start node of every
     * conversation that lacks one. See migrate() for context. Safe to run
     * multiple times; a per-flow marker option guards against redundant
     * work, and the SQL uses NOT EXISTS to be extra-safe.
     */
    public static function backfill_start_node_views() {
        global $wpdb;
        $flows_table     = $wpdb->prefix . 'dialoga_flows';
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';

        $done = get_option('dialoga_backfilled_start_nodes', array());
        if (!is_array($done)) $done = array();

        $flow_rows = $wpdb->get_results("SELECT id, flow_data FROM {$flows_table}");
        if (!$flow_rows) return;

        $total_inserted = 0;
        foreach ($flow_rows as $flow) {
            $flow_id_key = (string) $flow->id;
            // Marker per-flow-per-start-node so re-runs after a start-node
            // change also backfill for the NEW start node without re-doing
            // the old one.
            $fd = json_decode($flow->flow_data, true);
            if (!is_array($fd) || empty($fd['nodes'])) continue;

            $start_id   = '';
            $start_type = '';
            foreach ($fd['nodes'] as $n) {
                if (!empty($n['data']['is_start'])) {
                    $start_id   = $n['id'];
                    $start_type = isset($n['data']['nodeType']) ? $n['data']['nodeType'] : '';
                    break;
                }
            }
            if (!$start_id && !empty($fd['nodes'][0])) {
                $start_id   = $fd['nodes'][0]['id'];
                $start_type = isset($fd['nodes'][0]['data']['nodeType']) ? $fd['nodes'][0]['data']['nodeType'] : '';
            }
            if (!$start_id) continue;

            $marker_key = $flow_id_key . ':' . $start_id;
            if (isset($done[$marker_key])) continue;

            $event_data = wp_json_encode(array(
                'node_id'   => $start_id,
                'node_type' => $start_type,
                'flow_id'   => intval($flow->id),
            ));

            // Insert a synthetic node_viewed event for every conversation
            // on this flow that lacks one for this start node. The
            // NOT EXISTS subquery prevents duplicates from prior partial
            // runs. Timestamp mirrors the conversation's created_at so
            // the event chronologically precedes any subsequent
            // node_viewed events (which is the natural real-world order).
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$analytics_table}
                    (conversation_id, flow_id, event_type, event_data, created_at)
                 SELECT c.id, %d, 'node_viewed', %s, c.created_at
                 FROM {$conv_table} c
                 WHERE c.flow_id = %d
                   AND NOT EXISTS (
                       SELECT 1 FROM {$analytics_table} a
                       WHERE a.conversation_id = c.id
                         AND a.event_type = 'node_viewed'
                         AND JSON_UNQUOTE(JSON_EXTRACT(a.event_data, '$.node_id')) = %s
                   )",
                intval($flow->id),
                $event_data,
                intval($flow->id),
                $start_id
            ));

            $total_inserted += intval($inserted);
            $done[$marker_key] = array(
                'ran_at'   => current_time('mysql'),
                'inserted' => intval($inserted),
            );
        }

        update_option('dialoga_backfilled_start_nodes', $done, false);

        if ($total_inserted > 0 && function_exists('error_log')) {
            error_log(sprintf('[Dialoga] Backfilled %d start-node view events across %d flows.', $total_inserted, count($flow_rows)));
        }
    }
    
    /**
     * Save lead data to database
     *
     * @param array  $lead_data  Lead information (name, email, phone, etc.)
     * @param array  $tags       Tags to attach
     * @param string $session_id Optional session id — used to link lead to its conversation row
     *                           so the lead-detail view can render the transcript.
     * @return int|false Lead ID on success, false on failure
     */
    public static function save_lead($lead_data, $tags = array(), $session_id = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dialoga_leads';

        // Extract standard fields
        $name  = isset($lead_data['name'])  ? $lead_data['name']  : '';
        $email = isset($lead_data['email']) ? $lead_data['email'] : '';
        $phone = isset($lead_data['phone']) ? $lead_data['phone'] : '';
        $company = isset($lead_data['company']) ? $lead_data['company'] : '';

        // Normalize tags — unique, non-empty
        $tags_clean = array_values(array_unique(array_filter(array_map('sanitize_text_field', $tags))));

        // Look up conversation_id from session_id so the lead-detail transcript view can join.
        // Without this link, the lead row's conversation_id stays NULL and the UI shows
        // "No transcript recorded" even though the transcript was saved.
        // Also carry the is_admin_session flag forward so the leads list can filter test data.
        $conversation_id = 0;
        $is_admin_session = (is_user_logged_in() && current_user_can('manage_options')) ? 1 : 0;
        if (!empty($session_id)) {
            $conv_table = $wpdb->prefix . 'dialoga_conversations';
            $conv_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, is_admin_session FROM {$conv_table} WHERE session_id = %s LIMIT 1",
                $session_id
            ));
            if ($conv_row) {
                $conversation_id = (int) $conv_row->id;
                // Promote flag if EITHER the conv was started by an admin OR the
                // lead submission itself is happening while admin is logged in
                if (!empty($conv_row->is_admin_session)) $is_admin_session = 1;
            }
        }

        // Prepare insert data — start with columns that exist on every install
        $insert_data = array(
            'name'             => $name,
            'email'            => $email,
            'phone'            => $phone,
            'lead_data'        => json_encode($lead_data),
            'status'           => 'new',
            'tags'             => !empty($tags_clean) ? json_encode($tags_clean) : null,
            'created_at'       => current_time('mysql'),
        );
        $insert_format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s');

        // Introspect the leads table and only add optional columns that exist.
        // Same defense pattern as save_conversation — protects installs where
        // migration didn't run or ran late.
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        if (in_array('is_admin_session', $columns, true)) {
            $insert_data['is_admin_session'] = $is_admin_session;
            $insert_format[] = '%d';
        }
        if ($conversation_id > 0) {
            $insert_data['conversation_id'] = $conversation_id;
            $insert_format[] = '%d';
        }
        if (in_array('company', $columns, true)) {
            $insert_data['company'] = $company;
            $insert_format[] = '%s';
        }

        // Insert lead
        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $insert_format
        );

        if ($result === false) {
            error_log('DIALOGA: Failed to save lead - ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }
    
    /**
     * Update conversation with captured lead data
     * 
     * @param string $session_id Session identifier
     * @param array $lead_data Lead information
     * @return bool Success status
     */
    public static function update_conversation_lead($session_id, $lead_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dialoga_conversations';
        
        // Update conversation record
        $result = $wpdb->update(
            $table_name,
            array(
                'lead_data' => json_encode($lead_data),
                'updated_at' => current_time('mysql')
            ),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );
        
        if ($result === false) {
            error_log('DIALOGA: Failed to update conversation lead - ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Insert a new conversation record at the start of a chat session.
     *
     * @param string $session_id  Unique session identifier
     * @param int    $flow_id     Flow being used
     * @param string $page_url    Page URL where chat started
     * @param string $ip_address  Visitor IP
     * @param string $user_agent  Visitor user-agent
     * @return int|false          Row ID on success, false on failure
     */
    public static function save_conversation($session_id, $flow_id, $page_url = '', $ip_address = '', $user_agent = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_conversations';

        // Base insert data — required columns that exist on every install
        $data = array(
            'session_id'       => $session_id,
            'flow_id'          => $flow_id,
            'conversation_data'=> '{}',
            'page_url'         => $page_url,
            'ip_address'       => $ip_address,
            'user_agent'       => $user_agent,
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        );
        $format = array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s');

        // v0.38.13 — only include is_admin_session if the column actually
        // exists. Without this check, sites where the migration didn't run
        // (or ran after the upload but before the schema catch-up) would
        // silently reject every save because the column is missing.
        $columns = $wpdb->get_col("DESCRIBE {$table}", 0);
        if (in_array('is_admin_session', $columns, true)) {
            $data['is_admin_session'] = (is_user_logged_in() && current_user_can('manage_options')) ? 1 : 0;
            $format[] = '%d';
        }

        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            error_log('Dialoga: Failed to save conversation - ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * v1.0.4 — repair flows whose JSON keys were case-folded on save.
     *
     * Between v1.0.0 and v1.0.3 dialoga_sanitize_json() ran sanitize_key() over
     * every object key in flow_data before writing it. sanitize_key()
     * lowercases, so the builder's camelCase keys were renamed in place:
     *
     *     nodeType     -> nodetype
     *     sourceHandle -> sourcehandle
     *     targetHandle -> targethandle
     *     loopSide     -> loopside
     *
     * Nothing reads the lowercase spellings, so the damage was silent until a
     * visitor opened the chat: format_node_response() could not resolve
     * $node['data']['nodeType'], hit its default branch, and greeted them with
     * "Configuration error. Please contact support." Branch and loop edges
     * lost their handle bindings the same way.
     *
     * The sanitizer no longer touches case (see dialoga_sanitize_json_key), but
     * rows written while it did are still wrong on disk, so rename them back.
     * Only flows that actually contain a mangled key are rewritten, and the
     * correct spelling always wins if somehow both are present.
     */
    public static function repair_flow_key_case() {
        global $wpdb;
        $flows_table = $wpdb->prefix . 'dialoga_flows';

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$flows_table}'")) {
            return 0;
        }

        $map = array(
            'nodetype'     => 'nodeType',
            'sourcehandle' => 'sourceHandle',
            'targethandle' => 'targetHandle',
            'loopside'     => 'loopSide',
        );

        $rows = $wpdb->get_results("SELECT id, flow_data FROM {$flows_table}");
        if (empty($rows)) {
            return 0;
        }

        $repaired = 0;

        foreach ($rows as $row) {
            $data = json_decode($row->flow_data, true);
            if (!is_array($data)) {
                continue;
            }

            $changed = false;
            $data    = self::rename_keys_deep($data, $map, $changed);

            if (!$changed) {
                continue;
            }

            $wpdb->update(
                $flows_table,
                array('flow_data' => wp_json_encode($data)),
                array('id' => $row->id),
                array('%s'),
                array('%d')
            );
            $repaired++;
        }

        return $repaired;
    }

    /**
     * Recursively rename object keys per $map. $changed is set by reference so
     * the caller can skip the UPDATE on rows that were already correct.
     */
    private static function rename_keys_deep($value, $map, &$changed) {
        if (!is_array($value)) {
            return $value;
        }

        $out = array();
        foreach ($value as $key => $child) {
            if (is_string($key) && isset($map[$key])) {
                $correct = $map[$key];
                // A row that somehow holds both spellings keeps the good one.
                if (array_key_exists($correct, $value)) {
                    $changed = true;
                    continue;
                }
                $key     = $correct;
                $changed = true;
            }
            $out[$key] = self::rename_keys_deep($child, $map, $changed);
        }

        return $out;
    }
}
