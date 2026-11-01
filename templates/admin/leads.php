<?php
/**
 * Leads Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$leads_table = $wpdb->prefix . 'dialogawp_leads';

// Handle status update
if (isset($_POST['update_lead_status']) && check_admin_referer('dialogawp_update_lead')) {
    $lead_id    = intval($_POST['lead_id']);
    $new_status = sanitize_text_field(wp_unslash($_POST['status']));
    $wpdb->update($leads_table, array('status' => $new_status), array('id' => $lead_id), array('%s'), array('%d'));
    echo '<div class="notice notice-success"><p>' . esc_html__('Lead status updated!', 'dialogawp') . '</p></div>';
}

// Tag filter
$tag_filter = isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '';

// Admin-session filter — by default hide leads from admin testing sessions
// so the leads list reflects real public visitors. Toggle in Settings.
//
// v0.38.17 — defensive column existence check so we don't throw
// "Unknown column" errors on installs where migration hasn't run yet.
$dialogawp_leads_cols   = $wpdb->get_col("DESCRIBE {$leads_table}", 0);
$has_admin_flag_col  = in_array('is_admin_session', $dialogawp_leads_cols, true);
$dialogawp_settings_for_filter = get_option('dialogawp_settings', array());
$include_admin = !empty($dialogawp_settings_for_filter['include_admin_sessions']);
$admin_clause  = ($include_admin || !$has_admin_flag_col) ? '' : ' AND is_admin_session = 0';

// Allow viewing admin-test rows via ?show=admin for one-off inspection
$show_admin_only = isset($_GET['show']) && $_GET['show'] === 'admin' && $has_admin_flag_col;
if ($show_admin_only) {
    $admin_clause = ' AND is_admin_session = 1';
}

// Build query
if ($tag_filter) {
    $tag_like = '%' . $wpdb->esc_like('"' . $tag_filter . '"') . '%';
    $leads = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $leads_table WHERE tags LIKE %s {$admin_clause} ORDER BY created_at DESC LIMIT 200",
        $tag_like
    ));
} else {
    $leads = $wpdb->get_results("SELECT * FROM $leads_table WHERE 1=1 {$admin_clause} ORDER BY created_at DESC LIMIT 200");
}

// CSV export handled earlier in admin_init (see DialogaWP::maybe_export_leads_csv)

// Collect all unique tags for filter dropdown
$all_leads_for_tags = $wpdb->get_col("SELECT tags FROM $leads_table WHERE tags IS NOT NULL AND tags != ''");
$all_tags = array();
foreach ($all_leads_for_tags as $tags_json) {
    $arr = json_decode($tags_json, true);
    if (is_array($arr)) {
        $all_tags = array_merge($all_tags, $arr);
    }
}
$all_tags = array_values(array_unique(array_filter($all_tags)));
sort($all_tags);

// Tag colors — hash-based for consistency
function dialogawp_tag_color($tag) {
    $colors = array(
        array('#dbeafe','#190F4F'), array('#dcfce7','#15803d'), array('#fef9c3','#a16207'),
        array('#fce7f3','#be185d'), array('#ede9fe','#6d28d9'), array('#ffedd5','#c2410c'),
        array('#e0f2fe','#0369a1'), array('#f0fdf4','#166534'),
    );
    $idx = abs(crc32($tag)) % count($colors);
    return $colors[$idx];
}

$base_url = admin_url('admin.php?page=dialogawp-leads');
$status_labels = array(
    'new' => __('New', 'dialogawp'),
    'contacted' => __('Contacted', 'dialogawp'),
    'qualified' => __('Qualified', 'dialogawp'),
    'converted' => __('Converted', 'dialogawp'),
    'closed' => __('Closed', 'dialogawp'),
);
$export_url = wp_nonce_url(
    $base_url . ($tag_filter ? '&tag=' . urlencode($tag_filter) : '') . '&export=csv',
    'dialogawp_export_leads'
);
?>

<div class="wrap dialogawp-leads-wrap">
    <h1><?php echo esc_html__('DialogaWP Leads', 'dialogawp'); ?>
        <?php if ($show_admin_only) : ?>
            <span style="font-size:13px;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:10px;vertical-align:middle;margin-left:8px;"><?php echo esc_html__('Admin test sessions only', 'dialogawp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads')); ?>" style="font-size:13px;margin-left:8px;text-decoration:none;">&larr; <?php echo esc_html__('Back to public leads', 'dialogawp'); ?></a>
        <?php endif; ?>
    </h1>

    <?php
    if (!$show_admin_only && !$include_admin && $has_admin_flag_col) {
        $admin_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE is_admin_session = 1");
        if ($admin_count > 0) :
    ?>
        <p style="color:#6b7280;font-size:13px;margin:-4px 0 12px;">
            <?php
            /* translators: %d: hidden admin test lead count. */
            echo esc_html(sprintf(_n('%d admin test lead hidden', '%d admin test leads hidden', $admin_count, 'dialogawp'), $admin_count));
            ?> &mdash;
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads&show=admin')); ?>"><?php echo esc_html__('view', 'dialogawp'); ?></a>
            &middot; <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings')); ?>"><?php echo esc_html__('change in Settings', 'dialogawp'); ?></a>
        </p>
    <?php endif; } ?>

    <!-- Tag filter + Export bar -->
    <?php if (!empty($leads)) : ?>
    <div style="margin-bottom:16px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1 1 auto;">
            <?php if (!empty($all_tags)) : ?>
            <span style="font-size:13px;color:#666;font-weight:600;"><?php echo esc_html__('Filter by tag:', 'dialogawp'); ?></span>
            <a href="<?php echo esc_url($base_url); ?>"
               style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-decoration:none;
                      background:<?php echo $tag_filter ? '#F7F8FC' : '#190F4F'; ?>;
                      color:<?php echo $tag_filter ? '#374151' : '#fff'; ?>;border:1px solid #E5E7EF;">
                <?php echo esc_html__('All', 'dialogawp'); ?>
            </a>
            <?php foreach ($all_tags as $t) :
                $active = ($tag_filter === $t);
                list($bg, $text) = dialogawp_tag_color($t);
            ?>
            <a href="<?php echo esc_url($base_url . '&tag=' . urlencode($t)); ?>"
               style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-decoration:none;
                      background:<?php echo esc_attr($active ? $text : $bg); ?>;
                      color:<?php echo esc_attr($active ? '#fff' : $text); ?>;
                      border:1px solid <?php echo esc_attr($text); ?>;">
                <?php echo esc_html($t); ?>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a href="<?php echo esc_url($export_url); ?>" class="button button-primary" style="flex-shrink:0;">
            <span class="dashicons dashicons-download" style="margin-top:3px;"></span> <?php echo esc_html__('Export CSV', 'dialogawp'); ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Datalist for tag autocomplete (populated from existing tags) -->
    <datalist id="dialogawp-tag-suggestions">
        <?php foreach ($all_tags as $t) : ?>
        <option value="<?php echo esc_attr($t); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <?php if (empty($leads)) : ?>
        <div class="dialogawp-notice">
            <p><?php
            /* translators: %s: tag name. */
            echo $tag_filter ? sprintf(esc_html__('No leads with tag %s.', 'dialogawp'), '<strong>' . esc_html($tag_filter) . '</strong>') : esc_html__('No leads captured yet.', 'dialogawp');
            ?></p>
        </div>
    <?php else : ?>
        <table class="dialogawp-leads-table wp-list-table widefat striped" style="border-radius:6px;overflow:hidden;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo esc_html__('Name', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Email', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Phone', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Status', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Tags', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Created', 'dialogawp'); ?></th>
                    <th><?php echo esc_html__('Actions', 'dialogawp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead) :
                    $tags_arr = $lead->tags ? json_decode($lead->tags, true) : array();
                    if (!is_array($tags_arr)) $tags_arr = array();
                ?>
                    <tr id="lead-row-<?php echo esc_attr($lead->id); ?>">
                        <td><?php echo esc_html($lead->id); ?></td>
                        <td><?php echo esc_html($lead->name ?: '—'); ?></td>
                        <td>
                            <?php if ($lead->email) : ?>
                                <a href="mailto:<?php echo esc_attr($lead->email); ?>"><?php echo esc_html($lead->email); ?></a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lead->phone) : ?>
                                <a href="tel:<?php echo esc_attr($lead->phone); ?>"><?php echo esc_html($lead->phone); ?></a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="dialogawp-lead-status <?php echo esc_attr($lead->status); ?>">
                                <?php echo esc_html($status_labels[$lead->status] ?? $lead->status); ?>
                            </span>
                        </td>
                        <td class="dialogawp-tags-cell" data-lead-id="<?php echo esc_attr($lead->id); ?>">
                            <div class="dialogawp-tags-list" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                <?php foreach ($tags_arr as $tag) :
                                    list($bg, $text) = dialogawp_tag_color($tag);
                                ?>
                                <span class="dialogawp-tag-pill"
                                      data-tag="<?php echo esc_attr($tag); ?>"
                                      style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($text); ?>;border:1px solid <?php echo esc_attr($text); ?>;">
                                    <?php echo esc_html($tag); ?>
                                    <button type="button" class="dialogawp-tag-remove"
                                            onclick="dialogawpLeads.removeTag(<?php echo esc_attr($lead->id); ?>, '<?php echo esc_js($tag); ?>')"
                                            style="background:none;border:none;cursor:pointer;padding:0;line-height:1;color:inherit;font-size:13px;opacity:.7;"
                                            title="<?php echo esc_attr__('Remove tag', 'dialogawp'); ?>">×</button>
                                </span>
                                <?php endforeach; ?>
                                <button type="button" class="dialogawp-tag-add-btn"
                                        onclick="dialogawpLeads.showAddTag(<?php echo esc_attr($lead->id); ?>)"
                                        style="background:none;border:1px dashed #d1d5db;border-radius:10px;padding:2px 8px;font-size:11px;color:#9ca3af;cursor:pointer;">
                                    + <?php echo esc_html__('tag', 'dialogawp'); ?>
                                </button>
                                <span class="dialogawp-tag-spinner" id="tag-spinner-<?php echo esc_attr($lead->id); ?>" style="display:none;">
                                    <span class="dialogawp-spinner-dot"></span>
                                    <span style="font-size:11px;color:#6b7280;"><?php echo esc_html__('Saving...', 'dialogawp'); ?></span>
                                </span>
                            </div>
                            <div class="dialogawp-tag-input-wrap" id="tag-input-<?php echo esc_attr($lead->id); ?>" style="display:none;margin-top:4px;">
                                <input type="text" placeholder="<?php echo esc_attr__('tag name', 'dialogawp'); ?>"
                                       list="dialogawp-tag-suggestions"
                                       autocomplete="off"
                                       style="padding:3px 6px;font-size:12px;border:1px solid #d1d5db;border-radius:4px;width:140px;"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();dialogawpLeads.addTag(<?php echo esc_attr($lead->id); ?>,this.value);}"
                                       id="tag-input-field-<?php echo esc_attr($lead->id); ?>" />
                                <button type="button" onclick="dialogawpLeads.addTag(<?php echo esc_attr($lead->id); ?>,document.getElementById('tag-input-field-<?php echo esc_attr($lead->id); ?>').value)"
                                        style="margin-left:4px;padding:3px 8px;font-size:12px;background:#190F4F;color:#fff;border:none;border-radius:4px;cursor:pointer;"><?php echo esc_html__('Add', 'dialogawp'); ?></button>
                                <button type="button" onclick="document.getElementById('tag-input-<?php echo esc_attr($lead->id); ?>').style.display='none'"
                                        style="margin-left:2px;padding:3px 6px;font-size:12px;background:#F7F8FC;color:#374151;border:1px solid #E5E7EF;border-radius:4px;cursor:pointer;">✕</button>
                            </div>
                        </td>
                        <td><?php echo esc_html(wp_date('M d, Y g:i A', strtotime($lead->created_at), new DateTimeZone('UTC'))); ?></td>
                        <td style="white-space:nowrap;">
                            <button class="button button-small"
                                    onclick="dialogawpLeads.viewLead(<?php echo esc_attr($lead->id); ?>)">
                                <?php echo esc_html__('View', 'dialogawp'); ?>
                            </button>
                            <button class="button button-small dialogawp-lead-delete-btn"
                                    onclick="dialogawpLeads.deleteLead(<?php echo esc_attr($lead->id); ?>, '<?php
                                    /* translators: %d: lead ID. */
                                    echo esc_js($lead->name ?: $lead->email ?: sprintf(__('Lead #%d', 'dialogawp'), $lead->id));
                                    ?>')"
                                    style="color:#b91c1c;border-color:#fecaca;">
                                <?php echo esc_html__('Delete', 'dialogawp'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Lead detail modal -->
<div id="dialogawp-lead-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:99999;align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:12px;max-width:780px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:1px solid #E5E7EF;display:flex;align-items:center;justify-content:space-between;background:#F7F8FC;">
            <h2 id="dialogawp-lead-modal-title" style="margin:0;font-size:18px;color:#26233A;"><?php echo esc_html__('Lead Details', 'dialogawp'); ?></h2>
            <button type="button" onclick="dialogawpLeads.closeModal()" style="background:none;border:none;font-size:22px;color:#6b7280;cursor:pointer;line-height:1;padding:0;">&times;</button>
        </div>
        <div id="dialogawp-lead-modal-body" style="padding:24px;overflow:auto;flex:1;">
            <div style="text-align:center;padding:40px 0;color:#6b7280;"><?php echo esc_html__('Loading...', 'dialogawp'); ?></div>
        </div>
    </div>
</div>

<?php
// Screen CSS lives in assets/css/screen-leads.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>

<?php
// wp.org: no output buffering. The script body is a nowdoc (no
// interpolation, so jQuery $ and regex escapes survive intact) and the
// two runtime values are substituted after the fact via strtr().
$dialogawp_leads_js = strtr(<<<'JS'
(function() {
var t = function(s) { return (dialogawpAdmin.i18n || {})[s] || s; };
window.dialogawpLeads = {
    nonce: '%%DIALOGAWP_NONCE%%',
    ajaxUrl: '%%DIALOGAWP_AJAXURL%%',

    escapeHtml: function(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    getLeadTags: function(leadId) {
        var pills = document.querySelectorAll('#lead-row-' + leadId + ' .dialogawp-tag-pill');
        var tags = [];
        pills.forEach(function(pill) { tags.push(pill.dataset.tag); });
        return tags;
    },

    showAddTag: function(leadId) {
        document.getElementById('tag-input-' + leadId).style.display = 'flex';
        document.getElementById('tag-input-field-' + leadId).focus();
    },

    addTag: function(leadId, tagValue) {
        tagValue = tagValue.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-');
        if (!tagValue) return;
        var tags = this.getLeadTags(leadId);
        if (tags.indexOf(tagValue) !== -1) return;
        tags.push(tagValue);
        this.saveTags(leadId, tags);
    },

    removeTag: function(leadId, tagValue) {
        var tags = this.getLeadTags(leadId).filter(function(t) { return t !== tagValue; });
        this.saveTags(leadId, tags);
    },

    setRowBusy: function(leadId, busy) {
        var row = document.getElementById('lead-row-' + leadId);
        if (!row) return;
        row.style.opacity = busy ? '0.5' : '';
        row.style.pointerEvents = busy ? 'none' : '';
        var spinner = document.getElementById('tag-spinner-' + leadId);
        if (spinner) spinner.style.display = busy ? 'inline-flex' : 'none';
    },

    saveTags: function(leadId, tags) {
        var self = this;
        // Close any open tag input for this row
        var inputWrap = document.getElementById('tag-input-' + leadId);
        if (inputWrap) inputWrap.style.display = 'none';
        self.setRowBusy(leadId, true);
        var data = new FormData();
        data.append('action', 'dialogawp_update_lead_tags');
        data.append('nonce', this.nonce);
        data.append('lead_id', leadId);
        tags.forEach(function(t) { data.append('tags[]', t); });

        fetch(this.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    self.setRowBusy(leadId, false);
                    alert(t('Failed to save tags. Please try again.'));
                }
            })
            .catch(function() {
                self.setRowBusy(leadId, false);
                alert(t('Network error saving tags. Please try again.'));
            });
    },

    deleteLead: function(leadId, label) {
        if (!window.confirm(t('Delete %s?\n\nThis removes the lead, its conversation, and clears it from your conversion rate. This cannot be undone.').replace('%s', function() { return label; }))) return;

        var self = this;
        this.setRowBusy(leadId, true);

        var data = new FormData();
        data.append('action', 'dialogawp_delete_lead');
        data.append('nonce', this.nonce);
        data.append('lead_id', leadId);

        fetch(this.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    var row = document.getElementById('lead-row-' + leadId);
                    if (row) row.parentNode.removeChild(row);
                } else {
                    self.setRowBusy(leadId, false);
                    alert((response.data && response.data.message) || t('Could not delete lead.'));
                }
            })
            .catch(function() {
                self.setRowBusy(leadId, false);
                alert(t('Network error deleting lead. Please try again.'));
            });
    },

    viewLead: function(leadId) {
        var self = this;
        var modal = document.getElementById('dialogawp-lead-modal');
        var body = document.getElementById('dialogawp-lead-modal-body');
        var title = document.getElementById('dialogawp-lead-modal-title');

        title.textContent = t('Lead Details');
        body.innerHTML = '<div style="text-align:center;padding:40px 0;color:#6b7280;">' + self.escapeHtml(t('Loading...')) + '</div>';
        modal.style.display = 'flex';

        var data = new FormData();
        data.append('action', 'dialogawp_get_lead_details');
        data.append('nonce', this.nonce);
        data.append('lead_id', leadId);

        fetch(this.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    self.renderLeadDetails(response.data);
                } else {
                    body.innerHTML = '<div class="dialogawp-ld-empty">' + self.escapeHtml(t('Could not load lead details.')) + '</div>';
                }
            })
            .catch(function() {
                body.innerHTML = '<div class="dialogawp-ld-empty">' + self.escapeHtml(t('Network error loading lead details.')) + '</div>';
            });
    },

    renderLeadDetails: function(payload) {
        var self = this;
        var lead = payload.lead || {};
        var conv = payload.conversation;
        var transcript = payload.transcript || [];
        var title = document.getElementById('dialogawp-lead-modal-title');
        var body = document.getElementById('dialogawp-lead-modal-body');

        title.textContent = t('Lead #%d').replace('%d', lead.id) + (lead.name ? ' — ' + lead.name : '');

        var tagsHtml = (lead.tags && lead.tags.length)
            ? lead.tags.map(function(t) {
                return '<span style="display:inline-block;padding:2px 8px;margin:2px;background:#eef2ff;color:#3730a3;border-radius:10px;font-size:11px;font-weight:600;">' + self.escapeHtml(t) + '</span>';
            }).join('')
            : '<span class="dialogawp-ld-empty" style="padding:0;">' + self.escapeHtml(t('none')) + '</span>';

        var extraFieldsHtml = '';
        var skip = { name: 1, email: 1, phone: 1 };
        Object.keys(lead.lead_data || {}).forEach(function(k) {
            if (skip[k]) return;
            extraFieldsHtml += '<dt>' + self.escapeHtml(k) + '</dt><dd>' + self.escapeHtml(lead.lead_data[k]) + '</dd>';
        });

        var contactHtml = '' +
            '<dt>' + self.escapeHtml(t('Name')) + '</dt><dd>' + self.escapeHtml(lead.name || '—') + '</dd>' +
            '<dt>' + self.escapeHtml(t('Email')) + '</dt><dd>' + (lead.email ? '<a href="mailto:' + self.escapeHtml(lead.email) + '">' + self.escapeHtml(lead.email) + '</a>' : '—') + '</dd>' +
            '<dt>' + self.escapeHtml(t('Phone')) + '</dt><dd>' + (lead.phone ? '<a href="tel:' + self.escapeHtml(lead.phone) + '">' + self.escapeHtml(lead.phone) + '</a>' : '—') + '</dd>' +
            '<dt>' + self.escapeHtml(t('Status')) + '</dt><dd>' + self.escapeHtml(({new: t('New'), contacted: t('Contacted'), qualified: t('Qualified'), converted: t('Converted'), closed: t('Closed')})[lead.status || 'new'] || lead.status) + '</dd>' +
            '<dt>' + self.escapeHtml(t('Captured')) + '</dt><dd>' + self.escapeHtml(lead.created_at) + '</dd>' +
            '<dt>' + self.escapeHtml(t('Tags')) + '</dt><dd>' + tagsHtml + '</dd>' +
            extraFieldsHtml;

        var convHtml = '';
        if (conv) {
            convHtml = '' +
                '<div class="dialogawp-ld-section">' +
                    '<h3>' + self.escapeHtml(t('Conversation')) + '</h3>' +
                    '<dl class="dialogawp-ld-grid">' +
                        '<dt>' + self.escapeHtml(t('Flow')) + '</dt><dd>' + self.escapeHtml(conv.flow_name || '#' + conv.flow_id) + '</dd>' +
                        '<dt>' + self.escapeHtml(t('Page')) + '</dt><dd>' + (conv.page_url ? '<a href="' + self.escapeHtml(conv.page_url) + '" target="_blank" rel="noopener">' + self.escapeHtml(conv.page_url) + '</a>' : '—') + '</dd>' +
                        '<dt>' + self.escapeHtml(t('Started')) + '</dt><dd>' + self.escapeHtml(conv.created_at) + '</dd>' +
                        '<dt>IP</dt><dd>' + self.escapeHtml(conv.ip_address || '—') + '</dd>' +
                    '</dl>' +
                '</div>';
        }

        var transcriptHtml = '<div class="dialogawp-ld-section"><h3>' + self.escapeHtml(t('Conversation')) + '</h3>';
        if (!transcript.length) {
            transcriptHtml += '<div class="dialogawp-ld-empty">' + self.escapeHtml(t('No conversation recorded.')) + '</div>';
        } else {
            transcriptHtml += '<div class="dialogawp-ld-transcript">';
            transcript.forEach(function(item) {
                var isUser = item.type === 'user';
                var text = '';
                if (isUser) {
                    text = item.text || '';
                } else if (item.message) {
                    text = item.message.text || '';
                }
                if (!text) return;
                transcriptHtml += '' +
                    '<div class="dialogawp-ld-msg ' + (isUser ? 'user' : 'bot') + '">' +
                        '<div>' +
                            '<div class="dialogawp-ld-msg-bubble">' + self.escapeHtml(text) + '</div>' +
                            (item.timestamp ? '<div class="dialogawp-ld-msg-time" style="text-align:' + (isUser ? 'right' : 'left') + ';">' + self.escapeHtml(item.timestamp) + '</div>' : '') +
                        '</div>' +
                    '</div>';
            });
            transcriptHtml += '</div>';
        }
        transcriptHtml += '</div>';

        body.innerHTML =
            '<div class="dialogawp-ld-section">' +
                '<h3>' + self.escapeHtml(t('Contact')) + '</h3>' +
                '<dl class="dialogawp-ld-grid">' + contactHtml + '</dl>' +
            '</div>' +
            convHtml +
            transcriptHtml;
    },

    closeModal: function() {
        document.getElementById('dialogawp-lead-modal').style.display = 'none';
    }
};

// Close modal when clicking the backdrop (but not the inner content)
document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'dialogawp-lead-modal') {
        dialogawpLeads.closeModal();
    }
});

// ESC to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('dialogawp-lead-modal');
        if (modal && modal.style.display !== 'none') {
            dialogawpLeads.closeModal();
        }
    }
});
})();
JS
, array(
    '%%DIALOGAWP_NONCE%%'   => esc_js(wp_create_nonce('dialogawp_nonce')),
    '%%DIALOGAWP_AJAXURL%%' => esc_js(admin_url('admin-ajax.php')),
));
wp_add_inline_script('dialogawp-admin', $dialogawp_leads_js);
?>
