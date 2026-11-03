<?php
/**
 * Analytics for Dialoga
 *
 * SQL safety note (wp.org Plugin Check):
 *   All queries in this file interpolate three kinds of tokens that PHPCS can't
 *   statically verify but are provably safe:
 *     1. Table names built as $wpdb->prefix . 'dialoga_xxx' (never user input)
 *     2. Static SQL fragments from date_range_clause() / admin_session_clause() /
 *        admin_session_subquery() — those helpers themselves use $wpdb->prepare()
 *        for any dynamic values and return literal WHERE/AND fragments
 *     3. Placeholder strings (?, %d, %s) — never user data
 *   User-supplied values ALWAYS go through $wpdb->prepare() with proper placeholders.
 *   The rule-scoped disables below silence the false positives without weakening
 *   the file's actual SQL safety review.
 */
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

if (!defined('ABSPATH')) {
    exit;
}

class Dialoga_Analytics {

    /**
     * Track event
     *
     * @param int    $conversation_id  DB row ID of the conversation
     * @param string $event_type       Event name (conversation_started, node_viewed, lead_captured, conversation_abandoned)
     * @param array  $event_data       Additional event payload
     * @param int    $flow_id          Flow ID (denormalised for fast queries)
     */
    public static function track_event($conversation_id, $event_type, $event_data = array(), $flow_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_analytics';

        $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id ?: null,
                'flow_id'         => $flow_id ?: null,
                'event_type'      => $event_type,
                'event_data'      => json_encode($event_data),
                'created_at'      => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
    }

    /**
     * v0.40.0 — Return the set of node IDs (across all flows) whose Link
     * Button config currently has "Count as conversion" enabled. Used by
     * `get_conversation_metrics` to decide which historical `link_clicked`
     * events should count as conversions.
     *
     * Design choice: resolved from CURRENT flow config rather than a stored
     * per-event flag, so toggling the admin checkbox retroactively includes
     * or excludes past clicks on that node. Trade-off: dashboard reflects
     * present intent rather than click-time state. Users overwhelmingly
     * expect the retroactive behavior — "I turned this on, why is the
     * historical data zero?" — so we optimize for that.
     *
     * Cached per-request in a static var so repeat metric calls in one
     * request (dashboard + analytics page + AJAX refresh) share one parse.
     * Not persisted between requests because flow edits can happen at any
     * moment; the cache would go stale silently.
     *
     * @return array Flat list of node ID strings.
     */
    public static function get_conversion_link_node_ids() {
        static $cache = null;
        if ($cache !== null) return $cache;

        global $wpdb;
        $flows_table = $wpdb->prefix . 'dialoga_flows';
        $rows = $wpdb->get_results("SELECT id, flow_data FROM {$flows_table}");

        $node_ids = array();
        if ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->flow_data, true);
                if (!is_array($data) || empty($data['nodes'])) continue;
                foreach ($data['nodes'] as $node) {
                    // Guard: only action nodes of type link_button, only when
                    // is_conversion is truthy. Any node without an id is
                    // corrupt data — skip silently rather than break metrics.
                    if (empty($node['id'])) continue;
                    if (!isset($node['data']) || !is_array($node['data'])) continue;
                    if (($node['data']['nodeType'] ?? '') !== 'action') continue;
                    if (($node['data']['action_type'] ?? '') !== 'link_button') continue;
                    $cfg = isset($node['data']['config']) && is_array($node['data']['config']) ? $node['data']['config'] : array();
                    if (empty($cfg['is_conversion'])) continue;
                    $node_ids[] = (string) $node['id'];
                }
            }
        }

        // De-dupe defensively — node IDs are unique per flow but if the
        // same ID somehow appears across flows the IN() clause doesn't care.
        $cache = array_values(array_unique($node_ids));
        return $cache;
    }

    /**
     * Get high-level conversation metrics for a date range.
     *
     * v0.40.0 — `conversion_rate` now reflects BOTH lead captures and marked
     * Link Button clicks. The set of "which link clicks count" is derived
     * from current flow config via get_conversion_link_node_ids(), so
     * toggling the "Count as conversion" checkbox on a node retroactively
     * includes/excludes ALL historical clicks on it. This is intentional
     * (users expect the checkbox to feel retroactive) — the trade-off is
     * that the historical count "changes" when the config changes.
     *
     * `conversations_with_leads` is unchanged (still lead captures only)
     * so callers that need the strict lead rate can compute it.
     * `conversations_with_link_conversions` exposes the link-click
     * contribution for transparency in the UI.
     */
    public static function get_conversation_metrics($date_from = null, $date_to = null) {
        global $wpdb;
        $table          = $wpdb->prefix . 'dialoga_conversations';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';

        $where = 'WHERE 1=1';
        if ($date_from) $where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
        if ($date_to)   $where .= $wpdb->prepare(' AND created_at <= %s', $date_to . ' 23:59:59');
        // v0.38.7 — exclude admin testing sessions so the conversion rate reflects real visitors
        $where .= self::admin_session_clause();

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        $with_leads = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where AND lead_data IS NOT NULL AND lead_data != '{}'");

        // v0.40.0 — resolve currently-marked Link Button node IDs from live
        // flow config. If nothing is marked, skip the link-conversion queries
        // entirely (saves two JSON_EXTRACT passes) and treat those metrics
        // as zero.
        $conversion_node_ids = self::get_conversion_link_node_ids();

        $with_link_conversions   = 0;
        $total_link_click_events = 0;
        $converted               = $with_leads;

        if (!empty($conversion_node_ids)) {
            // Build the aliased WHERE (primary $where uses unaliased columns
            // and can't be reused in a JOINed query).
            $where_aliased = 'WHERE 1=1';
            if ($date_from) $where_aliased .= $wpdb->prepare(' AND c.created_at >= %s', $date_from);
            if ($date_to)   $where_aliased .= $wpdb->prepare(' AND c.created_at <= %s', $date_to . ' 23:59:59');
            $where_aliased .= self::admin_session_clause('c');

            $placeholders = implode(',', array_fill(0, count($conversion_node_ids), '%s'));
            // JSON_UNQUOTE strips the surrounding double-quotes JSON_EXTRACT
            // wraps string values in, so IN() comparison against the string
            // list works without coercion surprises.
            $node_id_expr = "JSON_UNQUOTE(JSON_EXTRACT(a.event_data, '$.node_id'))";

            $link_conversions_sql = "
                SELECT COUNT(DISTINCT a.conversation_id)
                FROM {$analytics_table} a
                INNER JOIN {$table} c ON c.id = a.conversation_id
                {$where_aliased}
                  AND a.event_type = 'link_clicked'
                  AND {$node_id_expr} IN ({$placeholders})
            ";
            // Query is genuinely safe: table names are $wpdb->prefix-derived, and
            // $conversion_node_ids values pass through $wpdb->prepare below.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $with_link_conversions = (int) $wpdb->get_var( $wpdb->prepare($link_conversions_sql, $conversion_node_ids) );

            // v0.40.3 — total raw click count on marked buttons. Same predicate
            // as above but without DISTINCT — one row per click, so a visitor
            // who clicked Salt Cave + Massage in one conversation contributes
            // 2 here (while `conversations_with_link_conversions` counts them
            // as 1). Different lens: this is booking-actions volume, that is
            // unique-visitor-converted count.
            $total_click_sql = "
                SELECT COUNT(*)
                FROM {$analytics_table} a
                INNER JOIN {$table} c ON c.id = a.conversation_id
                {$where_aliased}
                  AND a.event_type = 'link_clicked'
                  AND {$node_id_expr} IN ({$placeholders})
            ";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_link_click_events = (int) $wpdb->get_var( $wpdb->prepare($total_click_sql, $conversion_node_ids) );

            // Union: DISTINCT conversations that captured a lead OR triggered
            // a marked link click.
            $union_sql = "
                SELECT COUNT(DISTINCT c.id)
                FROM {$table} c
                LEFT JOIN {$analytics_table} a
                    ON a.conversation_id = c.id
                   AND a.event_type = 'link_clicked'
                   AND {$node_id_expr} IN ({$placeholders})
                {$where_aliased}
                  AND (
                    (c.lead_data IS NOT NULL AND c.lead_data != '{}')
                    OR a.id IS NOT NULL
                  )
            ";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $converted = (int) $wpdb->get_var( $wpdb->prepare($union_sql, $conversion_node_ids) );
        }

        return array(
            'total_conversations'                 => $total,
            'conversations_with_leads'            => $with_leads,
            'conversations_with_link_conversions' => $with_link_conversions,
            'conversations_converted'             => $converted,
            'total_link_conversion_clicks'        => $total_link_click_events,
            'conversion_rate'                     => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
        );
    }

    /**
     * Get lead metrics for a date range.
     */
    public static function get_lead_metrics($date_from = null, $date_to = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_leads';

        $where = 'WHERE 1=1';
        if ($date_from) $where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
        if ($date_to)   $where .= $wpdb->prepare(' AND created_at <= %s', $date_to . ' 23:59:59');
        // v0.38.17 — same column on leads table; leads created during admin
        // testing carry the flag forward from the source conversation.
        $where .= self::admin_session_clause();

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        $by_status  = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $table $where GROUP BY status");
        $by_service = $wpdb->get_results("SELECT service_interest, COUNT(*) as count FROM $table $where GROUP BY service_interest");

        return array(
            'total_leads' => $total,
            'by_status'   => $by_status,
            'by_service'  => $by_service,
        );
    }

    /**
     * Daily conversation counts for the last N days.
     */
    public static function get_time_metrics($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_conversations';

        // v0.38.17 — the daily trend chart used to skip the admin filter,
        // causing tile totals and chart lines to disagree. Apply consistently.
        $admin_clause = self::admin_session_clause();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY){$admin_clause}
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $days
        ));
    }

    /**
     * Per-node view counts — funnel data for a given flow.
     * Returns nodes ordered by view count descending.
     */
    public static function get_funnel_data($flow_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub = self::admin_session_subquery();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data, COUNT(*) as views
             FROM $table
             WHERE event_type = 'node_viewed' AND flow_id = %d{$admin_sub}
             GROUP BY event_data
             ORDER BY views DESC",
            $flow_id
        ));

        $funnel = array();
        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            if ($data) {
                $funnel[] = array(
                    'node_id'    => isset($data['node_id'])    ? $data['node_id']    : '',
                    'node_type'  => isset($data['node_type'])  ? $data['node_type']  : '',
                    'node_label' => isset($data['node_label']) ? $data['node_label'] : '',
                    'views'      => (int) $row->views,
                );
            }
        }

        return $funnel;
    }

    /**
     * v0.40.5 — Top N nodes ranked by "how many visitors never reached this node".
     * Returns [{node_id, node_label, node_type, views, missed, drop_off_pct}]
     * ordered by missed DESC (biggest bottleneck first).
     *
     * v0.40.6 — labels now sourced from live flow config via
     * get_node_display_label(), so Action nodes correctly show "🔗 Book
     * Massage" instead of the raw type slug "action". Nodes that no longer
     * exist in the current flow config are excluded (deleted-node events
     * are noise).
     *
     * v0.40.12 — conversion-marked Link Buttons are excluded from the
     * report entirely. Reaching one IS the goal, so counting them as
     * drop-off points is exactly backwards — Chris repeatedly caught the
     * Dashboard tile and Analytics tile showing "94% of visitors dropped
     * off at Book Facial", which was wildly misleading. Real drop-offs
     * happen at Question and Capture Lead nodes (where visitors saw a
     * prompt and bailed instead of proceeding). Link Button "views" are
     * conversion opportunities; clicks are the conversion signal (tracked
     * separately as link_clicked events).
     *
     * Excludes the flow's first node (everyone views it by definition, no drop
     * possible). Excludes nodes with <3 views (statistical noise on small
     * flows). Filters admin sessions like all other analytics queries.
     */
    public static function get_top_drop_offs($flow_id, $limit = 3) {
        global $wpdb;
        $table     = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub = self::admin_session_subquery();

        $started = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE event_type = 'conversation_started' AND flow_id = %d{$admin_sub}",
            $flow_id
        ));
        if (!$started) return array();

        // Load flow nodes; identify start node so we can exclude it.
        $nodes_by_id   = self::get_flow_nodes_by_id($flow_id);
        $first_node_id = '';
        foreach ($nodes_by_id as $nid => $n) {
            if (!empty($n['data']['is_start'])) {
                $first_node_id = $nid;
                break;
            }
        }
        // Fallback to the first node in doc order.
        if (!$first_node_id && !empty($nodes_by_id)) {
            $first_node_id = array_key_first($nodes_by_id);
        }

        // v0.40.12 — precompute conversion node IDs so we can skip them.
        // Reaching a conversion terminal IS the goal; it's not a "drop-off".
        $conversion_node_ids = array_flip(self::get_conversion_link_node_ids());

        // Aggregate views by node_id + capture historical labels. Ordered
        // by created_at ASC so the LAST label written per node_id is the
        // most recent (v0.40.17 — preserves historical text for A/B
        // testing; editing a Question's wording doesn't rewrite the past).
        $node_views  = array();
        $node_labels = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data
             FROM {$table}
             WHERE event_type = 'node_viewed' AND flow_id = %d{$admin_sub}
             ORDER BY created_at ASC",
            $flow_id
        ));
        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            if (!is_array($data) || empty($data['node_id'])) continue;
            $nid = $data['node_id'];
            $node_views[$nid] = ($node_views[$nid] ?? 0) + 1;
            // Only accept meaningful labels (non-empty, not the raw type slug).
            $lbl = trim($data['node_label'] ?? '');
            $type_str = $data['node_type'] ?? '';
            if ($lbl !== '' && $lbl !== $type_str) {
                $node_labels[$nid] = $lbl;
            }
        }

        $result = array();
        foreach ($node_views as $node_id => $views) {
            if ($node_id === $first_node_id) continue;
            if ($views < 3) continue; // noise floor
            // Skip events for nodes that no longer exist in the flow.
            if (!isset($nodes_by_id[$node_id])) continue;
            // v0.40.12 — never report a conversion terminal as a drop-off.
            if (isset($conversion_node_ids[$node_id])) continue;
            $node = $nodes_by_id[$node_id];
            $missed = max(0, $started - $views);
            $result[] = array(
                'node_id'      => $node_id,
                // Prefer historical label (from most recent event with a
                // valid label); fall back to live config for old events
                // that pre-date v0.40.17's proper label capture.
                'node_label'   => $node_labels[$node_id] ?? self::get_node_display_label($node),
                'node_type'    => $node['data']['nodeType'] ?? '',
                'views'        => $views,
                'missed'       => $missed,
                'drop_off_pct' => $started > 0 ? round(($missed / $started) * 100, 1) : 0,
            );
        }

        // Sort by missed descending (biggest bottleneck first) and cap at limit.
        usort($result, function($a, $b) { return $b['missed'] - $a['missed']; });
        return array_slice($result, 0, $limit);
    }

    /**
     * v0.40.5 — Branch Insights: for every Question / Branch node in the flow,
     * report which downstream option visitors actually took, ordered by
     * popularity. This replaces the Sankey (Path Flow) as the primary
     * "understanding visitor choices" report — text-first, per-branch, with
     * clear percentages. Only choice nodes that saw traffic are returned.
     *
     * Returns [{node_id, node_label, node_type, total_visitors, splits: [{label, target_node_id, count, percent}]}]
     */
    public static function get_branch_insights($flow_id, $date_from = null, $date_to = null) {
        global $wpdb;

        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        if (!$flow) return array();
        $fd = json_decode($flow->flow_data, true);
        if (!is_array($fd) || empty($fd['nodes']) || empty($fd['edges'])) return array();

        // Index nodes and identify choice-bearing nodes (Question + Branch).
        $nodes_by_id  = array();
        $choice_nodes = array();
        foreach ($fd['nodes'] as $node) {
            if (empty($node['id'])) continue;
            $nodes_by_id[$node['id']] = $node;
            $data      = $node['data'] ?? array();
            $node_type = $data['nodeType'] ?? '';
            if ($node_type === 'question' && !empty($data['options'])) {
                $choice_nodes[$node['id']] = array('type' => 'question', 'options' => $data['options']);
            } elseif ($node_type === 'branch' && !empty($data['output_groups'])) {
                $choice_nodes[$node['id']] = array('type' => 'branch', 'options' => $data['output_groups']);
            }
        }
        if (empty($choice_nodes)) return array();

        // Build edge lookup: source_id → [sourceHandle => target_id].
        // For question nodes sourceHandle is option_id; for branch nodes it's group_id.
        $edges_from = array();
        foreach ($fd['edges'] as $edge) {
            $src    = $edge['source'] ?? '';
            $handle = $edge['sourceHandle'] ?? '';
            $tgt    = $edge['target'] ?? '';
            if ($src === '' || $tgt === '') continue;
            if (!isset($edges_from[$src])) $edges_from[$src] = array();
            $edges_from[$src][$handle] = $tgt;
        }

        // Load node_viewed events in conversation order.
        $admin_sub  = self::admin_session_subquery();
        $date_where = self::date_range_clause('created_at', $date_from, $date_to);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, event_data, created_at
             FROM {$wpdb->prefix}dialoga_analytics
             WHERE event_type = 'node_viewed' AND flow_id = %d AND conversation_id IS NOT NULL{$admin_sub}{$date_where}
             ORDER BY conversation_id ASC, created_at ASC",
            $flow_id
        ));

        // Walk pairs: whenever prev is a choice node, credit the transition
        // prev_node_id → this_node_id.
        $transitions = array();
        $prev_conv   = null;
        $prev_id     = null;
        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            $node_id = is_array($data) ? ($data['node_id'] ?? '') : '';
            if ($node_id !== '' && $row->conversation_id === $prev_conv && $prev_id !== null && isset($choice_nodes[$prev_id])) {
                if (!isset($transitions[$prev_id])) $transitions[$prev_id] = array();
                $transitions[$prev_id][$node_id] = ($transitions[$prev_id][$node_id] ?? 0) + 1;
            }
            $prev_conv = $row->conversation_id;
            $prev_id   = $node_id;
        }

        // Build the report.
        $insights = array();
        foreach ($choice_nodes as $node_id => $info) {
            $node        = $nodes_by_id[$node_id];
            // v0.40.6 — per-type label extraction. Full label (no truncation)
            // so the Branch Insights UI can show the complete question text.
            $node_label  = self::get_node_display_label($node, 0);

            $splits = array();
            $total  = 0;
            foreach ($info['options'] as $opt) {
                $opt_id    = $opt['id']    ?? '';
                $opt_label = $opt['label'] ?? __('Unnamed option', 'dialoga');
                $target_id = $edges_from[$node_id][$opt_id] ?? null;
                $count     = ($target_id && isset($transitions[$node_id][$target_id]))
                    ? (int) $transitions[$node_id][$target_id]
                    : 0;
                $splits[] = array(
                    'label'          => $opt_label,
                    'target_node_id' => $target_id,
                    'count'          => $count,
                );
                $total += $count;
            }

            if ($total === 0) continue; // skip untraveled branches — no signal

            foreach ($splits as &$s) {
                $s['percent'] = $total > 0 ? round($s['count'] / $total * 100, 1) : 0;
            }
            unset($s);
            usort($splits, function($a, $b) { return $b['count'] - $a['count']; });

            $insights[] = array(
                'node_id'        => $node_id,
                'node_label'     => $node_label,
                'node_type'      => $info['type'],
                'total_visitors' => $total,
                'splits'         => $splits,
            );
        }

        // Sort by total_visitors desc so the busiest branch shows first.
        usort($insights, function($a, $b) { return $b['total_visitors'] - $a['total_visitors']; });
        return $insights;
    }

    /**
     * v0.40.5 — Most common complete conversation paths for a flow. Returns
     * the top N distinct sequences of node labels, ordered by frequency.
     * Paired with Branch Insights on the Path Flow tab to answer:
     *   "What's the most common journey visitors take?"
     *
     * Consecutive duplicate node views are collapsed (a node re-render
     * counts as one step). Returns [{path: 'Start → X → Y', nodes: [...], count}]
     */
    public static function get_common_paths($flow_id, $limit = 5, $date_from = null, $date_to = null) {
        global $wpdb;

        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        if (!$flow) return array();
        $fd = json_decode($flow->flow_data, true);
        if (!is_array($fd) || empty($fd['nodes'])) return array();

        // v0.40.6 — use per-type label extraction so Action / Branch nodes
        // pick their meaningful field. Full labels (no truncation) so the
        // expandable UI in the Journey tab can render complete question text.
        $labels_by_id = array();
        foreach ($fd['nodes'] as $node) {
            if (empty($node['id'])) continue;
            $labels_by_id[$node['id']] = self::get_node_display_label($node, 0);
        }

        $admin_sub  = self::admin_session_subquery();
        $date_where = self::date_range_clause('created_at', $date_from, $date_to);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, event_data
             FROM {$wpdb->prefix}dialoga_analytics
             WHERE event_type = 'node_viewed' AND flow_id = %d AND conversation_id IS NOT NULL{$admin_sub}{$date_where}
             ORDER BY conversation_id ASC, created_at ASC",
            $flow_id
        ));

        $paths = array();      // signature => count
        $samples = array();    // signature => list of node labels (populated once)
        $current_conv = null;
        $current_path = array();

        $flush = function() use (&$paths, &$samples, &$current_path) {
            if (empty($current_path)) return;
            $signature = implode('|', $current_path);
            if (!isset($paths[$signature])) {
                $paths[$signature]   = 0;
                $samples[$signature] = $current_path;
            }
            $paths[$signature]++;
        };

        foreach ($rows as $row) {
            if ($row->conversation_id !== $current_conv) {
                $flush();
                $current_conv = $row->conversation_id;
                $current_path = array();
            }
            $data = json_decode($row->event_data, true);
            $nid  = is_array($data) ? ($data['node_id'] ?? '') : '';
            if ($nid === '' || !isset($labels_by_id[$nid])) continue;
            // Collapse consecutive duplicate labels (node re-renders).
            if (end($current_path) === $labels_by_id[$nid]) continue;
            $current_path[] = $labels_by_id[$nid];
        }
        $flush();

        arsort($paths);
        $paths = array_slice($paths, 0, $limit, true);

        $result = array();
        foreach ($paths as $signature => $count) {
            $result[] = array(
                'path'  => implode(' → ', $samples[$signature]),
                'nodes' => $samples[$signature],
                'count' => (int) $count,
            );
        }
        return $result;
    }

    /**
     * v0.40.5 — Normalize a raw label / message string for display in reports.
     * Strips `{{variable}}` placeholders and (optionally) truncates. Pass
     * $max_len=0 to skip truncation entirely (useful for expandable UI where
     * the full text is shown on demand).
     */
    public static function clean_node_label($raw, $max_len = 60) {
        if (!is_string($raw)) $raw = (string) $raw;
        $s = trim(preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '[answer]', $raw));
        // Collapse whitespace / newlines for one-line display.
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '') return '';
        if ($max_len > 0) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($s) > $max_len) $s = mb_substr($s, 0, $max_len - 1) . '…';
            } elseif (strlen($s) > $max_len) {
                $s = substr($s, 0, $max_len - 1) . '…';
            }
        }
        return $s;
    }

    /**
     * v0.40.6 — Given a node object from flow_data, return the best
     * human-readable label for reports. Different node types store their
     * meaningful label in different fields:
     *
     *   - Question / AI Chat  → `data.message` (question text) is what
     *                            visitors actually see; use that first.
     *   - Action              → `data.label` is set by the flow builder
     *                            with context (e.g. "🔗 Book Massage",
     *                            "Email: chris@…", "Set variable X").
     *   - Branch              → `data.label` (concatenated option list).
     *   - Loop / Webhook      → `data.label`.
     *
     * Pre-v0.40.6 all callers used the label stored in event_data at
     * capture time, which for Action nodes fell back to the raw `nodeType`
     * slug ("action") because Action nodes have no `message` field. That
     * leaked into every report — Dashboard drop-off tile, Funnel tab,
     * Sankey — and made them useless. This helper fixes it by pulling
     * live labels from flow config at report time, so:
     *   1. Labels are always current (respects flow edits)
     *   2. Correct field per node type
     *   3. One code path used everywhere for consistency
     *
     * $max_len = 0 skips truncation (for expandable / hover UI).
     */
    public static function get_node_display_label($node, $max_len = 60) {
        if (!is_array($node)) return __('Untitled node', 'dialoga');
        $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : array();
        $type = isset($data['nodeType']) ? $data['nodeType'] : '';

        // Ordered candidates per node type — first non-empty wins.
        $candidates = array();
        switch ($type) {
            case 'question':
                $candidates[] = $data['message'] ?? '';
                $candidates[] = $data['label']   ?? '';
                break;
            case 'ai_chat':
                $candidates[] = $data['config']['greeting'] ?? '';
                $candidates[] = ($data['label'] ?? '') === 'AI Chat'
                    ? __('AI Chat', 'dialoga') : ($data['label'] ?? '');
                break;
            case 'action':
                $config = isset($data['config']) && is_array($data['config']) ? $data['config'] : array();
                $label = $data['label'] ?? '';
                $canonical_label = 'Action';
                $display_label = __('Action', 'dialoga');
                switch ($data['action_type'] ?? '') {
                    case 'capture_lead':
                        $canonical_label = 'Capture Lead';
                        $display_label = __('Capture Lead', 'dialoga');
                        break;
                    case 'redirect':
                        $host = !empty($config['url']) ? wp_parse_url($config['url'], PHP_URL_HOST) : '';
                        $canonical_label = $host ? 'Redirect → ' . $host : 'Redirect (URL needed)';
                        // translators: %s: the destination host of the redirect.
                        $display_label = $host ? sprintf(__('Redirect to %s', 'dialoga'), $host) : __('Redirect (URL needed)', 'dialoga');
                        break;
                    case 'link_button':
                        $canonical_label = 'Link Button (label needed)';
                        $display_label = __('Link Button (label needed)', 'dialoga');
                        break;
                    case 'send_email':
                        $canonical_label = 'Email: ' . (!empty($config['to']) ? $config['to'] : 'Not configured');
                        // translators: %s: recipient email address of the action node.
                        $display_label = sprintf(__('Email: %s', 'dialoga'), !empty($config['to']) ? $config['to'] : __('Not configured', 'dialoga'));
                        break;
                    case 'set_variable':
                        $canonical_label = 'Set ' . (!empty($config['variable_name']) ? $config['variable_name'] : 'variable');
                        // translators: %s: name of the flow variable being set.
                        $display_label = sprintf(__('Set %s', 'dialoga'), !empty($config['variable_name']) ? $config['variable_name'] : __('variable', 'dialoga'));
                        break;
                    case 'tag_lead':
                        $tags = implode(', ', $config['tags'] ?? array());
                        $canonical_label = 'Tag: ' . ($tags !== '' ? $tags : 'No tags');
                        // translators: %s: comma-separated list of lead tags.
                        $display_label = sprintf(__('Tag: %s', 'dialoga'), $tags !== '' ? $tags : __('No tags', 'dialoga'));
                        break;
                }
                // Translate only the builder's generated label, never authored text.
                $candidates[] = $label === $canonical_label ? $display_label : $label;
                // Fallback to config-specific labels when the generic label
                // hasn't been computed yet (rare — flow builder normally sets it).
                $candidates[] = $data['config']['button_label'] ?? '';
                $candidates[] = $data['config']['to']            ?? '';
                $candidates[] = $data['config']['variable_name'] ?? '';
                break;
            case 'branch':
                $candidates[] = ($data['label'] ?? '') === 'Branch Logic'
                    ? __('Branch Logic', 'dialoga') : ($data['label'] ?? '');
                $candidates[] = $data['message'] ?? '';
                break;
            case 'webhook':
                $candidates[] = ($data['label'] ?? '') === '⚡ Webhook'
                    ? '⚡ ' . __('Webhook', 'dialoga') : ($data['label'] ?? '');
                $candidates[] = $data['message'] ?? '';
                break;
            case 'loop':
            case 'message':
                $candidates[] = $data['label']   ?? '';
                $candidates[] = $data['message'] ?? '';
                break;
            default:
                $candidates[] = $data['label']   ?? '';
                $candidates[] = $data['message'] ?? '';
        }

        foreach ($candidates as $cand) {
            $clean = self::clean_node_label($cand, $max_len);
            if ($clean !== '') return $clean;
        }

        // Last resort — surface node type as a labeled fallback so admins
        // can at least tell what KIND of node this is.
        $type_pretty_map = array(
            'question' => __('Question', 'dialoga'),
            'ai_chat'  => __('AI Chat', 'dialoga'),
            'action'   => __('Action', 'dialoga'),
            'branch'   => __('Branch', 'dialoga'),
            'loop'     => __('Loop', 'dialoga'),
            'webhook'  => __('Webhook', 'dialoga'),
            'message'  => __('Message', 'dialoga'),
        );
        return $type_pretty_map[$type] ?? __('Untitled node', 'dialoga');
    }

    /**
     * v0.40.15 — Return per-visible-node choice insights: for every
     * Question that has options AND feeds a downstream Branch, produce a
     * split of how many visitors chose each option.
     *
     * Different from get_branch_insights() (which keys by the routing
     * Branch node) — this one keys by the VISIBLE Question node so the
     * Funnel template can attach the split inline underneath the
     * question row. Branch nodes are hidden from the funnel view
     * (v0.40.14), so keying insights on them is useless — the question
     * that PROMPTED the choice is what admins see and think about.
     *
     * Returns [node_id => { total, splits: [{label, count, percent}] }].
     * Only Questions with recorded transitions are included.
     */
    public static function get_choice_insights_by_question($flow_id, $date_from = null, $date_to = null) {
        $nodes_by_id = self::get_flow_nodes_by_id($flow_id);
        if (empty($nodes_by_id)) return array();

        global $wpdb;
        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        if (!$flow) return array();
        $fd = json_decode($flow->flow_data, true);
        if (!is_array($fd)) return array();

        // Build edge indexes.
        $edges_out           = array(); // src => [tgt, ...]
        $edges_by_src_handle = array(); // src => [handle => tgt]
        if (!empty($fd['edges'])) {
            foreach ($fd['edges'] as $edge) {
                $src = $edge['source'] ?? '';
                $tgt = $edge['target'] ?? '';
                if ($src === '' || $tgt === '') continue;
                if (!isset($edges_out[$src])) $edges_out[$src] = array();
                $edges_out[$src][] = $tgt;
                $handle = $edge['sourceHandle'] ?? '';
                if (!isset($edges_by_src_handle[$src])) $edges_by_src_handle[$src] = array();
                $edges_by_src_handle[$src][$handle] = $tgt;
            }
        }

        // Walk node_viewed events and count source→target transitions.
        // Needed because the Branch is a silent router — we can't count
        // its outputs directly, we have to observe which downstream node
        // each visitor arrived at next.
        $admin_sub  = self::admin_session_subquery();
        $date_where = self::date_range_clause('created_at', $date_from, $date_to);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, event_data
             FROM {$wpdb->prefix}dialoga_analytics
             WHERE event_type = 'node_viewed' AND flow_id = %d AND conversation_id IS NOT NULL{$admin_sub}{$date_where}
             ORDER BY conversation_id ASC, created_at ASC",
            $flow_id
        ));
        $transitions = array();
        $prev_conv = null;
        $prev_id   = null;
        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            $nid  = is_array($data) ? ($data['node_id'] ?? '') : '';
            if ($nid !== '' && $row->conversation_id === $prev_conv && $prev_id !== null) {
                if (!isset($transitions[$prev_id])) $transitions[$prev_id] = array();
                $transitions[$prev_id][$nid] = ($transitions[$prev_id][$nid] ?? 0) + 1;
            }
            $prev_conv = $row->conversation_id;
            $prev_id   = $nid;
        }

        // For each Question node with options + downstream Branch, build the split.
        $insights = array();
        foreach ($nodes_by_id as $node_id => $node) {
            $data = $node['data'] ?? array();
            $type = $data['nodeType'] ?? '';
            if ($type !== 'question' || empty($data['options'])) continue;

            // Find the immediate downstream node. Questions typically have
            // a single output edge; if it doesn't lead to a Branch, skip.
            $downstream_ids = $edges_out[$node_id] ?? array();
            if (empty($downstream_ids)) continue;
            $downstream_id = $downstream_ids[0];
            $downstream    = $nodes_by_id[$downstream_id] ?? null;
            if (!$downstream) continue;
            if (($downstream['data']['nodeType'] ?? '') !== 'branch') continue;

            // Map each Question option → the branch output_group whose
            // first condition value matches the option label. Then look up
            // the target node for that group via the branch's edge map,
            // and count how many transitions from the branch landed there.
            $output_groups = $downstream['data']['output_groups'] ?? array();
            $option_to_target = array();
            foreach ($output_groups as $group) {
                $gid = $group['id'] ?? '';
                if ($gid === '') continue;
                $val = '';
                if (!empty($group['conditions']) && is_array($group['conditions'])) {
                    $val = trim($group['conditions'][0]['value'] ?? '');
                }
                if ($val === '') continue;
                $target = $edges_by_src_handle[$downstream_id][$gid] ?? null;
                if ($target) $option_to_target[$val] = $target;
            }

            // v0.40.16 — count transitions FROM the Question, not from the
            // Branch. Branch nodes are silent (don't fire node_viewed), so
            // the observed sequence is Question → next-visible-node,
            // skipping the Branch in between. Looking up transitions on
            // the Branch's ID always returned 0.
            $splits = array();
            $total  = 0;
            foreach ($data['options'] as $opt) {
                $lbl = trim($opt['label'] ?? '');
                if ($lbl === '') continue;
                $target = $option_to_target[$lbl] ?? null;
                $count  = ($target && isset($transitions[$node_id][$target]))
                    ? (int) $transitions[$node_id][$target]
                    : 0;
                $splits[] = array('label' => $lbl, 'count' => $count);
                $total += $count;
            }
            if ($total === 0) continue;

            foreach ($splits as &$s) {
                $s['percent'] = $total > 0 ? round($s['count'] / $total * 100, 1) : 0;
            }
            unset($s);
            usort($splits, function($a, $b) { return $b['count'] - $a['count']; });

            $insights[$node_id] = array(
                'total'  => $total,
                'splits' => $splits,
            );
        }

        return $insights;
    }

    /**
     * v0.40.11 — Detect whether a flow has a "major branch" (a decision
     * point where two or more downstream subtrees NEVER re-converge). Used
     * by the Funnel report to decide whether to render one funnel or a
     * shared entry + per-branch side-by-side funnels.
     *
     * Definition per Chris:
     *   1. Walk BFS from the start node
     *   2. First `Branch` node encountered is the candidate split
     *   3. For each of its outputs, collect the full downstream reachable
     *      set of node ids
     *   4. If any two output sets share ANY node → branches merge → NOT a
     *      major branch. Return null (single-funnel fallback).
     *   5. If all output sets are pairwise disjoint → major branch confirmed.
     *
     * The branch label prefers the value of the routing condition (e.g.
     * "Wellness") over the auto-generated "Output 1". Falls back to the
     * target node's display label if no condition is set.
     *
     * Returns:
     *   null when there's no branch, or branches converge.
     *   {
     *     shared_node_ids: [ids...],       // nodes before the branch (incl. start + question-before-branch)
     *     branch_node_id:  'node_2',       // the branch node itself
     *     branches: [
     *       { label: 'Wellness', target_node_id: 'node_6', node_ids: [...] },
     *       { label: 'Therapy',  target_node_id: 'node_3', node_ids: [...] },
     *     ]
     *   }
     */
    public static function get_funnel_branches($flow_id) {
        $nodes_by_id = self::get_flow_nodes_by_id($flow_id);
        if (empty($nodes_by_id)) return null;

        global $wpdb;
        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        if (!$flow) return null;
        $fd = json_decode($flow->flow_data, true);
        if (!is_array($fd)) return null;

        // Build edges_out map: source_id => [target_id, ...]. Preserve
        // edge order via numeric index so BFS is deterministic across runs.
        $edges_out = array();
        $edges_out_full = array(); // source_id => [ ['handle' => ..., 'target' => ...] ]
        if (!empty($fd['edges'])) {
            foreach ($fd['edges'] as $edge) {
                $src = $edge['source'] ?? '';
                $tgt = $edge['target'] ?? '';
                if ($src === '' || $tgt === '') continue;
                if (!isset($edges_out[$src])) $edges_out[$src] = array();
                $edges_out[$src][] = $tgt;
                if (!isset($edges_out_full[$src])) $edges_out_full[$src] = array();
                $edges_out_full[$src][] = array(
                    'handle' => $edge['sourceHandle'] ?? '',
                    'target' => $tgt,
                );
            }
        }

        // Find start node
        $start_id = '';
        foreach ($nodes_by_id as $nid => $n) {
            if (!empty($n['data']['is_start'])) { $start_id = $nid; break; }
        }
        if (!$start_id) $start_id = array_key_first($nodes_by_id);
        if (!$start_id) return null;

        // BFS from start until we hit the first Branch node with 2+ outputs.
        // Everything traversed BEFORE that point is "shared upstream".
        $shared_node_ids = array();
        $branch_id       = null;
        $queue           = array($start_id);
        $visited         = array($start_id => true);

        while (!empty($queue)) {
            $nid = array_shift($queue);
            $node = $nodes_by_id[$nid] ?? null;
            if (!$node) continue;
            $node_type = $node['data']['nodeType'] ?? '';
            $shared_node_ids[] = $nid;

            $children = $edges_out[$nid] ?? array();
            // Branch node with 2+ distinct downstream targets = candidate split.
            if ($node_type === 'branch' && count(array_unique($children)) >= 2) {
                $branch_id = $nid;
                break;
            }

            foreach ($children as $child) {
                if (isset($visited[$child])) continue;
                if (!isset($nodes_by_id[$child])) continue;
                $visited[$child] = true;
                $queue[] = $child;
            }
        }

        if (!$branch_id) return null;

        // For each branch output, BFS downstream. Stop at the branch node
        // itself (never traverse back through it) and skip cycles.
        $branch_output_edges = $edges_out_full[$branch_id] ?? array();
        if (count($branch_output_edges) < 2) return null;

        // Unique output targets keyed by target id (preserving first-seen order).
        $unique_targets = array();
        foreach ($branch_output_edges as $e) {
            if (!isset($unique_targets[$e['target']])) $unique_targets[$e['target']] = $e;
        }
        if (count($unique_targets) < 2) return null;

        $reachable_per_output = array();
        foreach ($unique_targets as $target_id => $edge_info) {
            $reachable_per_output[$target_id] = self::_bfs_reachable_downstream(
                $target_id, $edges_out, $nodes_by_id, array($branch_id => true)
            );
        }

        // Pairwise disjointness check — any shared node = merges = not a major branch.
        $target_ids = array_keys($reachable_per_output);
        for ($i = 0; $i < count($target_ids); $i++) {
            for ($j = $i + 1; $j < count($target_ids); $j++) {
                $a = $reachable_per_output[$target_ids[$i]];
                $b = $reachable_per_output[$target_ids[$j]];
                foreach ($a as $node_id => $_) {
                    if (isset($b[$node_id])) return null; // merges — fallback to single funnel
                }
            }
        }

        // Build per-branch labels. Prefer the routing condition value
        // ("Wellness") from the branch node's output_groups over the
        // auto-generated group name ("Output 1"). Fall back to the target
        // node's display label if we can't find a condition value.
        $branch_node = $nodes_by_id[$branch_id];
        $output_groups = $branch_node['data']['output_groups'] ?? array();
        $group_labels_by_id = array();
        foreach ($output_groups as $group) {
            $gid = $group['id'] ?? '';
            if ($gid === '') continue;
            // Prefer the first condition's `value` — that's what the visitor
            // actually clicked ("Wellness", "Yes", etc.).
            $val = '';
            if (!empty($group['conditions']) && is_array($group['conditions'])) {
                $val = trim($group['conditions'][0]['value'] ?? '');
            }
            if ($val === '') $val = trim($group['label'] ?? '');
            if ($val !== '') $group_labels_by_id[$gid] = $val;
        }

        $branches = array();
        foreach ($unique_targets as $target_id => $edge_info) {
            $handle = $edge_info['handle'] ?? '';
            $label  = $group_labels_by_id[$handle] ?? '';
            if ($label === '') {
                // Fallback: use the target node's display label.
                $tgt_node = $nodes_by_id[$target_id] ?? null;
                // translators: %d: branch number in the report.
                $label = $tgt_node ? self::get_node_display_label($tgt_node) : sprintf(__('Branch %d', 'dialoga'), count($branches) + 1);
            }
            $branches[] = array(
                'label'          => $label,
                'target_node_id' => $target_id,
                'node_ids'       => array_keys($reachable_per_output[$target_id]),
            );
        }

        return array(
            'shared_node_ids' => $shared_node_ids,
            'branch_node_id'  => $branch_id,
            'branches'        => $branches,
        );
    }

    /**
     * Private-ish BFS helper for get_funnel_branches. Returns array of
     * reachable node_ids keyed by id (for O(1) membership tests). Respects
     * a stop-set so we don't traverse back through the parent branch node.
     */
    private static function _bfs_reachable_downstream($start_id, $edges_out, $nodes_by_id, $stop_at = array()) {
        $reachable = array();
        $queue     = array($start_id);
        $visited   = array($start_id => true);
        while (!empty($queue)) {
            $nid = array_shift($queue);
            if (isset($stop_at[$nid])) continue;
            if (!isset($nodes_by_id[$nid])) continue;
            $reachable[$nid] = true;
            $children = $edges_out[$nid] ?? array();
            foreach ($children as $child) {
                if (isset($visited[$child])) continue;
                if (isset($stop_at[$child])) continue;
                $visited[$child] = true;
                $queue[] = $child;
            }
        }
        return $reachable;
    }

    /**
     * v0.40.11 — Given a set of node_ids to include, return funnel rows
     * (same shape as get_funnel_ordered) restricted to just those nodes.
     * Used by the branch-split Funnel renderer to build per-branch funnels
     * from a shared analytics-query result.
     *
     * Ordering: BFS from the first node in the set, preserving the same
     * "flow-graph distance from start" logic as get_funnel_ordered.
     */
    public static function get_funnel_ordered_subset($flow_id, $node_id_set, $start_from = '', $date_from = null, $date_to = null) {
        if (empty($node_id_set)) return array();

        $nodes_by_id = self::get_flow_nodes_by_id($flow_id);
        if (empty($nodes_by_id)) return array();

        // Load edges for BFS ordering.
        global $wpdb;
        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        $edges_out = array();
        if ($flow) {
            $fd = json_decode($flow->flow_data, true);
            if (is_array($fd) && !empty($fd['edges'])) {
                foreach ($fd['edges'] as $edge) {
                    $src = $edge['source'] ?? ''; $tgt = $edge['target'] ?? '';
                    if ($src === '' || $tgt === '') continue;
                    $edges_out[$src][] = $tgt;
                }
            }
        }

        // Aggregate views + capture historical labels (v0.40.17).
        $admin_sub  = self::admin_session_subquery();
        $date_where = self::date_range_clause('created_at', $date_from, $date_to);
        $view_counts     = array();
        $historical_lbls = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data
             FROM {$wpdb->prefix}dialoga_analytics
             WHERE event_type = 'node_viewed' AND flow_id = %d{$admin_sub}{$date_where}
             ORDER BY created_at ASC",
            $flow_id
        ));
        foreach ($rows as $row) {
            $d = json_decode($row->event_data, true);
            if (!is_array($d) || empty($d['node_id'])) continue;
            $nid = $d['node_id'];
            $view_counts[$nid] = ($view_counts[$nid] ?? 0) + 1;
            $lbl = trim($d['node_label'] ?? '');
            $type_str = $d['node_type'] ?? '';
            if ($lbl !== '' && $lbl !== $type_str) $historical_lbls[$nid] = $lbl;
        }

        // BFS from $start_from (or the first node in $node_id_set) but only
        // traverse nodes that are IN the set — gives per-branch flow order.
        $set = array_flip($node_id_set);
        $start = $start_from && isset($set[$start_from]) ? $start_from : reset($node_id_set);

        $order = array();
        $bfs_index = 0;
        $queue = array($start);
        $visited = array($start => true);
        while (!empty($queue)) {
            $nid = array_shift($queue);
            $order[$nid] = $bfs_index++;
            foreach (($edges_out[$nid] ?? array()) as $child) {
                if (isset($visited[$child])) continue;
                if (!isset($set[$child])) continue; // stay within the branch subset
                $visited[$child] = true;
                $queue[] = $child;
            }
        }

        // v0.40.14 — skip nodes with 0 views AND server-side "silent" node
        // types that never fire node_viewed events. These are invisible
        // to visitors (Branch = pure routing, Loop = jump target, Webhook /
        // Set Variable / Tag Lead = server-side side effects) so counting
        // them as funnel steps produces the "100% drop → then jumps back
        // up" nonsense Chris caught. Filtering by views=0 alone almost
        // does it, but Branch nodes CAN accidentally have views (from an
        // old tracking bug) so the type filter is the safety net.
        $silent_types = array('branch', 'loop', 'webhook', 'set_variable', 'tag_lead');

        // Any set nodes not reachable by BFS from $start (orphans in the subset)
        // appended after, by view count desc.
        $conversion_node_ids = array_flip(self::get_conversion_link_node_ids());
        $reachable = array();
        $unreachable = array();
        foreach ($node_id_set as $nid) {
            if (!isset($nodes_by_id[$nid])) continue;
            $node_type = $nodes_by_id[$nid]['data']['nodeType'] ?? '';
            if (in_array($node_type, $silent_types, true)) continue;
            $views = $view_counts[$nid] ?? 0;
            if ($views === 0) continue; // not visited — hide from funnel
            $entry = array(
                'node_id'       => $nid,
                'node_type'     => $node_type,
                // v0.40.17 — historical label preferred over live config.
                'node_label'    => $historical_lbls[$nid] ?? self::get_node_display_label($nodes_by_id[$nid]),
                'views'         => (int) $views,
                'is_conversion' => isset($conversion_node_ids[$nid]),
            );
            if (isset($order[$nid])) {
                $entry['_ord'] = $order[$nid];
                $reachable[] = $entry;
            } else {
                $unreachable[] = $entry;
            }
        }
        usort($reachable,   function($a, $b) { return $a['_ord'] - $b['_ord']; });
        usort($unreachable, function($a, $b) { return $b['views'] - $a['views']; });
        $out = array();
        foreach ($reachable as $e) { unset($e['_ord']); $out[] = $e; }
        foreach ($unreachable as $e) { $out[] = $e; }
        return $out;
    }

    /**
     * v0.40.6 — Load a flow's nodes indexed by id. Cached per-request so
     * multiple reports on the same page (e.g. Dashboard tile + Analytics
     * tile) don't re-parse the JSON.
     */
    public static function get_flow_nodes_by_id($flow_id) {
        static $cache = array();
        $flow_id = (int) $flow_id;
        if (isset($cache[$flow_id])) return $cache[$flow_id];

        global $wpdb;
        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        $indexed = array();
        if ($flow) {
            $fd = json_decode($flow->flow_data, true);
            if (is_array($fd) && !empty($fd['nodes'])) {
                foreach ($fd['nodes'] as $n) {
                    if (!empty($n['id'])) $indexed[$n['id']] = $n;
                }
            }
        }
        return $cache[$flow_id] = $indexed;
    }

    /**
     * Top drop-off node — the node with the fewest views relative to the start.
     * Returns the node_label and drop count, or null if no data.
     */
    public static function get_top_drop_off($flow_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub = self::admin_session_subquery();

        // Get total conversations for this flow
        $started = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_type = 'conversation_started' AND flow_id = %d{$admin_sub}",
            $flow_id
        ));

        if (!$started) return null;

        // Get the least-viewed node (excluding the very first node since everyone sees it)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data, COUNT(*) as views
             FROM $table
             WHERE event_type = 'node_viewed' AND flow_id = %d{$admin_sub}
             GROUP BY event_data
             ORDER BY views ASC
             LIMIT 1",
            $flow_id
        ));

        if (empty($rows)) return null;

        $data  = json_decode($rows[0]->event_data, true);
        $views = (int) $rows[0]->views;

        return array(
            'node_label'  => isset($data['node_label']) ? $data['node_label'] : __('Unknown node', 'dialoga'),
            'node_type'   => isset($data['node_type'])  ? $data['node_type']  : '',
            'views'       => $views,
            'drop_off_pct'=> $started > 0 ? round((1 - $views / $started) * 100, 1) : 0,
        );
    }

    /**
     * Top pages that start conversations (lead source).
     */
    public static function get_top_sources($limit = 5) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_conversations';
        $admin_clause = self::admin_session_clause();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, COUNT(*) as count
             FROM $table
             WHERE page_url IS NOT NULL AND page_url != ''{$admin_clause}
             GROUP BY page_url
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get popular paths (legacy — kept for compatibility)
     */
    public static function get_popular_paths($limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub = self::admin_session_subquery();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT event_data, COUNT(*) as count
             FROM $table
             WHERE event_type = 'path_taken'{$admin_sub}
             GROUP BY event_data
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Per-node funnel ordered by flow-graph BFS distance from the start node.
     *
     * v0.40.7 — replaces the previous first-seen-timestamp ordering which
     * broke whenever a node started tracking later than others (e.g., the
     * start node itself, which pre-v0.40.7 never fired a node_viewed event).
     * Symptom: even after we started firing node_viewed for the start node,
     * old conversations still made deep nodes' first_seen timestamps
     * earlier than the start node's, so the start node landed way down the
     * funnel instead of at position 1.
     *
     * BFS from start gives the natural "how far into the flow is this node"
     * ordering that admins expect. Nodes not reachable from start (orphans,
     * legacy nodes) go at the end sorted by view count desc.
     *
     * View counts are aggregated by node_id (not raw event_data JSON) so
     * message edits between visits don't split counts across rows. Labels
     * come from live flow config via get_node_display_label().
     */
    public static function get_funnel_ordered($flow_id, $date_from = null, $date_to = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub  = self::admin_session_subquery();
        $date_where = self::date_range_clause('created_at', $date_from, $date_to);

        $nodes_by_id = self::get_flow_nodes_by_id($flow_id);
        if (empty($nodes_by_id)) return array();

        // Aggregate views + historical labels (v0.40.17 — preserves past
        // wording so editing a Question doesn't rewrite past reports).
        // ORDER BY ASC so the last-written label per node wins = most recent.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data
             FROM $table
             WHERE event_type = 'node_viewed' AND flow_id = %d{$admin_sub}{$date_where}
             ORDER BY created_at ASC",
            $flow_id
        ));
        $view_counts     = array();
        $historical_lbls = array();
        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            if (!is_array($data) || empty($data['node_id'])) continue;
            $nid = $data['node_id'];
            $view_counts[$nid] = ($view_counts[$nid] ?? 0) + 1;
            $lbl = trim($data['node_label'] ?? '');
            $type_str = $data['node_type'] ?? '';
            if ($lbl !== '' && $lbl !== $type_str) $historical_lbls[$nid] = $lbl;
        }

        // Load flow edges to build the graph.
        $flow = $wpdb->get_row($wpdb->prepare(
            "SELECT flow_data FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
            $flow_id
        ));
        $edges_out = array(); // source_id => [target_id, ...]
        $start_id  = '';
        if ($flow) {
            $fd = json_decode($flow->flow_data, true);
            if (is_array($fd)) {
                if (!empty($fd['edges'])) {
                    foreach ($fd['edges'] as $edge) {
                        $src = $edge['source'] ?? '';
                        $tgt = $edge['target'] ?? '';
                        if ($src === '' || $tgt === '') continue;
                        if (!isset($edges_out[$src])) $edges_out[$src] = array();
                        $edges_out[$src][] = $tgt;
                    }
                }
                if (!empty($fd['nodes'])) {
                    foreach ($fd['nodes'] as $n) {
                        if (!empty($n['data']['is_start'])) {
                            $start_id = $n['id'];
                            break;
                        }
                    }
                    if (!$start_id && !empty($fd['nodes'][0]['id'])) {
                        $start_id = $fd['nodes'][0]['id'];
                    }
                }
            }
        }

        // BFS from start; each node gets an order index. Nodes reachable
        // sooner come first. Ties broken by depth-then-declaration-order.
        $order       = array();  // node_id => BFS index
        $bfs_index   = 0;
        if ($start_id !== '' && isset($nodes_by_id[$start_id])) {
            $queue   = array($start_id);
            $visited = array($start_id => true);
            while (!empty($queue)) {
                $nid = array_shift($queue);
                $order[$nid] = $bfs_index++;
                $children = $edges_out[$nid] ?? array();
                // Deterministic child order — sort so subsequent runs match.
                sort($children);
                foreach ($children as $child) {
                    if (isset($visited[$child])) continue;
                    if (!isset($nodes_by_id[$child])) continue;
                    $visited[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        // v0.40.10 — precompute the set of nodes marked as conversions
        // (currently: Link Button nodes with `is_conversion=true`). Views
        // on these nodes represent SUCCESSFUL conversions, not drop-offs
        // — the visitor did the thing the flow was designed to make them
        // do, then left. The funnel template uses this flag to render
        // them as green/success rather than as red/drop-off severity.
        $conversion_node_ids = array_flip(self::get_conversion_link_node_ids());

        // v0.40.14 — filter out silent/routing node types + zero-view nodes.
        // Same rationale as get_funnel_ordered_subset(): Branch / Loop /
        // Webhook / Set Variable / Tag Lead don't fire node_viewed events
        // and aren't user-visible steps. Showing them as funnel rows with
        // 0 views + "100% drop" is confusing noise.
        $silent_types = array('branch', 'loop', 'webhook', 'set_variable', 'tag_lead');

        // Build funnel entries only for nodes that BOTH exist in the flow
        // AND have been viewed at least once. Unreachable-from-start nodes
        // (orphans / removed edges) still show up, appended after the
        // reachable ones, sorted by view count desc.
        $reachable   = array();
        $unreachable = array();
        foreach ($view_counts as $nid => $views) {
            if (!isset($nodes_by_id[$nid])) continue; // deleted from flow
            $node  = $nodes_by_id[$nid];
            $node_type = $node['data']['nodeType'] ?? '';
            if (in_array($node_type, $silent_types, true)) continue;
            if ((int) $views === 0) continue;
            $entry = array(
                'node_id'         => $nid,
                'node_type'       => $node_type,
                // v0.40.17 — historical label preferred over live config.
                'node_label'      => $historical_lbls[$nid] ?? self::get_node_display_label($node),
                'views'           => (int) $views,
                'is_conversion'   => isset($conversion_node_ids[$nid]),
            );
            if (isset($order[$nid])) {
                $entry['_ord'] = $order[$nid];
                $reachable[] = $entry;
            } else {
                $unreachable[] = $entry;
            }
        }

        usort($reachable,   function($a, $b) { return $a['_ord'] - $b['_ord']; });
        usort($unreachable, function($a, $b) { return $b['views'] - $a['views']; });

        $funnel = array();
        foreach ($reachable as $e) {
            unset($e['_ord']);
            $funnel[] = $e;
        }
        foreach ($unreachable as $e) {
            $funnel[] = $e;
        }
        return $funnel;
    }

    /**
     * Average time in seconds from conversation start to lead capture.
     */
    public static function get_avg_time_to_lead($flow_id = 0, $date_from = null, $date_to = null) {
        global $wpdb;
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';

        $flow_clause  = $flow_id ? $wpdb->prepare(' AND c.flow_id = %d', $flow_id) : '';
        $admin_clause = self::admin_session_clause('c');
        // v0.40.18 — scope by conversation start date so avg-time-to-lead
        // reflects only conversations that started in the range.
        $date_clause  = self::date_range_clause('c.created_at', $date_from, $date_to);

        $seconds = $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, c.created_at, a.created_at))
             FROM {$conv_table} c
             INNER JOIN {$analytics_table} a ON a.conversation_id = c.id
             WHERE a.event_type = 'lead_captured'{$flow_clause}{$admin_clause}{$date_clause}"
        );

        return $seconds !== null ? (int) round(floatval($seconds)) : null;
    }

    /**
     * Per-flow stats for all flows — used for A/B comparison.
     *
     * v0.40.4 — extended with `link_conversions` (distinct conversations that
     * clicked a marked Link Button on this flow) and `conversions` (union of
     * leads + link conversions). `rate` continues to represent the union
     * conversion rate — matches the Dashboard's Conversion Rate tile, so the
     * A/B "winner" call now reflects true funnel outcome rather than only
     * form submissions. Legacy `leads` column kept so existing UI can still
     * display leads-specifically.
     */
    public static function get_flow_comparison($date_from = null, $date_to = null) {
        global $wpdb;
        $flows_table     = $wpdb->prefix . 'dialoga_flows';
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';

        $flows  = $wpdb->get_results("SELECT id, flow_name, is_active FROM {$flows_table} ORDER BY created_at DESC");
        $result = array();
        $admin_clause = self::admin_session_clause();
        $admin_sub    = self::admin_session_subquery();
        // v0.40.18 — date range filter applies to conversation creation +
        // the analytics events we count. Both aliased for JOINed variants.
        $date_conv     = self::date_range_clause('created_at', $date_from, $date_to);
        $date_conv_c   = self::date_range_clause('c.created_at', $date_from, $date_to);
        $date_analytic = self::date_range_clause('created_at', $date_from, $date_to);

        // v0.40.4 — precompute once per call.
        $conversion_node_ids = self::get_conversion_link_node_ids();
        $node_id_expr        = "JSON_UNQUOTE(JSON_EXTRACT(a.event_data, '$.node_id'))";

        foreach ($flows as $flow) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$conv_table} WHERE flow_id = %d{$admin_clause}{$date_conv}", $flow->id
            ));
            $leads = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$analytics_table} WHERE event_type = 'lead_captured' AND flow_id = %d{$admin_sub}{$date_analytic}", $flow->id
            ));
            $avg_time = self::get_avg_time_to_lead($flow->id, $date_from, $date_to);

            $link_conversions = 0;
            $conversions      = $leads;
            if (!empty($conversion_node_ids)) {
                $placeholders = implode(',', array_fill(0, count($conversion_node_ids), '%s'));

                $link_conversions = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT a.conversation_id)
                         FROM {$analytics_table} a
                         INNER JOIN {$conv_table} c ON c.id = a.conversation_id
                         WHERE c.flow_id = %d
                           AND a.event_type = 'link_clicked'
                           AND {$node_id_expr} IN ({$placeholders})
                           " . self::admin_session_clause('c') . $date_conv_c,
                        array_merge(array($flow->id), $conversion_node_ids)
                    )
                );

                $conversions = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT c.id)
                         FROM {$conv_table} c
                         LEFT JOIN {$analytics_table} a
                             ON a.conversation_id = c.id
                            AND a.event_type = 'link_clicked'
                            AND {$node_id_expr} IN ({$placeholders})
                         WHERE c.flow_id = %d
                           " . self::admin_session_clause('c') . $date_conv_c . "
                           AND (
                             (c.lead_data IS NOT NULL AND c.lead_data != '{}')
                             OR a.id IS NOT NULL
                           )",
                        array_merge($conversion_node_ids, array($flow->id))
                    )
                );
            }

            $result[] = array(
                'flow_id'          => (int) $flow->id,
                'flow_name'        => $flow->flow_name,
                'is_active'        => (bool) $flow->is_active,
                'total'            => $total,
                'leads'            => $leads,
                'link_conversions' => $link_conversions,
                'conversions'      => $conversions,
                'lead_rate'        => $total > 0 ? round($leads / $total * 100, 1)       : 0,
                'rate'             => $total > 0 ? round($conversions / $total * 100, 1) : 0,
                'avg_time'         => $avg_time,
            );
        }

        return $result;
    }

    /**
     * Get paginated session list for transcripts view.
     *
     * v0.40.5 — added optional `$page_url` filter. Allows the Free Analytics
     * page "Top Pages Starting Conversations" table to link out to a
     * Conversations view scoped to a single landing page.
     */
    public static function get_sessions($limit = 25, $offset = 0, $flow_id = 0, $page_url = '', $date_from = null, $date_to = null) {
        global $wpdb;
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';
        $flows_table     = $wpdb->prefix . 'dialoga_flows';
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';

        $where = $flow_id ? $wpdb->prepare('WHERE c.flow_id = %d', $flow_id) : 'WHERE 1=1';
        if ($page_url !== '') {
            $where .= $wpdb->prepare(' AND c.page_url = %s', $page_url);
        }
        // v0.38.7 — hide admin-test sessions by default
        $where .= self::admin_session_clause('c');
        // v0.40.18 — optional date range on conversation creation time.
        $where .= self::date_range_clause('c.created_at', $date_from, $date_to);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.session_id, c.flow_id, c.conversation_data, c.lead_data,
                    c.page_url, c.created_at, c.updated_at, f.flow_name,
                    a.created_at AS lead_time
             FROM {$conv_table} c
             LEFT JOIN {$flows_table} f ON f.id = c.flow_id
             LEFT JOIN {$analytics_table} a ON a.conversation_id = c.id AND a.event_type = 'lead_captured'
             {$where}
             ORDER BY c.created_at DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    /**
     * Total session count for pagination.
     *
     * v0.40.5 — accepts optional page_url filter matching get_sessions().
     */
    public static function get_sessions_count($flow_id = 0, $page_url = '', $date_from = null, $date_to = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_conversations';

        $admin_clause = self::admin_session_clause();
        $date_where   = self::date_range_clause('created_at', $date_from, $date_to);

        $where = 'WHERE 1=1';
        if ($flow_id)          $where .= $wpdb->prepare(' AND flow_id = %d', $flow_id);
        if ($page_url !== '')  $where .= $wpdb->prepare(' AND page_url = %s', $page_url);

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}{$admin_clause}{$date_where}");
    }

    /**
     * v0.38.7 — Top-of-funnel metrics for the dashboard.
     *   Impressions  = bubble_shown events (real visitor bubble renders)
     *   Opens        = conversations started (visitor clicked the bubble)
     *   Leads        = conversations that captured a lead
     *
     * v0.40.4 — extended with `conversions` (leads UNION marked link clicks),
     * `link_conversions` (link-click contribution), plus a matching
     * `conversion_rate` (of opens) and `overall_conversion_rate` (of
     * impressions). Callers that want the pre-v0.40 lead-only funnel still
     * get `leads` / `lead_rate` / `overall_rate` untouched.
     */
    public static function get_funnel_metrics($date_from = null, $date_to = null) {
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';
        $conv_table      = $wpdb->prefix . 'dialoga_conversations';

        $a_where = "WHERE event_type = 'bubble_shown'";
        $c_where = 'WHERE 1=1';
        if ($date_from) {
            $a_where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
            $c_where .= $wpdb->prepare(' AND created_at >= %s', $date_from);
        }
        if ($date_to) {
            $a_where .= $wpdb->prepare(' AND created_at <= %s', $date_to . ' 23:59:59');
            $c_where .= $wpdb->prepare(' AND created_at <= %s', $date_to . ' 23:59:59');
        }
        $c_where .= self::admin_session_clause();

        $impressions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analytics_table} {$a_where}");
        $opens       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$conv_table} {$c_where}");
        $leads       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$conv_table} {$c_where} AND lead_data IS NOT NULL AND lead_data != '{}'");

        // v0.40.4 — union count using the shared current-config helper.
        $conversion_node_ids = self::get_conversion_link_node_ids();
        $link_conversions    = 0;
        $conversions         = $leads;

        if (!empty($conversion_node_ids)) {
            $c_where_aliased = 'WHERE 1=1';
            if ($date_from) $c_where_aliased .= $wpdb->prepare(' AND c.created_at >= %s', $date_from);
            if ($date_to)   $c_where_aliased .= $wpdb->prepare(' AND c.created_at <= %s', $date_to . ' 23:59:59');
            $c_where_aliased .= self::admin_session_clause('c');

            $placeholders = implode(',', array_fill(0, count($conversion_node_ids), '%s'));
            $node_id_expr = "JSON_UNQUOTE(JSON_EXTRACT(a.event_data, '$.node_id'))";

            $link_conversions = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT a.conversation_id)
                     FROM %i a
                     INNER JOIN %i c ON c.id = a.conversation_id
                     {$c_where_aliased}
                       AND a.event_type = 'link_clicked'
                       AND {$node_id_expr} IN ({$placeholders})",
                    array_merge(array($analytics_table, $conv_table), $conversion_node_ids)
                )
            );

            $conversions = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT c.id)
                     FROM %i c
                     LEFT JOIN %i a
                         ON a.conversation_id = c.id
                        AND a.event_type = 'link_clicked'
                        AND {$node_id_expr} IN ({$placeholders})
                     {$c_where_aliased}
                       AND (
                         (c.lead_data IS NOT NULL AND c.lead_data != '{}')
                         OR a.id IS NOT NULL
                       )",
                    array_merge(array($conv_table, $analytics_table), $conversion_node_ids)
                )
            );
        }

        return array(
            'impressions'             => $impressions,
            'opens'                   => $opens,
            'leads'                   => $leads,
            'link_conversions'        => $link_conversions,
            'conversions'             => $conversions,
            'open_rate'               => $impressions > 0 ? round(($opens / $impressions) * 100, 1)         : 0,
            'lead_rate'               => $opens > 0       ? round(($leads / $opens) * 100, 1)               : 0,
            'overall_rate'            => $impressions > 0 ? round(($leads / $impressions) * 100, 2)         : 0,
            'conversion_rate'         => $opens > 0       ? round(($conversions / $opens) * 100, 1)         : 0,
            'overall_conversion_rate' => $impressions > 0 ? round(($conversions / $impressions) * 100, 2)   : 0,
        );
    }

    /**
     * Returns a SQL fragment ( "" or " AND <alias>.is_admin_session = 0" )
     * to append to WHERE clauses so admin-test sessions are excluded by default.
     * Setting "include_admin_sessions" disables the filter.
     */
    /**
     * Returns true if the admin-session column exists on the current DB.
     * Cached per-request via static so we don't re-issue DESCRIBE on every call.
     */
    private static function has_admin_column() {
        static $exists = null;
        if ($exists === null) {
            global $wpdb;
            $conv_table = $wpdb->prefix . 'dialoga_conversations';
            $cols = $wpdb->get_col("DESCRIBE {$conv_table}", 0);
            $exists = in_array('is_admin_session', $cols, true);
        }
        return $exists;
    }

    /**
     * True when the admin-session filter should be applied. Two conditions:
     *  1. The setting toggle "include_admin_sessions" is OFF (default)
     *  2. The is_admin_session column actually exists on the DB
     */
    private static function should_filter_admin() {
        $settings = get_option('dialoga_settings', array());
        if (!empty($settings['include_admin_sessions'])) return false;
        return self::has_admin_column();
    }

    /**
     * v0.40.18 — Reusable date-range WHERE fragment for report queries.
     * Returns "" (no filter) when both dates are empty. When filtering,
     * $date_to gets the full end-of-day appended so a "single day" range
     * (from=to=2026-07-21) includes all events on that day.
     */
    public static function date_range_clause($column, $date_from = null, $date_to = null) {
        global $wpdb;
        $sql = '';
        if ($date_from) {
            $sql .= $wpdb->prepare(" AND {$column} >= %s", $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $sql .= $wpdb->prepare(" AND {$column} <= %s", $date_to . ' 23:59:59');
        }
        return $sql;
    }

    /**
     * WHERE fragment for queries that hit dialoga_conversations or dialoga_leads
     * directly. Optional alias for joined queries.
     *   $alias = ''  → " AND is_admin_session = 0"
     *   $alias = 'c' → " AND c.is_admin_session = 0"
     */
    private static function admin_session_clause($table_alias = '') {
        if (!self::should_filter_admin()) return '';
        $col = $table_alias ? "{$table_alias}.is_admin_session" : 'is_admin_session';
        return " AND {$col} = 0";
    }

    /**
     * WHERE fragment for queries that hit dialoga_analytics (event log). The
     * analytics table doesn't carry the flag directly — filter via subquery
     * against dialoga_conversations. Cheap because IN( ) uses the conv PK index.
     */
    private static function admin_session_subquery() {
        if (!self::should_filter_admin()) return '';
        global $wpdb;
        $conv_table = $wpdb->prefix . 'dialoga_conversations';
        return " AND conversation_id IN (SELECT id FROM {$conv_table} WHERE is_admin_session = 0)";
    }

    /**
     * Pairs of consecutive node views per conversation — used for Sankey path analysis.
     * Returns array of {from, to, count} rows ordered by count desc.
     */
    public static function get_flow_transitions($flow_id) {
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'dialoga_analytics';
        $admin_sub = self::admin_session_subquery();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT conversation_id, event_data, created_at
             FROM {$analytics_table}
             WHERE event_type = 'node_viewed' AND flow_id = %d AND conversation_id IS NOT NULL{$admin_sub}
             ORDER BY conversation_id ASC, created_at ASC",
            $flow_id
        ));

        $transitions = array();
        $prev_conv   = null;
        $prev_label  = null;

        foreach ($rows as $row) {
            $data = json_decode($row->event_data, true);
            if (!$data) { $prev_conv = $row->conversation_id; $prev_label = null; continue; }

            $label = !empty($data['node_label']) ? $data['node_label'] : ($data['node_id'] ?? 'unknown');
            $label = trim(preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '[answer]', $label));
            if (strlen($label) > 40) $label = substr($label, 0, 37) . '…';

            if ($row->conversation_id === $prev_conv && $prev_label !== null && $prev_label !== $label) {
                $key = $prev_label . '→' . $label;
                if (!isset($transitions[$key])) {
                    $transitions[$key] = array('from' => $prev_label, 'to' => $label, 'count' => 0);
                }
                $transitions[$key]['count']++;
            }
            $prev_conv  = $row->conversation_id;
            $prev_label = $label;
        }

        usort($transitions, function($a, $b) { return $b['count'] - $a['count']; });
        return array_values($transitions);
    }

    /**
     * Conversations grouped by day-of-week and hour — returns 7x24 matrix of counts.
     * Days: 0=Sunday ... 6=Saturday. Hours: 0..23 in site timezone.
     */
    public static function get_time_of_day_heatmap($flow_id = 0, $days_back = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_conversations';

        $where = $wpdb->prepare('created_at >= %s', gmdate('Y-m-d H:i:s', strtotime("-{$days_back} days")));
        if ($flow_id) $where .= $wpdb->prepare(' AND flow_id = %d', $flow_id);
        $where .= self::admin_session_clause();

        // MySQL DAYOFWEEK returns 1=Sunday..7=Saturday — we subtract 1 for 0-indexed
        $rows = $wpdb->get_results(
            "SELECT (DAYOFWEEK(created_at) - 1) AS dow, HOUR(created_at) AS hour, COUNT(*) AS cnt
             FROM {$table}
             WHERE {$where}
             GROUP BY dow, hour"
        );

        $matrix = array();
        for ($d = 0; $d < 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }
        foreach ($rows as $r) {
            $matrix[(int) $r->dow][(int) $r->hour] = (int) $r->cnt;
        }
        return $matrix;
    }
}
