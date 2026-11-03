<?php
/**
 * Flow Builder Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen routing (GET flow_id), no form data processed.
$dialoga_flow_id = isset($_GET['flow_id']) ? absint(wp_unslash($_GET['flow_id'])) : 0;
$dialoga_flows_table = $wpdb->prefix . 'dialoga_flows';
$dialoga_selected_flow = null;

if ($dialoga_flow_id) {
    $dialoga_selected_flow = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM %i WHERE id = %d", $dialoga_flows_table, $dialoga_flow_id)
    );
}

$dialoga_is_editor = (bool) $dialoga_selected_flow;
$dialoga_flows = array();
if (!$dialoga_flow_id) {
    $dialoga_flows = $wpdb->get_results(
        $wpdb->prepare("SELECT id, flow_name, is_active, visibility_mode, updated_at FROM %i ORDER BY updated_at DESC", $dialoga_flows_table)
    );
}
$dialoga_builder_url = admin_url('admin.php?page=dialoga-builder');
?>

<?php if ($dialoga_is_editor) : ?>
<div class="dialoga-builder-mobile-notice">
    <div class="dialoga-builder-mobile-inner">
        <img src="<?php echo esc_url(DIALOGA_LOGO_URL); ?>" alt="Dialoga" style="height:80px;width:auto;margin-bottom:20px;" onerror="this.style.display='none'" />
        <h2><?php echo esc_html__('Laptop or Desktop Required', 'dialoga'); ?></h2>
        <p><?php echo esc_html__('The Flow Builder is a visual drag-and-drop tool that works best on a desktop or laptop computer with a larger screen.', 'dialoga'); ?></p>
        <p><?php echo esc_html__('Please switch to a device with a screen width of at least 1024px for the full experience.', 'dialoga'); ?></p>
        <p><?php
        /* translators: %s: desktop-mode label. */
        echo sprintf(esc_html__("If a mobile device is your only option, switch your browser to %s (found in your browser's menu) to access the builder, though the experience may not be the same as using a laptop or desktop.", 'dialoga'), '<strong>' . esc_html__('Desktop Mode', 'dialoga') . '</strong>');
        ?></p>
        <div style="margin-top:24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=Dialoga')); ?>" class="button button-primary" style="padding:8px 24px;"><?php echo esc_html__('Back to Dashboard', 'dialoga'); ?></a>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="wrap dialoga-builder-wrap <?php echo $dialoga_is_editor ? 'dialoga-builder-wrap--editor' : 'dialoga-builder-wrap--list'; ?>">
        <div class="dialoga-screen-header dialoga-builder-page-header">
            <div>
                <h1><?php echo esc_html__('Flow Builder', 'dialoga'); ?></h1>
                <p><?php echo $dialoga_is_editor ? esc_html__('Design conversation paths, capture leads, and publish when your flow is ready.', 'dialoga') : esc_html__('Create, review, and manage your conversational flows.', 'dialoga'); ?></p>
            </div>
            <?php if ($dialoga_is_editor) : ?>
            <a href="<?php echo esc_url($dialoga_builder_url); ?>" class="button dialoga-back-to-flows">
                <span class="dashicons dashicons-arrow-left-alt"></span> <?php echo esc_html__('Back to flows', 'dialoga'); ?>
            </a>
            <?php else : ?>
            <button class="button button-primary dialoga-new-flow">
                <span class="dashicons dashicons-plus-alt"></span> <?php echo esc_html__('New Flow', 'dialoga'); ?>
            </button>
            <?php endif; ?>
        </div>

    <?php if ($dialoga_flow_id && !$dialoga_is_editor) : ?>
        <div class="notice notice-error" role="alert">
            <p><?php echo esc_html__('The selected flow could not be found.', 'dialoga'); ?> <a href="<?php echo esc_url($dialoga_builder_url); ?>"><?php echo esc_html__('Back to flows', 'dialoga'); ?></a></p>
        </div>
    <?php elseif (!$dialoga_is_editor) : ?>
        <div class="dialoga-builder-list-view">
            <div class="dialoga-builder-list-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga-wizard')); ?>" class="button">
                    <span class="dashicons dashicons-admin-tools"></span> <?php echo esc_html__('Setup Wizard', 'dialoga'); ?>
                </a>
                <button type="button" class="button dialoga-import-flows"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Import', 'dialoga'); ?></button>
                <button type="button" class="button dialoga-export-flows"><span class="dashicons dashicons-download"></span> <?php echo esc_html__('Export flows', 'dialoga'); ?></button>
                <input type="file" id="dialoga-import-file" accept=".json,application/json" hidden />
            </div>
            <div class="dialoga-builder-list-table-wrap">
                <table class="wp-list-table widefat fixed striped table-view-list dialoga-flows-table">
                    <caption class="screen-reader-text"><?php echo esc_html__('Your Flows', 'dialoga'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" class="column-primary"><?php echo esc_html__('Flow Name', 'dialoga'); ?></th>
                            <th scope="col"><?php echo esc_html__('Status', 'dialoga'); ?></th>
                            <th scope="col"><?php echo esc_html__('Visibility', 'dialoga'); ?></th>
                            <th scope="col"><?php echo esc_html__('Updated', 'dialoga'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="dialoga-flows-list">
                    <?php if (empty($dialoga_flows)) : ?>
                        <tr class="no-items"><td class="colspanchange" colspan="4"><?php echo esc_html__('No flows yet. Create your first flow!', 'dialoga'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($dialoga_flows as $dialoga_flow) : ?>
                            <?php
                            $dialoga_edit_url = add_query_arg('flow_id', absint($dialoga_flow->id), $dialoga_builder_url);
                            $dialoga_visibility = $dialoga_flow->visibility_mode ?: 'everyone';
                            $dialoga_visibility_label = 'logged_in' === $dialoga_visibility
                                ? __('Logged-in users', 'dialoga')
                                : ('specific_roles' === $dialoga_visibility ? __('Specific roles', 'dialoga') : __('Everyone', 'dialoga'));
                            ?>
                            <tr data-flow-id="<?php echo esc_attr($dialoga_flow->id); ?>">
                                <td class="column-primary" data-colname="<?php echo esc_attr__('Flow Name', 'dialoga'); ?>">
                                    <strong><a class="row-title" href="<?php echo esc_url($dialoga_edit_url); ?>"><?php echo esc_html($dialoga_flow->flow_name ?: __('Untitled Flow', 'dialoga')); ?></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url($dialoga_edit_url); ?>"><?php echo esc_html__('Edit', 'dialoga'); ?></a> | </span>
                                        <span class="trash"><button type="button" class="button-link-delete dialoga-delete-flow" data-flow-id="<?php echo esc_attr($dialoga_flow->id); ?>"><?php echo esc_html__('Delete', 'dialoga'); ?></button></span>
                                    </div>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php echo esc_html__('Show more details', 'dialoga'); ?></span></button>
                                </td>
                                <td data-colname="<?php echo esc_attr__('Status', 'dialoga'); ?>"><span class="dialoga-flow-status <?php echo $dialoga_flow->is_active ? 'active' : 'inactive'; ?>"><?php echo $dialoga_flow->is_active ? esc_html__('Active', 'dialoga') : esc_html__('Inactive', 'dialoga'); ?></span></td>
                                <td data-colname="<?php echo esc_attr__('Visibility', 'dialoga'); ?>"><?php echo esc_html($dialoga_visibility_label); ?></td>
                                <td data-colname="<?php echo esc_attr__('Updated', 'dialoga'); ?>"><?php echo esc_html(wp_date(get_option('date_format'), strtotime($dialoga_flow->updated_at . ' UTC'))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else : ?>
    <div class="dialoga-builder-container">
        <!-- Flow Builder Canvas -->
        <div class="dialoga-builder-main">
            <div class="dialoga-builder-active" id="dialoga-builder-active">
            <div class="dialoga-builder-toolbar">
                <div class="dialoga-builder-toolbar-left">
                    <label class="screen-reader-text" for="dialoga-flow-name"><?php echo esc_html__('Flow Name', 'dialoga'); ?></label>
                    <input type="text" 
                           id="dialoga-flow-name" 
                           class="dialoga-flow-name-input" 
                           placeholder="<?php echo esc_attr__('Flow Name', 'dialoga'); ?>" 
                           value="Untitled Flow" />
                    <label class="dialoga-active-toggle">
                        <input type="checkbox" id="dialoga-flow-active" checked />
                        <span><?php echo esc_html__('Active', 'dialoga'); ?></span>
                    </label>
                    
                    <!-- Visibility Controls -->
                    <div class="dialoga-visibility-controls">
                        <label for="dialoga-flow-visibility">
                            <?php echo esc_html__('Visible to:', 'dialoga'); ?>
                        </label>
                        <select id="dialoga-flow-visibility">
                            <option value="everyone"><?php echo esc_html__('Everyone (Public)', 'dialoga'); ?></option>
                            <option value="logged_in"><?php echo esc_html__('Logged-in Users', 'dialoga'); ?></option>
                            <option value="specific_roles"><?php echo esc_html__('Specific Roles', 'dialoga'); ?></option>
                        </select>
                        <button type="button" class="button button-small" id="dialoga-visibility-settings" style="display: none;">
                            <span class="dashicons dashicons-admin-users"></span><span class="dialoga-toolbar-label"><?php echo esc_html__('Configure Roles', 'dialoga'); ?></span>
                        </button>
                    </div>

                </div>
                <div class="dialoga-builder-toolbar-right">
                    <button type="button" class="button dialoga-export-current" title="<?php echo esc_attr__('Export', 'dialoga'); ?>" aria-label="<?php echo esc_attr__('Export', 'dialoga'); ?>"><span class="dashicons dashicons-download"></span><span class="dialoga-toolbar-label"> <?php echo esc_html__('Export', 'dialoga'); ?></span></button>
                    <button type="button" class="button dialoga-import-flows" title="<?php echo esc_attr__('Import', 'dialoga'); ?>" aria-label="<?php echo esc_attr__('Import', 'dialoga'); ?>"><span class="dashicons dashicons-upload"></span><span class="dialoga-toolbar-label"> <?php echo esc_html__('Import', 'dialoga'); ?></span></button>
                    <input type="file" id="dialoga-import-file" accept=".json,application/json" hidden />
                    <button type="button" class="button dialoga-fullscreen-toggle" aria-pressed="false" title="<?php echo esc_attr__('Expand canvas within this browser tab', 'dialoga'); ?>"><span class="dashicons dashicons-editor-expand"></span><span class="dialoga-fullscreen-label"> <?php echo esc_html__('Full screen', 'dialoga'); ?></span></button>
                    <span id="dialoga-save-status" class="dialoga-save-status dialoga-save-status--saved" role="status" aria-live="polite" title="<?php echo esc_attr__('All changes saved', 'dialoga'); ?>">
                        <span class="dialoga-save-status-dot"></span>
                        <span class="dialoga-save-status-text"><?php echo esc_html__('All changes saved', 'dialoga'); ?></span>
                    </span>
                    <button class="button button-primary dialoga-save-flow" id="dialoga-save-flow" title="<?php echo esc_attr__('Save', 'dialoga'); ?>" aria-label="<?php echo esc_attr__('Save', 'dialoga'); ?>">
                        <span class="dashicons dashicons-saved"></span><span class="dialoga-toolbar-label"> <?php echo esc_html__('Save', 'dialoga'); ?></span>
                    </button>
                </div>
            </div>
            
            <!-- Node Palette -->
            <div class="dialoga-node-palette">
                <h3>💡 <?php echo esc_html__('Drag nodes onto canvas or click to add', 'dialoga'); ?></h3>
                <div class="dialoga-node-buttons">
                <button class="dialoga-add-node dialoga-draggable-node"
                    data-type="question"
                    draggable="true">
                        <span class="dashicons dashicons-format-chat"></span>
                        <?php echo esc_html__('Message / Question', 'dialoga'); ?>
                    </button>
                    <button class="dialoga-add-node dialoga-draggable-node" 
                    data-type="branch"
                    draggable="true">
                        <span class="dashicons dashicons-randomize"></span>
                        <?php echo esc_html__('Branch', 'dialoga'); ?>
                    </button>
                    <button class="dialoga-add-node dialoga-draggable-node"
                    data-type="action"
                    draggable="true">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php echo esc_html__('Action', 'dialoga'); ?>
                    </button>
                    <button class="dialoga-add-node dialoga-draggable-node"
                    data-type="ai_chat"
                    draggable="true">
                        <span class="dashicons dashicons-format-status"></span>
                        <?php echo esc_html__('AI Chat', 'dialoga'); ?>
                    </button>
                    <button class="dialoga-add-node dialoga-draggable-node"
                    data-type="loop"
                    draggable="true">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo esc_html__('Loop', 'dialoga'); ?>
                    </button>
                    <?php
                    // v1.0.0 — Extensions register additional palette node
                    // types here (e.g., Webhook, Survey, Qualify). Free
                    // plugin ships with the six node types above; the Pro
                    // plugin hooks this action to add its own.
                    do_action('dialoga_builder_palette_extra_nodes');
                    ?>
                </div>
            </div>
            
            </div> <!-- /dialoga-builder-active -->

            <!-- ReactFlow Canvas (always mounted for React) -->
            <div id="dialoga-canvas" class="dialoga-canvas"></div>

            <!-- Node Editor Panel -->
            <aside id="dialoga-node-editor" class="dialoga-node-editor" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dialoga-node-editor-title" tabindex="-1">
                <div class="dialoga-node-editor-header">
                    <h3 id="dialoga-node-editor-title"><?php echo esc_html__('Edit Node', 'dialoga'); ?></h3>
                    <button class="dialoga-close-editor" type="button" aria-label="<?php echo esc_attr__('Close node editor', 'dialoga'); ?>">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                </div>
                <div id="dialoga-node-editor-content" class="dialoga-node-editor-content">
                    <!-- Dynamic content based on node type -->
                </div>
                <div class="dialoga-node-editor-footer">
                    <button class="button button-secondary dialoga-delete-node"><?php echo esc_html__('Delete Node', 'dialoga'); ?></button>
                    <button class="button button-primary dialoga-save-node"><?php echo esc_html__('Save', 'dialoga'); ?></button>
                </div>
            </aside>
        </div>
    </div>
    <?php endif; ?>

    <!-- Visibility Role Selection Modal -->
    <?php if ($dialoga_is_editor) : ?>
    <div id="dialoga-visibility-modal" class="dialoga-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dialoga-visibility-modal-title" tabindex="-1">
        <div class="dialoga-modal-backdrop"></div>
        <div class="dialoga-modal-content" style="max-width: 500px;">
            <div class="dialoga-modal-header">
                <h3 id="dialoga-visibility-modal-title">👑 <?php echo esc_html__('Select Visible Roles', 'dialoga'); ?></h3>
                <button class="dialoga-modal-close" type="button" aria-label="<?php echo esc_attr__('Close dialog', 'dialoga'); ?>">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>
            <div class="dialoga-modal-body">
                <p style="margin-bottom: 15px; color: #666;"><?php echo esc_html__('Choose which user roles can see this chatbot flow:', 'dialoga'); ?></p>
                <div id="dialoga-roles-list">
                    <?php
                    $wp_roles = wp_roles();
                    $dialoga_available_roles = $wp_roles->get_names();
                    foreach ($dialoga_available_roles as $dialoga_role_key => $dialoga_role_name) {
                        echo '<label style="display: block; margin-bottom: 10px; padding: 8px; background: #F7F8FC; border-radius: 4px;">';
                        echo '<input type="checkbox" class="dialoga-role-checkbox" value="' . esc_attr($dialoga_role_key) . '" style="margin-right: 8px;" />';
                        echo esc_html(translate_user_role($dialoga_role_name));
                        echo '</label>';
                    }
                    ?>
                </div>
            </div>
            <div class="dialoga-modal-footer">
                <button class="button button-secondary dialoga-modal-close"><?php echo esc_html__('Cancel', 'dialoga'); ?></button>
                <button class="button button-primary" id="dialoga-save-roles"><?php echo esc_html__('Save Roles', 'dialoga'); ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- v1.0.1 — <script type="text/template"> blocks moved to templates/admin/builder-templates.php
     and injected into window.dialogaTemplates via wp_localize_script (wp.org compliance). -->

<?php
// Screen CSS lives in assets/css/screen-builder.css and is enqueued from
// Dialoga::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>
