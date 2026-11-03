<?php
/**
 * Analytics Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Preset ranges resolved server-side so reload preserves state
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only analytics GET report params, no form data processed.
$dialoga_preset = isset($_GET['preset']) ? sanitize_text_field(wp_unslash($_GET['preset'])) : '';
$dialoga_today  = gmdate('Y-m-d');

switch ($dialoga_preset) {
    case 'today':
        $dialoga_date_from = $dialoga_today; $dialoga_date_to = $dialoga_today; break;
    case '7d':
        $dialoga_date_from = gmdate('Y-m-d', strtotime('-6 days')); $dialoga_date_to = $dialoga_today; break;
    case '30d':
        $dialoga_date_from = gmdate('Y-m-d', strtotime('-29 days')); $dialoga_date_to = $dialoga_today; break;
    case '90d':
        $dialoga_date_from = gmdate('Y-m-d', strtotime('-89 days')); $dialoga_date_to = $dialoga_today; break;
    case 'mtd':
        $dialoga_date_from = gmdate('Y-m-01'); $dialoga_date_to = $dialoga_today; break;
    case 'last_month':
        $dialoga_date_from = gmdate('Y-m-01', strtotime('first day of last month'));
        $dialoga_date_to   = gmdate('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'ytd':
        $dialoga_date_from = gmdate('Y-01-01'); $dialoga_date_to = $dialoga_today; break;
    case 'all':
        $dialoga_date_from = '2020-01-01'; $dialoga_date_to = $dialoga_today; break;
    default:
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only analytics GET report params, no form data processed.
        $dialoga_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : gmdate('Y-m-d', strtotime('-29 days'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only analytics GET report params, no form data processed.
        $dialoga_date_to   = isset($_GET['date_to'])   ? sanitize_text_field(wp_unslash($_GET['date_to']))   : $dialoga_today;
}

$dialoga_base_url = admin_url('admin.php?page=dialoga-analytics');
$dialoga_presets = array(
    'today'      => __('Today', 'dialoga'),
    '7d'         => __('Last 7 days', 'dialoga'),
    '30d'        => __('Last 30 days', 'dialoga'),
    '90d'        => __('Last 90 days', 'dialoga'),
    'mtd'        => __('Month to date', 'dialoga'),
    'last_month' => __('Last month', 'dialoga'),
    'ytd'        => __('Year to date', 'dialoga'),
    'all'        => __('All time', 'dialoga'),
);

$dialoga_conversation_metrics = Dialoga_Analytics::get_conversation_metrics($dialoga_date_from, $dialoga_date_to);
$dialoga_lead_metrics         = Dialoga_Analytics::get_lead_metrics($dialoga_date_from, $dialoga_date_to);
$dialoga_time_metrics         = Dialoga_Analytics::get_time_metrics(30);
$dialoga_top_sources          = Dialoga_Analytics::get_top_sources(5);

// Build chart-ready data arrays (must be AFTER the fetches above)
$dialoga_trend_labels = array();
$dialoga_trend_values = array();
foreach ($dialoga_time_metrics as $dialoga_metric) {
    $dialoga_trend_labels[] = wp_date('M j', strtotime($dialoga_metric->date), new DateTimeZone('UTC'));
    $dialoga_trend_values[] = (int) $dialoga_metric->count;
}

// v0.40.0 — donut counts any conversion (lead or marked link click), matching
// the conversion_rate metric so the chart and the tile don't disagree.
$dialoga_conv_converted     = isset($dialoga_conversation_metrics['conversations_converted'])
    ? (int) $dialoga_conversation_metrics['conversations_converted']
    : (int) $dialoga_conversation_metrics['conversations_with_leads'];
$dialoga_conv_not_converted = max(0, (int) $dialoga_conversation_metrics['total_conversations'] - $dialoga_conv_converted);

$dialoga_source_labels = array();
$dialoga_source_values = array();
foreach ($dialoga_top_sources as $dialoga_source) {
    $path = wp_parse_url($dialoga_source->page_url, PHP_URL_PATH) ?: $dialoga_source->page_url;
    if (strlen($path) > 40) $path = substr($path, 0, 37) . '…';
    if ($path === '' || $path === '/') $path = __('Home', 'dialoga');
    $dialoga_source_labels[] = $path;
    $dialoga_source_values[] = (int) $dialoga_source->count;
}

$dialoga_chart_payload = array(
    'trend'      => array('labels' => $dialoga_trend_labels, 'values' => $dialoga_trend_values),
    'conversion' => array('converted' => $dialoga_conv_converted, 'not_converted' => $dialoga_conv_not_converted),
    'sources'    => array('labels' => $dialoga_source_labels, 'values' => $dialoga_source_values),
);

// Get the active flow for funnel data
global $wpdb;
$dialoga_active_flow = dialoga_query_cached('analytics_active_flow', static function () {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT id, flow_name FROM %i WHERE is_active = 1 ORDER BY id DESC LIMIT 1", $wpdb->prefix . 'dialoga_flows'));
}, 120);
// v0.40.5 — switched to get_top_drop_offs (plural). Legacy get_top_drop_off
// returned the least-viewed node and used stale node_label from event_data,
// which surfaced raw internal types like "action" when the label was empty.
// The new helper pulls fresh labels from live flow config and skips the
// start node + statistical-noise floors.
$dialoga_drop_offs = $dialoga_active_flow ? Dialoga_Analytics::get_top_drop_offs($dialoga_active_flow->id, 1) : array();
$dialoga_drop_off  = !empty($dialoga_drop_offs) ? $dialoga_drop_offs[0] : null;
?>

<?php
// v1.0.0 — wp.org compliance: attach page-specific CSS + chart data via
// wp_add_inline_style / wp_add_inline_script attached to already-enqueued
// handles. Reviewers require this instead of raw <style> / <script> tags.
// Screen CSS lives in assets/css/screen-analytics.css and is enqueued from
// Dialoga::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
wp_add_inline_script('dialoga-admin', 'window.dialogaAnalytics = ' . wp_json_encode($dialoga_chart_payload) . ';', 'before');
?>

<div class="wrap dialoga-analytics-wrap">
    <div class="dialoga-screen-header dialoga-analytics-header">
        <div>
            <h1><?php echo esc_html__('Analytics', 'dialoga'); ?></h1>
            <p><?php echo esc_html__('Conversation trends and stats', 'dialoga'); ?></p>
        </div>
    </div>

    <!-- Date filter -->
    <div class="dialoga-analytics-filter">
        <!-- Preset pills -->
        <nav class="dialoga-analytics-presets" aria-label="<?php echo esc_attr__('Analytics', 'dialoga'); ?>">
            <?php foreach ($dialoga_presets as $dialoga_key => $dialoga_label) :
                $dialoga_active = ($dialoga_preset === $dialoga_key);
            ?>
            <a href="<?php echo esc_url($dialoga_base_url . '&preset=' . $dialoga_key); ?>"
               class="dialoga-analytics-preset<?php echo $dialoga_active ? ' is-active' : ''; ?>"
               <?php if ($dialoga_active) : ?>aria-current="page"<?php endif; ?>>
                <?php echo esc_html($dialoga_label); ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <form method="get" action="">
            <input type="hidden" name="page" value="dialoga-analytics" />
            <div class="dialoga-analytics-date-fields">
                <div>
                    <label for="dialoga-date-from"><?php echo esc_html__('From', 'dialoga'); ?></label>
                    <input id="dialoga-date-from" type="date" name="date_from" value="<?php echo esc_attr($dialoga_date_from); ?>" />
                </div>
                <div>
                    <label for="dialoga-date-to"><?php echo esc_html__('To', 'dialoga'); ?></label>
                    <input id="dialoga-date-to" type="date" name="date_to" value="<?php echo esc_attr($dialoga_date_to); ?>" />
                </div>
                <div>
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Apply', 'dialoga'); ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- Definition note -->
    <p class="dialoga-analytics-definition">
        <strong><?php echo esc_html__('How conversions are counted:', 'dialoga'); ?></strong>
        <?php echo esc_html__('A conversion is a conversation where the visitor submitted a Capture Lead form or clicked a Link Button marked as a conversion.', 'dialoga'); ?>
        <?php echo esc_html__('Each converted conversation is counted once, even when it includes both actions.', 'dialoga'); ?>
        <?php echo esc_html__('Link Conversions = clicks on Link Button nodes with "Count as conversion" enabled.', 'dialoga'); ?>
    </p>

    <!-- Wrapped content — overlay covers everything below the filter during reload -->
    <div class="dialoga-analytics-content" id="dialoga-analytics-content" aria-busy="false">
        <div class="dialoga-analytics-loading" role="status" aria-live="polite">
            <div class="dialoga-analytics-spinner" aria-hidden="true"></div>
            <div class="dialoga-analytics-loading-text"><?php echo esc_html__('Loading analytics...', 'dialoga'); ?></div>
        </div>

    <!-- Stat boxes -->
    <div class="dialoga-analytics-stats">
        <div class="dialoga-analytics-stat dialoga-analytics-stat-accent-1">
            <h3><?php echo esc_html__('Conversations', 'dialoga'); ?></h3>
            <div class="dialoga-analytics-stat-value"><?php echo number_format($dialoga_conversation_metrics['total_conversations']); ?></div>
            <div class="dialoga-analytics-stat-label"><?php echo esc_html__('Total chats started', 'dialoga'); ?></div>
        </div>
        <div class="dialoga-analytics-stat dialoga-analytics-stat-accent-2">
            <h3><?php echo esc_html__('Conversions', 'dialoga'); ?></h3>
            <div class="dialoga-analytics-stat-value"><?php echo number_format($dialoga_conversation_metrics['conversations_converted']); ?></div>
            <div class="dialoga-analytics-stat-label">
                <?php
                $dialoga_leads_n = (int) $dialoga_conversation_metrics['conversations_with_leads'];
                $dialoga_links_n = isset($dialoga_conversation_metrics['conversations_with_link_conversions'])
                    ? (int) $dialoga_conversation_metrics['conversations_with_link_conversions']
                    : 0;
                if ($dialoga_links_n > 0) {
                    /* translators: %d: converted conversation count with captured lead data. */
                    $dialoga_leads_label = sprintf(_n('%d conversation with lead data', '%d conversations with lead data', $dialoga_leads_n, 'dialoga'), $dialoga_leads_n);
                    /* translators: %d: converted conversation count with a marked link button click. */
                    $dialoga_links_label = sprintf(_n('%d conversation with a conversion link click', '%d conversations with conversion link clicks', $dialoga_links_n, 'dialoga'), $dialoga_links_n);
                    /* translators: %1$s: lead-conversation count label, %2$s: link-conversion conversation count label. */
                    echo esc_html(sprintf(__('%1$s + %2$s', 'dialoga'), $dialoga_leads_label, $dialoga_links_label));
                } else {
                    echo esc_html__('Conversations with lead data', 'dialoga');
                }
                ?>
            </div>
        </div>
        <div class="dialoga-analytics-stat dialoga-analytics-stat-accent-3">
            <h3><?php echo esc_html__('Conversion Rate', 'dialoga'); ?></h3>
            <div class="dialoga-analytics-stat-value"><?php echo esc_html($dialoga_conversation_metrics['conversion_rate']); ?>%</div>
            <div class="dialoga-analytics-stat-label"><?php echo esc_html__('Chats that converted', 'dialoga'); ?></div>
        </div>
        <div class="dialoga-analytics-stat dialoga-analytics-stat-accent-4">
            <h3><?php echo esc_html__('Total Leads', 'dialoga'); ?></h3>
            <div class="dialoga-analytics-stat-value"><?php echo number_format($dialoga_lead_metrics['total_leads']); ?></div>
            <div class="dialoga-analytics-stat-label"><?php echo esc_html__('Selected date range', 'dialoga'); ?></div>
        </div>
        <?php
        // v0.40.3 — Total Link Clicks tile. Counts EACH click on marked
        // Link Buttons (not distinct conversations), so one visitor who
        // clicked both Salt Cave and Massage contributes 2 here. Shown
        // only when link conversions are configured — for lead-only setups
        // this would always be 0 and adding a zero tile is noise.
        $dialoga_total_link_clicks = isset($dialoga_conversation_metrics['total_link_conversion_clicks'])
            ? (int) $dialoga_conversation_metrics['total_link_conversion_clicks']
            : 0;
        $dialoga_links_conv_count = isset($dialoga_conversation_metrics['conversations_with_link_conversions'])
            ? (int) $dialoga_conversation_metrics['conversations_with_link_conversions']
            : 0;
        if ($dialoga_total_link_clicks > 0 || $dialoga_links_conv_count > 0) :
        ?>
        <div class="dialoga-analytics-stat dialoga-analytics-stat-accent-5">
            <h3><?php echo esc_html__('Total Link Clicks', 'dialoga'); ?></h3>
            <div class="dialoga-analytics-stat-value"><?php echo number_format($dialoga_total_link_clicks); ?></div>
            <div class="dialoga-analytics-stat-label" title="<?php echo esc_attr__("Every click on a Link Button marked 'Count as conversion' - one visitor clicking multiple buttons contributes to each", 'dialoga'); ?>">
                <?php
                if ($dialoga_links_conv_count > 0 && $dialoga_total_link_clicks > $dialoga_links_conv_count) {
                    $dialoga_avg = round($dialoga_total_link_clicks / $dialoga_links_conv_count, 1);
                    /* translators: %1$d: visitor count, %2$s: average clicks. */
                    echo esc_html(sprintf(_n('across %1$d visitor (%2$s avg)', 'across %1$d visitors (%2$s avg)', $dialoga_links_conv_count, 'dialoga'), $dialoga_links_conv_count, $dialoga_avg));
                } elseif ($dialoga_links_conv_count > 0) {
                    /* translators: %d: visitor count. */
                    echo esc_html(sprintf(_n('across %d visitor', 'across %d visitors', $dialoga_links_conv_count, 'dialoga'), $dialoga_links_conv_count));
                } else {
                    echo esc_html__('On marked conversion buttons', 'dialoga');
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Drop-off highlight -->
    <?php if ($dialoga_drop_off) :
        // v0.40.5 — human-friendly node type label mapping. Prior version
        // showed the raw internal type slug ("action", "ai_chat") which is
        // meaningless to admins.
        $dialoga_type_label_map = array(
            'question' => __('Question', 'dialoga'),
            'branch'   => __('Branch', 'dialoga'),
            'action'   => __('Action', 'dialoga'),
            'ai_chat'  => __('AI Chat', 'dialoga'),
            'loop'     => __('Loop', 'dialoga'),
            'webhook'  => __('Webhook', 'dialoga'),
            'message'  => __('Message', 'dialoga'),
        );
        $dialoga_type_pretty = $dialoga_type_label_map[$dialoga_drop_off['node_type']] ?? ucfirst($dialoga_drop_off['node_type']);
    ?>
    <div class="dialoga-settings-section">
        <h2><?php echo esc_html__('Top Drop-off Point', 'dialoga'); ?></h2>
        <p class="dialoga-analytics-section-description"><?php echo esc_html__('Where most visitors stop engaging in the active flow.', 'dialoga'); ?></p>
        <div class="dialoga-analytics-dropoff">
            <div class="dialoga-analytics-dropoff-value"><?php echo esc_html($dialoga_drop_off['drop_off_pct']); ?>%</div>
            <div>
                <strong class="dialoga-analytics-dropoff-label"><?php echo esc_html__('of visitors drop off at:', 'dialoga'); ?></strong><br/>
                <span class="dialoga-analytics-dropoff-node"><?php echo esc_html($dialoga_drop_off['node_label']); ?></span>
                <?php if ($dialoga_type_pretty) : ?>
                    <span class="dialoga-analytics-dropoff-type"><?php echo esc_html($dialoga_type_pretty); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Conversation trend -->
    <div class="dialoga-chart-container">
        <h3><?php echo esc_html__('Conversations - Last 30 Days', 'dialoga'); ?></h3>
        <?php if (!empty($dialoga_time_metrics)) : ?>
        <div class="dialoga-chart-canvas dialoga-chart-canvas-trend">
            <canvas id="dialoga-chart-trend" role="img" aria-label="<?php echo esc_attr__('Conversations - Last 30 Days', 'dialoga'); ?>"></canvas>
        </div>
        <?php else : ?>
        <p class="dialoga-analytics-empty"><?php echo esc_html__('No conversation data yet.', 'dialoga'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Conversion split -->
    <?php if ($dialoga_conversation_metrics['total_conversations'] > 0) : ?>
    <div class="dialoga-chart-container">
        <h3><?php echo esc_html__('Conversion Split', 'dialoga'); ?></h3>
        <div class="dialoga-analytics-conversion-layout">
            <div class="dialoga-chart-canvas dialoga-chart-canvas-donut">
                <canvas id="dialoga-chart-conversion" role="img" aria-label="<?php echo esc_attr__('Conversion Split', 'dialoga'); ?>"></canvas>
            </div>
            <div class="dialoga-analytics-conversion-copy">
                <p><?php
                /* translators: %1$s: total conversations, %2$s: converted conversations, %3$s: unconverted conversations. */
                echo esc_html(sprintf(__('Of %1$s conversations in this range, %2$s converted and %3$s did not.', 'dialoga'),
                    number_format($dialoga_conversation_metrics['total_conversations']),
                    number_format($dialoga_conv_converted),
                    number_format($dialoga_conv_not_converted)));
                ?></p>
                <p class="dialoga-analytics-conversion-tip"><?php echo esc_html__('Want a higher conversion rate? Try making your Capture Lead form shorter or adding a clearer value proposition before asking for contact info.', 'dialoga'); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top sources -->
    <div class="dialoga-chart-container">
        <h3><?php echo esc_html__('Top Pages Starting Conversations', 'dialoga'); ?> <span class="dialoga-analytics-heading-meta"><?php echo esc_html__('All time', 'dialoga'); ?></span></h3>
        <?php if (!empty($dialoga_top_sources)) : ?>
        <div class="dialoga-chart-canvas dialoga-chart-canvas-sources" style="--dialoga-chart-height: <?php echo intval(max(120, count($dialoga_top_sources) * 40)); ?>px;">
            <canvas id="dialoga-chart-sources" role="img" aria-label="<?php echo esc_attr__('Top Pages Starting Conversations', 'dialoga'); ?>"></canvas>
        </div>
        <div class="dialoga-table-scroll" tabindex="0">
        <table class="dialoga-leads-table">
            <caption class="screen-reader-text"><?php echo esc_html__('Top Pages Starting Conversations', 'dialoga'); ?></caption>
            <thead>
                <tr><th scope="col"><?php echo esc_html__('Page', 'dialoga'); ?></th><th scope="col"><?php echo esc_html__('Conversations', 'dialoga'); ?></th></tr>
            </thead>
            <tbody>
                <?php
                // v0.40.5 — conversation count is now clickable, taking Pro
                // users to a filtered Conversations tab so they can read the
                // actual chats coming from each page. Free users see the
                // count as plain text with a subtle "Pro" upgrade tooltip.
                $dialoga_is_pro_here = function_exists('dialoga_is_pro') ? dialoga_is_pro() : defined('DIALOGA_PRO_VERSION');
                foreach ($dialoga_top_sources as $dialoga_source) :
                    $dialoga_page_url = $dialoga_source->page_url;
                ?>
                <tr>
                    <th scope="row" class="dialoga-analytics-source-page">
                        <a href="<?php echo esc_url($dialoga_page_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html($dialoga_page_url); ?>
                        </a>
                    </th>
                    <td>
                        <?php if ($dialoga_is_pro_here) :
                            $dialoga_conv_url = add_query_arg(array(
                                'page'     => 'dialoga-pro-analytics',
                                'tab'      => 'conversations',
                                'page_url' => rawurlencode($dialoga_page_url),
                            ), admin_url('admin.php'));
                        ?>
                            <a href="<?php echo esc_url($dialoga_conv_url); ?>"
                               title="<?php
                               /* translators: %s: conversation count. */
                               echo esc_attr(sprintf(__('View the %s conversations that started on this page', 'dialoga'), number_format($dialoga_source->count)));
                               ?>"
                               class="dialoga-analytics-count-link">
                                 <?php echo number_format($dialoga_source->count); ?>
                                 <span aria-hidden="true">→</span>
                            </a>
                        <?php else : ?>
                            <?php echo number_format($dialoga_source->count); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else : ?>
        <p class="dialoga-analytics-empty"><?php echo esc_html__('No source data yet - start a chat on your site to begin tracking.', 'dialoga'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!dialoga_is_pro()) : ?>
    <div class="dialoga-settings-section dialoga-analytics-pro-promo">
        <h2>Dialoga Pro</h2>
        <p><?php echo esc_html__('Dialoga Pro is a separate plugin that adds further reporting on top of everything here - a per-node conversation funnel, full chat transcripts by session, A/B test comparisons, time-to-lead, and analytics CSV export.', 'dialoga'); ?></p>
        <a href="<?php echo esc_url(dialoga_upgrade_url()); ?>" target="_blank" rel="noopener" class="button"><?php echo esc_html__('Learn more about Dialoga Pro', 'dialoga'); ?> &rarr;</a>
    </div>
    <?php endif; ?>

    </div><!-- /.dialoga-analytics-content -->
</div>

<?php
// v1.0.0 — page loading overlay JS, attached via enqueue system
wp_add_inline_script('dialoga-admin', <<<'JS'
(function() {
    var content = document.getElementById('dialoga-analytics-content');
    if (!content) return;
    function showLoading() {
        content.classList.add('is-loading');
        content.setAttribute('aria-busy', 'true');
    }
    var pills = document.querySelectorAll('.dialoga-analytics-filter a[href*="preset="], .dialoga-analytics-filter a[href*="page=dialoga-analytics"]');
    pills.forEach(function(a) { a.addEventListener('click', showLoading); });
    var form = document.querySelector('.dialoga-analytics-filter form');
    if (form) form.addEventListener('submit', showLoading);
})();
JS
);
?>
