<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap dialogawp-ai-settings-page">
    <h1><?php echo esc_html__('DIALOGAWP - AI Settings', 'dialogawp'); ?></h1>
    <p class="description"><?php echo esc_html__('Connect an AI provider to power conversational responses in your chatbot flows.', 'dialogawp'); ?></p>

    <div class="dialogawp-ai-layout">

        <!-- ================================================================
             LEFT COLUMN: API config + instructions + cost calculator
             ================================================================ -->
        <div class="dialogawp-ai-col-main">

            <!-- API Configuration -->
            <div class="dialogawp-card">
                <h2><?php echo esc_html__('API Configuration', 'dialogawp'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><label for="ai-provider"><?php echo esc_html__('AI Provider', 'dialogawp'); ?></label></th>
                        <td>
                            <select id="ai-provider" class="regular-text">
                                <option value="anthropic">Anthropic (Claude)</option>
                                <option value="openai">OpenAI (ChatGPT)</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="row-anthropic-model">
                        <th><label for="ai-model-anthropic"><?php echo esc_html__('Claude Model', 'dialogawp'); ?></label></th>
                        <td>
                            <select id="ai-model-anthropic" class="regular-text">
                                <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 &mdash; <?php echo esc_html__('Fast & affordable', 'dialogawp'); ?></option>
                                <option value="claude-sonnet-4-6">Claude Sonnet 4.6 &mdash; <?php echo esc_html__('Balanced', 'dialogawp'); ?></option>
                                <option value="claude-opus-4-6">Claude Opus 4.6 &mdash; <?php echo esc_html__('Most capable', 'dialogawp'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="row-openai-model" style="display:none;">
                        <th><label for="ai-model-openai"><?php echo esc_html__('GPT Model', 'dialogawp'); ?></label></th>
                        <td>
                            <select id="ai-model-openai" class="regular-text">
                                <option value="gpt-4o-mini">GPT-4o Mini &mdash; <?php echo esc_html__('Fast & affordable', 'dialogawp'); ?></option>
                                <option value="gpt-4o">GPT-4o &mdash; <?php echo esc_html__('Most capable', 'dialogawp'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai-api-key"><?php echo esc_html__('API Key', 'dialogawp'); ?></label></th>
                        <td>
                            <input type="password" id="ai-api-key" class="regular-text" placeholder="<?php echo esc_attr__('Paste your API key here', 'dialogawp'); ?>" autocomplete="new-password" />
                            <p class="description" id="ai-api-key-current"></p>
                            <p class="description">
                                <?php echo esc_html__('Anthropic key:', 'dialogawp'); ?> <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>
                                &nbsp;|&nbsp;
                                <?php echo esc_html__('OpenAI key:', 'dialogawp'); ?> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai-max-tokens"><?php echo esc_html__('Max Response Tokens', 'dialogawp'); ?></label></th>
                        <td>
                            <input type="number" id="ai-max-tokens" class="small-text" value="500" min="100" max="2000" />
                            <p class="description"><?php echo esc_html__('Controls how long AI responses can be. 500 is a good default for chat.', 'dialogawp'); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="dialogawp-ai-actions">
                    <button class="button button-primary" id="ai-save-settings"><?php echo esc_html__('Save Settings', 'dialogawp'); ?></button>
                    <button class="button" id="ai-test-connection"><?php echo esc_html__('Test Connection', 'dialogawp'); ?></button>
                    <span class="dialogawp-ai-status" id="ai-save-status"></span>
                </div>
            </div>

            <!-- Custom Instructions -->
            <div class="dialogawp-card">
                <h2><?php echo esc_html__('Custom Instructions & Memory', 'dialogawp'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Tell the AI about your business, services, tone of voice, and how to handle common questions. This is included in every AI conversation.', 'dialogawp'); ?>
                </p>
                <textarea id="ai-instructions" rows="10" style="width:100%;font-family:monospace;font-size:13px;"
                    placeholder="<?php echo esc_attr__('Examples:
- We are a mental health practice in Austin, TX.
- Our services include therapy, couples counseling, and psychiatry.
- Always be warm and empathetic. Never diagnose or give medical advice.
- If someone seems in crisis, immediately share the 988 Suicide Hotline.
- Our intake coordinator is Sarah — hello@example.com.
- We accept Aetna, BCBS, and Cigna insurance.', 'dialogawp'); ?>"></textarea>
                <p class="description"><?php echo esc_html__('Tip: Be specific. The more context you give, the better the AI represents your business.', 'dialogawp'); ?></p>
                <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
                    <button class="button button-primary" id="ai-save-instructions"><?php echo esc_html__('Save Instructions', 'dialogawp'); ?></button>
                    <span class="dialogawp-ai-status" id="ai-instructions-status"></span>
                </div>
            </div>

            <!-- Cost Estimator -->
            <div class="dialogawp-card">
                <h2><?php echo esc_html__('Cost Estimator', 'dialogawp'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Estimate your monthly AI spend based on expected usage.', 'dialogawp'); ?>
                    <strong><?php echo esc_html__('You are billed directly by Anthropic or OpenAI - DIALOGAWP does not charge for AI usage.', 'dialogawp'); ?></strong>
                </p>

                <table class="form-table" style="margin-bottom:0;">
                    <tr>
                        <th style="width:220px;"><label><?php echo esc_html__('Monthly Conversations', 'dialogawp'); ?></label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <input type="range" id="cost-conversations" min="10" max="10000" step="10" value="500" style="width:200px;" />
                                <input type="number" id="cost-conversations-num" value="500" min="1" max="999999" style="width:80px;" class="small-text" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php echo esc_html__('Avg Messages per Conversation', 'dialogawp'); ?></label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <input type="range" id="cost-messages" min="1" max="30" step="1" value="5" style="width:200px;" />
                                <input type="number" id="cost-messages-num" value="5" min="1" max="100" style="width:80px;" class="small-text" />
                            </div>
                        </td>
                    </tr>
                </table>

                <div id="cost-result" style="margin-top:16px;padding:16px;background:#F7F8FC;border-radius:4px;border:1px solid #E5E7EF;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                        <div id="cost-breakdown" style="font-size:13px;color:#3c434a;line-height:1.8;"></div>
                        <div style="text-align:right;">
                            <div style="font-size:13px;color:#787c82;"><?php echo esc_html__('Estimated monthly cost', 'dialogawp'); ?></div>
                            <div id="cost-estimate" style="font-size:32px;font-weight:700;color:#26233A;line-height:1.2;">$0.00</div>
                            <div id="cost-per-convo" style="font-size:12px;color:#787c82;"></div>
                        </div>
                    </div>
                </div>

                <p class="description" style="margin-top:10px;font-size:12px;">
                    <?php
                    /* translators: %s: provider pricing link. */
                    echo sprintf(esc_html__('* Estimates are based on ~1,000 input tokens and ~250 output tokens per AI message, using %s as of March 2026. Actual costs vary. Always monitor your API dashboard.', 'dialogawp'),
                        '<a href="https://openai.com/api/pricing/" target="_blank" rel="noopener">' . esc_html__('publicly listed pricing', 'dialogawp') . '</a>');
                    ?>
                </p>
            </div>

        </div><!-- .dialogawp-ai-col-main -->

        <!-- ================================================================
             RIGHT COLUMN: Knowledge base
             ================================================================ -->
        <div class="dialogawp-ai-col-side">
            <div class="dialogawp-card">
                <h2><?php echo esc_html__('Website Knowledge Base', 'dialogawp'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Index your pages and posts so the AI can answer questions about your business. Only the most relevant content is sent per message - not everything at once.', 'dialogawp'); ?>
                </p>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                    <button class="button button-primary" id="ai-crawl-website">
                        <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
                        <?php echo esc_html__('Crawl Website', 'dialogawp'); ?>
                    </button>
                </div>
                <span class="dialogawp-ai-status" id="ai-crawl-status" style="display:block;margin-top:6px;font-size:12px;"></span>
                <p class="description" style="margin-top:8px;font-size:12px;">
                    &#x2728; <?php
                    /* translators: %s: AI Flow Wizard label. */
                    echo sprintf(esc_html__("Once crawled, an %s will appear to automatically generate a custom chatbot flow from your site's content.", 'dialogawp'), '<strong>' . esc_html__('AI Flow Wizard', 'dialogawp') . '</strong>');
                    ?>
                </p>

                <div id="ai-knowledge-list" style="margin-top:16px;"></div>
            </div>

            <!-- Flow Wizard trigger (shown after knowledge base has items) -->
            <div class="dialogawp-card" id="ai-wizard-card" style="display:none;background:linear-gradient(135deg,#f0f6ff,#fff);">
                <h2 style="color:#190F4F;">&#x2728; <?php echo esc_html__('AI Flow Wizard', 'dialogawp'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Your site has been indexed. Let the AI analyze your content and automatically generate a customized intake flow for your business.', 'dialogawp'); ?>
                </p>
                <button class="button button-primary dialogawp-cta" id="ai-generate-flow">
                    <?php echo esc_html__('Generate Suggested Flow', 'dialogawp'); ?>
                </button>
                <span class="dialogawp-ai-status" id="ai-wizard-trigger-status" style="display:block;margin-top:6px;font-size:12px;"></span>
            </div>

            <div class="dialogawp-card" style="background:#F7F8FC;">
                <h3 style="margin-top:0;font-size:13px;"><?php echo esc_html__('How retrieval works', 'dialogawp'); ?></h3>
                <p class="description" style="font-size:12px;line-height:1.6;">
                    <?php echo esc_html__('When a visitor sends a message, DIALOGAWP finds the most relevant pages from your knowledge base and sends only those to the AI - keeping costs low and responses focused.', 'dialogawp'); ?>
                </p>
                <p class="description" style="font-size:12px;line-height:1.6;">
                    <?php
                    /* translators: %1$s: OpenAI users label, %2$s: Anthropic users label. */
                    echo sprintf(esc_html__('%1$s get semantic search (understands meaning). %2$s get keyword search. Both work well for most sites.', 'dialogawp'),
                        '<strong>' . esc_html__('OpenAI users', 'dialogawp') . '</strong>', '<strong>' . esc_html__('Anthropic users', 'dialogawp') . '</strong>');
                    ?>
                </p>
            </div>
        </div><!-- .dialogawp-ai-col-side -->

    </div><!-- .dialogawp-ai-layout -->

    <!-- ================================================================
         FLOW WIZARD PANEL (full width, below both columns)
         ================================================================ -->
    <div id="ai-flow-wizard" style="display:none;margin-top:20px;">
        <div class="dialogawp-card">

            <div id="wizard-loading" style="display:none;text-align:center;padding:30px 0;">
                <span class="dashicons dashicons-update" style="font-size:32px;color:#190F4F;animation:dialogawp-spin 1s linear infinite;"></span>
                <p style="margin-top:12px;font-size:15px;color:#3c434a;"><?php echo esc_html__('Analyzing your website and generating a personalized flow...', 'dialogawp'); ?></p>
                <p class="description"><?php echo esc_html__('This usually takes 10-20 seconds.', 'dialogawp'); ?></p>
            </div>

            <div id="wizard-result" style="display:none;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                    <h2 style="margin:0;">&#x2728; <?php echo esc_html__('AI-Generated Flow Suggestion', 'dialogawp'); ?></h2>
                    <button class="button" id="wizard-dismiss" style="flex-shrink:0;"><?php echo esc_html__('Dismiss', 'dialogawp'); ?></button>
                </div>
                <div id="wizard-summary" style="margin-bottom:16px;padding:12px;background:#f0f6ff;border-radius:4px;border:1px solid #c3d9f7;font-size:13px;"></div>
                <h3 style="margin-top:0;"><?php echo esc_html__('Suggested Flow Steps', 'dialogawp'); ?></h3>
                <ol id="wizard-steps" style="font-size:13px;line-height:2;"></ol>
                <p class="description" style="font-size:12px;margin-top:6px;">&#9998; <?php echo esc_html__('Edit any message above before creating the flow.', 'dialogawp'); ?></p>
                <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
                    <button class="button button-primary button-large dialogawp-cta" id="wizard-create-flow"><?php echo esc_html__('Create This Flow', 'dialogawp'); ?> &rarr;</button>
                    <span class="dialogawp-ai-status" id="wizard-create-status"></span>
                </div>
                <p class="description" style="margin-top:8px;">
                    <?php echo esc_html__('The flow will open in the builder where you can customize every node before activating it.', 'dialogawp'); ?>
                </p>
            </div>

            <div id="wizard-error" style="display:none;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                    <h2 style="margin:0;color:#d63638;"><?php echo esc_html__('Could Not Generate Flow', 'dialogawp'); ?></h2>
                    <button class="button" id="wizard-dismiss-error"><?php echo esc_html__('Dismiss', 'dialogawp'); ?></button>
                </div>
                <p class="description"><span id="wizard-error-msg"></span></p>
                <p class="description">
                    <?php echo esc_html__('A basic template flow has been prepared instead. You can create it and customize it in the builder.', 'dialogawp'); ?>
                </p>
                <div style="display:flex;align-items:center;gap:12px;margin-top:12px;">
                    <button class="button button-primary" id="wizard-use-template"><?php echo esc_html__('Use Template Flow Instead', 'dialogawp'); ?></button>
                    <span class="dialogawp-ai-status" id="wizard-template-status"></span>
                </div>
            </div>

        </div>
    </div><!-- #ai-flow-wizard -->

</div><!-- .wrap -->

<?php
// Screen CSS lives in assets/css/screen-ai-settings.css and is enqueued from
// DialogaWP::enqueue_admin_assets(). It cannot be attached here:
// wp_add_inline_style() is a no-op once the handle has printed.
?>

<?php
// wp.org: no output buffering — the asset body is a nowdoc string,
// so there is no buffer that a hook or early return could leave open.
$dialogawp_ai_js = <<<'JS'
jQuery(function($) {
    const t = s => (dialogawpAdmin.i18n || {})[s] || s;
    const ajax  = dialogawpAdmin.ajaxUrl;
    const nonce = dialogawpAdmin.nonce;

    // =========================================================================
    // MODEL COSTS — hardcoded, last updated March 2026
    // Update these when providers change their pricing.
    // Pricing page: anthropic.com/pricing | openai.com/api/pricing
    // =========================================================================
    const MODEL_COSTS = {
        'claude-haiku-4-5-20251001': { input: 0.80,  output: 4.00  },
        'claude-sonnet-4-6':         { input: 3.00,  output: 15.00 },
        'claude-opus-4-6':           { input: 15.00, output: 75.00 },
        'gpt-4o-mini':               { input: 0.15,  output: 0.60  },
        'gpt-4o':                    { input: 2.50,  output: 10.00 },
    };
    const EMBED_COST_PER_1M = 0.02; // text-embedding-3-small
    // Assumptions per AI message turn
    const AVG_INPUT_TOKENS  = 1000; // system prompt + history + context
    const AVG_OUTPUT_TOKENS = 250;  // AI response
    const AVG_EMBED_TOKENS  = 30;   // user query embedding

    // =========================================================================
    // Helpers
    // =========================================================================
    function currentModel() {
        return $('#ai-provider').val() === 'openai'
            ? $('#ai-model-openai').val()
            : $('#ai-model-anthropic').val();
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // =========================================================================
    // Load settings on page load
    // =========================================================================
    $.post(ajax, { action: 'dialogawp_get_ai_settings', nonce: nonce }, function(res) {
        if (!res.success) return;
        const s = res.data;

        $('#ai-provider').val(s.provider || 'anthropic').trigger('change');

        if (s.provider === 'openai') {
            $('#ai-model-openai').val(s.model || 'gpt-4o-mini');
        } else {
            $('#ai-model-anthropic').val(s.model || 'claude-haiku-4-5-20251001');
        }

        $('#ai-max-tokens').val(s.max_tokens || 500);
        $('#ai-instructions').val(s.instructions || '');

        if (s.api_key_masked) {
            $('#ai-api-key-current').text(t('Current: %s - leave blank to keep').replace('%s', () => s.api_key_masked));
        }

        updateCostEstimate();
    });

    // =========================================================================
    // Provider / model toggle
    // =========================================================================
    $('#ai-provider').on('change', function() {
        const p = $(this).val();
        $('#row-anthropic-model').toggle(p === 'anthropic');
        $('#row-openai-model').toggle(p === 'openai');
        updateCostEstimate();
    });

    $('#ai-model-anthropic, #ai-model-openai').on('change', updateCostEstimate);


    // =========================================================================
    // Cost estimator
    // =========================================================================
    function updateCostEstimate() {
        const conversations = parseInt($('#cost-conversations').val()) || 500;
        const messages      = parseInt($('#cost-messages').val()) || 5;
        const model         = currentModel();
        const costs         = MODEL_COSTS[model] || MODEL_COSTS['claude-haiku-4-5-20251001'];

        const inputCost  = (AVG_INPUT_TOKENS  / 1_000_000) * costs.input;
        const outputCost = (AVG_OUTPUT_TOKENS / 1_000_000) * costs.output;
        const embedCost  = (AVG_EMBED_TOKENS  / 1_000_000) * EMBED_COST_PER_1M;
        const perMessage = inputCost + outputCost + embedCost;
        const perConvo   = perMessage * messages;
        const monthly    = perConvo * conversations;

        $('#cost-estimate').text('$' + monthly.toFixed(2));
        $('#cost-per-convo').text(t('Approx. $%s per conversation').replace('%s', perConvo.toFixed(4)));
        $('#cost-breakdown').html(
            `<span style="color:#787c82;">${escHtml(t('Model:'))}</span> ${escHtml(model)}<br>` +
            `<span style="color:#787c82;">${escHtml(t('Per message:'))}</span> $${perMessage.toFixed(5)}<br>` +
            `<span style="color:#787c82;">${escHtml(t('Messages x conversations:'))}</span> ${messages} × ${conversations.toLocaleString()}`
        );
    }

    // Sync sliders ↔ number inputs
    $('#cost-conversations').on('input', function() {
        $('#cost-conversations-num').val($(this).val());
        updateCostEstimate();
    });
    $('#cost-conversations-num').on('input', function() {
        $('#cost-conversations').val(Math.min($(this).val(), 10000));
        updateCostEstimate();
    });
    $('#cost-messages').on('input', function() {
        $('#cost-messages-num').val($(this).val());
        updateCostEstimate();
    });
    $('#cost-messages-num').on('input', function() {
        $('#cost-messages').val(Math.min($(this).val(), 30));
        updateCostEstimate();
    });

    // =========================================================================
    // Save settings
    // =========================================================================
    function saveSettings($status) {
        $status.removeClass('error').text(t('Saving...'));
        $.post(ajax, {
            action:       'dialogawp_save_ai_settings',
            nonce:        nonce,
            provider:     $('#ai-provider').val(),
            model:        currentModel(),
            api_key:      $('#ai-api-key').val(),
            max_tokens:   $('#ai-max-tokens').val(),
            instructions: $('#ai-instructions').val(),
        }, function(res) {
            if (res.success) {
                $status.removeClass('error').text('✓ ' + t('Saved!'));
            } else {
                $status.addClass('error').text(t('Error: %s').replace('%s', () => res.data));
            }
            setTimeout(() => $status.text(''), 3000);
        });
    }

    $('#ai-save-settings').on('click', function() {
        $(this).prop('disabled', true);
        saveSettings($('#ai-save-status'));
        setTimeout(() => $(this).prop('disabled', false), 1500);
    });

    $('#ai-save-instructions').on('click', function() {
        $(this).prop('disabled', true);
        saveSettings($('#ai-instructions-status'));
        setTimeout(() => $(this).prop('disabled', false), 1500);
    });

    // =========================================================================
    // Test connection
    // =========================================================================
    $('#ai-test-connection').on('click', function() {
        const $btn = $(this);
        const $s   = $('#ai-save-status');
        $btn.prop('disabled', true);
        $s.removeClass('error').text(t('Testing...'));

        $.post(ajax, { action: 'dialogawp_test_ai', nonce: nonce }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $s.removeClass('error').text('✓ ' + res.data.message);
            } else {
                $s.addClass('error').text(t('Error: %s').replace('%s', () => res.data));
            }
            setTimeout(() => $s.text(''), 8000);
        });
    });

    // =========================================================================
    // Crawl website
    // =========================================================================
    $('#ai-crawl-website').on('click', function() {
        const $btn = $(this);
        const $s   = $('#ai-crawl-status');
        $btn.prop('disabled', true);
        $s.removeClass('error').text(t('Crawling...'));

        $.post(ajax, { action: 'dialogawp_crawl_website', nonce: nonce }, function(res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $s.removeClass('error').text('✓ ' + res.data.message);
                loadKnowledge();
            } else {
                $s.addClass('error').text(t('Error: %s').replace('%s', () => res.data));
            }
            setTimeout(() => $s.text(''), 10000);
        });
    });

    // =========================================================================
    // Knowledge base list
    // =========================================================================
    function loadKnowledge() {
        $('#ai-crawl-website').prop('disabled', true);
        $('#ai-knowledge-list').html(
            '<p class="description" style="color:#787c82;">' +
            '<span class="dashicons dashicons-update" style="font-size:14px;vertical-align:middle;margin-top:-2px;animation:dialogawp-spin 1s linear infinite;display:inline-block;"></span>' +
            ' ' + escHtml(t('Loading knowledge base...')) + '</p>'
        );

        $.post(ajax, { action: 'dialogawp_get_ai_knowledge', nonce: nonce }, function(res) {
            $('#ai-crawl-website').prop('disabled', false);
            const $list = $('#ai-knowledge-list');

            if (!res.success || !res.data.length) {
                $list.html('<p class="description">' + escHtml(t('No content indexed yet. Click "Crawl Website" to get started.')) + '</p>');
                $('#ai-wizard-card').hide();
                return;
            }

            // Show the wizard card once there's something to work with
            $('#ai-wizard-card').show();

            const total = res.data.length;

            let items = '';
            res.data.forEach(function(item) {
                items += `
                    <div class="dialogawp-knowledge-item" data-id="${item.id}">
                        <span class="ki-type">${escHtml(({page: t('Page'), post: t('Post')})[item.content_type] || item.content_type)}</span>
                        <span class="ki-title" title="${escHtml(item.url)}">${escHtml(item.title)}</span>
                        <span class="ki-tokens" title="${escHtml(t('~%d tokens - estimated AI context size for this page').replace('%d', item.token_estimate))}">~${item.token_estimate}t</span>
                        <button class="ki-delete" title="${escHtml(t('Remove'))}">&times;</button>
                    </div>`;
            });

            let html = `
                <div class="dialogawp-kb-toggle" id="ki-toggle">
                    <span class="ki-count">${escHtml((total === 1 ? t('%d item indexed') : t('%d items indexed')).replace('%d', total))}</span>
                    <span class="ki-chevron">&#9654;</span>
                </div>
                <div class="dialogawp-kb-items" id="ki-items" style="display:none;">
                    ${items}
                </div>`;

            $list.html(html);

            $('#ki-toggle').on('click', function() {
                const $items = $('#ki-items');
                const open   = $items.is(':visible');
                $items.slideToggle(150);
                $(this).find('.ki-chevron').css('transform', open ? '' : 'rotate(90deg)');
            });

            $list.find('.ki-delete').on('click', function() {
                const $item = $(this).closest('.dialogawp-knowledge-item');
                const id    = $item.data('id');
                if (!confirm(t('Remove this item from the knowledge base?'))) return;
                $.post(ajax, { action: 'dialogawp_delete_knowledge_item', nonce: nonce, id: id }, function(res) {
                    if (res.success) loadKnowledge();
                });
            });
        });
    }

    // =========================================================================
    // AI Flow Wizard
    // =========================================================================
    let wizardFlowData = null;

    $('#ai-generate-flow').on('click', function() {
        const $btn = $(this);
        const $s   = $('#ai-wizard-trigger-status');
        $btn.prop('disabled', true);
        $s.removeClass('error').text('');

        $('#ai-flow-wizard').show();
        $('#wizard-loading').show();
        $('#wizard-result').hide();
        $('#wizard-error').hide();

        $('html,body').animate({ scrollTop: $('#ai-flow-wizard').offset().top - 40 }, 400);

        $.post(ajax, { action: 'dialogawp_generate_flow', nonce: nonce }, function(res) {
            $btn.prop('disabled', false);
            $('#wizard-loading').hide();

            if (res.success && res.data.flow) {
                wizardFlowData = res.data.flow;
                renderWizardPreview(res.data.flow);
                $('#wizard-result').show();
            } else {
                const errData = res.data || {};
                const msg     = typeof errData === 'string' ? errData : (errData.message || t('Unknown error'));

                // Log the raw AI response so we can debug
                if (errData.raw_preview) {
                    console.error('🤖 DIALOGAWP wizard — AI raw response preview:', errData.raw_preview);
                } else {
                    console.error('🤖 DIALOGAWP wizard error:', errData);
                }

                // Offer fallback if available
                if (errData.fallback) {
                    wizardFlowData = errData.fallback;
                }

                $('#wizard-error-msg').text(msg);
                $('#wizard-error').show();
                $('#wizard-use-template').toggle(!!errData.fallback);
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#wizard-loading').hide();
            $('#wizard-error-msg').text(t('Network error. Please try again.'));
            $('#wizard-error').show();
        });
    });

    function renderWizardPreview(flow) {
        // Business summary
        if (flow.business_summary) {
            $('#wizard-summary').html(
                '<strong>' + escHtml(t('Business detected:')) + '</strong> ' + escHtml(flow.business_summary)
            ).show();
        } else {
            $('#wizard-summary').hide();
        }

        // Human-readable step list — editable where text matters
        const $steps = $('#wizard-steps').empty();
        (flow.nodes || []).forEach(function(node, index) {
            const d   = node.data;
            let   desc = '';

            if (d.nodeType === 'question') {
                const msg = d.message || d.label || '';
                desc = '<div style="margin-bottom:4px;">' +
                    '<textarea class="wizard-editable-msg" data-node-index="' + index + '" ' +
                    'rows="2" style="width:100%;font-size:13px;padding:4px 6px;border:1px solid #E5E7EF;border-radius:3px;resize:vertical;">' +
                    escHtml(msg) + '</textarea></div>';
                if (d.options && d.options.length) {
                    const opts = d.options.map(o => '<em>' + escHtml(o.label) + '</em>').join(', ');
                    desc += '<span style="color:#787c82;font-size:12px;">' + escHtml(t('Options:')) + ' ' + opts + '</span>';
                }
                if (d.allow_text_input) {
                    desc += ' <span style="color:#787c82;font-size:12px;">' + escHtml(t('[free text]')) + '</span>';
                }
            } else if (d.nodeType === 'branch') {
                desc = '<span style="color:#787c82;">' + escHtml(t('Branch: route visitors based on their answer')) + '</span>';
            } else if (d.nodeType === 'action') {
                if (d.action_type === 'capture_lead') {
                    const cfg    = d.config || {};
                    const fields = cfg.fields
                        ? Object.values(cfg.fields).filter(f => f.enabled).map(f => f.label).join(', ')
                        : t('Name') + ', ' + t('Email');
                    desc = '<strong>' + escHtml(t('Capture lead:')) + '</strong> ' + escHtml(t('collect %s').replace('%s', () => fields));
                } else if (d.action_type === 'ai_chat') {
                    desc = '<strong>' + escHtml(t('AI Chat:')) + '</strong> ' + escHtml((d.config && d.config.greeting) || t('Open AI conversation'));
                } else if (d.action_type === 'redirect') {
                    desc = '<strong>' + escHtml(t('Redirect to:')) + '</strong> ' + escHtml((d.config && d.config.url) || '');
                } else {
                    desc = '<strong>' + escHtml(t('Action:')) + '</strong> ' + escHtml(d.action_type || '');
                }
            }

            $steps.append('<li style="margin-bottom:10px;">' + desc + '</li>');
        });

    }

    // Collect edited messages from the preview back into wizardFlowData before saving
    function applyWizardEdits() {
        $('.wizard-editable-msg').each(function() {
            const index = parseInt($(this).data('node-index'));
            const newMsg = $(this).val();
            if (wizardFlowData && wizardFlowData.nodes && wizardFlowData.nodes[index]) {
                wizardFlowData.nodes[index].data.message = newMsg;
                wizardFlowData.nodes[index].data.label   = newMsg;
            }
        });
    }

    // Save the flow via the existing save handler, then redirect to builder
    function createFlow($statusEl) {
        if (!wizardFlowData) return;

        $statusEl.removeClass('error').text(t('Creating flow...'));

        const formData = new FormData();
        formData.append('action',          'dialogawp_save_flow');
        formData.append('nonce',           nonce);
        formData.append('flow_id',         0);
        formData.append('flow_name',       wizardFlowData.flow_name || 'AI-Generated Flow');
        formData.append('is_active',       1);
        formData.append('visibility_mode', 'everyone');
        formData.append('visible_roles',   '[]');
        formData.append('flow_data',       JSON.stringify({
            nodes: wizardFlowData.nodes,
            edges: wizardFlowData.edges,
        }));

        fetch(ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(function(res) {
                if (res.success) {
                    $statusEl.text('✓ ' + t('Flow created! Opening builder...'));
                    const flowId = res.data.flow_id;
                    setTimeout(function() {
                        window.location.href = dialogawpAdmin.adminUrl +
                            'admin.php?page=dialogawp-builder&flow_id=' + flowId;
                    }, 1000);
                } else {
                    $statusEl.addClass('error').text(t('Error: %s').replace('%s', () => res.data || t('Save failed')));
                }
            })
            .catch(function() {
                $statusEl.addClass('error').text(t('Network error. Please try again.'));
            });
    }

    $('#wizard-create-flow').on('click', function() {
        applyWizardEdits();
        createFlow($('#wizard-create-status'));
    });

    $('#wizard-use-template').on('click', function() {
        applyWizardEdits();
        createFlow($('#wizard-template-status'));
    });

    $('#wizard-dismiss').on('click', function() {
        $('#ai-flow-wizard').hide();
        wizardFlowData = null;
    });

    $('#wizard-dismiss-error').on('click', function() {
        $('#ai-flow-wizard').hide();
    });

    // =========================================================================
    // Init
    // =========================================================================
    loadKnowledge();
    updateCostEstimate();
});
JS;
wp_add_inline_script('dialogawp-admin', $dialogawp_ai_js, 'after');
?>
