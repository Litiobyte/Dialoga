<?php
/**
 * Flow Builder Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

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
<div class="wrap dialogawp-builder-wrap">
    <h1>
        <?php echo esc_html__('DIALOGAWP - Flow Builder', 'dialogawp'); ?>
        <button class="button button-primary dialogawp-new-flow">
            <span class="dashicons dashicons-plus-alt"></span> <?php echo esc_html__('New Flow', 'dialogawp'); ?>
        </button>
    </h1>
    
    <div class="dialogawp-builder-container">
        <!-- Flow List Sidebar -->
        <div class="dialogawp-flows-sidebar">
            <div class="dialogawp-flows-sidebar-header">
                <h2><?php echo esc_html__('Your Flows', 'dialogawp'); ?></h2>
                <div class="dialogawp-flows-sidebar-actions">
                    <button class="button button-primary dialogawp-new-flow dialogawp-new-flow-sidebar" title="<?php echo esc_attr__('Create New Flow', 'dialogawp'); ?>">
                        <span class="dashicons dashicons-plus-alt2"></span> <?php echo esc_html__('New Flow', 'dialogawp'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-wizard')); ?>" class="button dialogawp-wizard-sidebar" title="<?php echo esc_attr__('Open the setup wizard to build a flow from your site content', 'dialogawp'); ?>">
                        <span class="dashicons dashicons-admin-tools"></span> <?php echo esc_html__('Wizard', 'dialogawp'); ?>
                    </a>
                    <button type="button" class="button dialogawp-import-flows"><span class="dashicons dashicons-upload"></span> <?php echo esc_html__('Import', 'dialogawp'); ?></button>
                    <button type="button" class="button dialogawp-export-flows"><span class="dashicons dashicons-download"></span> <?php echo esc_html__('Export flows', 'dialogawp'); ?></button>
                    <input type="file" id="dialogawp-import-file" accept=".json,application/json" hidden />
                </div>
            </div>
            <div id="dialogawp-flows-list">
                <p class="dialogawp-loading"><?php echo esc_html__('Loading flows...', 'dialogawp'); ?></p>
            </div>
        </div>
        
        <!-- Flow Builder Canvas -->
        <div class="dialogawp-builder-main">
            <!-- Empty state — shown when no flow is selected -->
            <div class="dialogawp-builder-empty-state" id="dialogawp-builder-empty-state">
                <div class="dialogawp-builder-empty-inner">
                    <img src="<?php echo esc_url(DIALOGAWP_LOGO_URL); ?>" alt="DialogaWP" style="height:80px;width:auto;margin-bottom:20px;opacity:0.5;" onerror="this.style.display='none'" />
                    <h2><?php echo esc_html__('Welcome to the Flow Builder', 'dialogawp'); ?></h2>
                    <p><?php echo esc_html__('Create conversational chatbot flows with our visual drag-and-drop editor.', 'dialogawp'); ?></p>
                    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px;">
                        <button class="button button-primary dialogawp-new-flow" style="padding:8px 24px;font-size:14px;">
                            <span class="dashicons dashicons-plus-alt" style="margin-top:3px;"></span> <?php echo esc_html__('Create New Flow', 'dialogawp'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp-wizard')); ?>" class="button" style="padding:8px 24px;font-size:14px;">
                            <span class="dashicons dashicons-admin-tools" style="margin-top:3px;"></span> <?php echo esc_html__('Setup Wizard', 'dialogawp'); ?>
                        </a>
                    </div>
                    <p style="margin-top:16px;font-size:13px;color:#94A3B8;"><?php echo esc_html__('Or select an existing flow from the sidebar to edit it.', 'dialogawp'); ?></p>
                </div>
            </div>

            <!-- Builder UI — hidden until flow selected -->
            <div class="dialogawp-builder-active" id="dialogawp-builder-active" style="display:none;">
            <div class="dialogawp-builder-toolbar">
                <div class="dialogawp-builder-toolbar-left">
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
                    <div class="dialogawp-visibility-controls" style="display: inline-flex; align-items: center; gap: 8px; margin-left: 12px; padding-left: 12px; border-left: 1px solid #E5E7EF;">
                        <label style="font-size: 13px; font-weight: 600; margin: 0; white-space: nowrap;">
                            <?php echo esc_html__('Visible to:', 'dialogawp'); ?>
                        </label>
                        <select id="dialogawp-flow-visibility" style="font-size: 13px; padding: 5px 10px; min-width: 170px;">
                            <option value="everyone"><?php echo esc_html__('Everyone (Public)', 'dialogawp'); ?></option>
                            <option value="logged_in"><?php echo esc_html__('Logged-in Users', 'dialogawp'); ?></option>
                            <option value="specific_roles"><?php echo esc_html__('Specific Roles', 'dialogawp'); ?></option>
                        </select>
                        <button type="button" class="button button-small" id="dialogawp-visibility-settings" style="display: none; font-size: 12px;">
                            <?php echo esc_html__('Configure Roles', 'dialogawp'); ?>
                        </button>
                    </div>

                </div>
                <div class="dialogawp-builder-toolbar-right">
                    <button type="button" class="button dialogawp-export-current"><span class="dashicons dashicons-download"></span> <?php echo esc_html__('Export', 'dialogawp'); ?></button>
                    <button type="button" class="button dialogawp-fullscreen-toggle" aria-pressed="false" title="<?php echo esc_attr__('Expand canvas within this browser tab', 'dialogawp'); ?>"><span class="dashicons dashicons-editor-expand"></span> <span class="dialogawp-fullscreen-label"><?php echo esc_html__('Full screen', 'dialogawp'); ?></span></button>
                    <span id="dialogawp-save-status" class="dialogawp-save-status dialogawp-save-status--saved" title="<?php echo esc_attr__('All changes saved', 'dialogawp'); ?>">
                        <span class="dialogawp-save-status-dot"></span>
                        <span class="dialogawp-save-status-text"><?php echo esc_html__('All changes saved', 'dialogawp'); ?></span>
                    </span>
                    <button class="button dialogawp-preview-flow">
                        <span class="dashicons dashicons-visibility"></span> <?php echo esc_html__('Preview', 'dialogawp'); ?>
                    </button>
                    <button class="button button-primary dialogawp-save-flow" id="dialogawp-save-flow">
                        <span class="dashicons dashicons-saved"></span> <?php echo esc_html__('Save Flow', 'dialogawp'); ?>
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
            <div id="dialogawp-node-editor" class="dialogawp-node-editor" style="display: none;">
                <div class="dialogawp-node-editor-header">
                    <h3><?php echo esc_html__('Edit Node', 'dialogawp'); ?></h3>
                    <button class="dialogawp-close-editor">
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
            </div>
    </div>

    <!-- Visibility Role Selection Modal -->
    <div id="dialogawp-visibility-modal" class="dialogawp-modal" style="display: none;">
        <div class="dialogawp-modal-backdrop"></div>
        <div class="dialogawp-modal-content" style="max-width: 500px;">
            <div class="dialogawp-modal-header">
                <h3>👑 <?php echo esc_html__('Select Visible Roles', 'dialogawp'); ?></h3>
                <button class="dialogawp-modal-close">
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
</div>

<!-- v1.0.1 — <script type="text/template"> blocks moved to templates/admin/builder-templates.php
     and injected into window.dialogawpTemplates via wp_localize_script (wp.org compliance). -->

<?php
// Screen CSS lives in assets/css/screen-builder.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>
