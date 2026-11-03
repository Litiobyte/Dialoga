<?php if (!defined('ABSPATH')) exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Getting Started — Dialoga', 'dialoga'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="dialoga-wizard-body">

<div class="dialoga-wizard-wrap" id="dialoga-wizard">

    <!-- Header -->
    <div class="dialoga-wizard-header">
        <div class="dialoga-wizard-brand">
            <img src="<?php echo esc_url(DIALOGA_LOGO_URL); ?>" alt="Dialoga" class="dialoga-wizard-brand-logo" onerror="this.style.display='none'" />
        </div>
        <nav class="dialoga-wizard-progress" id="wizard-progress" aria-label="<?php esc_attr_e('Setup progress', 'dialoga'); ?>"></nav>
        <div class="dialoga-wizard-header-actions">
            <span class="dialoga-wizard-save-pill" id="wizard-save-pill" aria-live="polite">
                <span class="dialoga-wizard-save-dot"></span>
                <span class="dialoga-wizard-save-text"><?php esc_html_e('Progress saved', 'dialoga'); ?></span>
            </span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dialoga')); ?>" class="dialoga-wizard-exit-btn" id="wizard-save-exit" title="<?php esc_attr_e('Your progress is auto-saved. Resume anytime from the Dashboard.', 'dialoga'); ?>"><?php esc_html_e('Save & Exit', 'dialoga'); ?></a>
            <button type="button" class="dialoga-wizard-skip-btn" id="wizard-skip"><?php esc_html_e('Skip Setup', 'dialoga'); ?></button>
        </div>
    </div>

    <!-- Loading state — shown until JS initializes -->
    <div class="dialoga-wizard-loading" id="wizard-loading" role="status" aria-live="polite">
        <div class="dialoga-wizard-spinner"></div>
        <p style="color:#94A3B8;font-size:14px;margin-top:12px;"><?php esc_html_e('Loading wizard...', 'dialoga'); ?></p>
    </div>
    <div class="dialoga-wizard-sr-only" id="wizard-announcements" role="status" aria-live="polite" aria-atomic="true"></div>

    <!-- Steps Container -->
    <div class="dialoga-wizard-content" style="display:none;">
        <div class="dialoga-wizard-slider" id="wizard-slider">

            <!-- Step 0: Welcome -->
            <div class="dialoga-wizard-step" id="wizard-step-0" data-step="0">
                <div class="dialoga-wizard-card dialoga-wizard-card-wide">
                    <div class="dialoga-wizard-welcome-icon">
                        <img src="<?php echo esc_url(DIALOGA_LOGO_URL); ?>" alt="Dialoga" style="height:100px;width:auto;" onerror="this.style.display='none'" />
                    </div>
                    <h1><?php esc_html_e('Welcome to Dialoga', 'dialoga'); ?></h1>
                    <p class="dialoga-wizard-subtitle"><?php esc_html_e('Let\'s build your first chatbot in just a few minutes. This wizard will walk you through everything — no coding required.', 'dialoga'); ?></p>
                    <div class="dialoga-wizard-welcome-steps" id="wizard-welcome-steps">
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="1">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-1">1</div>
                            <div>
                                <strong><?php esc_html_e('AI Setup', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('Connect your AI provider (OpenAI or Anthropic)', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="2">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-2">2</div>
                            <div>
                                <strong><?php esc_html_e('Crawl Website', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('We\'ll learn about your business from your website', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="3">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-3">3</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Customization', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('Add personality and instructions for your chatbot', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="4">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-4">4</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Goals', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('Select what you want your chatbot to do', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="5">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-5">5</div>
                            <div>
                                <strong><?php esc_html_e('Chatbot Visibility', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('Choose who can see your chatbot', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                        <button type="button" class="dialoga-wizard-welcome-step" data-goto="6">
                            <div class="dialoga-wizard-welcome-num" id="welcome-step-6">6</div>
                            <div>
                                <strong><?php esc_html_e('Launch Chatbot', 'dialoga'); ?></strong>
                                <span><?php esc_html_e('Review and create your chatbot', 'dialoga'); ?></span>
                            </div>
                            <div class="dialoga-wizard-welcome-arrow">&#8250;</div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Step 1: AI Setup -->
            <div class="dialoga-wizard-step" id="wizard-step-1" data-step="1">
                <div class="dialoga-wizard-card">
                    <h2><?php esc_html_e('Connect Your AI Provider', 'dialoga'); ?></h2>
                    <p class="dialoga-wizard-desc"><?php esc_html_e('Dialoga uses AI to power your chatbot conversations. You\'ll need an API key from OpenAI or Anthropic.', 'dialoga'); ?></p>

                    <!-- Instructions accordion — shown first for visibility -->
                    <div class="dialoga-wizard-accordion" id="wizard-instructions-accordion" style="margin-bottom:24px;">
                        <button type="button" class="dialoga-wizard-accordion-toggle" id="wizard-instructions-toggle" aria-expanded="false" aria-controls="wizard-instructions-body">
                            <?php esc_html_e('How do I get an API key?', 'dialoga'); ?> <span class="dialoga-wizard-chevron">&#9660;</span>
                        </button>
                        <div class="dialoga-wizard-accordion-body" id="wizard-instructions-body" hidden>
                            <div class="dialoga-wizard-instructions" id="wizard-openai-instructions">
                                <h4><?php esc_html_e('Getting an OpenAI API Key', 'dialoga'); ?></h4>
                                <ol>
                                    <li><?php echo wp_kses_post(__('Go to <a href="https://platform.openai.com/signup" target="_blank" rel="noopener noreferrer">platform.openai.com</a> and create an account', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Navigate to <strong>API Keys</strong> in the left sidebar', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Click <strong>"Create new secret key"</strong>', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Copy the key (starts with <code>sk-</code>) and paste it below', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Add billing: Go to <strong>Settings → Billing</strong> and add a payment method', 'dialoga')); ?></li>
                                </ol>
                                <p class="dialoga-wizard-note"><?php esc_html_e('Typical cost: $0.01-0.05 per chatbot conversation with GPT-4o Mini.', 'dialoga'); ?></p>
                            </div>
                            <div class="dialoga-wizard-instructions" id="wizard-anthropic-instructions" style="display:none;">
                                <h4><?php esc_html_e('Getting an Anthropic API Key', 'dialoga'); ?></h4>
                                <ol>
                                    <li><?php echo wp_kses_post(__('Go to <a href="https://console.anthropic.com/" target="_blank" rel="noopener noreferrer">console.anthropic.com</a> and create an account', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Navigate to <strong>API Keys</strong>', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Click <strong>"Create Key"</strong>', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Copy the key (starts with <code>sk-ant-</code>) and paste it below', 'dialoga')); ?></li>
                                    <li><?php echo wp_kses_post(__('Add billing: Go to <strong>Plans & Billing</strong> and add a payment method', 'dialoga')); ?></li>
                                </ol>
                                <p class="dialoga-wizard-note"><?php esc_html_e('Typical cost: $0.01-0.03 per chatbot conversation with Claude Haiku.', 'dialoga'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="dialoga-wizard-field">
                        <label><?php esc_html_e('AI Provider', 'dialoga'); ?></label>
                        <div class="dialoga-wizard-radio-group dialoga-wizard-radio-group-3">
                            <label class="dialoga-wizard-radio-card" data-provider="openai">
                                <input type="radio" name="ai_provider" value="openai" checked />
                                <div class="dialoga-wizard-radio-content">
                                    <strong>OpenAI</strong>
                                    <span>GPT-4o, GPT-4o mini</span>
                                </div>
                            </label>
                            <label class="dialoga-wizard-radio-card" data-provider="anthropic">
                                <input type="radio" name="ai_provider" value="anthropic" />
                                <div class="dialoga-wizard-radio-content">
                                    <strong>Anthropic</strong>
                                    <span>Claude Haiku, Sonnet, Opus</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="dialoga-wizard-field">
                        <label for="wizard-api-key"><?php esc_html_e('API Key', 'dialoga'); ?></label>
                        <div class="dialoga-wizard-key-row" id="wizard-key-row-saved" style="display:none;">
                            <input type="text" value="••••••••••••••••••••••••••••" readonly class="dialoga-wizard-key-masked" />
                            <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-outline dialoga-wizard-btn-sm" id="wizard-change-key"><?php esc_html_e('Change Key', 'dialoga'); ?></button>
                        </div>
                        <div id="wizard-key-row-input">
                            <input type="password" id="wizard-api-key" placeholder="<?php esc_attr_e('Paste your API key here...', 'dialoga'); ?>" autocomplete="off" />
                        </div>
                        <div class="dialoga-wizard-key-status" id="wizard-key-status" role="status" aria-live="polite"></div>
                    </div>

                    <div class="dialoga-wizard-field">
                        <label for="wizard-model"><?php esc_html_e('Model', 'dialoga'); ?></label>
                        <select id="wizard-model">
                            <optgroup label="OpenAI" id="wizard-models-openai">
                                <option value="gpt-4o-mini" selected><?php esc_html_e('GPT-4o Mini (recommended — fast & affordable)', 'dialoga'); ?></option>
                                <option value="gpt-4o"><?php esc_html_e('GPT-4o (more capable, higher cost)', 'dialoga'); ?></option>
                            </optgroup>
                            <optgroup label="Anthropic" id="wizard-models-anthropic" disabled>
                                <option value="claude-haiku-4-5-20251001"><?php esc_html_e('Claude Haiku (recommended — fast & affordable)', 'dialoga'); ?></option>
                                <option value="claude-sonnet-4-6-20250514"><?php esc_html_e('Claude Sonnet (more capable)', 'dialoga'); ?></option>
                            </optgroup>
                        </select>
                    </div>

                    <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-outline" id="wizard-test-connection">
                        <?php esc_html_e('Test Connection', 'dialoga'); ?>
                    </button>
                </div>
            </div>

            <!-- Step 2: Website Crawl -->
            <div class="dialoga-wizard-step" id="wizard-step-2" data-step="2">
                <div class="dialoga-wizard-card">
                    <h2><?php esc_html_e('Crawl Your Website', 'dialoga'); ?></h2>
                    <p class="dialoga-wizard-desc"><?php esc_html_e('We\'ll scan your website to learn about your business, services, and content. This helps the AI give accurate, relevant answers to your visitors.', 'dialoga'); ?></p>

                    <div class="dialoga-wizard-field">
                        <label><?php esc_html_e('Your Website', 'dialoga'); ?></label>
                        <div class="dialoga-wizard-site-url" id="wizard-site-url"></div>
                    </div>

                    <div class="dialoga-wizard-crawl-status" id="wizard-crawl-status">
                        <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-primary dialoga-wizard-btn-lg" id="wizard-crawl-btn">
                            <?php esc_html_e('Scan My Website', 'dialoga'); ?>
                        </button>
                        <p class="dialoga-wizard-note"><?php esc_html_e('This usually takes 10-30 seconds depending on your site size.', 'dialoga'); ?></p>
                    </div>

                    <div class="dialoga-wizard-crawl-progress" id="wizard-crawl-progress" role="status" aria-live="polite" style="display:none;">
                        <div class="dialoga-wizard-spinner"></div>
                        <p><?php esc_html_e('Scanning your website...', 'dialoga'); ?></p>
                        <p class="dialoga-wizard-note"><?php esc_html_e('Reading pages, extracting content, and building your knowledge base.', 'dialoga'); ?></p>
                    </div>

                    <div class="dialoga-wizard-crawl-results" id="wizard-crawl-results" role="status" aria-live="polite" style="display:none;">
                        <div class="dialoga-wizard-success-icon">&#x2705;</div>
                        <h3><?php esc_html_e('Website Scanned Successfully!', 'dialoga'); ?></h3>
                        <p id="wizard-crawl-summary"></p>
                        <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-outline dialoga-wizard-btn-sm" id="wizard-recrawl-btn"><?php esc_html_e('Scan Again', 'dialoga'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Step 3: Customize -->
            <div class="dialoga-wizard-step" id="wizard-step-3" data-step="3">
                <div class="dialoga-wizard-card">
                    <h2><?php esc_html_e('Customize Your Chatbot', 'dialoga'); ?></h2>
                    <p class="dialoga-wizard-desc"><?php esc_html_e('Give your chatbot a personality and context so it represents your business accurately.', 'dialoga'); ?></p>

                    <div class="dialoga-wizard-field">
                        <label for="wizard-business-name"><?php esc_html_e('Business Name', 'dialoga'); ?></label>
                        <input type="text" id="wizard-business-name" placeholder="<?php esc_attr_e('e.g. Austin Therapy Group', 'dialoga'); ?>" />
                    </div>

                    <div class="dialoga-wizard-field">
                        <label for="wizard-business-type"><?php esc_html_e('Industry / Business Type', 'dialoga'); ?></label>
                        <select id="wizard-business-type">
                            <option value=""><?php esc_html_e('Select your industry...', 'dialoga'); ?></option>
                            <option value="agency"><?php esc_html_e('Agency / Consultancy', 'dialoga'); ?></option>
                            <option value="ecommerce"><?php esc_html_e('E-commerce / Retail', 'dialoga'); ?></option>
                            <option value="healthcare"><?php esc_html_e('Healthcare / Medical', 'dialoga'); ?></option>
                            <option value="legal"><?php esc_html_e('Legal / Law Firm', 'dialoga'); ?></option>
                            <option value="realestate"><?php esc_html_e('Real Estate', 'dialoga'); ?></option>
                            <option value="saas"><?php esc_html_e('SaaS / Software', 'dialoga'); ?></option>
                            <option value="education"><?php esc_html_e('Education', 'dialoga'); ?></option>
                            <option value="finance"><?php esc_html_e('Finance / Insurance', 'dialoga'); ?></option>
                            <option value="hospitality"><?php esc_html_e('Hospitality / Restaurant', 'dialoga'); ?></option>
                            <option value="nonprofit"><?php esc_html_e('Nonprofit', 'dialoga'); ?></option>
                            <option value="other"><?php esc_html_e('Other', 'dialoga'); ?></option>
                        </select>
                    </div>

                    <div class="dialoga-wizard-field" id="wizard-other-industry-field" style="display:none;">
                        <label for="wizard-other-industry"><?php esc_html_e('Please specify your industry *', 'dialoga'); ?></label>
                        <input type="text" id="wizard-other-industry" placeholder="<?php esc_attr_e('e.g. Pet Services, Construction, Travel...', 'dialoga'); ?>" />
                    </div>

                    <div class="dialoga-wizard-field">
                        <label for="wizard-custom-instructions"><?php esc_html_e('Custom Instructions', 'dialoga'); ?> <span class="dialoga-wizard-optional"><?php esc_html_e('(optional)', 'dialoga'); ?></span></label>
                        <textarea id="wizard-custom-instructions" rows="5" placeholder="<?php esc_attr_e("Tell the AI how to behave. For example:\n\n- You are a friendly assistant for our therapy practice\n- Focus on helping visitors book a consultation\n- Never discuss specific medical advice\n- Our office hours are Mon-Fri 9am-5pm", 'dialoga'); ?>"></textarea>
                        <p class="dialoga-wizard-hint"><?php esc_html_e('These instructions shape how the AI responds. You can always change them later.', 'dialoga'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Step 4: Goal Selection -->
            <div class="dialoga-wizard-step" id="wizard-step-4" data-step="4">
                <div class="dialoga-wizard-card dialoga-wizard-card-wide">
                    <h2><?php esc_html_e('What Are Your Goals?', 'dialoga'); ?></h2>
                    <p class="dialoga-wizard-desc"><?php esc_html_e('Select all that apply — we\'ll build a flow that covers everything you need.', 'dialoga'); ?></p>

                    <!-- Free Goals (4 cards) -->
                    <div class="dialoga-wizard-goal-grid dialoga-wizard-goal-grid-4">
                        <button type="button" class="dialoga-wizard-goal-card" data-goal="leads" aria-pressed="false">
                            <div class="dialoga-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="6" y="10" width="36" height="28" rx="4" stroke="#16A34A" stroke-width="3" fill="#F0FDF4"/>
                                    <line x1="6" y1="18" x2="42" y2="18" stroke="#16A34A" stroke-width="3"/>
                                    <rect x="12" y="24" width="16" height="3" rx="1.5" fill="#16A34A" opacity="0.5"/>
                                    <rect x="12" y="30" width="12" height="3" rx="1.5" fill="#16A34A" opacity="0.5"/>
                                    <circle cx="36" cy="34" r="6" fill="#16A34A"/>
                                    <path d="M33.5 34L35.5 36L38.5 32" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Capture Leads', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Collect visitor contact info with a conversational lead form.', 'dialoga'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Qualifying questions', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Name, email, phone form', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Best for: sales, inquiries', 'dialoga'); ?></li>
                            </ul>
                        </button>

                        <button type="button" class="dialoga-wizard-goal-card" data-goal="booking" aria-pressed="false">
                            <div class="dialoga-wizard-goal-icon">
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
                            <h3><?php esc_html_e('Book Appointments', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Guide visitors to schedule a call or consultation.', 'dialoga'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Availability questions', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Calendar link or booking', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Best for: services, consults', 'dialoga'); ?></li>
                            </ul>
                        </button>

                        <button type="button" class="dialoga-wizard-goal-card" data-goal="support" aria-pressed="false">
                            <div class="dialoga-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="24" cy="24" r="18" stroke="#EA580C" stroke-width="3" fill="#FFF7ED"/>
                                    <circle cx="24" cy="24" r="8" stroke="#EA580C" stroke-width="3" fill="none"/>
                                    <line x1="24" y1="6" x2="24" y2="12" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="24" y1="36" x2="24" y2="42" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="6" y1="24" x2="12" y2="24" stroke="#EA580C" stroke-width="3"/>
                                    <line x1="36" y1="24" x2="42" y2="24" stroke="#EA580C" stroke-width="3"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Customer Support', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('AI-powered answers with optional escalation.', 'dialoga'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Answers from knowledge base', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Escalate to email/ticket', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Best for: helpdesk, FAQ', 'dialoga'); ?></li>
                            </ul>
                        </button>

                        <button type="button" class="dialoga-wizard-goal-card" data-goal="info" aria-pressed="false">
                            <div class="dialoga-wizard-goal-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="4" y="4" width="40" height="32" rx="8" stroke="#007D75" stroke-width="3" fill="#EFF6FF"/>
                                    <path d="M12 40L20 36H28" stroke="#007D75" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    <rect x="12" y="14" width="18" height="3" rx="1.5" fill="#007D75" opacity="0.5"/>
                                    <rect x="12" y="20" width="14" height="3" rx="1.5" fill="#007D75" opacity="0.5"/>
                                    <circle cx="34" cy="18" r="6" fill="#007D75"/>
                                    <text x="34" y="22" text-anchor="middle" fill="white" font-size="10" font-weight="bold"><?php esc_html_e('AI', 'dialoga'); ?></text>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Info Chatbot', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Instant AI answers based on your website content.', 'dialoga'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Open-ended AI conversation', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Answers from knowledge base', 'dialoga'); ?></li>
                                <li><?php esc_html_e('Best for: education, FAQ', 'dialoga'); ?></li>
                            </ul>
                        </button>
                    </div>

                    <?php
                    // v1.0.2 — the free plugin ships no locked goal cards. The Pro
                    // plugin (a separate download) adds its own goals through this
                    // action. Nothing is rendered here when Pro is not installed.
                    do_action('dialoga_wizard_extra_goal_cards');
                    ?>
                </div>
            </div>

            <!-- Step 5: Chatbot Visibility -->
            <div class="dialoga-wizard-step" id="wizard-step-5" data-step="5">
                <div class="dialoga-wizard-card dialoga-wizard-card-wide">
                    <h2><?php esc_html_e('Chatbot Visibility', 'dialoga'); ?></h2>
                    <p class="dialoga-wizard-desc"><?php esc_html_e('Who should see your chatbot? You can change this anytime in the flow builder.', 'dialoga'); ?></p>

                    <div class="dialoga-wizard-visibility-grid">
                        <button type="button" class="dialoga-wizard-visibility-card selected" data-visibility="everyone" aria-pressed="true">
                            <div class="dialoga-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="24" cy="24" r="18" stroke="#16A34A" stroke-width="3" fill="#F0FDF4"/>
                                    <circle cx="24" cy="18" r="6" fill="#16A34A" opacity="0.5"/>
                                    <path d="M12 38c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#16A34A" stroke-width="3" fill="none"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Everyone', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('All website visitors can see and interact with the chatbot. Best for most use cases.', 'dialoga'); ?></p>
                        </button>

                        <button type="button" class="dialoga-wizard-visibility-card" data-visibility="logged_in" aria-pressed="false">
                            <div class="dialoga-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <rect x="10" y="20" width="28" height="20" rx="4" stroke="#007D75" stroke-width="3" fill="#EFF6FF"/>
                                    <path d="M16 20v-6a8 8 0 1116 0v6" stroke="#007D75" stroke-width="3" fill="none"/>
                                    <circle cx="24" cy="32" r="3" fill="#007D75"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Logged-in Users', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Only users who are logged into your WordPress site can see the chatbot.', 'dialoga'); ?></p>
                        </button>

                        <button type="button" class="dialoga-wizard-visibility-card" data-visibility="specific_roles" aria-pressed="false" aria-controls="wizard-roles-section">
                            <div class="dialoga-wizard-visibility-icon">
                                <svg width="40" height="40" viewBox="0 0 48 48" fill="none">
                                    <circle cx="18" cy="16" r="6" stroke="#7C3AED" stroke-width="3" fill="#F5F3FF"/>
                                    <circle cx="34" cy="16" r="6" stroke="#7C3AED" stroke-width="3" fill="#F5F3FF"/>
                                    <path d="M6 36c0-6.627 5.373-12 12-12" stroke="#7C3AED" stroke-width="3" fill="none"/>
                                    <path d="M22 36c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#7C3AED" stroke-width="3" fill="none"/>
                                </svg>
                            </div>
                            <h3><?php esc_html_e('Specific Roles', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Only users with selected WordPress roles can see the chatbot.', 'dialoga'); ?></p>
                        </button>
                    </div>

                    <div id="wizard-roles-section" style="display:none;">
                        <div class="dialoga-wizard-field" style="margin-top:20px;">
                            <label><?php esc_html_e('Select Roles', 'dialoga'); ?></label>
                            <div class="dialoga-wizard-roles-grid">
                                <?php
                                $wp_roles = wp_roles();
                                foreach ($wp_roles->role_names as $role_slug => $role_name) :
                                ?>
                                <label class="dialoga-wizard-role-item">
                                    <input type="checkbox" class="dialoga-wizard-role-check" value="<?php echo esc_attr($role_slug); ?>" />
                                    <?php echo esc_html(translate_user_role($role_name)); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 6: Launch Chatbot -->
            <div class="dialoga-wizard-step" id="wizard-step-6" data-step="6">
                <div class="dialoga-wizard-card">
                    <div id="wizard-review-summary">
                        <h2><?php esc_html_e('Review & Create', 'dialoga'); ?></h2>
                        <p class="dialoga-wizard-desc"><?php esc_html_e('Here\'s a summary of your chatbot setup. Click "Create My Chatbot" to build your flow.', 'dialoga'); ?></p>

                        <div class="dialoga-wizard-review-grid" id="wizard-review-grid">
                            <!-- Populated by JS -->
                        </div>

                        <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-primary dialoga-wizard-btn-lg dialoga-wizard-btn-full" id="wizard-create-btn">
                            <?php esc_html_e('Create My Chatbot', 'dialoga'); ?>
                        </button>
                    </div>

                    <div id="wizard-creating" role="status" aria-live="polite" style="display:none;">
                        <div class="dialoga-wizard-creating">
                            <div class="dialoga-wizard-spinner dialoga-wizard-spinner-lg"></div>
                            <h3><?php esc_html_e('Building Your Chatbot...', 'dialoga'); ?></h3>
                            <p><?php esc_html_e('Our AI is designing a custom conversational flow for your business. This takes about 15-30 seconds.', 'dialoga'); ?></p>
                        </div>
                    </div>

                    <div id="wizard-complete" role="status" aria-live="polite" style="display:none;">
                        <div class="dialoga-wizard-complete">
                            <div class="dialoga-wizard-complete-icon">&#x1f389;</div>
                            <h2><?php esc_html_e('Your Chatbot is Ready!', 'dialoga'); ?></h2>
                            <p><?php esc_html_e('We\'ve created a custom flow based on your business. You can preview it, customize it further, or go live right away.', 'dialoga'); ?></p>
                            <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-primary dialoga-wizard-btn-lg" id="wizard-go-to-builder">
                                <?php esc_html_e('Open Flow Builder', 'dialoga'); ?>
                            </button>
                        </div>
                    </div>

                    <div id="wizard-error" role="alert" aria-live="assertive" style="display:none;">
                        <div class="dialoga-wizard-error">
                            <div class="dialoga-wizard-error-icon">&#x26A0;&#xFE0F;</div>
                            <h3><?php esc_html_e('Something Went Wrong', 'dialoga'); ?></h3>
                            <p id="wizard-error-message"></p>
                            <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-outline" id="wizard-retry-btn"><?php esc_html_e('Try Again', 'dialoga'); ?></button>
                            <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-primary" id="wizard-use-template-btn"><?php esc_html_e('Use Starter Template', 'dialoga'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <div class="dialoga-wizard-footer">
        <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-ghost" id="wizard-back" style="visibility:hidden;">
            <?php esc_html_e('← Back', 'dialoga'); ?>
        </button>
        <div class="dialoga-wizard-step-label" id="wizard-step-label" aria-live="polite"></div>
        <button type="button" class="dialoga-wizard-btn dialoga-wizard-btn-primary" id="wizard-next">
            <?php esc_html_e('Get Started →', 'dialoga'); ?>
        </button>
    </div>

</div>

<?php wp_footer(); ?>
</body>
</html>
