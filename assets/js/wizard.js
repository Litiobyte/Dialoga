(function() {
    'use strict';

    function t(message) {
        var wizard = window.dialogawpWizard && window.dialogawpWizard.i18n;
        var admin = window.dialogawpAdmin && window.dialogawpAdmin.i18n;
        if (wizard && Object.prototype.hasOwnProperty.call(wizard, message)) return wizard[message];
        return admin && Object.prototype.hasOwnProperty.call(admin, message) ? admin[message] : message;
    }

    function escapeHtml(value) {
        var el = document.createElement('span');
        el.textContent = value;
        return el.innerHTML;
    }

    var W = {
        currentStep: 0,
        totalSteps: 7,
        stepLabels: ['Welcome', 'AI Setup', 'Crawl Website', 'Chatbot Customization', 'Chatbot Goals', 'Chatbot Visibility', 'Launch Chatbot'],
        data: {
            ai: { provider: 'openai', model: 'gpt-4o-mini', testPassed: false },
            crawl: { completed: false, itemCount: 0, message: '' },
            customize: { businessName: '', businessType: '', instructions: '' },
            goal: { types: [] },
            visibility: { mode: 'everyone', roles: [] },
        },
        createdFlowId: null,
        saveTimer: null,

        // ====================================================================
        // Init
        // ====================================================================
        init: function() {
            this.bindEvents();
            this.renderProgress();
            this.loadState();
        },

        // ====================================================================
        // Events
        // ====================================================================
        bindEvents: function() {
            var self = this;

            // Navigation
            document.getElementById('wizard-next').addEventListener('click', function() { self.nextStep(); });
            document.getElementById('wizard-back').addEventListener('click', function() { self.prevStep(); });
            document.getElementById('wizard-skip').addEventListener('click', function() { self.skipWizard(); });

            // Progress dots — click to jump to step
            document.addEventListener('click', function(e) {
                var dot = e.target.closest('.dialogawp-wizard-progress-dot');
                if (dot) {
                    var step = parseInt(dot.dataset.step);
                    self.goToStep(step);
                }
            });

            // Welcome step shortcuts — click to jump to step
            document.querySelectorAll('.dialogawp-wizard-welcome-step[data-goto]').forEach(function(step) {
                step.addEventListener('click', function() {
                    var target = parseInt(step.dataset.goto);
                    self.goToStep(target);
                });
            });

            // Change API key button
            document.getElementById('wizard-change-key').addEventListener('click', function() {
                document.getElementById('wizard-key-row-saved').style.display = 'none';
                document.getElementById('wizard-key-row-input').style.display = '';
                document.getElementById('wizard-api-key').focus();
                self.data.ai.testPassed = false;
                var status = document.getElementById('wizard-key-status');
                status.textContent = '';
                status.className = 'dialogawp-wizard-key-status';
            });

            // Managed AI click — show info modal
            var managedAI = document.querySelector('.dialogawp-wizard-radio-card-coming-soon');
            if (managedAI) managedAI.addEventListener('click', function(e) {
                e.preventDefault();
                dialogawpModal.confirm(
                    t('Managed AI is coming soon to DialogaWP Pro. We handle the AI infrastructure so you don\'t need your own API key — just plug in and go.\n\nIncludes:\n- No API key setup required\n- Pay-as-you-go usage billing\n- Automatic model updates\n- Priority AI response times\n\nWant to be notified when it launches?'),
                    function() {
                        window.open('https://www.litiobyte.com/labs-detalle.php?slug=dialogawp', '_blank');
                    },
                    { title: t('Managed AI — Coming Soon'), okText: t('Join Waitlist'), cancelText: t('Close'), icon: '\uD83E\uDD16' }
                );
            });

            // AI Setup — provider toggle
            document.querySelectorAll('input[name="ai_provider"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    self.data.ai.provider = this.value;
                    self.data.ai.testPassed = false;
                    self.updateProviderUI();
                    self.debounceSave();
                });
            });

            // AI Setup — test connection
            document.getElementById('wizard-test-connection').addEventListener('click', function() { self.testConnection(); });

            // AI Setup — model change
            document.getElementById('wizard-model').addEventListener('change', function() {
                self.data.ai.model = this.value;
                self.debounceSave();
            });

            // AI instructions accordion
            document.getElementById('wizard-instructions-toggle').addEventListener('click', function() {
                var acc = document.getElementById('wizard-instructions-accordion');
                acc.classList.toggle('open');
            });

            // Crawl
            document.getElementById('wizard-crawl-btn').addEventListener('click', function() { self.startCrawl(); });
            document.getElementById('wizard-recrawl-btn').addEventListener('click', function() { self.startCrawl(); });

            // Customize — blur saves
            ['wizard-business-name', 'wizard-business-type', 'wizard-custom-instructions'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('blur', function() { self.captureCustomize(); self.debounceSave(); });
            });

            // Industry "Other" toggle
            document.getElementById('wizard-business-type').addEventListener('change', function() {
                var otherField = document.getElementById('wizard-other-industry-field');
                if (this.value === 'other') {
                    otherField.style.display = '';
                    document.getElementById('wizard-other-industry').focus();
                } else {
                    otherField.style.display = 'none';
                }
            });
            document.getElementById('wizard-other-industry').addEventListener('blur', function() {
                self.captureCustomize();
                self.debounceSave();
            });

            // Goal cards (multi-select toggle)
            document.querySelectorAll('.dialogawp-wizard-goal-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    var goal = card.dataset.goal;
                    var idx = self.data.goal.types.indexOf(goal);
                    if (idx > -1) {
                        self.data.goal.types.splice(idx, 1);
                        card.classList.remove('selected');
                    } else {
                        self.data.goal.types.push(goal);
                        card.classList.add('selected');
                    }
                    self.debounceSave();
                });
            });

            // Visibility cards
            document.querySelectorAll('.dialogawp-wizard-visibility-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    document.querySelectorAll('.dialogawp-wizard-visibility-card').forEach(function(c) { c.classList.remove('selected'); });
                    card.classList.add('selected');
                    self.data.visibility.mode = card.dataset.visibility;
                    // Show/hide roles checkboxes
                    var rolesSection = document.getElementById('wizard-roles-section');
                    if (rolesSection) {
                        rolesSection.style.display = card.dataset.visibility === 'specific_roles' ? '' : 'none';
                    }
                    self.debounceSave();
                });
            });

            // Visibility role checkboxes
            document.querySelectorAll('.dialogawp-wizard-role-check').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    self.data.visibility.roles = [];
                    document.querySelectorAll('.dialogawp-wizard-role-check:checked').forEach(function(checked) {
                        self.data.visibility.roles.push(checked.value);
                    });
                    self.debounceSave();
                });
            });

            // Review — Create
            document.getElementById('wizard-create-btn').addEventListener('click', function() { self.createChatbot(); });
            document.getElementById('wizard-go-to-builder').addEventListener('click', function() { self.completeWizard(); });
            document.getElementById('wizard-retry-btn').addEventListener('click', function() { self.createChatbot(); });
            document.getElementById('wizard-use-template-btn').addEventListener('click', function() { self.useTemplate(); });
        },

        // ====================================================================
        // Navigation
        // ====================================================================
        goToStep: function(index) {
            if (index < 0 || index >= this.totalSteps) return;

            // Hide all steps
            document.querySelectorAll('.dialogawp-wizard-step').forEach(function(s) { s.classList.remove('active'); });

            // Show target step
            var step = document.querySelector('.dialogawp-wizard-step[data-step="' + index + '"]');
            if (step) step.classList.add('active');

            this.currentStep = index;
            this.updateProgress();
            this.updateNavButtons();

            // Step-specific init
            if (index === 0) this.updateWelcomeSteps();
            if (index === 1) this.initAIStep();
            if (index === 2) this.initCrawlStep();
            if (index === 3) this.initCustomizeStep();
            if (index === 4) this.initGoalStep();
            if (index === 5) this.initVisibilityStep();
            if (index === 6) this.initReviewStep();
        },

        nextStep: function() {
            if (!this.validateCurrentStep()) return;
            this.saveState();
            this.goToStep(this.currentStep + 1);
        },

        prevStep: function() {
            this.goToStep(this.currentStep - 1);
        },

        validateCurrentStep: function() {
            switch (this.currentStep) {
                case 1: // AI Setup
                    if (!this.data.ai.testPassed) {
                        var status = document.getElementById('wizard-key-status');
                        status.textContent = t('Please test your connection before proceeding.');
                        status.className = 'dialogawp-wizard-key-status error';
                        return false;
                    }
                    return true;
                case 3: // Customize
                    this.captureCustomize();
                    if (!this.data.customize.businessType) {
                        dialogawpModal.error(t('Please select your industry or business type.'), t('Error'));
                        document.getElementById('wizard-business-type').focus();
                        return false;
                    }
                    if (this.data.customize.businessType === 'other' && !this.data.customize.otherIndustry) {
                        dialogawpModal.error(t('Please specify your industry.'), t('Error'));
                        document.getElementById('wizard-other-industry').focus();
                        return false;
                    }
                    return true;
                case 4: // Goal
                    if (!this.data.goal.types || this.data.goal.types.length === 0) {
                        // Subtle highlight
                        document.querySelectorAll('.dialogawp-wizard-goal-card').forEach(function(c) {
                            c.style.animation = 'none';
                            c.offsetHeight; // reflow
                            c.style.animation = 'wizardFadeIn 0.3s ease';
                        });
                        return false;
                    }
                    return true;
                default:
                    return true;
            }
        },

        updateNavButtons: function() {
            var backBtn = document.getElementById('wizard-back');
            var nextBtn = document.getElementById('wizard-next');
            var label = document.getElementById('wizard-step-label');

            backBtn.style.visibility = this.currentStep > 0 ? 'visible' : 'hidden';

            if (this.currentStep === 0) {
                nextBtn.textContent = t('Get Started →');
            } else if (this.currentStep === this.totalSteps - 1) {
                nextBtn.style.display = 'none';
            } else if (this.currentStep === this.totalSteps - 2) {
                nextBtn.textContent = t('Review & Launch →');
            } else {
                nextBtn.style.display = '';
                nextBtn.textContent = t('Next →');
            }

            label.textContent = t('Step %1$d of %2$d').replace('%1$d', this.currentStep + 1).replace('%2$d', this.totalSteps);
        },

        // ====================================================================
        // Progress Indicator
        // ====================================================================
        renderProgress: function() {
            var container = document.getElementById('wizard-progress');
            var shortLabels = ['Welcome', 'AI Setup', 'Crawl Website', 'Chat Customization', 'Chat Goals', 'Visibility', 'Launch'];
            var html = '';
            for (var i = 0; i < this.totalSteps; i++) {
                html += '<div class="dialogawp-wizard-progress-step">';
                html += '<div class="dialogawp-wizard-progress-dot-wrap">';
                html += '<div class="dialogawp-wizard-progress-dot" data-step="' + i + '">' + (i + 1) + '</div>';
                html += '<div class="dialogawp-wizard-progress-label">' + escapeHtml(t(shortLabels[i])) + '</div>';
                html += '</div>';
                if (i < this.totalSteps - 1) {
                    html += '<div class="dialogawp-wizard-progress-line" data-line="' + i + '"></div>';
                }
                html += '</div>';
            }
            container.innerHTML = html;
        },

        updateProgress: function() {
            var stepDone = [
                false, // Step 0: Welcome (never marked done)
                this.data.ai.testPassed,
                this.data.crawl.completed,
                !!(this.data.customize.businessName || this.data.customize.instructions),
                this.data.goal.types && this.data.goal.types.length > 0,
                !!(this.data.visibility && this.data.visibility.mode),
                false // Step 6: Launch (never marked done)
            ];

            for (var i = 0; i < this.totalSteps; i++) {
                var dot = document.querySelector('.dialogawp-wizard-progress-dot[data-step="' + i + '"]');
                var line = document.querySelector('.dialogawp-wizard-progress-line[data-line="' + i + '"]');

                dot.classList.remove('active', 'completed');
                if (i === this.currentStep) {
                    dot.classList.add('active');
                    dot.innerHTML = i + 1;
                } else if (stepDone[i] || i < this.currentStep) {
                    dot.classList.add('completed');
                    dot.innerHTML = '\u2713';
                } else {
                    dot.innerHTML = i + 1;
                }

                if (line) {
                    line.classList.toggle('completed', stepDone[i] || i < this.currentStep);
                }
            }
        },

        // ====================================================================
        // State Persistence
        // ====================================================================
        saveState: function() {
            var self = this;
            var fd = new FormData();
            fd.append('action', 'dialogawp_wizard_save_state');
            fd.append('nonce', dialogawpWizard.nonce);
            fd.append('currentStep', this.currentStep);
            fd.append('data', JSON.stringify(this.data));
            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp && resp.success) self.showSavePill();
                })
                .catch(function() { /* silent — debounced retries on next change */ });
        },

        // Flash the "Progress saved" pill for ~1.6s after each successful auto-save.
        // Reuses the existing pill element; re-triggering before fade-out resets the timer.
        showSavePill: function() {
            var pill = document.getElementById('wizard-save-pill');
            if (!pill) return;
            pill.classList.add('is-visible');
            clearTimeout(this._savePillTimer);
            this._savePillTimer = setTimeout(function() {
                pill.classList.remove('is-visible');
            }, 1600);
        },

        debounceSave: function() {
            var self = this;
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(function() { self.saveState(); }, 500);
        },

        loadState: function() {
            var self = this;
            var fd = new FormData();
            fd.append('action', 'dialogawp_wizard_get_state');
            fd.append('nonce', dialogawpWizard.nonce);

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (!resp.success) { self.goToStep(0); return; }

                    var d = resp.data;

                    // Restore from saved state
                    if (d.state && d.state.data) {
                        self.data = Object.assign(self.data, d.state.data);
                    }

                    // Check server-side state
                    if (d.hasApiKey) self.data.ai.testPassed = true;
                    if (d.kbCount > 0) {
                        self.data.crawl.completed = true;
                        self.data.crawl.itemCount = d.kbCount;
                    }
                    if (d.aiProvider) self.data.ai.provider = d.aiProvider;
                    if (d.aiModel) self.data.ai.model = d.aiModel;

                    var startStep = (d.state && d.state.currentStep) ? d.state.currentStep : 0;
                    document.getElementById('wizard-loading').style.display = 'none';
                    document.querySelector('.dialogawp-wizard-content').style.display = '';
                    self.goToStep(startStep);
                })
                .catch(function() {
                    document.getElementById('wizard-loading').style.display = 'none';
                    document.querySelector('.dialogawp-wizard-content').style.display = '';
                    self.goToStep(0);
                });
        },

        // ====================================================================
        // Step 1: AI Setup
        // ====================================================================
        initAIStep: function() {
            // Set provider radio
            var radio = document.querySelector('input[name="ai_provider"][value="' + this.data.ai.provider + '"]');
            if (radio) radio.checked = true;

            // Set model
            var modelSelect = document.getElementById('wizard-model');
            modelSelect.value = this.data.ai.model;

            this.updateProviderUI();

            // Show masked key or input based on whether key exists
            if (this.data.ai.testPassed) {
                document.getElementById('wizard-key-row-saved').style.display = '';
                document.getElementById('wizard-key-row-input').style.display = 'none';
                var status = document.getElementById('wizard-key-status');
                status.textContent = '\u2713 ' + t('Connection verified. You\'re all set!');
                status.className = 'dialogawp-wizard-key-status success';
            } else {
                document.getElementById('wizard-key-row-saved').style.display = 'none';
                document.getElementById('wizard-key-row-input').style.display = '';
            }
        },

        updateProviderUI: function() {
            var isOpenAI = this.data.ai.provider === 'openai';
            document.getElementById('wizard-models-openai').disabled = !isOpenAI;
            document.getElementById('wizard-models-anthropic').disabled = isOpenAI;
            document.getElementById('wizard-openai-instructions').style.display = isOpenAI ? '' : 'none';
            document.getElementById('wizard-anthropic-instructions').style.display = isOpenAI ? 'none' : '';

            // Auto-select appropriate model
            var modelSelect = document.getElementById('wizard-model');
            if (isOpenAI && modelSelect.value.startsWith('claude')) {
                modelSelect.value = 'gpt-4o-mini';
                this.data.ai.model = 'gpt-4o-mini';
            } else if (!isOpenAI && modelSelect.value.startsWith('gpt')) {
                modelSelect.value = 'claude-haiku-4-5-20251001';
                this.data.ai.model = 'claude-haiku-4-5-20251001';
            }
        },

        testConnection: function() {
            var self = this;
            var btn = document.getElementById('wizard-test-connection');
            var status = document.getElementById('wizard-key-status');
            var apiKey = document.getElementById('wizard-api-key').value.trim();

            if (!apiKey && !this.data.ai.testPassed) {
                status.textContent = t('Please enter your API key.');
                status.className = 'dialogawp-wizard-key-status error';
                return;
            }

            btn.textContent = t('Testing...');
            btn.disabled = true;

            // First save the AI settings
            var fd = new FormData();
            fd.append('action', 'dialogawp_save_ai_settings');
            fd.append('nonce', dialogawpWizard.nonce);
            fd.append('provider', this.data.ai.provider);
            fd.append('model', this.data.ai.model);
            fd.append('max_tokens', 500);
            fd.append('instructions', '');
            if (apiKey) fd.append('api_key', apiKey);

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function() {
                    // Now test the connection
                    var fd2 = new FormData();
                    fd2.append('action', 'dialogawp_test_ai');
                    fd2.append('nonce', dialogawpWizard.nonce);

                    return fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd2 });
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.textContent = t('Test Connection');
                    btn.disabled = false;

                    if (data.success) {
                        self.data.ai.testPassed = true;
                        status.textContent = '\u2713 ' + t('Connection successful! Your AI is ready.');
                        status.className = 'dialogawp-wizard-key-status success';
                        document.getElementById('wizard-api-key').value = '';
                        document.getElementById('wizard-key-row-saved').style.display = '';
                        document.getElementById('wizard-key-row-input').style.display = 'none';
                        self.debounceSave();
                    } else {
                        self.data.ai.testPassed = false;
                        status.textContent = '\u2717 ' + t(data.data.message || 'Connection failed. Check your API key.');
                        status.className = 'dialogawp-wizard-key-status error';
                    }
                })
                .catch(function() {
                    btn.textContent = t('Test Connection');
                    btn.disabled = false;
                    status.textContent = '\u2717 ' + t('Network error. Please try again.');
                    status.className = 'dialogawp-wizard-key-status error';
                });
        },

        // ====================================================================
        // Step 2: Website Crawl
        // ====================================================================
        initCrawlStep: function() {
            document.getElementById('wizard-site-url').textContent = dialogawpWizard.siteUrl;

            if (this.data.crawl.completed) {
                document.getElementById('wizard-crawl-status').style.display = 'none';
                document.getElementById('wizard-crawl-progress').style.display = 'none';
                document.getElementById('wizard-crawl-results').style.display = '';
                document.getElementById('wizard-crawl-summary').textContent =
                    t('%d pages indexed in your knowledge base.').replace('%d', this.data.crawl.itemCount);
            }
        },

        startCrawl: function() {
            var self = this;
            document.getElementById('wizard-crawl-status').style.display = 'none';
            document.getElementById('wizard-crawl-results').style.display = 'none';
            document.getElementById('wizard-crawl-progress').style.display = '';

            var fd = new FormData();
            fd.append('action', 'dialogawp_crawl_website');
            fd.append('nonce', dialogawpWizard.nonce);

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    document.getElementById('wizard-crawl-progress').style.display = 'none';

                    if (data.success) {
                        self.data.crawl.completed = true;
                        var d = data.data;
                        self.data.crawl.itemCount = d.saved + d.updated;
                        self.data.crawl.message = d.message || '';

                        document.getElementById('wizard-crawl-results').style.display = '';
                        document.getElementById('wizard-crawl-summary').textContent =
                            t('%1$d pages indexed (%2$d new, %3$d updated).').replace('%1$d', d.saved + d.updated).replace('%2$d', d.saved).replace('%3$d', d.updated);
                        self.debounceSave();
                    } else {
                        document.getElementById('wizard-crawl-status').style.display = '';
                        dialogawpModal.error(t(data.data.message || 'Crawl failed. Please try again.'), t('Error'));
                    }
                })
                .catch(function() {
                    document.getElementById('wizard-crawl-progress').style.display = 'none';
                    document.getElementById('wizard-crawl-status').style.display = '';
                    dialogawpModal.error(t('Network error during crawl. Please try again.'), t('Error'));
                });
        },

        // ====================================================================
        // Step 3: Customize
        // ====================================================================
        initCustomizeStep: function() {
            var d = this.data.customize;
            document.getElementById('wizard-business-name').value = d.businessName || dialogawpWizard.siteName || '';
            document.getElementById('wizard-business-type').value = d.businessType || '';
            document.getElementById('wizard-custom-instructions').value = d.instructions || '';
            document.getElementById('wizard-other-industry').value = d.otherIndustry || '';
            document.getElementById('wizard-other-industry-field').style.display = d.businessType === 'other' ? '' : 'none';
        },

        captureCustomize: function() {
            this.data.customize.businessName = document.getElementById('wizard-business-name').value.trim();
            this.data.customize.businessType = document.getElementById('wizard-business-type').value;
            this.data.customize.otherIndustry = document.getElementById('wizard-other-industry').value.trim();
            this.data.customize.instructions = document.getElementById('wizard-custom-instructions').value.trim();
        },

        // ====================================================================
        // Step 4: Goal
        // ====================================================================
        initGoalStep: function() {
            var types = this.data.goal.types || [];
            document.querySelectorAll('.dialogawp-wizard-goal-card').forEach(function(c) {
                c.classList.toggle('selected', types.indexOf(c.dataset.goal) > -1);
            });
        },

        // ====================================================================
        // Step 5: Visibility
        // ====================================================================
        initVisibilityStep: function() {
            var mode = this.data.visibility.mode || 'everyone';
            document.querySelectorAll('.dialogawp-wizard-visibility-card').forEach(function(c) {
                c.classList.toggle('selected', c.dataset.visibility === mode);
            });
            var rolesSection = document.getElementById('wizard-roles-section');
            if (rolesSection) {
                rolesSection.style.display = mode === 'specific_roles' ? '' : 'none';
            }
            var roles = this.data.visibility.roles || [];
            document.querySelectorAll('.dialogawp-wizard-role-check').forEach(function(cb) {
                cb.checked = roles.indexOf(cb.value) > -1;
            });
        },

        // ====================================================================
        // Welcome — update step completion indicators
        // ====================================================================
        updateWelcomeSteps: function() {
            var steps = [
                { id: 1, done: this.data.ai.testPassed },
                { id: 2, done: this.data.crawl.completed },
                { id: 3, done: !!(this.data.customize.businessName || this.data.customize.instructions) },
                { id: 4, done: this.data.goal.types && this.data.goal.types.length > 0 },
                { id: 5, done: !!(this.data.visibility && this.data.visibility.mode) },
                { id: 6, done: false } // Launch is never "done" from welcome
            ];

            steps.forEach(function(s) {
                var stepEl = document.querySelector('.dialogawp-wizard-welcome-step[data-goto="' + s.id + '"]');
                var numEl = document.getElementById('welcome-step-' + s.id);
                if (!stepEl || !numEl) return;

                if (s.done) {
                    stepEl.classList.add('completed');
                    numEl.innerHTML = '\u2713';
                } else {
                    stepEl.classList.remove('completed');
                    numEl.innerHTML = s.id;
                }
            });
        },

        // ====================================================================
        // Step 5: Review & Create
        // ====================================================================
        initReviewStep: function() {
            this.captureCustomize();

            var providerLabels = { openai: 'OpenAI', anthropic: 'Anthropic' };
            var goalLabels = {
                leads: 'Capture Leads',
                booking: 'Book Appointments',
                support: 'Customer Support',
                info: 'Info Chatbot',
                qualify: 'Qualify Leads',
                survey: 'Survey / Feedback',
                webhooks: 'Webhooks'
            };
            var industryEl = document.getElementById('wizard-business-type');
            var industryLabel = industryEl.options[industryEl.selectedIndex] ? industryEl.options[industryEl.selectedIndex].text : '-';
            if (this.data.customize.businessType === 'other' && this.data.customize.otherIndustry) {
                industryLabel = this.data.customize.otherIndustry;
            }

            var selectedGoals = (this.data.goal.types || []).map(function(g) {
                return t(goalLabels[g] || g);
            }).join(', ') || '-';

            var grid = document.getElementById('wizard-review-grid');
            grid.innerHTML =
                this.reviewItem(t('AI Provider'), providerLabels[this.data.ai.provider] || '-') +
                this.reviewItem(t('Model'), this.data.ai.model || '-') +
                this.reviewItem(t('Knowledge Base'), t('%d pages').replace('%d', this.data.crawl.itemCount)) +
                this.reviewItem(t('Business Name'), this.data.customize.businessName || '-') +
                this.reviewItem(t('Industry'), industryLabel) +
                this.reviewItem(t('Goals'), selectedGoals) +
                this.reviewItem(t('Visibility'), t(this.data.visibility.mode === 'everyone' ? 'Public (Everyone)' : this.data.visibility.mode === 'logged_in' ? 'Logged-in Users' : 'Specific Roles'));

            // Reset states
            document.getElementById('wizard-review-summary').style.display = '';
            document.getElementById('wizard-creating').style.display = 'none';
            document.getElementById('wizard-complete').style.display = 'none';
            document.getElementById('wizard-error').style.display = 'none';
        },

        reviewItem: function(label, value) {
            return '<div class="dialogawp-wizard-review-item">' +
                '<div class="dialogawp-wizard-review-label">' + escapeHtml(label) + '</div>' +
                '<div class="dialogawp-wizard-review-value">' + escapeHtml(value) + '</div>' +
                '</div>';
        },

        // ====================================================================
        // Create Chatbot Flow
        // ====================================================================
        createChatbot: function() {
            var self = this;

            // Save custom instructions first
            this.captureCustomize();
            var fd0 = new FormData();
            fd0.append('action', 'dialogawp_save_ai_settings');
            fd0.append('nonce', dialogawpWizard.nonce);
            fd0.append('provider', this.data.ai.provider);
            fd0.append('model', this.data.ai.model);
            fd0.append('max_tokens', 500);
            fd0.append('instructions', this.data.customize.instructions);

            document.getElementById('wizard-review-summary').style.display = 'none';
            document.getElementById('wizard-error').style.display = 'none';
            document.getElementById('wizard-creating').style.display = '';

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd0 })
                .then(function() {
                    // Generate the flow with wizard context
                    var fd = new FormData();
                    fd.append('action', 'dialogawp_generate_flow');
                    fd.append('nonce', dialogawpWizard.nonce);
                    fd.append('business_name', self.data.customize.businessName || '');
                    fd.append('business_type', self.data.customize.businessType || '');
                    fd.append('other_industry', self.data.customize.otherIndustry || '');
                    fd.append('goals', JSON.stringify(self.data.goal.types || []));

                    return fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd });
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data.flow) {
                        var flow = data.data.flow;

                        // Add lead capture if any goal needs contact info
                        var goals = self.data.goal.types || [];
                        var needsLeadForm = goals.indexOf('leads') > -1 || goals.indexOf('booking') > -1 || goals.indexOf('qualify') > -1;
                        if (needsLeadForm) {
                            flow = self.ensureLeadCapture(flow);
                        }

                        // Save the flow
                        self.saveFlow(flow, data.data.flow_name || (self.data.customize.businessName + ' Chatbot'));
                    } else {
                        self.showCreateError(data.data ? data.data.message : 'Flow generation failed.');
                    }
                })
                .catch(function(err) {
                    self.showCreateError(t('Network error: %s').replace('%s', err.message));
                });
        },

        ensureLeadCapture: function(flow) {
            // Check if there's already a capture_lead action node
            var hasCapture = false;
            flow.nodes.forEach(function(n) {
                if (n.data && n.data.nodeType === 'action' && n.data.action_type === 'capture_lead') {
                    hasCapture = true;
                }
            });

            if (!hasCapture) {
                // Find the rightmost/bottommost node for positioning
                var maxY = 0;
                var maxX = 0;
                flow.nodes.forEach(function(n) {
                    if (n.position.y > maxY) maxY = n.position.y;
                    if (n.position.x > maxX) maxX = n.position.x;
                });

                var captureNode = {
                    id: 'node_capture_' + Date.now(),
                    type: 'custom',
                    position: { x: maxX, y: maxY + 200 },
                    data: {
                        nodeType: 'action',
                        label: 'Capture Lead',
                        is_start: false,
                        action_type: 'capture_lead',
                        config: {
                            fields: {
                                name: { enabled: true, label: 'Your Name', required: true },
                                email: { enabled: true, label: 'Email Address', required: true },
                                phone: { enabled: true, label: 'Phone Number', required: false }
                            },
                            success_message: 'Thanks! We\'ll be in touch shortly.',
                            redirect_url: ''
                        }
                    }
                };

                flow.nodes.push(captureNode);

                // Connect the last non-capture node to it
                var lastNode = flow.nodes[flow.nodes.length - 2];
                if (lastNode) {
                    flow.edges.push({
                        id: 'edge_to_capture_' + Date.now(),
                        source: lastNode.id,
                        target: captureNode.id,
                        sourceHandle: 'bottom',
                        targetHandle: 'top'
                    });
                }
            }

            return flow;
        },

        saveFlow: function(flow, flowName) {
            var self = this;
            var fd = new FormData();
            fd.append('action', 'dialogawp_save_flow');
            fd.append('nonce', dialogawpWizard.nonce);
            fd.append('flow_id', 0);
            fd.append('flow_name', flowName);
            fd.append('is_active', 1);
            fd.append('visibility_mode', this.data.visibility.mode || 'everyone');
            fd.append('visible_roles', JSON.stringify(this.data.visibility.roles || []));
            fd.append('flow_data', JSON.stringify(flow));

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        self.createdFlowId = data.data.flow_id;
                        document.getElementById('wizard-creating').style.display = 'none';
                        document.getElementById('wizard-complete').style.display = '';
                    } else {
                        self.showCreateError(data.data ? data.data.message : 'Could not save flow.');
                    }
                })
                .catch(function(err) {
                    self.showCreateError(t('Network error: %s').replace('%s', err.message));
                });
        },

        useTemplate: function() {
            // Build a simple template flow
            var flow = {
                nodes: [
                    {
                        id: 'node_1',
                        type: 'custom',
                        position: { x: 300, y: 50 },
                        data: {
                            nodeType: 'question',
                            label: 'Welcome',
                            is_start: true,
                            message: 'Hi there! How can I help you today?',
                            auto_continue: false,
                            delay: 0,
                            allow_text_input: false,
                            text_placeholder: '',
                            options: [
                                { id: 'opt_1', label: 'I have a question' },
                                { id: 'opt_2', label: 'I\'d like to get started' }
                            ]
                        }
                    },
                    {
                        id: 'node_2',
                        type: 'custom',
                        position: { x: 300, y: 300 },
                        data: {
                            nodeType: 'ai_chat',
                            label: 'AI Chat',
                            is_start: false,
                            config: {
                                greeting: 'Sure! What would you like to know?',
                                placeholder: 'Type your question...',
                                instructions: this.data.customize.instructions || ''
                            }
                        }
                    }
                ],
                edges: [
                    {
                        id: 'edge_1',
                        source: 'node_1',
                        target: 'node_2',
                        sourceHandle: 'bottom',
                        targetHandle: 'top'
                    }
                ]
            };

            // Add lead capture for applicable goals
            var goals = this.data.goal.types || [];
            var needsLeadForm = goals.indexOf('leads') > -1 || goals.indexOf('booking') > -1 || goals.indexOf('qualify') > -1;
            if (needsLeadForm) {
                flow = this.ensureLeadCapture(flow);
            }

            document.getElementById('wizard-error').style.display = 'none';
            document.getElementById('wizard-creating').style.display = '';

            this.saveFlow(flow, (this.data.customize.businessName || 'My') + ' Chatbot');
        },

        showCreateError: function(message) {
            document.getElementById('wizard-creating').style.display = 'none';
            document.getElementById('wizard-error').style.display = '';
            document.getElementById('wizard-error-message').textContent = t(message || 'Something went wrong.');
        },

        // ====================================================================
        // Complete / Skip
        // ====================================================================
        completeWizard: function() {
            var fd = new FormData();
            fd.append('action', 'dialogawp_wizard_complete');
            fd.append('nonce', dialogawpWizard.nonce);
            fd.append('flow_id', this.createdFlowId || 0);

            fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.href = data.data.redirect;
                    }
                });
        },

        skipWizard: function() {
            dialogawpModal.confirm(t('Skip the setup wizard? You can always set things up manually later.'), function() {
                var fd = new FormData();
                fd.append('action', 'dialogawp_wizard_skip');
                fd.append('nonce', dialogawpWizard.nonce);

                fetch(dialogawpWizard.ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            window.location.href = data.data.redirect;
                        }
                    });
            }, { title: t('Skip Setup'), okText: t('Skip'), cancelText: t('Continue Setup') });
        }
    };

    // Boot
    if (document.getElementById('dialogawp-wizard')) {
        W.init();
    }
})();
