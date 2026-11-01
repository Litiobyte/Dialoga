<?php
/**
 * Dashboard Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get metrics
$conversation_metrics = DialogaWP_Analytics::get_conversation_metrics();
$lead_metrics = DialogaWP_Analytics::get_lead_metrics();
$funnel_metrics = DialogaWP_Analytics::get_funnel_metrics();

global $wpdb;
$active_flows = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dialogawp_flows WHERE is_active = 1"));
$total_flows = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dialogawp_flows"));

// Recent leads (last 5)
$recent_leads = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}dialogawp_leads ORDER BY created_at DESC LIMIT 5"
);

// Resume-setup-wizard card — shown when the current admin has an in-progress
// wizard state. State is auto-saved as user_meta on every step change, then
// cleared on wizard_complete. Deliberately no fallback to skipped — if the
// user skipped, they don't want the resume nudge.
$wizard_state = get_user_meta(get_current_user_id(), 'dialogawp_wizard_state', true);
$wizard_resume = null;
if (is_array($wizard_state) && isset($wizard_state['currentStep'])) {
    $wizard_steps = array(
        0 => __('Welcome', 'dialogawp'),
        1 => __('AI Setup', 'dialogawp'),
        2 => __('Crawl Website', 'dialogawp'),
        3 => __('Chat Customization', 'dialogawp'),
        4 => __('Chat Goals', 'dialogawp'),
        5 => __('Visibility', 'dialogawp'),
        6 => __('Launch', 'dialogawp'),
    );
    $step_idx   = intval($wizard_state['currentStep']);
    /* translators: %d: setup step number. */
    $step_label = isset($wizard_steps[$step_idx]) ? $wizard_steps[$step_idx] : sprintf(__('Step %d', 'dialogawp'), $step_idx + 1);
    $total      = count($wizard_steps);
    $percent    = min(100, max(0, round(($step_idx / max(1, $total - 1)) * 100)));
    $updated    = isset($wizard_state['updated_at']) ? $wizard_state['updated_at'] : '';
    $wizard_resume = compact('step_idx', 'step_label', 'total', 'percent', 'updated');
}
?>

<?php
// Screen CSS lives in assets/css/screen-dashboard.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>
<div class="wrap dialogawp-dashboard">

    <div class="dialogawp-dash-header">
        <div>
            <h1><?php echo esc_html__('Dashboard', 'dialogawp'); ?></h1>
            <p class="dialogawp-dash-subtitle"><?php echo esc_html__("Welcome to DialogaWP. Here's how your chatbot is performing.", 'dialogawp'); ?></p>
        </div>
    </div>

    <div class="dialogawp-dash-header-actions">
        <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-builder')); ?>" class="button button-primary dialogawp-cta"><?php echo esc_html__('Create New Flow', 'dialogawp'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings')); ?>" class="button"><?php echo esc_html__('Settings', 'dialogawp'); ?></a>
    </div>

    <?php if ($wizard_resume) : ?>
    <div class="dialogawp-dash-resume" id="dialogawp-wizard-resume-card">
        <div class="dialogawp-dash-resume-icon"><span class="dashicons dashicons-admin-tools"></span></div>
        <div class="dialogawp-dash-resume-body">
            <p class="dialogawp-dash-resume-title"><?php echo esc_html__('Setup Wizard in progress', 'dialogawp'); ?></p>
            <p class="dialogawp-dash-resume-meta">
                <?php
                /* translators: %1$s: step label, %2$d: current step, %3$d: total steps. */
                echo sprintf(esc_html__('Resuming from %1$s (step %2$d of %3$d)', 'dialogawp'), '<strong>' . esc_html($wizard_resume['step_label']) . '</strong>', intval($wizard_resume['step_idx']) + 1, intval($wizard_resume['total']));
                ?>
                <?php if (!empty($wizard_resume['updated'])) : ?>
                    &middot; <?php
                    /* translators: %s: elapsed time. */
                    echo esc_html(sprintf(__('last saved %s ago', 'dialogawp'), human_time_diff(strtotime($wizard_resume['updated']), current_time('timestamp'))));
                    ?>
                <?php endif; ?>
            </p>
            <div class="dialogawp-dash-resume-bar"><div class="dialogawp-dash-resume-bar-fill" style="width: <?php echo intval($wizard_resume['percent']); ?>%;"></div></div>
        </div>
        <div class="dialogawp-dash-resume-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-wizard')); ?>" class="button button-primary"><?php echo esc_html__('Resume Setup', 'dialogawp'); ?></a>
            <button type="button" class="dialogawp-dash-resume-dismiss" id="dialogawp-wizard-resume-dismiss" title="<?php echo esc_attr__('Discard saved wizard progress', 'dialogawp'); ?>"><?php echo esc_html__('Dismiss', 'dialogawp'); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($active_flows === 0 && $total_flows === 0) : ?>
    <div class="dialogawp-dash-alert dialogawp-dash-alert-info">
        <?php
        /* translators: %1$s: create-flow link, %2$s: setup-wizard link. */
        echo sprintf(esc_html__('You haven\'t created any flows yet. %1$s or %2$s to get started.', 'dialogawp'),
            '<a href="' . esc_url(admin_url('admin.php?page=dialogawp-builder')) . '">' . esc_html__('Create your first flow', 'dialogawp') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=dialogawp-wizard')) . '">' . esc_html__('run the Setup Wizard', 'dialogawp') . '</a>');
        ?>
    </div>
    <?php elseif ($active_flows === 0) : ?>
    <div class="dialogawp-dash-alert dialogawp-dash-alert-info">
        <?php echo esc_html__("No active flows. Your chatbot won't appear on your website until you activate a flow.", 'dialogawp'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-builder')); ?>"><?php echo esc_html__('Manage Flows', 'dialogawp'); ?> &rarr;</a>
    </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="dialogawp-dash-stats">
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
        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #E0E7FF; color: #4338CA;">
                <span class="dashicons dashicons-visibility"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo number_format($bubble_impressions); ?></div>
                <div class="dialogawp-dash-stat-label" title="<?php echo esc_attr__('Times the chat bubble was shown to real visitors on your site', 'dialogawp'); ?>"><?php echo esc_html__('Bubble Shown', 'dialogawp'); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #EFF6FF; color: #007D75;">
                <span class="dashicons dashicons-format-chat"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo number_format($conversation_metrics['total_conversations']); ?></div>
                <div class="dialogawp-dash-stat-label"><?php echo esc_html__('Conversations', 'dialogawp'); ?></div>
            </div>
        </div>

        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #F0FDF4; color: #16A34A;">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo number_format($lead_metrics['total_leads']); ?></div>
                <div class="dialogawp-dash-stat-label"><?php echo esc_html__('Leads Captured', 'dialogawp'); ?></div>
            </div>
        </div>

        <?php
        // v0.40.2 — Total Conversions tile. Sits between Leads Captured (narrow:
        // only form submissions) and Conversion Rate (percentage) so admins can
        // see the underlying count that drives the rate. Includes both lead
        // captures AND marked Link Button clicks. Only shown when link
        // conversions are actually in play — for a lead-capture-only setup this
        // number always equals Leads Captured, so the tile would just be noise.
        $has_link_conv_configured = !empty(DialogaWP_Analytics::get_conversion_link_node_ids());
        if ($has_link_conv_configured) :
        ?>
        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #ECFDF5; color: #059669;">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo number_format($conversation_metrics['conversations_converted']); ?></div>
                <div class="dialogawp-dash-stat-label" title="<?php echo esc_attr__('Conversations that captured a lead or triggered a marked Link Button click', 'dialogawp'); ?>"><?php echo esc_html__('Total Conversions', 'dialogawp'); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #FFF7ED; color: #EA580C;">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo esc_html($conversation_metrics['conversion_rate']); ?>%</div>
                <div class="dialogawp-dash-stat-label"><?php echo esc_html__('Conversion Rate', 'dialogawp'); ?></div>
            </div>
        </div>

        <div class="dialogawp-dash-stat">
            <div class="dialogawp-dash-stat-icon" style="background: #F5F3FF; color: #7C3AED;">
                <span class="dashicons dashicons-randomize"></span>
            </div>
            <div class="dialogawp-dash-stat-content">
                <div class="dialogawp-dash-stat-value"><?php echo intval($active_flows); ?></div>
                <div class="dialogawp-dash-stat-label"><?php echo esc_html__('Active Flows', 'dialogawp'); ?></div>
            </div>
        </div>
    </div>

    <!-- Where visitors drop off. Top 3 for everyone; Pro adds a link to the
         separate Advanced Analytics → Funnel report for deeper drill-down.
    -->
    <?php
    $is_pro = function_exists('dialogawp_is_pro') ? dialogawp_is_pro() : (defined('DIALOGAWP_PRO_VERSION'));
    $active_flow_dash = $wpdb->get_row("SELECT id, flow_name FROM {$wpdb->prefix}dialogawp_flows WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $drop_offs_dash   = $active_flow_dash ? DialogaWP_Analytics::get_top_drop_offs($active_flow_dash->id, 3) : array();

    $type_label_map = array(
        'question' => __('Question', 'dialogawp'),
        'branch'   => __('Branch', 'dialogawp'),
        'action'   => __('Action', 'dialogawp'),
        'ai_chat'  => __('AI Chat', 'dialogawp'),
        'loop'     => __('Loop', 'dialogawp'),
        'webhook'  => __('Webhook', 'dialogawp'),
        'message'  => __('Message', 'dialogawp'),
    );
    ?>
    <?php if (!empty($drop_offs_dash)) : ?>
    <div class="dialogawp-dash-card" style="margin-bottom:24px;">
        <div class="dialogawp-dash-card-header">
            <h3><?php echo esc_html__('Where visitors are dropping off', 'dialogawp'); ?></h3>
            <?php if ($active_flow_dash) : ?>
                <span style="font-size:12px;color:#94A3B8;">
                    <?php echo esc_html__('Active flow:', 'dialogawp'); ?> <?php echo esc_html($active_flow_dash->flow_name); ?>
                </span>
            <?php endif; ?>
        </div>
        <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
            <?php foreach ($drop_offs_dash as $i => $do) :
                $type_pretty = $type_label_map[$do['node_type']] ?? ucfirst($do['node_type']);
            ?>
            <div style="display:flex;align-items:center;gap:16px;padding:12px 16px;background:#FFFBF4;border:1px solid #FEF3C7;border-radius:8px;">
                <div style="font-size:24px;font-weight:700;color:#B45309;min-width:64px;text-align:right;">
                    <?php echo esc_html($do['drop_off_pct']); ?>%
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;color:#26233A;font-weight:600;line-height:1.35;">
                        <?php echo esc_html($do['node_label']); ?>
                        <?php if ($type_pretty) : ?>
                            <span style="background:#FEF3C7;color:#92400E;padding:1px 7px;border-radius:4px;font-size:11px;margin-left:6px;vertical-align:middle;font-weight:500;"><?php echo esc_html($type_pretty); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#64748B;margin-top:2px;">
                        <?php
                        /* translators: %1$s: visitor count, %2$s: missed visitor count. */
                        echo esc_html(sprintf(__('%1$s visitors reached; %2$s never got here', 'dialogawp'), number_format($do['views']), number_format($do['missed'])));
                        ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($is_pro) : ?>
            <div style="text-align:right;padding-top:4px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-pro-analytics&tab=funnel&flow_id=' . intval($active_flow_dash->id))); ?>"
                   style="font-size:13px;">
                    <?php echo esc_html__('See full funnel breakdown', 'dialogawp'); ?> &rarr;
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Flows Overview -->
    <?php
    $all_flows = $wpdb->get_results("SELECT id, name, is_active, created_at FROM {$wpdb->prefix}dialogawp_flows ORDER BY created_at DESC LIMIT 5");
    ?>
    <?php if (!empty($all_flows)) : ?>
    <div class="dialogawp-dash-card" style="margin-bottom: 20px;">
        <div class="dialogawp-dash-card-header">
            <h3><?php echo esc_html__('Your Flows', 'dialogawp'); ?></h3>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-builder')); ?>"><?php echo esc_html__('Manage Flows', 'dialogawp'); ?> &rarr;</a>
        </div>
        <table class="dialogawp-dash-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Flow Name', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Status', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Created', 'dialogawp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_flows as $flow) : ?>
                <tr>
                    <td><strong><?php echo esc_html($flow->name ?: __('Untitled Flow', 'dialogawp')); ?></strong></td>
                    <td>
                        <?php if ($flow->is_active) : ?>
                            <span class="dialogawp-dash-badge dialogawp-dash-badge-active"><?php echo esc_html__('Active', 'dialogawp'); ?></span>
                        <?php else : ?>
                            <span class="dialogawp-dash-badge dialogawp-dash-badge-inactive"><?php echo esc_html__('Inactive', 'dialogawp'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(wp_date('M j, Y', strtotime($flow->created_at), new DateTimeZone('UTC'))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="dialogawp-dash-grid">

        <!-- Recent Leads -->
        <div class="dialogawp-dash-card">
            <div class="dialogawp-dash-card-header">
                <h3><?php echo esc_html__('Recent Leads', 'dialogawp'); ?></h3>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads')); ?>"><?php echo esc_html__('View All', 'dialogawp'); ?> &rarr;</a>
            </div>
            <?php if (!empty($recent_leads)) : ?>
            <table class="dialogawp-dash-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Name', 'dialogawp'); ?></th>
                        <th><?php echo esc_html__('Email', 'dialogawp'); ?></th>
                        <th><?php echo esc_html__('Date', 'dialogawp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_leads as $lead) :
                        $data = json_decode($lead->lead_data, true) ?: array();
                        $name = $data['name'] ?? $data['full_name'] ?? '-';
                        $email = $data['email'] ?? '-';
                    ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($email); ?></td>
                        <td><?php echo esc_html(wp_date('M j', strtotime($lead->created_at), new DateTimeZone('UTC'))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <div class="dialogawp-dash-empty">
                <p><?php echo esc_html__("No leads captured yet. Once visitors fill out your chatbot forms, they'll appear here.", 'dialogawp'); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="dialogawp-dash-card">
            <div class="dialogawp-dash-card-header">
                <h3><?php echo esc_html__('Quick Links', 'dialogawp'); ?></h3>
            </div>
            <div class="dialogawp-dash-links">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-builder')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-edit" style="color: #007D75;"></span>
                    <div>
                        <strong><?php echo esc_html__('Flow Builder', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Create and edit chatbot flows', 'dialogawp'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-groups" style="color: #16A34A;"></span>
                    <div>
                        <strong><?php echo esc_html__('Leads', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('View and export captured leads', 'dialogawp'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-analytics')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-chart-bar" style="color: #EA580C;"></span>
                    <div>
                        <strong><?php echo esc_html__('Analytics', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Conversation trends and stats', 'dialogawp'); ?></span>
                    </div>
                </a>
                <?php
                // Pro-gated links: Pro users go to the actual feature.
                // Free users get sent to dialogawp.io/pricing in a new tab
                // (clearer than routing them to a partial-featured page
                // that just shows an upgrade block at the bottom).
                $is_pro = function_exists('dialogawp_is_pro') && dialogawp_is_pro();
                $upgrade_url = function_exists('dialogawp_upgrade_url') ? dialogawp_upgrade_url() : 'https://www.litiobyte.com/labs-detalle.php?slug=dialogawp';
                $convo_url = $is_pro
                    ? admin_url('admin.php?page=dialogawp-pro-analytics&tab=conversations')
                    : $upgrade_url;
                $adv_url = $is_pro
                    ? admin_url('admin.php?page=dialogawp-pro-analytics&tab=performance')
                    : $upgrade_url;
                $gated_class    = $is_pro ? 'dialogawp-dash-link' : 'dialogawp-dash-link is-gated';
                $gated_target   = $is_pro ? '' : ' target="_blank" rel="noopener"';
                ?>
                <a href="<?php echo esc_url($convo_url); ?>" class="<?php echo esc_attr($gated_class); ?>"<?php echo wp_kses_post($gated_target); ?>>
                    <span class="dashicons dashicons-format-chat" style="color: #7C3AED;"></span>
                    <div>
                        <strong><?php echo esc_html__('Conversations', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Read every chat your visitors had', 'dialogawp'); ?></span>
                    </div>
                    <?php if (!$is_pro) : ?><span class="dialogawp-pro-pill">Pro</span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url($adv_url); ?>" class="<?php echo esc_attr($gated_class); ?>"<?php echo wp_kses_post($gated_target); ?>>
                    <span class="dashicons dashicons-chart-pie" style="color: #DB2777;"></span>
                    <div>
                        <strong><?php echo esc_html__('Advanced Analytics', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Funnel, heatmap, flow performance', 'dialogawp'); ?></span>
                    </div>
                    <?php if (!$is_pro) : ?><span class="dialogawp-pro-pill">Pro</span><?php endif; ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-ai-settings')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-admin-site-alt3" style="color: #7C3AED;"></span>
                    <div>
                        <strong><?php echo esc_html__('AI Settings', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Knowledge base and AI config', 'dialogawp'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-wizard')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-welcome-learn-more" style="color: #0891B2;"></span>
                    <div>
                        <strong><?php echo esc_html__('Setup Wizard', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Guided chatbot setup', 'dialogawp'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings')); ?>" class="dialogawp-dash-link">
                    <span class="dashicons dashicons-admin-generic" style="color: #64748B;"></span>
                    <div>
                        <strong><?php echo esc_html__('Settings', 'dialogawp'); ?></strong>
                        <span><?php echo esc_html__('Widget appearance and behavior', 'dialogawp'); ?></span>
                    </div>
                </a>
            </div>
        </div>

    </div>

</div>

<?php if ($wizard_resume) : ?>
<?php
$dialogawp_wizard_dismiss_js = "(function() {\n"
    . "    var t = function(s) { return (dialogawpAdmin.i18n || {})[s] || s; };\n"
    . "    var btn = document.getElementById('dialogawp-wizard-resume-dismiss');\n"
    . "    var card = document.getElementById('dialogawp-wizard-resume-card');\n"
    . "    if (!btn || !card) return;\n"
    . "    btn.addEventListener('click', function() {\n"
    . "        btn.disabled = true;\n"
    . "        btn.textContent = t('Dismissing...');\n"
    . "        var fd = new FormData();\n"
    . "        fd.append('action', 'dialogawp_wizard_dismiss_state');\n"
    . "        fd.append('nonce', '" . esc_js(wp_create_nonce('dialogawp_nonce')) . "');\n"
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
wp_add_inline_script('dialogawp-admin', $dialogawp_wizard_dismiss_js);
?>
<?php endif; ?>
