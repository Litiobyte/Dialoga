<?php
/**
 * Builder JS templates.
 *
 * v1.0.1 — wp.org compliance: the previous approach emitted these as
 * <script type="text/template"> blocks inside builder.php. Reviewers flagged
 * raw <script> tags in admin templates as non-compliant. This file returns
 * the same HTML fragments as a PHP associative array so the builder page can
 * hand them to admin.js via wp_localize_script('dialogawp-admin', 'dialogawpTemplates', …).
 *
 * admin.js reads window.dialogawpTemplates.<key> in place of the old
 * $('#dialogawp-<key>-template').html() calls.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string,string>  key => HTML template string
 */
function dialogawp_builder_templates() {
    $flow_item = <<<'HTML'
<div class="dialogawp-flow-item {{editing_class}}" data-flow-id="{{id}}" role="button" tabindex="0" aria-label="Edit {{flow_name}}">
    <div class="dialogawp-flow-item-header">
        <h4>{{flow_name}}</h4>
        <span class="dialogawp-flow-status {{status_class}}">{{status}}</span>
    </div>
    <div class="dialogawp-flow-item-meta">
        <span>Updated: {{updated_at}}</span>
        <br />
        <span class="dialogawp-flow-visibility" style="font-size: 11px; color: #666;">{{visibility_icon}} {{visibility_label}}</span>
    </div>
    <div class="dialogawp-flow-item-actions">
        <button class="button button-small dialogawp-edit-flow" data-flow-id="{{id}}">Edit</button>
        <button class="button button-small button-link-delete dialogawp-delete-flow" data-flow-id="{{id}}">Delete</button>
    </div>
</div>
HTML;

    $message_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Message Text</label>
    <textarea class="dialogawp-node-field" data-field="message" rows="4" placeholder="Enter your message...">{{message}}</textarea>
    <p class="description">This message will be displayed to the visitor.</p>
</div>
<div class="dialogawp-field">
    <label>Delay (ms)</label>
    <input type="number" class="dialogawp-node-field" data-field="delay" value="{{delay}}" min="0" step="100" />
    <p class="description">Optional delay before showing this message (to simulate typing).</p>
</div>
HTML;

    $question_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Message Text</label>
    <textarea class="dialogawp-node-field" data-field="message" rows="3" placeholder="What do you want to say or ask?">{{message}}</textarea>
    <p class="description">The message shown to the visitor</p>
</div>

<div class="dialogawp-field" style="background: #F7F8FC; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
    <h4 style="margin: 0 0 10px 0; font-size: 13px;">Response Type</h4>

    <label style="display: block; margin-bottom: 8px;">
        <input type="checkbox" class="dialogawp-node-field" data-field="auto_continue" {{auto_continue_checked}} />
        <strong>Auto-continue (Display only)</strong> - Don't wait for response
    </label>

    <div class="dialogawp-delay-field" style="margin-left: 24px; margin-bottom: 4px; {{delay_visibility}}">
        <label>Delay before continuing:
            <input type="number" class="dialogawp-node-field" data-field="delay" value="{{delay}}" min="0" max="10" step="0.5" style="width: 60px;" /> seconds
        </label>
    </div>

    <p class="description" style="margin: 10px 0 0 0; font-size: 12px; color: #6b7280; font-style: italic;">
        Need free-form answers? Use the <strong>AI Chat</strong> node instead &mdash; it's designed for open-ended questions.
    </p>
</div>

<div class="dialogawp-field">
    <label>Quick-Reply Buttons</label>
    <p class="description">The options visitors can click to answer this question.</p>
    <div id="dialogawp-question-options" class="dialogawp-question-options">
        <!-- Dynamic options -->
    </div>
    <button class="button button-small dialogawp-add-option">
        <span class="dashicons dashicons-plus"></span> Add Button
    </button>

    <label style="display: block; margin-top: 14px; padding: 10px 12px; background:#f0f7ff; border:1px solid #cfe3ff; border-radius:4px;">
        <input type="checkbox" class="dialogawp-node-field" data-field="allow_multiple" {{allow_multiple_checked}} />
        <strong>Allow multiple selections</strong>
        <p class="description" style="margin: 4px 0 0 24px; font-size: 12px;">
            Show checkboxes instead of buttons. The visitor can pick more than one; the answer is saved as a comma-separated list (e.g. <code>Individual, Couples</code>). Works with the Branch node's <em>contains</em> operator.
        </p>
    </label>
</div>

<div class="dialogawp-field" style="background: #fff8e1; padding: 12px 15px; border-radius: 4px; border-left: 3px solid #ffc107; margin-top: 8px;">
    <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">
        Save answer as variable <span style="font-weight: 400; color: #666;">(optional)</span>
    </label>
    <input type="text"
           class="dialogawp-node-field"
           data-field="save_as"
           value="{{save_as}}"
           placeholder="e.g. name, service, budget"
           style="width: 100%; padding: 8px; font-family: monospace;" />
    <p class="description" style="margin: 6px 0 0 0; font-size: 12px;">
        Reference this answer later using <code>{{variable_name}}</code> in any message text.
    </p>
</div>
HTML;

    $output_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Output Message</label>
    <textarea class="dialogawp-node-field" data-field="output_message" rows="4" placeholder="Enter the message to display to the user...">{{output_message}}</textarea>
    <p class="description">This message will be shown to the visitor when this path is taken.</p>
</div>
<div class="dialogawp-field">
    <label>Delay (ms)</label>
    <input type="number" class="dialogawp-node-field" data-field="delay" value="{{delay}}" min="0" step="100" />
    <p class="description">Optional delay before showing this message (to simulate typing).</p>
</div>
<div class="dialogawp-field">
    <label>Action Buttons (Optional)</label>
    <p class="description">Add clickable buttons that link to pages or trigger actions.</p>
    <div id="dialogawp-output-buttons" class="dialogawp-output-buttons">
        <!-- Dynamic buttons -->
    </div>
    <button class="button button-small dialogawp-add-button">
        <span class="dashicons dashicons-plus"></span> Add Button
    </button>
</div>
HTML;

    $click_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Detected Clicks</label>
    <p class="description">This node detects which button or link was clicked by the user. Connect from an Output node with buttons.</p>
    <div id="dialogawp-click-options" class="dialogawp-click-options">
        <!-- Dynamic click options based on connected Output node -->
    </div>
</div>
<div class="dialogawp-notice" style="margin-top: 15px;">
    <p><strong>&#128161; How it works:</strong> Connect an Output node (with buttons) to this Click node. Each button creates a path that can be routed differently.</p>
</div>
HTML;

    $branch_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Output Groups</label>
    <p class="description">Each group creates one output handle. Define conditions within each group.</p>
    <div id="dialogawp-branch-groups" class="dialogawp-branch-groups">
        <!-- Dynamic output groups -->
    </div>
    <button class="button button-small dialogawp-add-output-group">
        <span class="dashicons dashicons-plus"></span> Add Output Group
    </button>
</div>
HTML;

    $action_node = <<<'HTML'
<div class="dialogawp-field">
    <label>Action Type</label>
    <select id="dialogawp-action-type" class="dialogawp-node-field" data-field="action_type">
        <option value="" {{selected_none}}>&mdash; Select an action type &mdash;</option>
        <option value="capture_lead" {{selected_capture_lead}}>Capture Lead</option>
        <option value="link_button" {{selected_link_button}}>Link Button</option>
        <option value="redirect" {{selected_redirect}}>Redirect</option>
        <option value="send_email" {{selected_send_email}}>Send Email</option>
        <option value="set_variable" {{selected_set_variable}}>Set Variable</option>
        <option value="tag_lead" {{selected_tag_lead}}>Tag Lead</option>
    </select>
    <p class="description">Choose what action to perform when this node is reached.</p>
</div>

<!-- Dynamic configuration container -->
<div id="dialogawp-action-config" class="dialogawp-action-config">
    <!-- Configuration fields will be dynamically inserted here -->
</div>
HTML;

    // Match only visible text/attributes, never graph fields or {{...}} tokens.
    $translations = array(
        'Updated: ' => esc_html__('Updated:', 'dialogawp') . ' ',
        '>Edit<' => '>' . esc_html__('Edit', 'dialogawp') . '<',
        '>Duplicate<' => '>' . esc_html__('Duplicate', 'dialogawp') . '<',
        '>Delete<' => '>' . esc_html__('Delete', 'dialogawp') . '<',
        '>Message Text<' => '>' . esc_html__('Message Text', 'dialogawp') . '<',
        'placeholder="Enter your message..."' => 'placeholder="' . esc_attr__('Enter your message...', 'dialogawp') . '"',
        '>This message will be displayed to the visitor.<' => '>' . esc_html__('This message will be displayed to the visitor.', 'dialogawp') . '<',
        '>Delay (ms)<' => '>' . esc_html__('Delay (ms)', 'dialogawp') . '<',
        '>Optional delay before showing this message (to simulate typing).<' => '>' . esc_html__('Optional delay before showing this message (to simulate typing).', 'dialogawp') . '<',
        'placeholder="What do you want to say or ask?"' => 'placeholder="' . esc_attr__('What do you want to say or ask?', 'dialogawp') . '"',
        '>The message shown to the visitor<' => '>' . esc_html__('The message shown to the visitor', 'dialogawp') . '<',
        '>Response Type<' => '>' . esc_html__('Response Type', 'dialogawp') . '<',
        '>Auto-continue (Display only)<' => '>' . esc_html__('Auto-continue (Display only)', 'dialogawp') . '<',
        " - Don't wait for response" => ' - ' . esc_html__("Don't wait for response", 'dialogawp'),
        '>Delay before continuing:' => '>' . esc_html__('Delay before continuing:', 'dialogawp'),
        '/> seconds' => '/> ' . esc_html__('seconds', 'dialogawp'),
        "Need free-form answers? Use the <strong>AI Chat</strong> node instead &mdash; it's designed for open-ended questions." => sprintf(
            /* translators: %s: AI Chat node label. */
            esc_html__("Need free-form answers? Use the %s node instead - it's designed for open-ended questions.", 'dialogawp'),
            '<strong>' . esc_html__('AI Chat', 'dialogawp') . '</strong>'
        ),
        '>Quick-Reply Buttons<' => '>' . esc_html__('Quick-Reply Buttons', 'dialogawp') . '<',
        '>The options visitors can click to answer this question.<' => '>' . esc_html__('The options visitors can click to answer this question.', 'dialogawp') . '<',
        '</span> Add Button' => '</span> ' . esc_html__('Add Button', 'dialogawp'),
        '>Allow multiple selections<' => '>' . esc_html__('Allow multiple selections', 'dialogawp') . '<',
        "Show checkboxes instead of buttons. The visitor can pick more than one; the answer is saved as a comma-separated list (e.g. <code>Individual, Couples</code>). Works with the Branch node's <em>contains</em> operator." => sprintf(
            /* translators: %1$s: example saved answer, %2$s: technical contains operator. */
            esc_html__('Show checkboxes instead of buttons. The visitor can pick more than one; the answer is saved as a comma-separated list (e.g. %1$s). Works with the Branch node\'s %2$s operator.', 'dialogawp'),
            '<code>Individual, Couples</code>', '<em>contains</em>'
        ),
        'Save answer as variable <span' => esc_html__('Save answer as variable', 'dialogawp') . ' <span',
        '>(optional)<' => '>' . esc_html__('(optional)', 'dialogawp') . '<',
        'placeholder="e.g. name, service, budget"' => 'placeholder="' . esc_attr(sprintf(
            /* translators: %s: example variable identifiers, do not translate. */
            __('e.g. %s', 'dialogawp'), 'name, service, budget'
        )) . '"',
        'Reference this answer later using <code>{{variable_name}}</code> in any message text.' => sprintf(
            /* translators: %s: literal variable placeholder. */
            esc_html__('Reference this answer later using %s in any message text.', 'dialogawp'), '<code>{{variable_name}}</code>'
        ),
        '>Output Message<' => '>' . esc_html__('Output Message', 'dialogawp') . '<',
        'placeholder="Enter the message to display to the user..."' => 'placeholder="' . esc_attr__('Enter the message to display to the user...', 'dialogawp') . '"',
        '>This message will be shown to the visitor when this path is taken.<' => '>' . esc_html__('This message will be shown to the visitor when this path is taken.', 'dialogawp') . '<',
        '>Action Buttons (Optional)<' => '>' . esc_html__('Action Buttons (Optional)', 'dialogawp') . '<',
        '>Add clickable buttons that link to pages or trigger actions.<' => '>' . esc_html__('Add clickable buttons that link to pages or trigger actions.', 'dialogawp') . '<',
        '>Detected Clicks<' => '>' . esc_html__('Detected Clicks', 'dialogawp') . '<',
        '>This node detects which button or link was clicked by the user. Connect from an Output node with buttons.<' => '>' . esc_html__('This node detects which button or link was clicked by the user. Connect from an Output node with buttons.', 'dialogawp') . '<',
        '&#128161; How it works:' => '&#128161; ' . esc_html__('How it works:', 'dialogawp'),
        ' Connect an Output node (with buttons) to this Click node. Each button creates a path that can be routed differently.' => ' ' . esc_html__('Connect an Output node (with buttons) to this Click node. Each button creates a path that can be routed differently.', 'dialogawp'),
        '>Output Groups<' => '>' . esc_html__('Output Groups', 'dialogawp') . '<',
        '>Each group creates one output handle. Define conditions within each group.<' => '>' . esc_html__('Each group creates one output handle. Define conditions within each group.', 'dialogawp') . '<',
        '</span> Add Output Group' => '</span> ' . esc_html__('Add Output Group', 'dialogawp'),
        '>Action Type<' => '>' . esc_html__('Action Type', 'dialogawp') . '<',
        '>&mdash; Select an action type &mdash;<' => '>&mdash; ' . esc_html__('Select an action type', 'dialogawp') . ' &mdash;<',
        '>Capture Lead<' => '>' . esc_html__('Capture Lead', 'dialogawp') . '<',
        '>Link Button<' => '>' . esc_html__('Link Button', 'dialogawp') . '<',
        '>Redirect<' => '>' . esc_html__('Redirect', 'dialogawp') . '<',
        '>Send Email<' => '>' . esc_html__('Send Email', 'dialogawp') . '<',
        '>Set Variable<' => '>' . esc_html__('Set Variable', 'dialogawp') . '<',
        '>Tag Lead<' => '>' . esc_html__('Tag Lead', 'dialogawp') . '<',
        '>Choose what action to perform when this node is reached.<' => '>' . esc_html__('Choose what action to perform when this node is reached.', 'dialogawp') . '<',
    );

    $templates = array(
        'flowItem'     => $flow_item,
        'messageNode'  => $message_node,
        'questionNode' => $question_node,
        'outputNode'   => $output_node,
        'clickNode'    => $click_node,
        'branchNode'   => $branch_node,
        'actionNode'   => $action_node,
    );
    foreach ($templates as $key => $html) {
        $templates[$key] = strtr($html, $translations);
    }
    return $templates;
}
