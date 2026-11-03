<?php
/**
 * Leads Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$dialoga_leads_table = $wpdb->prefix . 'dialoga_leads';

// Handle status update
if (isset($_POST['update_lead_status']) && check_admin_referer('dialoga_update_lead')) {
    $dialoga_lead_id    = isset($_POST['lead_id']) ? absint(wp_unslash($_POST['lead_id'])) : 0;
    $dialoga_new_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- admin write/update op; $wpdb->update() handles preparation internally.
    $wpdb->update($dialoga_leads_table, array('status' => $dialoga_new_status), array('id' => $dialoga_lead_id), array('%s'), array('%d'));
    echo '<div class="notice notice-success"><p>' . esc_html__('Lead status updated!', 'dialoga') . '</p></div>';
}

// Tag filter
$dialoga_tag_filter = isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '';

// Admin-session filter — by default hide leads from admin testing sessions
// so the leads list reflects real public visitors. Toggle in Settings.
//
// v0.38.17 — defensive column existence check so we don't throw
// "Unknown column" errors on installs where migration hasn't run yet.
$dialoga_leads_cols   = $wpdb->get_col($wpdb->prepare("DESCRIBE %i", $dialoga_leads_table), 0);
$dialoga_has_admin_flag_col  = in_array('is_admin_session', $dialoga_leads_cols, true);
$dialoga_settings_for_filter = get_option('dialoga_settings', array());
$dialoga_include_admin = !empty($dialoga_settings_for_filter['include_admin_sessions']);
$dialoga_admin_clause  = ($dialoga_include_admin || !$dialoga_has_admin_flag_col) ? '' : ' AND is_admin_session = 0';

// Allow viewing admin-test rows via ?show=admin for one-off inspection
// (only possible when the column exists). The predicate is a constant
// literal baked into each of the four query variants below, so no SQL
// fragment is ever interpolated at runtime.
$dialoga_show_admin_only = isset($_GET['show']) && $_GET['show'] === 'admin' && $dialoga_has_admin_flag_col;

if ($dialoga_tag_filter) {
    $dialoga_tag_like = '%' . $wpdb->esc_like('"' . $dialoga_tag_filter . '"') . '%';
    // 2026-11 — four fully literal result queries: phpcs treats any SQL held
    // in a variable as NotPrepared/Unescaped even when both ternary branches
    // are string literals, so the predicate is inslined per branch instead.
    $dialoga_leads = $dialoga_show_admin_only
        ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE tags LIKE %s AND is_admin_session = 1 ORDER BY created_at DESC LIMIT 200",
            $dialoga_leads_table,
            $dialoga_tag_like
        ))
        : $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE tags LIKE %s ORDER BY created_at DESC LIMIT 200",
            $dialoga_leads_table,
            $dialoga_tag_like
        ));
} else {
    $dialoga_leads = $dialoga_show_admin_only
        ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE is_admin_session = 1 ORDER BY created_at DESC LIMIT 200",
            $dialoga_leads_table
        ))
        : $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE 1=1 ORDER BY created_at DESC LIMIT 200",
            $dialoga_leads_table
        ));
}

// CSV export handled earlier in admin_init (see Dialoga::maybe_export_leads_csv)

// Collect all unique tags for filter dropdown
$dialoga_all_leads_for_tags = $wpdb->get_col($wpdb->prepare(
    "SELECT tags FROM %i WHERE tags IS NOT NULL AND tags != ''",
    $dialoga_leads_table
));
$dialoga_all_tags = array();
foreach ($dialoga_all_leads_for_tags as $dialoga_tags_json) {
    $dialoga_arr = json_decode($dialoga_tags_json, true);
    if (is_array($dialoga_arr)) {
        $dialoga_all_tags = array_merge($dialoga_all_tags, $dialoga_arr);
    }
}
$dialoga_all_tags = array_values(array_unique(array_filter($dialoga_all_tags)));
sort($dialoga_all_tags);

// Tag colors — hash-based for consistency
function dialoga_tag_color($tag) {
    $colors = array(
        array('#dbeafe','#190F4F'), array('#dcfce7','#15803d'), array('#fef9c3','#a16207'),
        array('#fce7f3','#be185d'), array('#ede9fe','#6d28d9'), array('#ffedd5','#c2410c'),
        array('#e0f2fe','#0369a1'), array('#f0fdf4','#166534'),
    );
    $idx = abs(crc32($tag)) % count($colors);
    return $colors[$idx];
}

$dialoga_base_url = admin_url('admin.php?page=dialoga-leads');
$dialoga_status_labels = array(
    'new' => __('New', 'dialoga'),
    'contacted' => __('Contacted', 'dialoga'),
    'qualified' => __('Qualified', 'dialoga'),
    'converted' => __('Converted', 'dialoga'),
    'closed' => __('Closed', 'dialoga'),
);
$dialoga_export_url = wp_nonce_url(
    $dialoga_base_url . ($dialoga_tag_filter ? '&tag=' . urlencode($dialoga_tag_filter) : '') . '&export=csv',
    'dialoga_export_leads'
);
?>

<div class="wrap dialoga-leads-wrap">
    <div class="dialoga-leads-header">
        <div>
            <div class="dialoga-leads-title-row">
                <h1><?php echo esc_html__('Dialoga Leads', 'dialoga'); ?></h1>
                <?php if ($dialoga_show_admin_only) : ?>
                    <span class="dialoga-leads-view-badge"><?php echo esc_html__('Admin test sessions only', 'dialoga'); ?></span>
                <?php endif; ?>
            </div>
            <p class="dialoga-leads-description">
                <?php
                /* translators: %d: number of lead records shown. */
                echo esc_html(sprintf(_n('%d lead record shown', '%d lead records shown', count($dialoga_leads), 'dialoga'), count($dialoga_leads)));
                ?>
                <?php if ($dialoga_tag_filter) : ?>
                    <span class="dialoga-leads-current-filter">
                        <?php
                        /* translators: %s: active tag filter. */
                        echo esc_html(sprintf(__('Tagged “%s”', 'dialoga'), $dialoga_tag_filter));
                        ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($dialoga_show_admin_only) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-leads')); ?>" class="button">&larr; <?php echo esc_html__('Back to public leads', 'dialoga'); ?></a>
        <?php endif; ?>
    </div>

    <?php
    if (!$dialoga_show_admin_only && !$dialoga_include_admin && $dialoga_has_admin_flag_col) {
        $dialoga_admin_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE is_admin_session = %d",
            $dialoga_leads_table,
            1
        ));
        if ($dialoga_admin_count > 0) :
    ?>
        <p class="dialoga-leads-admin-note">
            <?php
            /* translators: %d: hidden admin test lead count. */
            echo esc_html(sprintf(_n('%d admin test lead hidden', '%d admin test leads hidden', $dialoga_admin_count, 'dialoga'), $dialoga_admin_count));
            ?> &mdash;
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-leads&show=admin')); ?>"><?php echo esc_html__('view', 'dialoga'); ?></a>
            &middot; <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-settings')); ?>"><?php echo esc_html__('change in Settings', 'dialoga'); ?></a>
        </p>
    <?php endif; } ?>

    <div class="dialoga-leads-toolbar">
        <nav class="dialoga-leads-filters" aria-label="<?php echo esc_attr__('Filter leads by tag', 'dialoga'); ?>">
            <?php if (!empty($dialoga_all_tags)) : ?>
            <span class="dialoga-leads-filter-label"><?php echo esc_html__('Filter by tag:', 'dialoga'); ?></span>
            <a href="<?php echo esc_url($dialoga_base_url); ?>"
               class="dialoga-filter-tag dialoga-filter-tag--all<?php echo $dialoga_tag_filter ? '' : ' is-active'; ?>"
               <?php echo $dialoga_tag_filter ? '' : 'aria-current="page"'; ?>>
                <?php echo esc_html__('All', 'dialoga'); ?>
            </a>
            <?php foreach ($dialoga_all_tags as $dialoga_t) :
                $dialoga_active = ($dialoga_tag_filter === $dialoga_t);
                list($dialoga_bg, $dialoga_text) = dialoga_tag_color($dialoga_t);
            ?>
            <a href="<?php echo esc_url($dialoga_base_url . '&tag=' . urlencode($dialoga_t)); ?>"
               class="dialoga-filter-tag<?php echo $dialoga_active ? ' is-active' : ''; ?>"
               <?php echo $dialoga_active ? 'aria-current="page"' : ''; ?>
               style="background:<?php echo esc_attr($dialoga_active ? $dialoga_text : $dialoga_bg); ?>;
                       color:<?php echo esc_attr($dialoga_active ? '#fff' : $dialoga_text); ?>;
                       border:1px solid <?php echo esc_attr($dialoga_text); ?>;">
                <?php echo esc_html($dialoga_t); ?>
            </a>
            <?php endforeach; ?>
            <?php else : ?>
                <span class="dialoga-leads-filter-label"><?php echo esc_html__('No tags available', 'dialoga'); ?></span>
            <?php endif; ?>
        </nav>
        <a href="<?php echo esc_url($dialoga_export_url); ?>" class="button button-primary dialoga-leads-export">
            <span class="dashicons dashicons-download" aria-hidden="true"></span> <?php echo esc_html__('Export CSV', 'dialoga'); ?>
        </a>
    </div>

    <!-- Datalist for tag autocomplete (populated from existing tags) -->
    <datalist id="dialoga-tag-suggestions">
        <?php foreach ($dialoga_all_tags as $dialoga_t) : ?>
        <option value="<?php echo esc_attr($dialoga_t); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <?php if (empty($dialoga_leads)) : ?>
        <div class="dialoga-notice dialoga-leads-empty">
            <p><?php
            /* translators: %s: tag name. */
            echo $dialoga_tag_filter ? sprintf(esc_html__('No leads with tag %s.', 'dialoga'), '<strong>' . esc_html($dialoga_tag_filter) . '</strong>') : esc_html__('No leads captured yet.', 'dialoga');
            ?></p>
        </div>
    <?php else : ?>
        <div class="dialoga-leads-table-shell">
        <p id="dialoga-leads-table-help" class="screen-reader-text">
            <?php echo esc_html__('Scroll horizontally to view all lead record columns.', 'dialoga'); ?>
        </p>
        <div class="dialoga-leads-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Lead records table', 'dialoga'); ?>" aria-describedby="dialoga-leads-table-help">
        <table class="dialoga-leads-table wp-list-table widefat striped">
            <caption>
                <?php
                /* translators: %d: number of lead records shown. */
                echo esc_html(sprintf(_n('Lead records: %d result', 'Lead records: %d results', count($dialoga_leads), 'dialoga'), count($dialoga_leads)));
                ?>
            </caption>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col"><?php echo esc_html__('Name', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Email', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Phone', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Tags', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Created', 'dialoga'); ?></th>
                    <th scope="col"><?php echo esc_html__('Actions', 'dialoga'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dialoga_leads as $dialoga_lead) :
                    $dialoga_tags_arr = $dialoga_lead->tags ? json_decode($dialoga_lead->tags, true) : array();
                    if (!is_array($dialoga_tags_arr)) $dialoga_tags_arr = array();
                ?>
                    <tr id="lead-row-<?php echo esc_attr($dialoga_lead->id); ?>">
                        <th scope="row"><?php echo esc_html($dialoga_lead->id); ?></th>
                        <td><?php echo esc_html($dialoga_lead->name ?: '—'); ?></td>
                        <td>
                            <?php if ($dialoga_lead->email) : ?>
                                <a href="mailto:<?php echo esc_attr($dialoga_lead->email); ?>"><?php echo esc_html($dialoga_lead->email); ?></a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($dialoga_lead->phone) : ?>
                                <a href="tel:<?php echo esc_attr($dialoga_lead->phone); ?>"><?php echo esc_html($dialoga_lead->phone); ?></a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="dialoga-lead-status dialoga-lead-status--<?php echo esc_attr(sanitize_html_class($dialoga_lead->status)); ?>">
                                <?php echo esc_html($dialoga_status_labels[$dialoga_lead->status] ?? $dialoga_lead->status); ?>
                            </span>
                        </td>
                        <td class="dialoga-tags-cell" data-lead-id="<?php echo esc_attr($dialoga_lead->id); ?>">
                            <div class="dialoga-tags-list">
                                <?php foreach ($dialoga_tags_arr as $tag) :
                                    list($dialoga_bg, $dialoga_text) = dialoga_tag_color($tag);
                                ?>
                                <span class="dialoga-tag-pill"
                                      data-tag="<?php echo esc_attr($tag); ?>"
                                       style="background:<?php echo esc_attr($dialoga_bg); ?>;color:<?php echo esc_attr($dialoga_text); ?>;border-color:<?php echo esc_attr($dialoga_text); ?>;">
                                     <?php echo esc_html($tag); ?>
                                     <button type="button" class="dialoga-tag-remove"
                                             onclick="dialogaLeads.removeTag(<?php echo esc_attr($dialoga_lead->id); ?>, '<?php echo esc_js($tag); ?>')"
                                             aria-label="<?php
                                             /* translators: 1: tag name, 2: lead ID. */
                                             echo esc_attr(sprintf(__('Remove tag %1$s from lead %2$d', 'dialoga'), $tag, $dialoga_lead->id));
                                             ?>">&times;</button>
                                </span>
                                <?php endforeach; ?>
                                 <button type="button" class="dialoga-tag-add-btn"
                                         onclick="dialogaLeads.showAddTag(<?php echo esc_attr($dialoga_lead->id); ?>)"
                                         aria-expanded="false"
                                         aria-controls="tag-input-<?php echo esc_attr($dialoga_lead->id); ?>"
                                         <?php // translators: %d: lead record ID. ?>
                                         aria-label="<?php echo esc_attr(sprintf(__('Add tag to lead %d', 'dialoga'), $dialoga_lead->id)); ?>">
                                     + <?php echo esc_html__('tag', 'dialoga'); ?>
                                 </button>
                                 <span class="dialoga-tag-spinner" id="tag-spinner-<?php echo esc_attr($dialoga_lead->id); ?>" hidden role="status">
                                     <span class="dialoga-spinner-dot" aria-hidden="true"></span>
                                     <span><?php echo esc_html__('Saving...', 'dialoga'); ?></span>
                                 </span>
                             </div>
                            <div class="dialoga-tag-input-wrap" id="tag-input-<?php echo esc_attr($dialoga_lead->id); ?>" hidden>
                                 <input type="text" placeholder="<?php echo esc_attr__('tag name', 'dialoga'); ?>"
                                        list="dialoga-tag-suggestions"
                                        autocomplete="off"
                                        <?php // translators: %d: lead record ID. ?>
                                        aria-label="<?php echo esc_attr(sprintf(__('Tag name for lead %d', 'dialoga'), $dialoga_lead->id)); ?>"
                                        onkeydown="if(event.key==='Enter'){event.preventDefault();dialogaLeads.addTag(<?php echo esc_attr($dialoga_lead->id); ?>,this.value);}"
                                        id="tag-input-field-<?php echo esc_attr($dialoga_lead->id); ?>" />
                                <button type="button" class="button button-small button-primary" onclick="dialogaLeads.addTag(<?php echo esc_attr($dialoga_lead->id); ?>,document.getElementById('tag-input-field-<?php echo esc_attr($dialoga_lead->id); ?>').value)"><?php echo esc_html__('Add', 'dialoga'); ?></button>
                                <button type="button" class="button button-small" onclick="dialogaLeads.hideAddTag(<?php echo esc_attr($dialoga_lead->id); ?>)"
                                        <?php // translators: %d: lead record ID. ?>
                                aria-label="<?php echo esc_attr(sprintf(__('Cancel adding a tag to lead %d', 'dialoga'), $dialoga_lead->id)); ?>"><?php echo esc_html__('Cancel', 'dialoga'); ?></button>
                            </div>
                        </td>
                        <td><?php echo esc_html(wp_date('M d, Y g:i A', strtotime($dialoga_lead->created_at), new DateTimeZone('UTC'))); ?></td>
                        <td class="dialoga-lead-actions">
                            <button class="button button-small"
                                    onclick="dialogaLeads.viewLead(<?php echo esc_attr($dialoga_lead->id); ?>)">
                                <?php echo esc_html__('View', 'dialoga'); ?>
                            </button>
                             <button class="button button-small dialoga-lead-delete-btn"
                                    onclick="dialogaLeads.deleteLead(<?php echo esc_attr($dialoga_lead->id); ?>, '<?php
                                    /* translators: %d: lead ID. */
                                    echo esc_js($dialoga_lead->name ?: $dialoga_lead->email ?: sprintf(__('Lead #%d', 'dialoga'), $dialoga_lead->id));
                                     ?>')">
                                <?php echo esc_html__('Delete', 'dialoga'); ?>
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
<div id="dialoga-lead-modal" class="dialoga-lead-modal" hidden>
    <div class="dialoga-lead-dialog" role="dialog" aria-modal="true" aria-labelledby="dialoga-lead-modal-title" aria-describedby="dialoga-lead-modal-context">
        <div class="dialoga-lead-dialog-header">
            <div>
                <h2 id="dialoga-lead-modal-title"><?php echo esc_html__('Lead Details', 'dialoga'); ?></h2>
                <p id="dialoga-lead-modal-context"><?php echo esc_html__('Loading lead record', 'dialoga'); ?></p>
            </div>
            <button type="button" class="dialoga-lead-dialog-close" onclick="dialogaLeads.closeModal()" aria-label="<?php echo esc_attr__('Close lead details', 'dialoga'); ?>">&times;</button>
        </div>
        <div id="dialoga-lead-modal-body" class="dialoga-lead-dialog-body" aria-live="polite" aria-busy="true">
            <div class="dialoga-ld-loading" role="status"><?php echo esc_html__('Loading...', 'dialoga'); ?></div>
        </div>
    </div>
</div>

<?php
// Screen CSS lives in assets/css/screen-leads.css and is enqueued from
// Dialoga::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>

<?php
// wp.org: no output buffering. The script body is a nowdoc (no
// interpolation, so jQuery $ and regex escapes survive intact) and the
// two runtime values are substituted after the fact via strtr().
$dialoga_leads_js = strtr(<<<'JS'
(function() {
var t = function(s) { return (dialogaAdmin.i18n || {})[s] || s; };
window.dialogaLeads = {
    nonce: '%%DIALOGA_NONCE%%',
    ajaxUrl: '%%DIALOGA_AJAXURL%%',
    previousFocus: null,

    escapeHtml: function(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    getLeadTags: function(leadId) {
        var pills = document.querySelectorAll('#lead-row-' + leadId + ' .dialoga-tag-pill');
        var tags = [];
        pills.forEach(function(pill) { tags.push(pill.dataset.tag); });
        return tags;
    },

    showAddTag: function(leadId) {
        var inputWrap = document.getElementById('tag-input-' + leadId);
        var button = document.querySelector('#lead-row-' + leadId + ' .dialoga-tag-add-btn');
        inputWrap.hidden = false;
        if (button) button.setAttribute('aria-expanded', 'true');
        document.getElementById('tag-input-field-' + leadId).focus();
    },

    hideAddTag: function(leadId) {
        var inputWrap = document.getElementById('tag-input-' + leadId);
        var button = document.querySelector('#lead-row-' + leadId + ' .dialoga-tag-add-btn');
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
        data.append('action', 'dialoga_update_lead_tags');
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
        data.append('action', 'dialoga_delete_lead');
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
        var modal = document.getElementById('dialoga-lead-modal');
        var body = document.getElementById('dialoga-lead-modal-body');
        var title = document.getElementById('dialoga-lead-modal-title');
        var context = document.getElementById('dialoga-lead-modal-context');

        this.previousFocus = document.activeElement;
        title.textContent = t('Lead Details');
        context.textContent = t('Loading lead record');
        body.setAttribute('aria-busy', 'true');
        body.innerHTML = '<div class="dialoga-ld-loading" role="status">' + self.escapeHtml(t('Loading...')) + '</div>';
        modal.hidden = false;
        document.body.classList.add('dialoga-modal-open');
        modal.querySelector('.dialoga-lead-dialog-close').focus();

        var data = new FormData();
        data.append('action', 'dialoga_get_lead_details');
        data.append('nonce', this.nonce);
        data.append('lead_id', leadId);

        fetch(this.ajaxUrl, { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    self.renderLeadDetails(response.data);
                } else {
                    body.innerHTML = '<div class="dialoga-ld-empty">' + self.escapeHtml(t('Could not load lead details.')) + '</div>';
                    body.setAttribute('aria-busy', 'false');
                }
            })
            .catch(function() {
                body.innerHTML = '<div class="dialoga-ld-empty">' + self.escapeHtml(t('Network error loading lead details.')) + '</div>';
                body.setAttribute('aria-busy', 'false');
            });
    },

    renderLeadDetails: function(payload) {
        var self = this;
        var lead = payload.lead || {};
        var conv = payload.conversation;
        var transcript = payload.transcript || [];
        var title = document.getElementById('dialoga-lead-modal-title');
        var context = document.getElementById('dialoga-lead-modal-context');
        var body = document.getElementById('dialoga-lead-modal-body');
        var status = lead.status || 'new';
        var statusLabel = ({new: t('New'), contacted: t('Contacted'), qualified: t('Qualified'), converted: t('Converted'), closed: t('Closed')})[status] || status;

        title.textContent = lead.name || t('Lead #%d').replace('%d', lead.id);
        context.textContent = t('Lead #%d').replace('%d', lead.id) + ' · ' + statusLabel;

        var tagsHtml = (lead.tags && lead.tags.length)
            ? lead.tags.map(function(t) {
                return '<span class="dialoga-ld-tag">' + self.escapeHtml(t) + '</span>';
            }).join('')
            : '<span class="dialoga-ld-none">' + self.escapeHtml(t('none')) + '</span>';

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
            '<dt>' + self.escapeHtml(t('Status')) + '</dt><dd><span class="dialoga-lead-status dialoga-lead-status--' + self.escapeHtml(status) + '">' + self.escapeHtml(statusLabel) + '</span></dd>' +
            '<dt>' + self.escapeHtml(t('Captured')) + '</dt><dd>' + self.escapeHtml(lead.created_at) + '</dd>' +
            '<dt>' + self.escapeHtml(t('Tags')) + '</dt><dd>' + tagsHtml + '</dd>' +
            extraFieldsHtml;

        var convHtml = '';
        if (conv) {
            convHtml = '' +
                '<div class="dialoga-ld-section">' +
                    '<h3>' + self.escapeHtml(t('Conversation')) + '</h3>' +
                    '<dl class="dialoga-ld-grid">' +
                        '<dt>' + self.escapeHtml(t('Flow')) + '</dt><dd>' + self.escapeHtml(conv.flow_name || '#' + conv.flow_id) + '</dd>' +
                        '<dt>' + self.escapeHtml(t('Page')) + '</dt><dd>' + (conv.page_url ? '<a href="' + self.escapeHtml(conv.page_url) + '" target="_blank" rel="noopener">' + self.escapeHtml(conv.page_url) + '</a>' : '—') + '</dd>' +
                        '<dt>' + self.escapeHtml(t('Started')) + '</dt><dd>' + self.escapeHtml(conv.created_at) + '</dd>' +
                        '<dt>IP</dt><dd>' + self.escapeHtml(conv.ip_address || '—') + '</dd>' +
                    '</dl>' +
                '</div>';
        }

        var transcriptHtml = '<div class="dialoga-ld-section"><h3>' + self.escapeHtml(t('Transcript')) + '</h3>';
        if (!transcript.length) {
            transcriptHtml += '<div class="dialoga-ld-empty">' + self.escapeHtml(t('No conversation recorded.')) + '</div>';
        } else {
            transcriptHtml += '<div class="dialoga-ld-transcript">';
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
                    '<div class="dialoga-ld-msg ' + (isUser ? 'user' : 'bot') + '">' +
                        '<div>' +
                            '<div class="dialoga-ld-msg-bubble">' + self.escapeHtml(text) + '</div>' +
                            (item.timestamp ? '<div class="dialoga-ld-msg-time">' + self.escapeHtml(item.timestamp) + '</div>' : '') +
                        '</div>' +
                    '</div>';
            });
            transcriptHtml += '</div>';
        }
        transcriptHtml += '</div>';

        body.innerHTML =
            '<div class="dialoga-ld-section">' +
                '<h3>' + self.escapeHtml(t('Contact')) + '</h3>' +
                '<dl class="dialoga-ld-grid">' + contactHtml + '</dl>' +
            '</div>' +
            convHtml +
            transcriptHtml;
        body.setAttribute('aria-busy', 'false');
    },

    closeModal: function() {
        var modal = document.getElementById('dialoga-lead-modal');
        modal.hidden = true;
        document.body.classList.remove('dialoga-modal-open');
        if (this.previousFocus && document.contains(this.previousFocus)) {
            this.previousFocus.focus();
        }
        this.previousFocus = null;
    }
};

// Close modal when clicking the backdrop (but not the inner content)
document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'dialoga-lead-modal') {
        dialogaLeads.closeModal();
    }
});

// ESC to close
document.addEventListener('keydown', function(e) {
    var modal = document.getElementById('dialoga-lead-modal');
    if (!modal || modal.hidden) return;

    if (e.key === 'Escape') {
        dialogaLeads.closeModal();
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
    '%%DIALOGA_NONCE%%'   => esc_js(wp_create_nonce('dialoga_nonce')),
    '%%DIALOGA_AJAXURL%%' => esc_js(admin_url('admin-ajax.php')),
));
wp_add_inline_script('dialoga-admin', $dialoga_leads_js);
?>
