<?php
/**
 * Analytics Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Preset ranges resolved server-side so reload preserves state
$preset = isset($_GET['preset']) ? sanitize_text_field(wp_unslash($_GET['preset'])) : '';
$today  = gmdate('Y-m-d');

switch ($preset) {
    case 'today':
        $date_from = $today; $date_to = $today; break;
    case '7d':
        $date_from = gmdate('Y-m-d', strtotime('-6 days')); $date_to = $today; break;
    case '30d':
        $date_from = gmdate('Y-m-d', strtotime('-29 days')); $date_to = $today; break;
    case '90d':
        $date_from = gmdate('Y-m-d', strtotime('-89 days')); $date_to = $today; break;
    case 'mtd':
        $date_from = gmdate('Y-m-01'); $date_to = $today; break;
    case 'last_month':
        $date_from = gmdate('Y-m-01', strtotime('first day of last month'));
        $date_to   = gmdate('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'ytd':
        $date_from = gmdate('Y-01-01'); $date_to = $today; break;
    case 'all':
        $date_from = '2020-01-01'; $date_to = $today; break;
    default:
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : gmdate('Y-m-d', strtotime('-29 days'));
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field(wp_unslash($_GET['date_to']))   : $today;
}

$base_url = admin_url('admin.php?page=dialogawp-analytics');
$presets = array(
    'today'      => __('Today', 'dialogawp'),
    '7d'         => __('Last 7 days', 'dialogawp'),
    '30d'        => __('Last 30 days', 'dialogawp'),
    '90d'        => __('Last 90 days', 'dialogawp'),
    'mtd'        => __('Month to date', 'dialogawp'),
    'last_month' => __('Last month', 'dialogawp'),
    'ytd'        => __('Year to date', 'dialogawp'),
    'all'        => __('All time', 'dialogawp'),
);

$conversation_metrics = DialogaWP_Analytics::get_conversation_metrics($date_from, $date_to);
$lead_metrics         = DialogaWP_Analytics::get_lead_metrics($date_from, $date_to);
$time_metrics         = DialogaWP_Analytics::get_time_metrics(30);
$top_sources          = DialogaWP_Analytics::get_top_sources(5);

// Build chart-ready data arrays (must be AFTER the fetches above)
$trend_labels = array();
$trend_values = array();
foreach ($time_metrics as $metric) {
    $trend_labels[] = wp_date('M j', strtotime($metric->date), new DateTimeZone('UTC'));
    $trend_values[] = (int) $metric->count;
}

// v0.40.0 — donut counts any conversion (lead or marked link click), matching
// the conversion_rate metric so the chart and the tile don't disagree.
$conv_converted     = isset($conversation_metrics['conversations_converted'])
    ? (int) $conversation_metrics['conversations_converted']
    : (int) $conversation_metrics['conversations_with_leads'];
$conv_not_converted = max(0, (int) $conversation_metrics['total_conversations'] - $conv_converted);

$source_labels = array();
$source_values = array();
foreach ($top_sources as $source) {
    $path = wp_parse_url($source->page_url, PHP_URL_PATH) ?: $source->page_url;
    if (strlen($path) > 40) $path = substr($path, 0, 37) . '…';
    if ($path === '' || $path === '/') $path = __('Home', 'dialogawp');
    $source_labels[] = $path;
    $source_values[] = (int) $source->count;
}

$chart_payload = array(
    'trend'      => array('labels' => $trend_labels, 'values' => $trend_values),
    'conversion' => array('converted' => $conv_converted, 'not_converted' => $conv_not_converted),
    'sources'    => array('labels' => $source_labels, 'values' => $source_values),
);

// Get the active flow for funnel data
global $wpdb;
$active_flow = $wpdb->get_row("SELECT id, flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
// v0.40.5 — switched to get_top_drop_offs (plural). Legacy get_top_drop_off
// returned the least-viewed node and used stale node_label from event_data,
// which surfaced raw internal types like "action" when the label was empty.
// The new helper pulls fresh labels from live flow config and skips the
// start node + statistical-noise floors.
$drop_offs = $active_flow ? DialogaWP_Analytics::get_top_drop_offs($active_flow->id, 1) : array();
$drop_off  = !empty($drop_offs) ? $drop_offs[0] : null;
?>

<?php
// v1.0.0 — wp.org compliance: attach page-specific CSS + chart data via
// wp_add_inline_style / wp_add_inline_script attached to already-enqueued
// handles. Reviewers require this instead of raw <style> / <script> tags.
// Screen CSS lives in assets/css/screen-analytics.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
wp_add_inline_script('dialogawp-admin', 'window.dialogawpAnalytics = ' . wp_json_encode($chart_payload) . ';', 'before');
?>

<div class="wrap dialogawp-analytics-wrap">
    <h1><?php echo esc_html__('Analytics', 'dialogawp'); ?></h1>

    <!-- Date filter -->
    <div class="dialogawp-analytics-filter">
        <!-- Preset pills -->
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
            <?php foreach ($presets as $key => $label) :
                $active = ($preset === $key);
            ?>
            <a href="<?php echo esc_url($base_url . '&preset=' . $key); ?>"
               style="padding:4px 12px;border-radius:16px;font-size:12px;font-weight:600;text-decoration:none;
                      background:<?php echo $active ? '#190F4F' : '#F7F8FC'; ?>;
                      color:<?php echo $active ? '#fff' : '#374151'; ?>;
                      border:1px solid <?php echo $active ? '#190F4F' : '#E5E7EF'; ?>;">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="">
            <input type="hidden" name="page" value="dialogawp-analytics" />
            <div style="display:flex;gap:15px;align-items:flex-end;">
                <div>
                    <label><?php echo esc_html__('From', 'dialogawp'); ?></label><br/>
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                </div>
                <div>
                    <label><?php echo esc_html__('To', 'dialogawp'); ?></label><br/>
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                </div>
                <div>
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Apply', 'dialogawp'); ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Definition note -->
    <p style="color:#64748B;font-size:13px;margin:0 0 16px;background:#F1F5F9;border-left:3px solid #00ADA1;padding:10px 14px;border-radius:4px;">
        <strong><?php echo esc_html__('How conversions are counted:', 'dialogawp'); ?></strong>
        <?php echo esc_html__('A "conversion" is a chat session where the visitor submitted the Capture Lead form.', 'dialogawp'); ?>
        <?php echo esc_html__('Conversion rate = (Leads Captured + Link Conversions) / Conversations x 100.', 'dialogawp'); ?>
        <?php echo esc_html__('Link Conversions = clicks on Link Button nodes with "Count as conversion" enabled.', 'dialogawp'); ?>
        <?php echo esc_html__('Note: "Total Leads" is an all-time count and may not match the Leads Captured number inside the selected date range.', 'dialogawp'); ?>
    </p>

    <!-- Wrapped content — overlay covers everything below the filter during reload -->
    <div class="dialogawp-analytics-content" id="dialogawp-analytics-content">
        <div class="dialogawp-analytics-loading">
            <div class="dialogawp-analytics-spinner"></div>
            <div class="dialogawp-analytics-loading-text"><?php echo esc_html__('Loading analytics...', 'dialogawp'); ?></div>
        </div>

    <!-- Stat boxes -->
    <div class="dialogawp-analytics-stats">
        <div class="dialogawp-analytics-stat dialogawp-analytics-stat-accent-1">
            <h3><?php echo esc_html__('Conversations', 'dialogawp'); ?></h3>
            <div class="dialogawp-analytics-stat-value"><?php echo number_format($conversation_metrics['total_conversations']); ?></div>
            <div class="dialogawp-analytics-stat-label"><?php echo esc_html__('Total chats started', 'dialogawp'); ?></div>
        </div>
        <div class="dialogawp-analytics-stat dialogawp-analytics-stat-accent-2">
            <h3><?php echo esc_html__('Conversions', 'dialogawp'); ?></h3>
            <div class="dialogawp-analytics-stat-value"><?php echo number_format($conversation_metrics['conversations_converted']); ?></div>
            <div class="dialogawp-analytics-stat-label">
                <?php
                $leads_n = (int) $conversation_metrics['conversations_with_leads'];
                $links_n = isset($conversation_metrics['conversations_with_link_conversions'])
                    ? (int) $conversation_metrics['conversations_with_link_conversions']
                    : 0;
                if ($links_n > 0) {
                    /* translators: %d: lead count. */
                    $leads_label = sprintf(_n('%d lead', '%d leads', $leads_n, 'dialogawp'), $leads_n);
                    /* translators: %d: link click count. */
                    $links_label = sprintf(_n('%d link click', '%d link clicks', $links_n, 'dialogawp'), $links_n);
                    /* translators: %1$s: leads count label, %2$s: link click count label. */
                    echo esc_html(sprintf(__('%1$s + %2$s', 'dialogawp'), $leads_label, $links_label));
                } else {
                    echo esc_html__('Conversations with lead data', 'dialogawp');
                }
                ?>
            </div>
        </div>
        <div class="dialogawp-analytics-stat dialogawp-analytics-stat-accent-3">
            <h3><?php echo esc_html__('Conversion Rate', 'dialogawp'); ?></h3>
            <div class="dialogawp-analytics-stat-value"><?php echo esc_html($conversation_metrics['conversion_rate']); ?>%</div>
            <div class="dialogawp-analytics-stat-label"><?php echo esc_html__('Chats that converted', 'dialogawp'); ?></div>
        </div>
        <div class="dialogawp-analytics-stat dialogawp-analytics-stat-accent-4">
            <h3><?php echo esc_html__('Total Leads', 'dialogawp'); ?></h3>
            <div class="dialogawp-analytics-stat-value"><?php echo number_format($lead_metrics['total_leads']); ?></div>
            <div class="dialogawp-analytics-stat-label"><?php echo esc_html__('All time', 'dialogawp'); ?></div>
        </div>
        <?php
        // v0.40.3 — Total Link Clicks tile. Counts EACH click on marked
        // Link Buttons (not distinct conversations), so one visitor who
        // clicked both Salt Cave and Massage contributes 2 here. Shown
        // only when link conversions are configured — for lead-only setups
        // this would always be 0 and adding a zero tile is noise.
        $total_link_clicks = isset($conversation_metrics['total_link_conversion_clicks'])
            ? (int) $conversation_metrics['total_link_conversion_clicks']
            : 0;
        $links_conv_count = isset($conversation_metrics['conversations_with_link_conversions'])
            ? (int) $conversation_metrics['conversations_with_link_conversions']
            : 0;
        if ($total_link_clicks > 0 || $links_conv_count > 0) :
        ?>
        <div class="dialogawp-analytics-stat dialogawp-analytics-stat-accent-5">
            <h3><?php echo esc_html__('Total Link Clicks', 'dialogawp'); ?></h3>
            <div class="dialogawp-analytics-stat-value"><?php echo number_format($total_link_clicks); ?></div>
            <div class="dialogawp-analytics-stat-label" title="<?php echo esc_attr__("Every click on a Link Button marked 'Count as conversion' - one visitor clicking multiple buttons contributes to each", 'dialogawp'); ?>">
                <?php
                if ($links_conv_count > 0 && $total_link_clicks > $links_conv_count) {
                    $avg = round($total_link_clicks / $links_conv_count, 1);
                    /* translators: %1$d: visitor count, %2$s: average clicks. */
                    echo esc_html(sprintf(_n('across %1$d visitor (%2$s avg)', 'across %1$d visitors (%2$s avg)', $links_conv_count, 'dialogawp'), $links_conv_count, $avg));
                } elseif ($links_conv_count > 0) {
                    /* translators: %d: visitor count. */
                    echo esc_html(sprintf(_n('across %d visitor', 'across %d visitors', $links_conv_count, 'dialogawp'), $links_conv_count));
                } else {
                    echo esc_html__('On marked conversion buttons', 'dialogawp');
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Drop-off highlight -->
    <?php if ($drop_off) :
        // v0.40.5 — human-friendly node type label mapping. Prior version
        // showed the raw internal type slug ("action", "ai_chat") which is
        // meaningless to admins.
        $type_label_map = array(
            'question' => __('Question', 'dialogawp'),
            'branch'   => __('Branch', 'dialogawp'),
            'action'   => __('Action', 'dialogawp'),
            'ai_chat'  => __('AI Chat', 'dialogawp'),
            'loop'     => __('Loop', 'dialogawp'),
            'webhook'  => __('Webhook', 'dialogawp'),
            'message'  => __('Message', 'dialogawp'),
        );
        $type_pretty = $type_label_map[$drop_off['node_type']] ?? ucfirst($drop_off['node_type']);
    ?>
    <div class="dialogawp-settings-section">
        <h2><?php echo esc_html__('Top Drop-off Point', 'dialogawp'); ?></h2>
        <p style="color:#666;margin-top:0;"><?php echo esc_html__('Where most visitors stop engaging in the active flow.', 'dialogawp'); ?></p>
        <div style="background:#fff8f0;border:1px solid #ff9800;border-radius:6px;padding:16px 20px;display:flex;align-items:center;gap:20px;">
            <div style="font-size:32px;font-weight:700;color:#f57c00;"><?php echo esc_html($drop_off['drop_off_pct']); ?>%</div>
            <div>
                <strong style="font-size:14px;"><?php echo esc_html__('of visitors drop off at:', 'dialogawp'); ?></strong><br/>
                <span style="font-size:15px;"><?php echo esc_html($drop_off['node_label']); ?></span>
                <?php if ($type_pretty) : ?>
                    <span style="background:#ff9800;color:#fff;padding:1px 7px;border-radius:4px;font-size:11px;margin-left:6px;"><?php echo esc_html($type_pretty); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Conversation trend -->
    <div class="dialogawp-chart-container">
        <h3><?php echo esc_html__('Conversations - Last 30 Days', 'dialogawp'); ?></h3>
        <?php if (!empty($time_metrics)) : ?>
        <div style="position:relative;height:280px;padding:8px 4px 0;">
            <canvas id="dialogawp-chart-trend"></canvas>
        </div>
        <?php else : ?>
        <p style="text-align:center;padding:40px;color:#666;"><?php echo esc_html__('No conversation data yet.', 'dialogawp'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Conversion split -->
    <?php if ($conversation_metrics['total_conversations'] > 0) : ?>
    <div class="dialogawp-chart-container">
        <h3><?php echo esc_html__('Conversion Split', 'dialogawp'); ?></h3>
        <div style="display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:center;">
            <div style="position:relative;height:220px;">
                <canvas id="dialogawp-chart-conversion"></canvas>
            </div>
            <div style="font-size:14px;color:#475569;line-height:1.6;">
                <p style="margin:0 0 10px;"><?php
                /* translators: %1$s: total conversations, %2$s: converted conversations, %3$s: unconverted conversations. */
                echo sprintf(esc_html__('Of %1$s conversations in this range, %2$s submitted a lead and %3$s did not.', 'dialogawp'),
                    '<strong>' . esc_html(number_format($conversation_metrics['total_conversations'])) . '</strong>',
                    '<strong style="color:#10B981;">' . esc_html(number_format($conv_converted)) . '</strong>',
                    '<strong>' . esc_html(number_format($conv_not_converted)) . '</strong>');
                ?></p>
                <p style="margin:0;color:#64748B;font-size:13px;"><?php echo esc_html__('Want a higher conversion rate? Try making your Capture Lead form shorter or adding a clearer value proposition before asking for contact info.', 'dialogawp'); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top sources -->
    <div class="dialogawp-chart-container">
        <h3><?php echo esc_html__('Top Pages Starting Conversations', 'dialogawp'); ?></h3>
        <?php if (!empty($top_sources)) : ?>
        <div style="position:relative;height:<?php echo intval(max(120, count($top_sources) * 40)); ?>px;margin-bottom:16px;">
            <canvas id="dialogawp-chart-sources"></canvas>
        </div>
        <table class="dialogawp-leads-table">
            <thead>
                <tr><th><?php echo esc_html__('Page', 'dialogawp'); ?></th><th><?php echo esc_html__('Conversations', 'dialogawp'); ?></th></tr>
            </thead>
            <tbody>
                <?php
                // v0.40.5 — conversation count is now clickable, taking Pro
                // users to a filtered Conversations tab so they can read the
                // actual chats coming from each page. Free users see the
                // count as plain text with a subtle "Pro" upgrade tooltip.
                $is_pro_here = function_exists('dialogawp_is_pro') ? dialogawp_is_pro() : defined('DIALOGAWP_PRO_VERSION');
                foreach ($top_sources as $source) :
                    $page_url = $source->page_url;
                ?>
                <tr>
                    <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <a href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html($page_url); ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($is_pro_here) :
                            $conv_url = add_query_arg(array(
                                'page'     => 'dialogawp-pro-analytics',
                                'tab'      => 'conversations',
                                'page_url' => rawurlencode($page_url),
                            ), admin_url('admin.php'));
                        ?>
                            <a href="<?php echo esc_url($conv_url); ?>"
                               title="<?php
                               /* translators: %s: conversation count. */
                               echo esc_attr(sprintf(__('View the %s conversations that started on this page', 'dialogawp'), number_format($source->count)));
                               ?>"
                               style="font-weight:600;color:#007D75;text-decoration:none;">
                                <?php echo number_format($source->count); ?>
                                <span style="color:#94A3B8;font-size:11px;margin-left:4px;">→</span>
                            </a>
                        <?php else : ?>
                            <?php echo number_format($source->count); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p style="text-align:center;padding:40px;color:#666;"><?php echo esc_html__('No source data yet - start a chat on your site to begin tracking.', 'dialogawp'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!dialogawp_is_pro()) : ?>
    <div class="dialogawp-settings-section" style="background:#F7F8FC;border:1px solid #E5E7EF;">
        <h2 style="color:#26233A;">DialogaWP Pro</h2>
        <p style="color:#475569;"><?php echo esc_html__('DialogaWP Pro is a separate plugin that adds further reporting on top of everything here - a per-node conversation funnel, full chat transcripts by session, A/B test comparisons, time-to-lead, and analytics CSV export.', 'dialogawp'); ?></p>
        <a href="<?php echo esc_url(dialogawp_upgrade_url()); ?>" target="_blank" rel="noopener" class="button"><?php echo esc_html__('Learn more about DialogaWP Pro', 'dialogawp'); ?> &rarr;</a>
    </div>
    <?php endif; ?>

    </div><!-- /.dialogawp-analytics-content -->
</div>

<?php
// v1.0.0 — page loading overlay JS, attached via enqueue system
wp_add_inline_script('dialogawp-admin', <<<'JS'
(function() {
    var content = document.getElementById('dialogawp-analytics-content');
    if (!content) return;
    function showLoading() { content.classList.add('is-loading'); }
    var pills = document.querySelectorAll('.dialogawp-analytics-filter a[href*="preset="], .dialogawp-analytics-filter a[href*="page=dialogawp-analytics"]');
    pills.forEach(function(a) { a.addEventListener('click', showLoading); });
    var form = document.querySelector('.dialogawp-analytics-filter form');
    if (form) form.addEventListener('submit', showLoading);
})();
JS
);
?>
