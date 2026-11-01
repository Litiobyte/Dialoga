<?php
/**
 * Flow Builder Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$dialogawp_flow_id = isset($_GET['flow_id']) ? absint(wp_unslash($_GET['flow_id'])) : 0;
$dialogawp_flows_table = $wpdb->prefix . 'dialogawp_flows';
$dialogawp_selected_flow = null;

if ($dialogawp_flow_id) {
    $dialogawp_selected_flow = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM {$dialogawp_flows_table} WHERE id = %d", $dialogawp_flow_id)
    );
}

$dialogawp_is_editor = (bool) $dialogawp_selected_flow;
$dialogawp_flows = array();
if (!$dialogawp_flow_id) {
    $dialogawp_flows = $wpdb->get_results("SELECT id, flow_name, is_active, visibility_mode, updated_at FROM {$dialogawp_flows_table} ORDER BY updated_at DESC");
}
$dialogawp_builder_url = admin_url('admin.php?page=dialogawp-builder');
?>

<?php if ($dialogawp_is_editor) : ?>
<div class="dialogawp-builder-mobile-notice">
    <div class="dialogawp-builder-mobile-inner">
        <img src="<?php echo esc_url(DIALOGAWP_LOGO_URL); ?>" alt="DialogaWP" style="height:80px;width:auto;margin-bottom:20px;" onerror="this.style.display='none'" />
        <h2><?php echo esc_html__('Laptop or Desktop Required', 'dialogawp'); ?></h2>
        <p><?php echo esc_html__('The Flow Builder is a visual drag-and-drop tool that works best on a desktop or laptop computer with a larger screen.', 'dialogawp'); ?></p>
        <p><?php echo esc_html__('Please switch to a device with a screen width of at least 1024px for the full experience.', 'dialogawp'); ?></p>
        <p><?php
        /* translators: %s: desktop-mode label. */
        echo sprintf(esc_html__("If a mobile device is your only option, switch your browser to %s (found in your browser's menu) to access the builder, though the experience may not be the same as using a laptop or desktop.", 'dialogawp'), '<strong>' . esc_html__('Desktop Mode', 'dialogawp') . '</strong>');
        ?></p>
        <div style="margin-top:24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=DialogaWP')); ?>" class="button button-primary" style="padding:8px 24px;"><?php echo esc_html__('Back to Dashboard', 'dialogawp'); ?></a>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="wrap dialogawp-builder-wrap <?php echo $dialogawp_is_editor ? 'dialogawp-builder-wrap--editor' : 'dialogawp-builder-wrap--list'; ?>">
        <div class="dialogawp-screen-header dialogawp-builder-page-header">
            <div>
                <h1><?php echo esc_html__('Flow Builder', 'dialogawp'); ?></h1>
                <p><?php echo $dialogawp_is_editor ? esc_html__('Design conversation paths, capture leads, and publish when your flow is ready.', 'dialogawp') : esc_html__('Create, review, and manage your conversational flows.', 'dialogawp'); ?></p>
            </div>
            <?php if ($dialogawp_is_editor) : ?>
            <a href="<?php echo esc_url($dialogawp_builder_url); ?>" class="button dialogawp-back-to-flows">
                <span class="dashicons dashicons-arrow-left-alt"></span> <?php echo esc_html__('Back to flows', 'dialogawp'); ?>
            </a>
            <?php else : ?>
            <button class="button button-primary dialogawp-new-flow">
                <span class="dashicons dashicons-plus-alt"></span> <?php echo esc_html__('New Flow', 'dialogawp'); ?>
            </button>
            <?php endif; ?>
        </div>

    <?php if ($dialogawp_flow_id && !$dialogawp_is_editor) : ?>
        <div class="notice notice-error" role="alert">
            <p><?php echo esc_html__('The selected flow could not be found.', 'dialogawp'); ?> <a href="<?php echo esc_url($dialogawp_builder_url); ?>"><?php echo esc_html__('Back to flows', 'dialogawp'); ?></a></p>
        </div>
    <?php elseif (!$dialogawp_is_editor) : ?>
        <div class="dialogawp-builder-list-view">
            <div class="dialogawp-builder-list-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-wizard')); ?>" class="button">
                    <span class="dashicons dashicons-admin-tools"></span> <?php echo esc_html__('Setup Wizard', 'dialogawp'); ?>
                </a>
                <button type="button" class="button dialogawp-import-flows"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Import', 'dialogawp'); ?></button>
                <button type="button" class="button dialogawp-export-flows"><span class="dashicons dashicons-download"></span> <?php echo esc_html__('Export flows', 'dialogawp'); ?></button>
                <input type="file" id="dialogawp-import-file" accept=".json,application/json" hidden />
            </div>
            <div class="dialogawp-builder-list-table-wrap">
                <table class="wp-list-table widefat fixed striped table-view-list dialogawp-flows-table">
                    <caption class="screen-reader-text"><?php echo esc_html__('Your Flows', 'dialogawp'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" class="column-primary"><?php echo esc_html__('Flow Name', 'dialogawp'); ?></th>
                            <th scope="col"><?php echo esc_html__('Status', 'dialogawp'); ?></th>
                            <th scope="col"><?php echo esc_html__('Visibility', 'dialogawp'); ?></th>
                            <th scope="col"><?php echo esc_html__('Updated', 'dialogawp'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="dialogawp-flows-list">
                    <?php if (empty($dialogawp_flows)) : ?>
                        <tr class="no-items"><td class="colspanchange" colspan="4"><?php echo esc_html__('No flows yet. Create your first flow!', 'dialogawp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($dialogawp_flows as $dialogawp_flow) : ?>
                            <?php
                            $dialogawp_edit_url = add_query_arg('flow_id', absint($dialogawp_flow->id), $dialogawp_builder_url);
                            $dialogawp_visibility = $dialogawp_flow->visibility_mode ?: 'everyone';
                            $dialogawp_visibility_label = 'logged_in' === $dialogawp_visibility
                                ? __('Logged-in users', 'dialogawp')
                                : ('specific_roles' === $dialogawp_visibility ? __('Specific roles', 'dialogawp') : __('Everyone', 'dialogawp'));
                            ?>
                            <tr data-flow-id="<?php echo esc_attr($dialogawp_flow->id); ?>">
                                <td class="column-primary" data-colname="<?php echo esc_attr__('Flow Name', 'dialogawp'); ?>">
                                    <strong><a class="row-title" href="<?php echo esc_url($dialogawp_edit_url); ?>"><?php echo esc_html($dialogawp_flow->flow_name ?: __('Untitled Flow', 'dialogawp')); ?></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url($dialogawp_edit_url); ?>"><?php echo esc_html__('Edit', 'dialogawp'); ?></a> | </span>
                                        <span class="trash"><button type="button" class="button-link-delete dialogawp-delete-flow" data-flow-id="<?php echo esc_attr($dialogawp_flow->id); ?>"><?php echo esc_html__('Delete', 'dialogawp'); ?></button></span>
                                    </div>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php echo esc_html__('Show more details', 'dialogawp'); ?></span></button>
                                </td>
                                <td data-colname="<?php echo esc_attr__('Status', 'dialogawp'); ?>"><span class="dialogawp-flow-status <?php echo $dialogawp_flow->is_active ? 'active' : 'inactive'; ?>"><?php echo $dialogawp_flow->is_active ? esc_html__('Active', 'dialogawp') : esc_html__('Inactive', 'dialogawp'); ?></span></td>
                                <td data-colname="<?php echo esc_attr__('Visibility', 'dialogawp'); ?>"><?php echo esc_html($dialogawp_visibility_label); ?></td>
                                <td data-colname="<?php echo esc_attr__('Updated', 'dialogawp'); ?>"><?php echo esc_html(wp_date(get_option('date_format'), strtotime($dialogawp_flow->updated_at . ' UTC'))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else : ?>
    <div class="dialogawp-builder-container">
        <!-- Flow Builder Canvas -->
        <div class="dialogawp-builder-main">
            <div class="dialogawp-builder-active" id="dialogawp-builder-active">
            <div class="dialogawp-builder-toolbar">
                <div class="dialogawp-builder-toolbar-left">
                    <label class="screen-reader-text" for="dialogawp-flow-name"><?php echo esc_html__('Flow Name', 'dialogawp'); ?></label>
                    <input type="text" 
                           id="dialogawp-flow-name" 
                           class="dialogawp-flow-name-input" 
                           placeholder="<?php echo esc_attr__('Flow Name', 'dialogawp'); ?>" 
                           value="Untitled Flow" />
                    <label class="dialogawp-active-toggle">
                        <input type="checkbox" id="dialogawp-flow-active" checked />
                        <span><?php echo esc_html__('Active', 'dialogawp'); ?></span>
                    </label>
                    
                    <!-- Visibility Controls -->
                    <div class="dialogawp-visibility-controls">
                        <label for="dialogawp-flow-visibility">
                            <?php echo esc_html__('Visible to:', 'dialogawp'); ?>
                        </label>
                        <select id="dialogawp-flow-visibility">
                            <option value="everyone"><?php echo esc_html__('Everyone (Public)', 'dialogawp'); ?></option>
                            <option value="logged_in"><?php echo esc_html__('Logged-in Users', 'dialogawp'); ?></option>
                            <option value="specific_roles"><?php echo esc_html__('Specific Roles', 'dialogawp'); ?></option>
                        </select>
                        <button type="button" class="button button-small" id="dialogawp-visibility-settings" style="display: none;">
                            <span class="dashicons dashicons-admin-users"></span><span class="dialogawp-toolbar-label"><?php echo esc_html__('Configure Roles', 'dialogawp'); ?></span>
                        </button>
                    </div>

                </div>
                <div class="dialogawp-builder-toolbar-right">
                    <button type="button" class="button dialogawp-export-current" title="<?php echo esc_attr__('Export', 'dialogawp'); ?>" aria-label="<?php echo esc_attr__('Export', 'dialogawp'); ?>"><span class="dashicons dashicons-download"></span><span class="dialogawp-toolbar-label"> <?php echo esc_html__('Export', 'dialogawp'); ?></span></button>
                    <button type="button" class="button dialogawp-import-flows" title="<?php echo esc_attr__('Import', 'dialogawp'); ?>" aria-label="<?php echo esc_attr__('Import', 'dialogawp'); ?>"><span class="dashicons dashicons-upload"></span><span class="dialogawp-toolbar-label"> <?php echo esc_html__('Import', 'dialogawp'); ?></span></button>
                    <input type="file" id="dialogawp-import-file" accept=".json,application/json" hidden />
                    <button type="button" class="button dialogawp-fullscreen-toggle" aria-pressed="false" title="<?php echo esc_attr__('Expand canvas within this browser tab', 'dialogawp'); ?>"><span class="dashicons dashicons-editor-expand"></span><span class="dialogawp-fullscreen-label"> <?php echo esc_html__('Full screen', 'dialogawp'); ?></span></button>
                    <span id="dialogawp-save-status" class="dialogawp-save-status dialogawp-save-status--saved" role="status" aria-live="polite" title="<?php echo esc_attr__('All changes saved', 'dialogawp'); ?>">
                        <span class="dialogawp-save-status-dot"></span>
                        <span class="dialogawp-save-status-text"><?php echo esc_html__('All changes saved', 'dialogawp'); ?></span>
                    </span>
                    <button class="button button-primary dialogawp-save-flow" id="dialogawp-save-flow" title="<?php echo esc_attr__('Save', 'dialogawp'); ?>" aria-label="<?php echo esc_attr__('Save', 'dialogawp'); ?>">
                        <span class="dashicons dashicons-saved"></span><span class="dialogawp-toolbar-label"> <?php echo esc_html__('Save', 'dialogawp'); ?></span>
                    </button>
                </div>
            </div>
            
            <!-- Node Palette -->
            <div class="dialogawp-node-palette">
                <h3>💡 <?php echo esc_html__('Drag nodes onto canvas or click to add', 'dialogawp'); ?></h3>
                <div class="dialogawp-node-buttons">
                <button class="dialogawp-add-node dialogawp-draggable-node"
                    data-type="question"
                    draggable="true">
                        <span class="dashicons dashicons-format-chat"></span>
                        <?php echo esc_html__('Message / Question', 'dialogawp'); ?>
                    </button>
                    <button class="dialogawp-add-node dialogawp-draggable-node" 
                    data-type="branch"
                    draggable="true">
                        <span class="dashicons dashicons-randomize"></span>
                        <?php echo esc_html__('Branch', 'dialogawp'); ?>
                    </button>
                    <button class="dialogawp-add-node dialogawp-draggable-node"
                    data-type="action"
                    draggable="true">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php echo esc_html__('Action', 'dialogawp'); ?>
                    </button>
                    <button class="dialogawp-add-node dialogawp-draggable-node"
                    data-type="ai_chat"
                    draggable="true">
                        <span class="dashicons dashicons-format-status"></span>
                        <?php echo esc_html__('AI Chat', 'dialogawp'); ?>
                    </button>
                    <button class="dialogawp-add-node dialogawp-draggable-node"
                    data-type="loop"
                    draggable="true">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo esc_html__('Loop', 'dialogawp'); ?>
                    </button>
                    <?php
                    // v1.0.0 — Extensions register additional palette node
                    // types here (e.g., Webhook, Survey, Qualify). Free
                    // plugin ships with the six node types above; the Pro
                    // plugin hooks this action to add its own.
                    do_action('dialogawp_builder_palette_extra_nodes');
                    ?>
                </div>
            </div>
            
            </div> <!-- /dialogawp-builder-active -->

            <!-- ReactFlow Canvas (always mounted for React) -->
            <div id="dialogawp-canvas" class="dialogawp-canvas"></div>

            <!-- Node Editor Panel -->
            <aside id="dialogawp-node-editor" class="dialogawp-node-editor" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dialogawp-node-editor-title" tabindex="-1">
                <div class="dialogawp-node-editor-header">
                    <h3 id="dialogawp-node-editor-title"><?php echo esc_html__('Edit Node', 'dialogawp'); ?></h3>
                    <button class="dialogawp-close-editor" type="button" aria-label="<?php echo esc_attr__('Close node editor', 'dialogawp'); ?>">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                </div>
                <div id="dialogawp-node-editor-content" class="dialogawp-node-editor-content">
                    <!-- Dynamic content based on node type -->
                </div>
                <div class="dialogawp-node-editor-footer">
                    <button class="button button-secondary dialogawp-delete-node"><?php echo esc_html__('Delete Node', 'dialogawp'); ?></button>
                    <button class="button button-primary dialogawp-save-node"><?php echo esc_html__('Save', 'dialogawp'); ?></button>
                </div>
            </aside>
        </div>
    </div>
    <?php endif; ?>

    <!-- Visibility Role Selection Modal -->
    <?php if ($dialogawp_is_editor) : ?>
    <div id="dialogawp-visibility-modal" class="dialogawp-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dialogawp-visibility-modal-title" tabindex="-1">
        <div class="dialogawp-modal-backdrop"></div>
        <div class="dialogawp-modal-content" style="max-width: 500px;">
            <div class="dialogawp-modal-header">
                <h3 id="dialogawp-visibility-modal-title">👑 <?php echo esc_html__('Select Visible Roles', 'dialogawp'); ?></h3>
                <button class="dialogawp-modal-close" type="button" aria-label="<?php echo esc_attr__('Close dialog', 'dialogawp'); ?>">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>
            <div class="dialogawp-modal-body">
                <p style="margin-bottom: 15px; color: #666;"><?php echo esc_html__('Choose which user roles can see this chatbot flow:', 'dialogawp'); ?></p>
                <div id="dialogawp-roles-list">
                    <?php
                    $wp_roles = wp_roles();
                    $available_roles = $wp_roles->get_names();
                    foreach ($available_roles as $role_key => $role_name) {
                        echo '<label style="display: block; margin-bottom: 10px; padding: 8px; background: #F7F8FC; border-radius: 4px;">';
                        echo '<input type="checkbox" class="dialogawp-role-checkbox" value="' . esc_attr($role_key) . '" style="margin-right: 8px;" />';
                        echo esc_html(translate_user_role($role_name));
                        echo '</label>';
                    }
                    ?>
                </div>
            </div>
            <div class="dialogawp-modal-footer">
                <button class="button button-secondary dialogawp-modal-close"><?php echo esc_html__('Cancel', 'dialogawp'); ?></button>
                <button class="button button-primary" id="dialogawp-save-roles"><?php echo esc_html__('Save Roles', 'dialogawp'); ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- v1.0.1 — <script type="text/template"> blocks moved to templates/admin/builder-templates.php
     and injected into window.dialogawpTemplates via wp_localize_script (wp.org compliance). -->

<?php
// Screen CSS lives in assets/css/screen-builder.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>
