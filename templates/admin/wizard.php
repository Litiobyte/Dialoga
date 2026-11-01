<?php if (!defined('ABSPATH')) exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Getting Started — DialogaWP', 'dialogawp'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="dialogawp-wizard-body">

<div class="dialogawp-wizard-wrap" id="dialogawp-wizard">

    <!-- Header -->
    <div class="dialogawp-wizard-header">
        <div class="dialogawp-wizard-brand">
            <img src="<?php echo esc_url(DIALOGAWP_LOGO_URL); ?>" alt="DialogaWP" class="dialogawp-wizard-brand-logo" onerror="this.style.display='none'" />
        </div>
        <div class="dialogawp-wizard-progress" id="wizard-progress"></div>
        <div class="dialogawp-wizard-header-actions">
            <span class="dialogawp-wizard-save-pill" id="wizard-save-pill" aria-live="polite">
                <span class="dialogawp-wizard-save-dot"></span>
                <span class="dialogawp-wizard-save-text"><?php esc_html_e('Progress saved', 'dialogawp'); ?></span>
            </span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialogawp')); ?>" class="dialogawp-wizard-exit-btn" id="wizard-save-exit" title="<?php esc_attr_e('Your progress is auto-saved. Resume anytime from the Dashboard.', 'dialogawp'); ?>"><?php esc_html_e('Save & Exit', 'dialogawp'); ?></a>
            <button class="dialogawp-wizard-skip-btn" id="wizard-skip"><?php esc_html_e('Skip Setup', 'dialogawp'); ?></button>
        </div>
    </div>

    <!-- Loading state — shown until JS initializes -->
    <div class="dialogawp-wizard-loading" id="wizard-loading">
        <div class="dialogawp-wizard-spinner"></div>
        <p style="color:#94A3B8;font-size:14px;margin-top:12px;"><?php esc_html_e('Loading wizard...', 'dialogawp'); ?></p>
    </div>

    <!-- Steps Container -->
    <div class="dialogawp-wizard-content" style="display:none;">
        <div class="dialogawp-wizard-slider" id="wizard-slider">

            <!-- Step 0: Welcome -->
            <div class="dialogawp-wizard-step" data-step="0">
                <div class="dialogawp-wizard-card dialogawp-wizard-card-wide">
                    <div class="dialogawp-wizard-welcome-icon">
                        <img src="<?php echo esc_url(DIALOGAWP_LOGO_URL); ?>" alt="DialogaWP" style="height:100px;width:auto;" onerror="this.style.display='none'" />
                    </div>
                    <h1><?php esc_html_e('Welcome to DialogaWP', 'dialogawp'); ?></h1>
                    <p class="dialogawp-wizard-subtitle"><?php esc_html_e('Let\'s build your first chatbot in just a few minutes. This wizard will walk you through everything — no coding required.', 'dialogawp'); ?></p>
                    <div class="dialogawp-wizard-welcome-steps" id="wizard-welcome-steps">
                        <div class="dialogawp-wizard-welcome-step" data-goto="1">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-1">1</div>
                            <div>
                                <strong><?php esc_html_e('AI Setup', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('Connect your AI provider (OpenAI or Anthropic)', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                        <div class="dialogawp-wizard-welcome-step" data-goto="2">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-2">2</div>
                            <div>
                                <strong><?php esc_html_e('Crawl Website', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('We\'ll learn about your business from your website', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                        <div class="dialogawp-wizard-welcome-step" data-goto="3">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-3">3</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Customization', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('Add personality and instructions for your chatbot', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                        <div class="dialogawp-wizard-welcome-step" data-goto="4">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-4">4</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Goals', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('Select what you want your chatbot to do', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                        <div class="dialogawp-wizard-welcome-step" data-goto="5">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-5">5</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Visibility', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('Choose who can see your chatbot', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                        <div class="dialogawp-wizard-welcome-step" data-goto="6">
                            <div class="dialogawp-wizard-welcome-num" id="welcome-step-6">6</div>
                            <div>
                                <strong><?php esc_html_e('Launch Chatbot', 'dialogawp'); ?></strong>
                                <span><?php esc_html_e('Review and create your chatbot', 'dialogawp'); ?></span>
                            </div>
                            <div class="dialogawp-wizard-welcome-arrow">&#8250;</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 1: AI Setup -->
            <div class="dialogawp-wizard-step" data-step="1">
                <div class="dialogawp-wizard-card">
                    <h2><?php esc_html_e('Connect Your AI Provider', 'dialogawp'); ?></h2>
                    <p class="dialogawp-wizard-desc"><?php esc_html_e('DialogaWP uses AI to power your chatbot conversations. You\'ll need an API key from OpenAI or Anthropic.', 'dialogawp'); ?></p>

                    <!-- Instructions accordion — shown first for visibility -->
                    <div class="dialogawp-wizard-accordion" id="wizard-instructions-accordion" style="margin-bottom:24px;">
                        <button class="dialogawp-wizard-accordion-toggle" id="wizard-instructions-toggle">
                            <?php esc_html_e('How do I get an API key?', 'dialogawp'); ?> <span class="dialogawp-wizard-chevron">&#9660;</span>
                        </button>
                        <div class="dialogawp-wizard-accordion-body" id="wizard-instructions-body">
                            <div class="dialogawp-wizard-instructions" id="wizard-openai-instructions">
                                <h4><?php esc_html_e('Getting an OpenAI API Key', 'dialogawp'); ?></h4>
                                <ol>
                                    <li><?php echo wp_kses_post(__('Go to <a href="https://platform.openai.com/signup" target="_blank">platform.openai.com</a> and create an account', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Navigate to <strong>API Keys</strong> in the left sidebar', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Click <strong>"Create new secret key"</strong>', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Copy the key (starts with <code>sk-</code>) and paste it below', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Add billing: Go to <strong>Settings → Billing</strong> and add a payment method', 'dialogawp')); ?></li>
                                </ol>
                                <p class="dialogawp-wizard-note"><?php esc_html_e('Typical cost: $0.01-0.05 per chatbot conversation with GPT-4o Mini.', 'dialogawp'); ?></p>
                            </div>
                            <div class="dialogawp-wizard-instructions" id="wizard-anthropic-instructions" style="display:none;">
                                <h4><?php esc_html_e('Getting an Anthropic API Key', 'dialogawp'); ?></h4>
                                <ol>
                                    <li><?php echo wp_kses_post(__('Go to <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a> and create an account', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Navigate to <strong>API Keys</strong>', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Click <strong>"Create Key"</strong>', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Copy the key (starts with <code>sk-ant-</code>) and paste it below', 'dialogawp')); ?></li>
                                    <li><?php echo wp_kses_post(__('Add billing: Go to <strong>Plans & Billing</strong> and add a payment method', 'dialogawp')); ?></li>
                                </ol>
                                <p class="dialogawp-wizard-note"><?php esc_html_e('Typical cost: $0.01-0.03 per chatbot conversation with Claude Haiku.', 'dialogawp'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="dialogawp-wizard-field">
                        <label><?php esc_html_e('AI Provider', 'dialogawp'); ?></label>
                        <div class="dialogawp-wizard-radio-group dialogawp-wizard-radio-group-3">
                            <label class="dialogawp-wizard-radio-card" data-provider="openai">
                                <input type="radio" name="ai_provider" value="openai" checked />
                                <div class="dialogawp-wizard-radio-content">
                                    <strong>OpenAI</strong>
                                    <span>GPT-4o, GPT-4o mini</span>
                                </div>
                            </label>
                            <label class="dialogawp-wizard-radio-card" data-provider="anthropic">
                                <input type="radio" name="ai_provider" value="anthropic" />
                                <div class="dialogawp-wizard-radio-content">
                                    <strong>Anthropic</strong>
                                    <span>Claude Haiku, Sonnet, Opus</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="dialogawp-wizard-field">
                        <label for="wizard-api-key"><?php esc_html_e('API Key', 'dialogawp'); ?></label>
                        <div class="dialogawp-wizard-key-row" id="wizard-key-row-saved" style="display:none;">
                            <input type="text" value="••••••••••••••••••••••••••••" readonly class="dialogawp-wizard-key-masked" />
                            <button type="button" class="dialogawp-wizard-btn dialogawp-wizard-btn-outline dialogawp-wizard-btn-sm" id="wizard-change-key"><?php esc_html_e('Change Key', 'dialogawp'); ?></button>
                        </div>
                        <div id="wizard-key-row-input">
                            <input type="password" id="wizard-api-key" placeholder="<?php esc_attr_e('Paste your API key here...', 'dialogawp'); ?>" autocomplete="off" />
                        </div>
                        <div class="dialogawp-wizard-key-status" id="wizard-key-status"></div>
                    </div>

                    <div class="dialogawp-wizard-field">
                        <label for="wizard-model"><?php esc_html_e('Model', 'dialogawp'); ?></label>
                        <select id="wizard-model">
                            <optgroup label="OpenAI" id="wizard-models-openai">
                                <option value="gpt-4o-mini" selected><?php esc_html_e('GPT-4o Mini (recommended — fast & affordable)', 'dialogawp'); ?></option>
                                <option value="gpt-4o"><?php esc_html_e('GPT-4o (more capable, higher cost)', 'dialogawp'); ?></option>
                            </optgroup>
                            <optgroup label="Anthropic" id="wizard-models-anthropic" disabled>
                                <option value="claude-haiku-4-5-20251001"><?php esc_html_e('Claude Haiku (recommended — fast & affordable)', 'dialogawp'); ?></option>
                                <option value="claude-sonnet-4-6-20250514"><?php esc_html_e('Claude Sonnet (more capable)', 'dialogawp'); ?></option>
                            </optgroup>
                        </select>
                    </div>

                    <button class="dialogawp-wizard-btn dialogawp-wizard-btn-outline" id="wizard-test-connection">
                        <?php esc_html_e('Test Connection', 'dialogawp'); ?>
                    </button>
                </div>
            </div>

            <!-- Step 2: Website Crawl -->
            <div class="dialogawp-wizard-step" data-step="2">
                <div class="dialogawp-wizard-card">
                    <h2><?php esc_html_e('Crawl Your Website', 'dialogawp'); ?></h2>
                    <p class="dialogawp-wizard-desc"><?php esc_html_e('We\'ll scan your website to learn about your business, services, and content. This helps the AI give accurate, relevant answers to your visitors.', 'dialogawp'); ?></p>

                    <div class="dialogawp-wizard-field">
                        <label><?php esc_html_e('Your Website', 'dialogawp'); ?></label>
                        <div class="dialogawp-wizard-site-url" id="wizard-site-url"></div>
                    </div>

                    <div class="dialogawp-wizard-crawl-status" id="wizard-crawl-status">
                        <button class="dialogawp-wizard-btn dialogawp-wizard-btn-primary dialogawp-wizard-btn-lg" id="wizard-crawl-btn">
                            <?php esc_html_e('Scan My Website', 'dialogawp'); ?>
                        </button>
                        <p class="dialogawp-wizard-note"><?php esc_html_e('This usually takes 10-30 seconds depending on your site size.', 'dialogawp'); ?></p>
                    </div>

                    <div class="dialogawp-wizard-crawl-progress" id="wizard-crawl-progress" style="display:none;">
                        <div class="dialogawp-wizard-spinner"></div>
                        <p><?php esc_html_e('Scanning your website...', 'dialogawp'); ?></p>
                        <p class="dialogawp-wizard-note"><?php esc_html_e('Reading pages, extracting content, and building your knowledge base.', 'dialogawp'); ?></p>
                    </div>

                    <div class="dialogawp-wizard-crawl-results" id="wizard-crawl-results" style="display:none;">
                        <div class="dialogawp-wizard-success-icon">&#x2705;</div>
                        <h3><?php esc_html_e('Website Scanned Successfully!', 'dialogawp'); ?></h3>
                        <p id="wizard-crawl-summary"></p>
                        <button class="dialogawp-wizard-btn dialogawp-wizard-btn-outline dialogawp-wizard-btn-sm" id="wizard-recrawl-btn"><?php esc_html_e('Scan Again', 'dialogawp'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Step 3: Customize -->
            <div class="dialogawp-wizard-step" data-step="3">
                <div class="dialogawp-wizard-card">
                    <h2><?php esc_html_e('Customize Your Chatbot', 'dialogawp'); ?></h2>
                    <p class="dialogawp-wizard-desc"><?php esc_html_e('Give your chatbot a personality and context so it represents your business accurately.', 'dialogawp'); ?></p>

                    <div class="dialogawp-wizard-field">
                        <label for="wizard-business-name"><?php esc_html_e('Business Name', 'dialogawp'); ?></label>
                        <input type="text" id="wizard-business-name" placeholder="<?php esc_attr_e('e.g. Austin Therapy Group', 'dialogawp'); ?>" />
                    </div>

                    <div class="dialogawp-wizard-field">
                        <label for="wizard-business-type"><?php esc_html_e('Industry / Business Type', 'dialogawp'); ?></label>
                        <select id="wizard-business-type">
                            <option value=""><?php esc_html_e('Select your industry...', 'dialogawp'); ?></option>
                            <option value="agency"><?php esc_html_e('Agency / Consultancy', 'dialogawp'); ?></option>
                            <option value="ecommerce"><?php esc_html_e('E-commerce / Retail', 'dialogawp'); ?></option>
                            <option value="healthcare"><?php esc_html_e('Healthcare / Medical', 'dialogawp'); ?></option>
                            <option value="legal"><?php esc_html_e('Legal / Law Firm', 'dialogawp'); ?></option>
                            <option value="realestate"><?php esc_html_e('Real Estate', 'dialogawp'); ?></option>
                            <option value="saas"><?php esc_html_e('SaaS / Software', 'dialogawp'); ?></option>
                            <option value="education"><?php esc_html_e('Education', 'dialogawp'); ?></option>
                            <option value="finance"><?php esc_html_e('Finance / Insurance', 'dialogawp'); ?></option>
                            <option value="hospitality"><?php esc_html_e('Hospitality / Restaurant', 'dialogawp'); ?></option>
                            <option value="nonprofit"><?php esc_html_e('Nonprofit', 'dialogawp'); ?></option>
                            <option value="other"><?php esc_html_e('Other', 'dialogawp'); ?></option>
                        </select>
                    </div>

                    <div class="dialogawp-wizard-field" id="wizard-other-industry-field" style="display:none;">
                        <label for="wizard-other-industry"><?php esc_html_e('Please specify your industry *', 'dialogawp'); ?></label>
                        <input type="text" id="wizard-other-industry" placeholder="<?php esc_attr_e('e.g. Pet Services, Construction, Travel...', 'dialogawp'); ?>" />
                    </div>

                    <div class="dialogawp-wizard-field">
                        <label for="wizard-custom-instructions"><?php esc_html_e('Custom Instructions', 'dialogawp'); ?> <span class="dialogawp-wizard-optional"><?php esc_html_e('(optional)', 'dialogawp'); ?></span></label>
                        <textarea id="wizard-custom-instructions" rows="5" placeholder="<?php esc_attr_e("Tell the AI how to behave. For example:\n\n- You are a friendly assistant for our therapy practice\n- Focus on helping visitors book a consultation\n- Never discuss specific medical advice\n- Our office hours are Mon-Fri 9am-5pm", 'dialogawp'); ?>"></textarea>
                        <p class="dialogawp-wizard-hint"><?php esc_html_e('These instructions shape how the AI responds. You can always change them later.', 'dialogawp'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Step 4: Goal Selection -->
            <div class="dialogawp-wizard-step" data-step="4">
                <div class="dialogawp-wizard-card dialogawp-wizard-card-wide">
                    <h2><?php esc_html_e('What Are Your Goals?', 'dialogawp'); ?></h2>
                    <p class="dialogawp-wizard-desc"><?php esc_html_e('Select all that apply — we\'ll build a flow that covers everything you need.', 'dialogawp'); ?></p>

                    <!-- Free Goals (4 cards) -->
                    <div class="dialogawp-wizard-goal-grid dialogawp-wizard-goal-grid-4">
                        <button class="dialogawp-wizard-goal-card" data-goal="leads">
                            <div class="dialogawp-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="6" y="10" width="36" height="28" rx="4" stroke="#16A34A" stroke-width="3" fill="#F0FDF4"/>
                                    <line x1="6" y1="18" x2="42" y2="18" stroke="#16A34A" stroke-width="3"/>
                                    <rect x="12" y="24" width="16" height="3" rx="1.5" fill="#16A34A" opacity="0.5"/>
                                    <rect x="12" y="30" width="12" height="3" rx="1.5" fill="#16A34A" opacity="0.5"/>
                                    <circle cx="36" cy="34" r="6" fill="#16A34A"/>
                                    <path d="M33.5 34L35.5 36L38.5 32" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Capture Leads', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Collect visitor contact info with a conversational lead form.', 'dialogawp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Qualifying questions', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Name, email, phone form', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Best for: sales, inquiries', 'dialogawp'); ?></li>
                            </ul>
                        </button>

                        <button class="dialogawp-wizard-goal-card" data-goal="booking">
                            <div class="dialogawp-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="8" y="6" width="32" height="36" rx="4" stroke="#7C3AED" stroke-width="3" fill="#F5F3FF"/>
                                    <line x1="8" y1="16" x2="40" y2="16" stroke="#7C3AED" stroke-width="3"/>
                                    <rect x="14" y="22" width="8" height="6" rx="2" fill="#7C3AED" opacity="0.3"/>
                                    <rect x="26" y="22" width="8" height="6" rx="2" fill="#7C3AED" opacity="0.3"/>
                                    <rect x="14" y="32" width="8" height="6" rx="2" fill="#7C3AED"/>
                                    <circle cx="18" cy="11" r="2" fill="#7C3AED"/>
                                    <circle cx="30" cy="11" r="2" fill="#7C3AED"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Book Appointments', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Guide visitors to schedule a call or consultation.', 'dialogawp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Availability questions', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Calendar link or booking', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Best for: services, consults', 'dialogawp'); ?></li>
                            </ul>
                        </button>

                        <button class="dialogawp-wizard-goal-card" data-goal="support">
                            <div class="dialogawp-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="24" cy="24" r="18" stroke="#EA580C" stroke-width="3" fill="#FFF7ED"/>
                                    <circle cx="24" cy="24" r="8" stroke="#EA580C" stroke-width="3" fill="none"/>
                                    <line x1="24" y1="6" x2="24" y2="12" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="24" y1="36" x2="24" y2="42" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="6" y1="24" x2="12" y2="24" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="36" y1="24" x2="42" y2="24" stroke="#EA580C" stroke-width="3"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Customer Support', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('AI-powered answers with optional escalation.', 'dialogawp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Answers from knowledge base', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Escalate to email/ticket', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Best for: helpdesk, FAQ', 'dialogawp'); ?></li>
                            </ul>
                        </button>

                        <button class="dialogawp-wizard-goal-card" data-goal="info">
                            <div class="dialogawp-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="4" y="4" width="40" height="32" rx="8" stroke="#007D75" stroke-width="3" fill="#EFF6FF"/>
                                    <path d="M12 40L20 36H28" stroke="#007D75" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    <rect x="12" y="14" width="18" height="3" rx="1.5" fill="#007D75" opacity="0.5"/>
                                    <rect x="12" y="20" width="14" height="3" rx="1.5" fill="#007D75" opacity="0.5"/>
                                    <circle cx="34" cy="18" r="6" fill="#007D75"/>
                                    <text x="34" y="22" text-anchor="middle" fill="white" font-size="10" font-weight="bold"><?php esc_html_e('AI', 'dialogawp'); ?></text>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Info Chatbot', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Instant AI answers based on your website content.', 'dialogawp'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Open-ended AI conversation', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Answers from knowledge base', 'dialogawp'); ?></li>
                                <li><?php esc_html_e('Best for: education, FAQ', 'dialogawp'); ?></li>
                            </ul>
                        </button>
                    </div>

                    <?php
                    // v1.0.2 — the free plugin ships no locked goal cards. The Pro
                    // plugin (a separate download) adds its own goals through this
                    // action. Nothing is rendered here when Pro is not installed.
                    do_action('dialogawp_wizard_extra_goal_cards');
                    ?>
                </div>
            </div>

            <!-- Step 5: Chatbot Visibility -->
            <div class="dialogawp-wizard-step" data-step="5">
                <div class="dialogawp-wizard-card dialogawp-wizard-card-wide">
                    <h2><?php esc_html_e('Chatbot Visibility', 'dialogawp'); ?></h2>
                    <p class="dialogawp-wizard-desc"><?php esc_html_e('Who should see your chatbot? You can change this anytime in the flow builder.', 'dialogawp'); ?></p>

                    <div class="dialogawp-wizard-visibility-grid">
                        <button class="dialogawp-wizard-visibility-card selected" data-visibility="everyone">
                            <div class="dialogawp-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="24" cy="24" r="18" stroke="#16A34A" stroke-width="3" fill="#F0FDF4"/>
                                    <circle cx="24" cy="18" r="6" fill="#16A34A" opacity="0.5"/>
                                    <path d="M12 38c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#16A34A" stroke-width="3" fill="none"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Everyone', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('All website visitors can see and interact with the chatbot. Best for most use cases.', 'dialogawp'); ?></p>
                        </button>

                        <button class="dialogawp-wizard-visibility-card" data-visibility="logged_in">
                            <div class="dialogawp-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="10" y="20" width="28" height="20" rx="4" stroke="#007D75" stroke-width="3" fill="#EFF6FF"/>
                                    <path d="M16 20v-6a8 8 0 1116 0v6" stroke="#007D75" stroke-width="3" fill="none"/>
                                    <circle cx="24" cy="32" r="3" fill="#007D75"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Logged-in Users', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Only users who are logged into your WordPress site can see the chatbot.', 'dialogawp'); ?></p>
                        </button>

                        <button class="dialogawp-wizard-visibility-card" data-visibility="specific_roles">
                            <div class="dialogawp-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="18" cy="16" r="6" stroke="#7C3AED" stroke-width="3" fill="#F5F3FF"/>
                                    <circle cx="34" cy="16" r="6" stroke="#7C3AED" stroke-width="3" fill="#F5F3FF"/>
                                    <path d="M6 36c0-6.627 5.373-12 12-12" stroke="#7C3AED" stroke-width="3" fill="none"/>
                                    <path d="M22 36c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#7C3AED" stroke-width="3" fill="none"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Specific Roles', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Only users with selected WordPress roles can see the chatbot.', 'dialogawp'); ?></p>
                        </button>
                    </div>

                    <div id="wizard-roles-section" style="display:none;">
                        <div class="dialogawp-wizard-field" style="margin-top:20px;">
                            <label><?php esc_html_e('Select Roles', 'dialogawp'); ?></label>
                            <div class="dialogawp-wizard-roles-grid">
                                <?php
                                $wp_roles = wp_roles();
                                foreach ($wp_roles->role_names as $role_slug => $role_name) :
                                ?>
                                <label class="dialogawp-wizard-role-item">
                                    <input type="checkbox" class="dialogawp-wizard-role-check" value="<?php echo esc_attr($role_slug); ?>" />
                                    <?php echo esc_html(translate_user_role($role_name)); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 6: Launch Chatbot -->
            <div class="dialogawp-wizard-step" data-step="6">
                <div class="dialogawp-wizard-card">
                    <div id="wizard-review-summary">
                        <h2><?php esc_html_e('Review & Create', 'dialogawp'); ?></h2>
                        <p class="dialogawp-wizard-desc"><?php esc_html_e('Here\'s a summary of your chatbot setup. Click "Create My Chatbot" to build your flow.', 'dialogawp'); ?></p>

                        <div class="dialogawp-wizard-review-grid" id="wizard-review-grid">
                            <!-- Populated by JS -->
                        </div>

                        <button class="dialogawp-wizard-btn dialogawp-wizard-btn-primary dialogawp-wizard-btn-lg dialogawp-wizard-btn-full" id="wizard-create-btn">
                            <?php esc_html_e('Create My Chatbot', 'dialogawp'); ?>
                        </button>
                    </div>

                    <div id="wizard-creating" style="display:none;">
                        <div class="dialogawp-wizard-creating">
                            <div class="dialogawp-wizard-spinner dialogawp-wizard-spinner-lg"></div>
                            <h3><?php esc_html_e('Building Your Chatbot...', 'dialogawp'); ?></h3>
                            <p><?php esc_html_e('Our AI is designing a custom conversational flow for your business. This takes about 15-30 seconds.', 'dialogawp'); ?></p>
                        </div>
                    </div>

                    <div id="wizard-complete" style="display:none;">
                        <div class="dialogawp-wizard-complete">
                            <div class="dialogawp-wizard-complete-icon">&#x1f389;</div>
                            <h2><?php esc_html_e('Your Chatbot is Ready!', 'dialogawp'); ?></h2>
                            <p><?php esc_html_e('We\'ve created a custom flow based on your business. You can preview it, customize it further, or go live right away.', 'dialogawp'); ?></p>
                            <button class="dialogawp-wizard-btn dialogawp-wizard-btn-primary dialogawp-wizard-btn-lg" id="wizard-go-to-builder">
                                <?php esc_html_e('Open Flow Builder', 'dialogawp'); ?>
                            </button>
                        </div>
                    </div>

                    <div id="wizard-error" style="display:none;">
                        <div class="dialogawp-wizard-error">
                            <div class="dialogawp-wizard-error-icon">&#x26A0;&#xFE0F;</div>
                            <h3><?php esc_html_e('Something Went Wrong', 'dialogawp'); ?></h3>
                            <p id="wizard-error-message"></p>
                            <button class="dialogawp-wizard-btn dialogawp-wizard-btn-outline" id="wizard-retry-btn"><?php esc_html_e('Try Again', 'dialogawp'); ?></button>
                            <button class="dialogawp-wizard-btn dialogawp-wizard-btn-primary" id="wizard-use-template-btn"><?php esc_html_e('Use Starter Template', 'dialogawp'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <div class="dialogawp-wizard-footer">
        <button class="dialogawp-wizard-btn dialogawp-wizard-btn-ghost" id="wizard-back" style="visibility:hidden;">
            <?php esc_html_e('← Back', 'dialogawp'); ?>
        </button>
        <div class="dialogawp-wizard-step-label" id="wizard-step-label"></div>
        <button class="dialogawp-wizard-btn dialogawp-wizard-btn-primary" id="wizard-next">
            <?php esc_html_e('Get Started →', 'dialogawp'); ?>
        </button>
    </div>

</div>

<?php wp_footer(); ?>
</body>
</html>
