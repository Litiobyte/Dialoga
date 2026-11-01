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
    <div class="dialogawp-leads-header">
        <div>
            <div class="dialogawp-leads-title-row">
                <h1><?php echo esc_html__('DialogaWP Leads', 'dialogawp'); ?></h1>
                <?php if ($show_admin_only) : ?>
                    <span class="dialogawp-leads-view-badge"><?php echo esc_html__('Admin test sessions only', 'dialogawp'); ?></span>
                <?php endif; ?>
            </div>
            <p class="dialogawp-leads-description">
                <?php
                /* translators: %d: number of lead records shown. */
                echo esc_html(sprintf(_n('%d lead record shown', '%d lead records shown', count($leads), 'dialogawp'), count($leads)));
                ?>
                <?php if ($tag_filter) : ?>
                    <span class="dialogawp-leads-current-filter">
                        <?php
                        /* translators: %s: active tag filter. */
                        echo esc_html(sprintf(__('Tagged “%s”', 'dialogawp'), $tag_filter));
                        ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($show_admin_only) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads')); ?>" class="button">&larr; <?php echo esc_html__('Back to public leads', 'dialogawp'); ?></a>
        <?php endif; ?>
    </div>

    <?php
    if (!$show_admin_only && !$include_admin && $has_admin_flag_col) {
        $admin_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE is_admin_session = 1");
        if ($admin_count > 0) :
    ?>
        <p class="dialogawp-leads-admin-note">
            <?php
            /* translators: %d: hidden admin test lead count. */
            echo esc_html(sprintf(_n('%d admin test lead hidden', '%d admin test leads hidden', $admin_count, 'dialogawp'), $admin_count));
            ?> &mdash;
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-leads&show=admin')); ?>"><?php echo esc_html__('view', 'dialogawp'); ?></a>
            &middot; <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-settings')); ?>"><?php echo esc_html__('change in Settings', 'dialogawp'); ?></a>
        </p>
    <?php endif; } ?>

    <div class="dialogawp-leads-toolbar">
        <nav class="dialogawp-leads-filters" aria-label="<?php echo esc_attr__('Filter leads by tag', 'dialogawp'); ?>">
            <?php if (!empty($all_tags)) : ?>
            <span class="dialogawp-leads-filter-label"><?php echo esc_html__('Filter by tag:', 'dialogawp'); ?></span>
            <a href="<?php echo esc_url($base_url); ?>"
               class="dialogawp-filter-tag dialogawp-filter-tag--all<?php echo $tag_filter ? '' : ' is-active'; ?>"
               <?php echo $tag_filter ? '' : 'aria-current="page"'; ?>>
                <?php echo esc_html__('All', 'dialogawp'); ?>
            </a>
            <?php foreach ($all_tags as $t) :
                $active = ($tag_filter === $t);
                list($bg, $text) = dialogawp_tag_color($t);
            ?>
            <a href="<?php echo esc_url($base_url . '&tag=' . urlencode($t)); ?>"
               class="dialogawp-filter-tag<?php echo $active ? ' is-active' : ''; ?>"
               <?php echo $active ? 'aria-current="page"' : ''; ?>
               style="background:<?php echo esc_attr($active ? $text : $bg); ?>;
                       color:<?php echo esc_attr($active ? '#fff' : $text); ?>;
                       border:1px solid <?php echo esc_attr($text); ?>;">
                <?php echo esc_html($t); ?>
            </a>
            <?php endforeach; ?>
            <?php else : ?>
                <span class="dialogawp-leads-filter-label"><?php echo esc_html__('No tags available', 'dialogawp'); ?></span>
            <?php endif; ?>
        </nav>
        <a href="<?php echo esc_url($export_url); ?>" class="button button-primary dialogawp-leads-export">
            <span class="dashicons dashicons-download" aria-hidden="true"></span> <?php echo esc_html__('Export CSV', 'dialogawp'); ?>
        </a>
    </div>

    <!-- Datalist for tag autocomplete (populated from existing tags) -->
    <datalist id="dialogawp-tag-suggestions">
        <?php foreach ($all_tags as $t) : ?>
        <option value="<?php echo esc_attr($t); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <?php if (empty($leads)) : ?>
        <div class="dialogawp-notice dialogawp-leads-empty">
            <p><?php
            /* translators: %s: tag name. */
            echo $tag_filter ? sprintf(esc_html__('No leads with tag %s.', 'dialogawp'), '<strong>' . esc_html($tag_filter) . '</strong>') : esc_html__('No leads captured yet.', 'dialogawp');
            ?></p>
        </div>
    <?php else : ?>
        <div class="dialogawp-leads-table-shell">
        <p id="dialogawp-leads-table-help" class="screen-reader-text">
            <?php echo esc_html__('Scroll horizontally to view all lead record columns.', 'dialogawp'); ?>
        </p>
        <div class="dialogawp-leads-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Lead records table', 'dialogawp'); ?>" aria-describedby="dialogawp-leads-table-help">
        <table class="dialogawp-leads-table wp-list-table widefat striped">
            <caption>
                <?php
                /* translators: %d: number of lead records shown. */
                echo esc_html(sprintf(_n('Lead records: %d result', 'Lead records: %d results', count($leads), 'dialogawp'), count($leads)));
                ?>
            </caption>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col"><?php echo esc_html__('Name', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Email', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Phone', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Tags', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Created', 'dialogawp'); ?></th>
                    <th scope="col"><?php echo esc_html__('Actions', 'dialogawp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead) :
                    $tags_arr = $lead->tags ? json_decode($lead->tags, true) : array();
                    if (!is_array($tags_arr)) $tags_arr = array();
                ?>
                    <tr id="lead-row-<?php echo esc_attr($lead->id); ?>">
                        <th scope="row"><?php echo esc_html($lead->id); ?></th>
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
                            <span class="dialogawp-lead-status dialogawp-lead-status--<?php echo esc_attr(sanitize_html_class($lead->status)); ?>">
                                <?php echo esc_html($status_labels[$lead->status] ?? $lead->status); ?>
                            </span>
                        </td>
                        <td class="dialogawp-tags-cell" data-lead-id="<?php echo esc_attr($lead->id); ?>">
                            <div class="dialogawp-tags-list">
                                <?php foreach ($tags_arr as $tag) :
                                    list($bg, $text) = dialogawp_tag_color($tag);
                                ?>
                                <span class="dialogawp-tag-pill"
                                      data-tag="<?php echo esc_attr($tag); ?>"
                                       style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($text); ?>;border-color:<?php echo esc_attr($text); ?>;">
                                     <?php echo esc_html($tag); ?>
                                     <button type="button" class="dialogawp-tag-remove"
                                             onclick="dialogawpLeads.removeTag(<?php echo esc_attr($lead->id); ?>, '<?php echo esc_js($tag); ?>')"
                                             aria-label="<?php
                                             /* translators: 1: tag name, 2: lead ID. */
                                             echo esc_attr(sprintf(__('Remove tag %1$s from lead %2$d', 'dialogawp'), $tag, $lead->id));
                                             ?>">&times;</button>
                                </span>
                                <?php endforeach; ?>
                                 <button type="button" class="dialogawp-tag-add-btn"
                                         onclick="dialogawpLeads.showAddTag(<?php echo esc_attr($lead->id); ?>)"
                                         aria-expanded="false"
                                         aria-controls="tag-input-<?php echo esc_attr($lead->id); ?>"
                                         aria-label="<?php echo esc_attr(sprintf(__('Add tag to lead %d', 'dialogawp'), $lead->id)); ?>">
                                     + <?php echo esc_html__('tag', 'dialogawp'); ?>
                                 </button>
                                 <span class="dialogawp-tag-spinner" id="tag-spinner-<?php echo esc_attr($lead->id); ?>" hidden role="status">
                                     <span class="dialogawp-spinner-dot" aria-hidden="true"></span>
                                     <span><?php echo esc_html__('Saving...', 'dialogawp'); ?></span>
                                 </span>
                             </div>
                            <div class="dialogawp-tag-input-wrap" id="tag-input-<?php echo esc_attr($lead->id); ?>" hidden>
                                 <input type="text" placeholder="<?php echo esc_attr__('tag name', 'dialogawp'); ?>"
                                        list="dialogawp-tag-suggestions"
                                        autocomplete="off"
                                        aria-label="<?php echo esc_attr(sprintf(__('Tag name for lead %d', 'dialogawp'), $lead->id)); ?>"
                                        onkeydown="if(event.key==='Enter'){event.preventDefault();dialogawpLeads.addTag(<?php echo esc_attr($lead->id); ?>,this.value);}"
                                        id="tag-input-field-<?php echo esc_attr($lead->id); ?>" />
                                <button type="button" class="button button-small button-primary" onclick="dialogawpLeads.addTag(<?php echo esc_attr($lead->id); ?>,document.getElementById('tag-input-field-<?php echo esc_attr($lead->id); ?>').value)"><?php echo esc_html__('Add', 'dialogawp'); ?></button>
                                <button type="button" class="button button-small" onclick="dialogawpLeads.hideAddTag(<?php echo esc_attr($lead->id); ?>)"
                                        aria-label="<?php echo esc_attr(sprintf(__('Cancel adding a tag to lead %d', 'dialogawp'), $lead->id)); ?>"><?php echo esc_html__('Cancel', 'dialogawp'); ?></button>
                            </div>
                        </td>
                        <td><?php echo esc_html(wp_date('M d, Y g:i A', strtotime($lead->created_at), new DateTimeZone('UTC'))); ?></td>
                        <td class="dialogawp-lead-actions">
                            <button class="button button-small"
                                    onclick="dialogawpLeads.viewLead(<?php echo esc_attr($lead->id); ?>)">
                                <?php echo esc_html__('View', 'dialogawp'); ?>
                            </button>
                             <button class="button button-small dialogawp-lead-delete-btn"
                                    onclick="dialogawpLeads.deleteLead(<?php echo esc_attr($lead->id); ?>, '<?php
                                    /* translators: %d: lead ID. */
                                    echo esc_js($lead->name ?: $lead->email ?: sprintf(__('Lead #%d', 'dialogawp'), $lead->id));
                                     ?>')">
                                <?php echo esc_html__('Delete', 'dialogawp'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php endif; ?>
</div>

<!-- Lead detail modal -->
<div id="dialogawp-lead-modal" class="dialogawp-lead-modal" hidden>
    <div class="dialogawp-lead-dialog" role="dialog" aria-modal="true" aria-labelledby="dialogawp-lead-modal-title" aria-describedby="dialogawp-lead-modal-context">
        <div class="dialogawp-lead-dialog-header">
            <div>
                <h2 id="dialogawp-lead-modal-title"><?php echo esc_html__('Lead Details', 'dialogawp'); ?></h2>
                <p id="dialogawp-lead-modal-context"><?php echo esc_html__('Loading lead record', 'dialogawp'); ?></p>
            </div>
            <button type="button" class="dialogawp-lead-dialog-close" onclick="dialogawpLeads.closeModal()" aria-label="<?php echo esc_attr__('Close lead details', 'dialogawp'); ?>">&times;</button>
        </div>
        <div id="dialogawp-lead-modal-body" class="dialogawp-lead-dialog-body" aria-live="polite" aria-busy="true">
            <div class="dialogawp-ld-loading" role="status"><?php echo esc_html__('Loading...', 'dialogawp'); ?></div>
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
    previousFocus: null,

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
        var inputWrap = document.getElementById('tag-input-' + leadId);
        var button = document.querySelector('#lead-row-' + leadId + ' .dialogawp-tag-add-btn');
        inputWrap.hidden = false;
        if (button) button.setAttribute('aria-expanded', 'true');
        document.getElementById('tag-input-field-' + leadId).focus();
    },

    hideAddTag: function(leadId) {
        var inputWrap = document.getElementById('tag-input-' + leadId);
        var button = document.querySelector('#lead-row-' + leadId + ' .dialogawp-tag-add-btn');
        if (inputWrap) inputWrap.hidden = true;
        if (button) {
            button.setAttribute('aria-expanded', 'false');
            button.focus();
        }
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
        row.classList.toggle('is-busy', busy);
        row.setAttribute('aria-busy', busy ? 'true' : 'false');
        var spinner = document.getElementById('tag-spinner-' + leadId);
        if (spinner) spinner.hidden = !busy;
    },

    saveTags: function(leadId, tags) {
        var self = this;
        // Close any open tag input for this row
        var inputWrap = document.getElementById('tag-input-' + leadId);
        if (inputWrap) inputWrap.hidden = true;
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
        var context = document.getElementById('dialogawp-lead-modal-context');

        this.previousFocus = document.activeElement;
        title.textContent = t('Lead Details');
        context.textContent = t('Loading lead record');
        body.setAttribute('aria-busy', 'true');
        body.innerHTML = '<div class="dialogawp-ld-loading" role="status">' + self.escapeHtml(t('Loading...')) + '</div>';
        modal.hidden = false;
        document.body.classList.add('dialogawp-modal-open');
        modal.querySelector('.dialogawp-lead-dialog-close').focus();

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
                    body.setAttribute('aria-busy', 'false');
                }
            })
            .catch(function() {
                body.innerHTML = '<div class="dialogawp-ld-empty">' + self.escapeHtml(t('Network error loading lead details.')) + '</div>';
                body.setAttribute('aria-busy', 'false');
            });
    },

    renderLeadDetails: function(payload) {
        var self = this;
        var lead = payload.lead || {};
        var conv = payload.conversation;
        var transcript = payload.transcript || [];
        var title = document.getElementById('dialogawp-lead-modal-title');
        var context = document.getElementById('dialogawp-lead-modal-context');
        var body = document.getElementById('dialogawp-lead-modal-body');
        var status = lead.status || 'new';
        var statusLabel = ({new: t('New'), contacted: t('Contacted'), qualified: t('Qualified'), converted: t('Converted'), closed: t('Closed')})[status] || status;

        title.textContent = lead.name || t('Lead #%d').replace('%d', lead.id);
        context.textContent = t('Lead #%d').replace('%d', lead.id) + ' · ' + statusLabel;

        var tagsHtml = (lead.tags && lead.tags.length)
            ? lead.tags.map(function(t) {
                return '<span class="dialogawp-ld-tag">' + self.escapeHtml(t) + '</span>';
            }).join('')
            : '<span class="dialogawp-ld-none">' + self.escapeHtml(t('none')) + '</span>';

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
            '<dt>' + self.escapeHtml(t('Status')) + '</dt><dd><span class="dialogawp-lead-status dialogawp-lead-status--' + self.escapeHtml(status) + '">' + self.escapeHtml(statusLabel) + '</span></dd>' +
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

        var transcriptHtml = '<div class="dialogawp-ld-section"><h3>' + self.escapeHtml(t('Transcript')) + '</h3>';
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
                            (item.timestamp ? '<div class="dialogawp-ld-msg-time">' + self.escapeHtml(item.timestamp) + '</div>' : '') +
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
        body.setAttribute('aria-busy', 'false');
    },

    closeModal: function() {
        var modal = document.getElementById('dialogawp-lead-modal');
        modal.hidden = true;
        document.body.classList.remove('dialogawp-modal-open');
        if (this.previousFocus && document.contains(this.previousFocus)) {
            this.previousFocus.focus();
        }
        this.previousFocus = null;
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
    var modal = document.getElementById('dialogawp-lead-modal');
    if (!modal || modal.hidden) return;

    if (e.key === 'Escape') {
        dialogawpLeads.closeModal();
        return;
    }

    if (e.key === 'Tab') {
        var focusable = modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
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
