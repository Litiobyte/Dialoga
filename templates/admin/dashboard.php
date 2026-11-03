<?php
/**
 * Dashboard Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get metrics
$conversation_metrics = Dialoga_Analytics::get_conversation_metrics();
$lead_metrics = Dialoga_Analytics::get_lead_metrics();
$funnel_metrics = Dialoga_Analytics::get_funnel_metrics();

global $wpdb;
$active_flows = intval(dialoga_query_cached('dash_active_flows', static function () {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE is_active = 1", $wpdb->prefix . 'dialoga_flows'));
}));
$total_flows = intval(dialoga_query_cached('dash_total_flows', static function () {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $wpdb->prefix . 'dialoga_flows'));
}));

// Recent leads (last 5)
$recent_leads = dialoga_query_cached('dash_recent_leads', static function () {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM %i ORDER BY created_at DESC LIMIT 5", $wpdb->prefix . 'dialoga_leads'));
});

// Resume-setup-wizard card — shown when the current admin has an in-progress
// wizard state. State is auto-saved as user_meta on every step change, then
// cleared on wizard_complete. Deliberately no fallback to skipped — if the
// user skipped, they don't want the resume nudge.
$wizard_state = get_user_meta(get_current_user_id(), 'dialoga_wizard_state', true);
$wizard_resume = null;
if (is_array($wizard_state) && isset($wizard_state['currentStep'])) {
    $wizard_steps = array(
        0 => __('Welcome', 'dialoga'),
        1 => __('AI Setup', 'dialoga'),
        2 => __('Crawl Website', 'dialoga'),
        3 => __('Chat Customization', 'dialoga'),
        4 => __('Chat Goals', 'dialoga'),
        5 => __('Visibility', 'dialoga'),
        6 => __('Launch', 'dialoga'),
    );
    $step_idx   = intval($wizard_state['currentStep']);
    /* translators: %d: setup step number. */
    $step_label = isset($wizard_steps[$step_idx]) ? $wizard_steps[$step_idx] : sprintf(__('Step %d', 'dialoga'), $step_idx + 1);
    $total      = count($wizard_steps);
    $percent    = min(100, max(0, round(($step_idx / max(1, $total - 1)) * 100)));
    $updated    = isset($wizard_state['updated_at']) ? $wizard_state['updated_at'] : '';
    $wizard_resume = compact('step_idx', 'step_label', 'total', 'percent', 'updated');
}
?>

<?php
// Screen CSS lives in assets/css/screen-dashboard.css and is enqueued from
// Dialoga::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>
<div class="wrap dialoga-dashboard">

    <div class="dialoga-screen-header dialoga-dash-header">
        <div>
            <h1><?php echo esc_html__('Dashboard', 'dialoga'); ?></h1>
            <p class="dialoga-dash-subtitle"><?php echo esc_html__("Welcome to Dialoga. Here's how your chatbot is performing.", 'dialoga'); ?></p>
        </div>
        <div class="dialoga-screen-header-actions dialoga-dash-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-builder')); ?>" class="button button-primary dialoga-cta"><?php echo esc_html__('Create New Flow', 'dialoga'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-settings')); ?>" class="button"><?php echo esc_html__('Settings', 'dialoga'); ?></a>
        </div>
    </div>

    <?php if ($wizard_resume) : ?>
    <div class="dialoga-dash-resume" id="dialoga-wizard-resume-card">
        <div class="dialoga-dash-resume-icon"><span class="dashicons dashicons-admin-tools"></span></div>
        <div class="dialoga-dash-resume-body">
            <p class="dialoga-dash-resume-title"><?php echo esc_html__('Setup Wizard in progress', 'dialoga'); ?></p>
            <p class="dialoga-dash-resume-meta">
                <?php
                /* translators: %1$s: step label, %2$d: current step, %3$d: total steps. */
                echo sprintf(esc_html__('Resuming from %1$s (step %2$d of %3$d)', 'dialoga'), '<strong>' . esc_html($wizard_resume['step_label']) . '</strong>', intval($wizard_resume['step_idx']) + 1, intval($wizard_resume['total']));
                ?>
                <?php if (!empty($wizard_resume['updated'])) : ?>
                    &middot; <?php
                    /* translators: %s: elapsed time. */
                    echo esc_html(sprintf(__('last saved %s ago', 'dialoga'), human_time_diff(strtotime($wizard_resume['updated']), current_time('timestamp'))));
                    ?>
                <?php endif; ?>
            </p>
            <div class="dialoga-dash-resume-bar"><div class="dialoga-dash-resume-bar-fill" style="width: <?php echo intval($wizard_resume['percent']); ?>%;"></div></div>
        </div>
        <div class="dialoga-dash-resume-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-wizard')); ?>" class="button button-primary"><?php echo esc_html__('Resume Setup', 'dialoga'); ?></a>
            <button type="button" class="dialoga-dash-resume-dismiss" id="dialoga-wizard-resume-dismiss" title="<?php echo esc_attr__('Discard saved wizard progress', 'dialoga'); ?>"><?php echo esc_html__('Dismiss', 'dialoga'); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_flows === 0 && $total_flows === 0) : ?>
    <div class="dialoga-dash-alert dialoga-dash-alert-info">
        <?php
        /* translators: %1$s: create-flow link, %2$s: setup-wizard link. */
        echo sprintf(esc_html__('You haven\'t created any flows yet. %1$s or %2$s to get started.', 'dialoga'),
            '<a href="' . esc_url(admin_url('admin.php?page=dialoga-builder')) . '">' . esc_html__('Create your first flow', 'dialoga') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=dialoga-wizard')) . '">' . esc_html__('run the Setup Wizard', 'dialoga') . '</a>');
        ?>
    </div>
    <?php elseif ($active_flows === 0) : ?>
    <div class="dialoga-dash-alert dialoga-dash-alert-info">
        <?php echo esc_html__("No active flows. Your chatbot won't appear on your website until you activate a flow.", 'dialoga'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-builder')); ?>"><?php echo esc_html__('Manage Flows', 'dialoga'); ?> &rarr;</a>
    </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="dialoga-dash-stats">
        <?php
        // v0.40.6 — Bubble Shown tile restored. Was previously inside the
        // Engagement Funnel card that got removed in v0.40.5 — impressions
        // are the top-of-funnel visibility metric and deserve a dedicated
        // tile. Hidden when zero (pre-v0.38.7 sites, or genuinely no traffic)
        // to avoid a confusing empty box.
        $bubble_impressions = isset($funnel_metrics['impressions'])
            ? (int) $funnel_metrics['impressions']
            : 0;
        if ($bubble_impressions > 0) :
        ?>
        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-visibility">
                <span class="dashicons dashicons-visibility"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo number_format($bubble_impressions); ?></div>
                <div class="dialoga-dash-stat-label" title="<?php echo esc_attr__('Times the chat bubble was shown to real visitors on your site', 'dialoga'); ?>"><?php echo esc_html__('Bubble Shown', 'dialoga'); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-chat">
                <span class="dashicons dashicons-format-chat"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo number_format($conversation_metrics['total_conversations']); ?></div>
                <div class="dialoga-dash-stat-label"><?php echo esc_html__('Conversations', 'dialoga'); ?></div>
            </div>
        </div>

        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-leads">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo number_format($lead_metrics['total_leads']); ?></div>
                <div class="dialoga-dash-stat-label"><?php echo esc_html__('Leads Captured', 'dialoga'); ?></div>
            </div>
        </div>

        <?php
        // v0.40.2 — Total Conversions tile. Sits between Leads Captured (narrow:
        // only form submissions) and Conversion Rate (percentage) so admins can
        // see the underlying count that drives the rate. Includes both lead
        // captures AND marked Link Button clicks. Only shown when link
        // conversions are actually in play — for a lead-capture-only setup this
        // number always equals Leads Captured, so the tile would just be noise.
        $has_link_conv_configured = !empty(Dialoga_Analytics::get_conversion_link_node_ids());
        if ($has_link_conv_configured) :
        ?>
        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-conversions">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo number_format($conversation_metrics['conversations_converted']); ?></div>
                <div class="dialoga-dash-stat-label" title="<?php echo esc_attr__('Conversations that captured a lead or triggered a marked Link Button click', 'dialoga'); ?>"><?php echo esc_html__('Total Conversions', 'dialoga'); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-rate">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo esc_html($conversation_metrics['conversion_rate']); ?>%</div>
                <div class="dialoga-dash-stat-label"><?php echo esc_html__('Conversion Rate', 'dialoga'); ?></div>
            </div>
        </div>

        <div class="dialoga-dash-stat">
            <div class="dialoga-dash-stat-icon dialoga-dash-stat-icon-flows">
                <span class="dashicons dashicons-randomize"></span>
            </div>
            <div class="dialoga-dash-stat-content">
                <div class="dialoga-dash-stat-value"><?php echo intval($active_flows); ?></div>
                <div class="dialoga-dash-stat-label"><?php echo esc_html__('Active Flows', 'dialoga'); ?></div>
            </div>
        </div>
    </div>

    <!-- Where visitors drop off. Top 3 for everyone; Pro adds a link to the
         separate Advanced Analytics → Funnel report for deeper drill-down.
    -->
    <?php
    $is_pro = function_exists('dialoga_is_pro') ? dialoga_is_pro() : (defined('DIALOGA_PRO_VERSION'));
    $active_flow_dash = dialoga_query_cached('dash_active_flow_row', static function () {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id, flow_name FROM %i WHERE is_active = 1 ORDER BY id DESC LIMIT 1", $wpdb->prefix . 'dialoga_flows'));
    });
    $drop_offs_dash   = $active_flow_dash ? Dialoga_Analytics::get_top_drop_offs($active_flow_dash->id, 3) : array();

    $type_label_map = array(
        'question' => __('Question', 'dialoga'),
        'branch'   => __('Branch', 'dialoga'),
        'action'   => __('Action', 'dialoga'),
        'ai_chat'  => __('AI Chat', 'dialoga'),
        'loop'     => __('Loop', 'dialoga'),
        'webhook'  => __('Webhook', 'dialoga'),
        'message'  => __('Message', 'dialoga'),
    );
    ?>
    <?php if (!empty($drop_offs_dash)) : ?>
    <div class="dialoga-dash-card dialoga-dash-card-spaced">
        <div class="dialoga-dash-card-header">
            <h3><?php echo esc_html__('Where visitors are dropping off', 'dialoga'); ?></h3>
            <?php if ($active_flow_dash) : ?>
                <span class="dialoga-dash-card-meta">
                    <?php echo esc_html__('Active flow:', 'dialoga'); ?> <?php echo esc_html($active_flow_dash->flow_name); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="dialoga-dash-dropoffs">
            <?php foreach ($drop_offs_dash as $i => $do) :
                $type_pretty = $type_label_map[$do['node_type']] ?? ucfirst($do['node_type']);
            ?>
            <div class="dialoga-dash-dropoff">
                <div class="dialoga-dash-dropoff-value">
                    <?php echo esc_html($do['drop_off_pct']); ?>%
                </div>
                <div class="dialoga-dash-dropoff-content">
                    <div class="dialoga-dash-dropoff-title">
                        <?php echo esc_html($do['node_label']); ?>
                        <?php if ($type_pretty) : ?>
                            <span class="dialoga-dash-dropoff-type"><?php echo esc_html($type_pretty); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="dialoga-dash-dropoff-meta">
                        <?php
                        /* translators: %1$s: visitor count, %2$s: missed visitor count. */
                        echo esc_html(sprintf(__('%1$s visitors reached; %2$s never got here', 'dialoga'), number_format($do['views']), number_format($do['missed'])));
                        ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($is_pro) : ?>
            <div class="dialoga-dash-dropoff-action">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-pro-analytics&tab=funnel&flow_id=' . intval($active_flow_dash->id))); ?>"
                   class="dialoga-dash-card-link">
                    <?php echo esc_html__('See full funnel breakdown', 'dialoga'); ?> &rarr;
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Flows Overview -->
    <?php
    $all_flows = dialoga_query_cached('dash_recent_flows', static function () {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, flow_name, is_active, created_at FROM %i ORDER BY created_at DESC LIMIT 5", $wpdb->prefix . 'dialoga_flows'));
    });
    ?>
    <?php if (!empty($all_flows)) : ?>
    <div class="dialoga-dash-card dialoga-dash-card-spaced">
        <div class="dialoga-dash-card-header">
            <h3><?php echo esc_html__('Your Flows', 'dialoga'); ?></h3>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-builder')); ?>"><?php echo esc_html__('Manage Flows', 'dialoga'); ?> &rarr;</a>
        </div>
        <div class="dialoga-table-scroll" tabindex="0">
        <table class="dialoga-dash-table">
            <caption class="screen-reader-text"><?php echo esc_html__('Your Flows', 'dialoga'); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Flow Name', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Created', 'dialoga'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_flows as $flow) : ?>
                <tr>
                    <th scope="row">
                        <a href="<?php echo esc_url(add_query_arg('flow_id', intval($flow->id), admin_url('admin.php?page=dialoga-builder'))); ?>">
                            <?php echo esc_html($flow->flow_name ?: __('Untitled Flow', 'dialoga')); ?>
                        </a>
                    </th>
                    <td>
                        <?php if ($flow->is_active) : ?>
                            <span class="dialoga-dash-badge dialoga-dash-badge-active"><?php echo esc_html__('Active', 'dialoga'); ?></span>
                        <?php else : ?>
                            <span class="dialoga-dash-badge dialoga-dash-badge-inactive"><?php echo esc_html__('Inactive', 'dialoga'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(wp_date('M j, Y', strtotime($flow->created_at), new DateTimeZone('UTC'))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="dialoga-dash-grid">

        <!-- Recent Leads -->
        <div class="dialoga-dash-card">
            <div class="dialoga-dash-card-header">
                <h3><?php echo esc_html__('Recent Leads', 'dialoga'); ?></h3>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-leads')); ?>"><?php echo esc_html__('View All', 'dialoga'); ?> &rarr;</a>
            </div>
            <?php if (!empty($recent_leads)) : ?>
            <div class="dialoga-table-scroll" tabindex="0">
            <table class="dialoga-dash-table">
                <caption class="screen-reader-text"><?php echo esc_html__('Recent Leads', 'dialoga'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Name', 'dialoga'); ?></th>
                        <th scope="col"><?php echo esc_html__('Email', 'dialoga'); ?></th>
                        <th scope="col"><?php echo esc_html__('Date', 'dialoga'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_leads as $lead) :
                        $data = json_decode($lead->lead_data, true) ?: array();
                        $name = $data['name'] ?? $data['full_name'] ?? '-';
                        $email = $data['email'] ?? '-';
                    ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($name); ?></th>
                        <td><?php echo esc_html($email); ?></td>
                        <td><?php echo esc_html(wp_date('M j', strtotime($lead->created_at), new DateTimeZone('UTC'))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else : ?>
            <div class="dialoga-dash-empty">
                <p><?php echo esc_html__("No leads captured yet. Once visitors fill out your chatbot forms, they'll appear here.", 'dialoga'); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="dialoga-dash-card">
            <div class="dialoga-dash-card-header">
                <h3><?php echo esc_html__('Quick Links', 'dialoga'); ?></h3>
            </div>
            <div class="dialoga-dash-links">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-builder')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-edit dialoga-dash-icon-teal"></span>
                    <div>
                        <strong><?php echo esc_html__('Flow Builder', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Create and edit chatbot flows', 'dialoga'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-leads')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-groups dialoga-dash-icon-green"></span>
                    <div>
                        <strong><?php echo esc_html__('Leads', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('View and export captured leads', 'dialoga'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-analytics')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-chart-bar dialoga-dash-icon-orange"></span>
                    <div>
                        <strong><?php echo esc_html__('Analytics', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Conversation trends and stats', 'dialoga'); ?></span>
                    </div>
                </a>
                <?php
                // Pro-gated links: Pro users go to the actual feature.
                // Free users get sent to dialoga.io/pricing in a new tab
                // (clearer than routing them to a partial-featured page
                // that just shows an upgrade block at the bottom).
                $is_pro = function_exists('dialoga_is_pro') && dialoga_is_pro();
                $upgrade_url = function_exists('dialoga_upgrade_url') ? dialoga_upgrade_url() : 'https://www.litiobyte.com/labs-detalle.php?slug=dialoga';
                $convo_url = $is_pro
                    ? admin_url('admin.php?page=dialoga-pro-analytics&tab=conversations')
                    : $upgrade_url;
                $adv_url = $is_pro
                    ? admin_url('admin.php?page=dialoga-pro-analytics&tab=performance')
                    : $upgrade_url;
                $gated_class    = $is_pro ? 'dialoga-dash-link' : 'dialoga-dash-link is-gated';
                $gated_target   = $is_pro ? '' : ' target="_blank" rel="noopener"';
                ?>
                <a href="<?php echo esc_url($convo_url); ?>" class="<?php echo esc_attr($gated_class); ?>"<?php echo wp_kses_post($gated_target); ?>>
                    <span class="dashicons dashicons-format-chat dialoga-dash-icon-purple"></span>
                    <div>
                        <strong><?php echo esc_html__('Conversations', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Read every chat your visitors had', 'dialoga'); ?></span>
                    </div>
                    <?php if (!$is_pro) : ?><span class="dialoga-pro-pill">Pro</span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url($adv_url); ?>" class="<?php echo esc_attr($gated_class); ?>"<?php echo wp_kses_post($gated_target); ?>>
                    <span class="dashicons dashicons-chart-pie dialoga-dash-icon-pink"></span>
                    <div>
                        <strong><?php echo esc_html__('Advanced Analytics', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Funnel, heatmap, flow performance', 'dialoga'); ?></span>
                    </div>
                    <?php if (!$is_pro) : ?><span class="dialoga-pro-pill">Pro</span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-ai-settings')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-admin-site-alt3 dialoga-dash-icon-purple"></span>
                    <div>
                        <strong><?php echo esc_html__('AI Settings', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Knowledge base and AI config', 'dialoga'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-wizard')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-welcome-learn-more dialoga-dash-icon-cyan"></span>
                    <div>
                        <strong><?php echo esc_html__('Setup Wizard', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Guided chatbot setup', 'dialoga'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-settings')); ?>" class="dialoga-dash-link">
                    <span class="dashicons dashicons-admin-generic dialoga-dash-icon-slate"></span>
                    <div>
                        <strong><?php echo esc_html__('Settings', 'dialoga'); ?></strong>
                        <span><?php echo esc_html__('Widget appearance and behavior', 'dialoga'); ?></span>
                    </div>
                </a>
            </div>
        </div>

    </div>

</div>

<?php if ($wizard_resume) : ?>
<?php
$dialoga_wizard_dismiss_js = "(function() {\n"
    . "    var t = function(s) { return (dialogaAdmin.i18n || {})[s] || s; };\n"
    . "    var btn = document.getElementById('dialoga-wizard-resume-dismiss');\n"
    . "    var card = document.getElementById('dialoga-wizard-resume-card');\n"
    . "    if (!btn || !card) return;\n"
    . "    btn.addEventListener('click', function() {\n"
    . "        btn.disabled = true;\n"
    . "        btn.textContent = t('Dismissing...');\n"
    . "        var fd = new FormData();\n"
    . "        fd.append('action', 'dialoga_wizard_dismiss_state');\n"
    . "        fd.append('nonce', '" . esc_js(wp_create_nonce('dialoga_nonce')) . "');\n"
    . "        fetch('" . esc_js(admin_url('admin-ajax.php')) . "', { method: 'POST', body: fd, credentials: 'same-origin' })\n"
    . "            .then(function(r) { return r.json(); })\n"
    . "            .then(function(resp) {\n"
    . "                if (resp && resp.success) {\n"
    . "                    card.style.transition = 'opacity 0.25s';\n"
    . "                    card.style.opacity = '0';\n"
    . "                    setTimeout(function() { card.remove(); }, 250);\n"
    . "                } else {\n"
    . "                    btn.disabled = false;\n"
    . "                    btn.textContent = t('Dismiss');\n"
    . "                }\n"
    . "            })\n"
    . "            .catch(function() { btn.disabled = false; btn.textContent = t('Dismiss'); });\n"
    . "    });\n"
    . "})();\n";
wp_add_inline_script('dialoga-admin', $dialoga_wizard_dismiss_js);
?>
<?php endif; ?>
