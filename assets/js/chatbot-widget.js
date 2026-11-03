/**
 * DIALOGA Chat Widget - Frontend JavaScript
 * Handles chat bubble, conversation flow, and user interactions
 */

(function($) {
    'use strict';

    // Store the localized settings from wp_localize_script before shadowing
    const dialogaConfig = window.dialogaWidget || {};
    if (!dialogaConfig.settings) dialogaConfig.settings = {};

    // Debug logging — only active when WP_DEBUG is true
    var _log = dialogaConfig.debug ? console.log.bind(console) : function() {};
    var _warn = dialogaConfig.debug ? console.warn.bind(console) : function() {};
    var _error = console.error.bind(console);

    const dialogaWidget = {
        sessionId: null,
        sessionToken: null,
        conversationId: null,
        flowId: null,
        currentNode: null,
        conversationHistory: [],
        isOpen: false,
        leadCaptured: false,
        pendingTags: [],
        variables: {},
        
        init: function() {
            _log('🎯 DIALOGA Widget initializing...');
            _log('📋 Widget settings:', dialogaConfig);

            // Apply primary color from settings as CSS custom property
            const primaryColor = dialogaConfig.settings.primaryColor;
            if (primaryColor) {
                document.getElementById('dialoga-widget').style.setProperty('--dialoga-primary', primaryColor);
                // Auto-derive a darker shade for hover states
                document.getElementById('dialoga-widget').style.setProperty('--dialoga-primary-dark', this.darkenColor(primaryColor, 15));
                document.getElementById('dialoga-widget').style.setProperty('--dialoga-primary-contrast', this.contrastColor(primaryColor));
            }

            // Check if widget HTML exists
            if ($('#dialoga-widget').length === 0) {
                _error('❌ DIALOGA widget HTML not found! The widget is not being rendered.');
                _log('💡 Possible reasons:');
                _log('   1. Widget is disabled in Settings');
                _log('   2. No active flow exists');
                _log('   3. PHP error preventing render');
                return;
            }
            
            _log('✅ Widget HTML found');

            // v0.38.20 — bubble-shown impression tracking moved out of init()
            // and into an inline vanilla script in render_chat_widget PHP so it
            // fires on page parse, not after jQuery finally loads.

            // v0.38.10 — preview message is now server-rendered + CSS-animated,
            // wired up via inline vanilla JS in render_chat_widget. This works
            // even when TenWeb / Breeze defer jQuery until first interaction.
            // The JS-based showPreviewMessage call is kept for back-compat only.
            
            // Fresh-session roles: when the logged-in user has a role configured in
            // Dialoga settings → Fresh Session for Roles, reset the chat on actual
            // page reloads (F5 / reload button) but preserve it when:
            //   - the visitor is navigating between pages (link click, new URL)
            //   - arriving via a flow Redirect action (?dialoga_open=1)
            // That way an admin can test a redirect and see the chat continue on
            // the destination page, while a fresh F5 still resets for clean tests.
            if (dialogaConfig.settings.freshSessionForUser) {
                const navEntry = performance.getEntriesByType('navigation')[0];
                const navType = navEntry ? navEntry.type : 'navigate';
                const urlHasOpenFlag = new URLSearchParams(window.location.search).get('dialoga_open') === '1';

                if (navType === 'reload' && !urlHasOpenFlag) {
                    localStorage.removeItem('dialoga_session');
                    _log('🧪 Fresh-session role: page reload detected → session cleared');
                } else {
                    _log('🧪 Fresh-session role: navigation/post-redirect → session preserved');
                }
            }

            // Test mode: clear session on page reload, preserve on navigation
            if (dialogaConfig.settings.testMode) {
                const navEntry = performance.getEntriesByType('navigation')[0];
                const isReload = navEntry ? navEntry.type === 'reload' : false;
                if (isReload) {
                    localStorage.removeItem('dialoga_session');
                    _log('🔄 Test mode: session cleared on reload');
                }
            }

            // Load session from LocalStorage
            this.loadSession();

            // Bind events
            this.bindEvents();

            // Check if coming from redirect (URL parameter)
            const urlParams = new URLSearchParams(window.location.search);
            const isAfterRedirect = urlParams.get('dialoga_open') === '1';
            
            // Handle session restoration
            if (this.sessionId) {
                // Check for stale session: session exists but history is empty
                // This happens when conversation was started but no messages were actually displayed
                // (e.g., page closed before first message rendered). Treat as fresh start.
                if (!this.conversationHistory || this.conversationHistory.length === 0) {
                    _log('⚠️ Stale session detected (empty history) — starting fresh conversation');
                    localStorage.removeItem('dialoga_session');
                    this.sessionId = null;
                    this.sessionToken = null;
                    this.conversationId = null;
                    this.flowId = null;
                    this.currentNode = null;
                    this.conversationHistory = [];
                    // Don't auto-open — wait for user to click bubble (will start new conversation)
                } else {
                    _log('📱 Resuming previous session:', this.sessionId);

                    // v0.38.12 — don't auto-open the chat window on mobile.
                    // On mobile the window is a full-screen bottom sheet, so
                    // auto-opening on every page load hijacks the visitor's
                    // screen and feels confusing. Restore the conversation
                    // state (so tapping the bubble picks up where they left
                    // off), but leave the widget closed until they tap.
                    var isMobileViewport = window.matchMedia && window.matchMedia('(max-width: 480px)').matches;
                    if (!isMobileViewport && !isAfterRedirect) {
                        this.openChat();
                    } else if (isAfterRedirect) {
                        // Post-redirect flows still auto-open — the visitor
                        // explicitly initiated the redirect (e.g. via a
                        // Capture Lead node), so continuing the chat is expected.
                        this.openChat();
                    }
                    this.restoreConversation(isAfterRedirect);
                }
            }
            if (!this.sessionId && isAfterRedirect) {
                // URL parameter present but no session - start new conversation
                _log('🔗 URL parameter detected - opening widget');
                this.openChat();
                _log('🆕 Starting new conversation');
                this.showTyping();
                this.startConversation();
            }
            
            // Bubble nudge — fires on every page load as long as the chat window
            // isn't already open, so returning visitors see the animation too.
            if (dialogaConfig.settings.bubbleNudge) {
                const widget = this;
                setTimeout(function() {
                    if (!widget.isOpen) {
                        $('#dialoga-bubble').addClass('dialoga-bubble--nudge');
                    }
                }, 3000);
            }

            _log('✅ DIALOGA Widget ready!');
        },
        
        bindEvents: function() {
            const self = this;
            
            // Open chat
            $('#dialoga-bubble').on('click', function() {
                self.openChat();
                if (!self.sessionId) {
                    // Show typing indicator immediately for visual feedback
                    // while we wait for the first message from the server
                    self.showTyping();
                    self.startConversation();
                }
            });
            
            // Close chat — vanilla listener so TenWeb / page-speed plugins that
            // defer jQuery and intercept click events don't strip this binding.
            var closeBtn = document.getElementById('dialoga-close');
            if (closeBtn && !closeBtn._dialogaBound) {
                closeBtn._dialogaBound = true;
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    self.closeChat();
                }, true); // capture phase to beat any interceptors
            }

            // Restart chat from header (always-available escape hatch — v0.38.7+)
            // v1.0.5 — the click trace now goes through _log like everything
            // else. It was a bare console.log, so every visitor who restarted
            // a chat got plugin output in their browser console.
            var restartBtn = document.getElementById('dialoga-restart-header');
            if (restartBtn && !restartBtn._dialogaBound) {
                restartBtn._dialogaBound = true;
                restartBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    _log('Dialoga: header restart clicked');
                    if (window.confirm('Start a new conversation? Your current chat history will be cleared.')) {
                        self.restartChat();
                    }
                }, true);
            }

            // Save transcript when the visitor leaves the page (best-effort via sendBeacon)
            $(window).on('beforeunload pagehide', function() {
                self.saveTranscript(true);
            });
        },
        
        openChat: function() {
            $('#dialoga-bubble').removeClass('dialoga-bubble--nudge').hide();
            $('.dialoga-preview-message').remove();
            $('#dialoga-window').fadeIn(200);
            this.isOpen = true;
            this.scrollToBottom();
        },

        // v0.38.7 — show a floating preview bubble next to the chat icon
        showPreviewMessage: function(text) {
            if ($('.dialoga-preview-message').length) return; // already visible

            var self = this;
            var $preview = $(
                '<div class="dialoga-preview-message" role="status">' +
                    '<button type="button" class="dialoga-preview-close" aria-label="Dismiss">&times;</button>' +
                    '<div class="dialoga-preview-text"></div>' +
                '</div>'
            );
            $preview.find('.dialoga-preview-text').text(text);

            $preview.on('click', function(e) {
                if ($(e.target).hasClass('dialoga-preview-close')) return;
                $preview.remove();
                self.openChat();
                if (!self.sessionId) {
                    self.showTyping();
                    self.startConversation();
                }
            });

            $preview.find('.dialoga-preview-close').on('click', function(e) {
                e.stopPropagation();
                localStorage.setItem('dialoga_preview_dismissed', '1');
                $preview.fadeOut(150, function() { $preview.remove(); });
            });

            $('#dialoga-widget').append($preview);
            $preview.hide().fadeIn(200);
        },
        
        closeChat: function() {
            $('#dialoga-window').fadeOut(200, function() {
                $('#dialoga-bubble').fadeIn(200);
            });
            this.isOpen = false;

            // Persist the transcript so it's captured even if no lead was submitted
            this.saveTranscript();

            // Track abandonment if session active but no lead captured
            if (this.conversationId && !this.leadCaptured) {
                $.ajax({
                    url: dialogaConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dialoga_track_event',
                        nonce: dialogaConfig.nonce,
                        conversation_id: this.conversationId,
                        event_type: 'conversation_abandoned',
                        flow_id: this.flowId || 0
                    }
                });
            }
        },

        // Save the current conversation transcript to the server.
        // Runs on chat close, flow end, and page unload (via sendBeacon).
        saveTranscript: function(useBeacon) {
            if (!this.sessionId || !this.conversationHistory || this.conversationHistory.length < 2) return;
            var payload = {
                action: 'dialoga_save_transcript',
                nonce: dialogaConfig.nonce,
                session_id: this.sessionId,
                session_token: this.sessionToken || '',
                flow_id: this.flowId || 0,
                history: JSON.stringify(this.conversationHistory)
            };
            if (useBeacon && navigator.sendBeacon) {
                // Best-effort during page unload — sendBeacon survives navigation
                var fd = new FormData();
                Object.keys(payload).forEach(function(k) { fd.append(k, payload[k]); });
                try { navigator.sendBeacon(dialogaConfig.ajaxUrl, fd); } catch (e) {}
                return;
            }
            $.ajax({ url: dialogaConfig.ajaxUrl, type: 'POST', data: payload });
        },
        
        startConversation: function() {
            const self = this;
            
            _log('🚀 Starting new conversation...');
            
            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialoga_start_conversation',
                    nonce: dialogaConfig.nonce,
                    page_url: window.location.href
                },
                success: function(response) {
                    self.hideTyping();

                    if (response.success) {
                        _log('✅ Conversation started:', response.data);

                        self.sessionId      = response.data.session_id;
                        self.sessionToken   = response.data.session_token || null;
                        self.conversationId = response.data.conversation_id || 0;
                        self.flowId         = response.data.flow_id;
                        self.currentNode    = response.data.current_node;

                        // Save session
                        self.saveSession();

                        // Display first message
                        self.displayBotMessage(response.data.message);
                    } else {
                        _error('❌ Failed to start conversation:', response.data);
                        self.displayBotMessage({
                            type: 'message',
                            text: 'Sorry, our chat is temporarily unavailable. Please try again later.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    self.hideTyping();
                    _error('❌ AJAX error:', error);
                    self.displayBotMessage({
                        type: 'message',
                        text: 'Connection error. Please refresh the page and try again.'
                    });
                }
            });
        },
        
        sendResponse: function(userResponse) {
            const self = this;

            // v0.39.9: skip user-message display for ALL internal flow-advance
            // sentinels, not just __auto_continue__. These sentinels are signals
            // to the server for advancing the flow, never real visitor input.
            // Prior to this fix, "__link_clicked__" was leaking into the chat as
            // a visible bubble after Link Button clicks.
            var internalSentinels = ['__auto_continue__', '__link_clicked__', '__ai_lead_captured__'];
            if (internalSentinels.indexOf(userResponse) === -1) {
                this.displayUserMessage(userResponse);
            }
            
            // Show typing indicator
            this.showTyping();
            
            _log('📤 Sending response:', userResponse);
            
            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialoga_process_response',
                    nonce: dialogaConfig.nonce,
                    session_id: this.sessionId,
                    session_token: this.sessionToken || '',
                    conversation_id: this.conversationId || 0,
                    flow_id: this.flowId,
                    current_node: this.currentNode,
                    response: userResponse,
                    variables: JSON.stringify(this.variables)
                },
                success: function(response) {
                    self.hideTyping();

                    if (response.success) {
                        _log('✅ Response processed:', response.data);

                        // Save variable if server flagged one
                        if (response.data.save_variable) {
                            const sv = response.data.save_variable;
                            self.variables[sv.key] = sv.value;
                            _log('💾 Variable saved:', sv.key, '=', sv.value);
                        }

                        // Accumulate pending tags from tag_lead nodes
                        if (response.data.pending_tags && response.data.pending_tags.length) {
                            self.pendingTags = self.pendingTags.concat(response.data.pending_tags);
                            _log('🏷️ Pending tags accumulated:', self.pendingTags);
                        }

                        if (response.data.end) {
                            // Conversation ended — tag the message so displayBotMessage shows the Restart UI
                            if (response.data.message) {
                                response.data.message.is_end = true;
                            }
                            self.displayBotMessage(response.data.message);
                            self.endConversation();
                        } else {
                            // Pro: Loop node may carry a pre-jump message and/or delay.
                            // Show the loopback message (if any), wait the delay, then
                            // display the actual target node's message.
                            const loopMsg = response.data.loop_message || '';
                            const loopDelay = parseInt(response.data.loop_delay_ms) || 0;

                            self.currentNode = response.data.current_node;
                            self.saveSession();

                            const displayTarget = function() {
                                self.displayBotMessage(response.data.message);
                            };

                            if (loopMsg || loopDelay > 0) {
                                if (loopMsg) {
                                    self.displayBotMessage({
                                        type: 'message',
                                        text: loopMsg
                                    });
                                }
                                if (loopDelay > 0) {
                                    // Brief typing indicator during the wait so the
                                    // pause feels intentional, not broken.
                                    self.showTyping();
                                    setTimeout(function() {
                                        self.hideTyping();
                                        displayTarget();
                                    }, loopDelay);
                                } else {
                                    displayTarget();
                                }
                            } else {
                                displayTarget();
                            }
                        }
                    } else {
                        _error('❌ Failed to process response:', response.data);
                        self.displayBotMessage({
                            type: 'message',
                            text: 'Sorry, something went wrong. Please try again.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    self.hideTyping();
                    _error('❌ AJAX error:', error);
                    self.displayBotMessage({
                        type: 'message',
                        text: 'Connection error. Please try again.'
                    });
                }
            });
        },
        
        displayBotMessage: function(message, options) {
            _log('🤖 Bot message:', message);
            options = options || {};

            const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

            // Check if this is an action node FIRST (don't create bubble for actions)
            if (message.type === 'action') {
                // Handle action nodes WITHOUT creating a message bubble
                this.displayActionNode(message);
                return null; // Exit early - action handler creates its own messages
            }

            // Add to messages container (NOT for action nodes)
            const $messageHtml = $(`
                <div class="dialoga-message dialoga-bot-message">
                    <div class="dialoga-message-content">
                        <div class="dialoga-message-text">${this.formatBotText(message.text)}</div>
                        <div class="dialoga-message-time">${timestamp}</div>
                    </div>
                </div>
            `);

            $('#dialoga-messages').append($messageHtml);

            // Save to history
            this.conversationHistory.push({
                type: 'bot',
                message: message,
                timestamp: timestamp
            });

            // Handle end-of-flow, auto-continue, or show input
            if (message.is_end) {
                this.showEndState();
            } else if (message.auto_continue) {
                // Handle auto-continue — minimum 1.5s so visitor can read the message
                const delay = Math.max((message.delay || 0) * 1000, 1500);
                setTimeout(() => {
                    this.sendResponse('__auto_continue__');
                }, delay);
            } else {
                // Show appropriate input
                this.showInput(message);
            }

            // Scroll so the top of this new bubble is visible — unless the caller
            // is part of a batch (e.g. AI chat sending multiple bubbles) and wants
            // to manage the final scroll target itself.
            if (!options.suppressScroll) {
                this.scrollToNewMessage($messageHtml);
            }

            return $messageHtml;
        },
        
        displayUserMessage: function(text) {
            const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            const $messageHtml = $(`
                <div class="dialoga-message dialoga-user-message">
                    <div class="dialoga-message-content">
                        <div class="dialoga-message-text">${this.escapeHtml(text)}</div>
                        <div class="dialoga-message-time">${timestamp}</div>
                    </div>
                </div>
            `);
            
            $('#dialoga-messages').append($messageHtml);
            
            // Save to history
            this.conversationHistory.push({
                type: 'user',
                text: text,
                timestamp: timestamp
            });
            
            this.scrollToBottom();
        },
        
        displayActionNode: function(action) {
            const self = this;
            _log('🎬 Displaying action:', action.action_type);

            switch (action.action_type) {
                case 'capture_lead':
                    // v0.38.24 — emit an intro bubble before the form appears.
                    // Without this, the transcript goes silent after the button
                    // click that triggered the Capture Lead node (form fields
                    // don't leave transcript entries), so admins reviewing
                    // abandoned conversations can't tell why the bot "stopped
                    // replying." Text can be customized per-node via
                    // config.intro_message from the Flow Builder.
                    var introMsg = (action.config && action.config.intro_message)
                        ? action.config.intro_message
                        : "Great! Just need a few quick details to help you.";
                    this.displayBotMessage({ text: introMsg, type: 'bot' });
                    this.renderCaptureLeadForm(action.config);
                    // Persist immediately so a bouncing visitor still leaves a
                    // record showing the form was presented.
                    this.saveTranscript();
                    break;
                case 'redirect':
                    this.executeRedirect(action.config);
                    break;
                case 'link_button':
                    this.renderLinkButton(action.config || {}, action.node_id || '');
                    break;
                case 'ai_chat':
                    this.displayAIChat(action.config || {});
                    break;
                case 'survey':
                    this.renderSurveyForm(action.config || {});
                    break;
                case 'qualify':
                    this.renderQualifyForm(action.config || {});
                    break;
                case 'send_email':
                    _log('📧 Email action not yet implemented');
                    break;
                case 'set_variable':
                    _log('💾 Set variable action not yet implemented');
                    break;
                default:
                    _warn('⚠️ Unknown action type:', action.action_type);
            }
        },

        displayAIChat: function(config) {
            const self = this;
            const greeting = config.greeting || 'Hi! I\'m your AI assistant. How can I help you today?';
            const placeholder = config.placeholder || 'Type your message...';

            // Initialize per-session AI history
            if (!this.aiChatHistory) {
                this.aiChatHistory = [];
            }
            // Store config for later use in sendAIMessage
            this.aiChatConfig = config;

            // Show greeting as bot message (this adds it to conversationHistory)
            this.displayBotMessage({ text: greeting, type: 'bot' });

            // Persist now so the greeting + AI context survive page navigations
            // even if the visitor hasn't typed an AI message yet.
            this.saveSession();

            // Show text input for AI conversation
            this.showAIChatInput(placeholder);
        },

        renderSurveyForm: function(config) {
            const self = this;
            const questions = config.questions || [];
            const thankYou = config.thank_you_message || 'Thanks for your feedback!';

            if (questions.length === 0) {
                this.displayBotMessage({ text: 'Survey configuration error.', type: 'bot' });
                return;
            }

            // Show intro message
            if (config.intro_message) {
                this.displayBotMessage({ text: config.intro_message, type: 'bot' });
            }

            // Build survey form HTML
            let formHtml = '<div class="dialoga-survey-form">';
            questions.forEach(function(q, idx) {
                formHtml += '<div class="dialoga-survey-question" data-index="' + idx + '">';
                formHtml += '<label class="dialoga-survey-label">' + self.escapeHtml(q.label) + '</label>';

                switch (q.type) {
                    case 'rating':
                        var max = q.max || 5;
                        formHtml += '<div class="dialoga-survey-rating" data-field="' + (q.field || 'q' + idx) + '">';
                        for (var i = 1; i <= max; i++) {
                            formHtml += '<button type="button" class="dialoga-survey-star" data-value="' + i + '">' +
                                (max <= 5 ? '★' : i) + '</button>';
                        }
                        formHtml += '<input type="hidden" class="dialoga-survey-value" value="" /></div>';
                        break;

                    case 'nps':
                        formHtml += '<div class="dialoga-survey-nps" data-field="' + (q.field || 'q' + idx) + '">';
                        for (var n = 0; n <= 10; n++) {
                            formHtml += '<button type="button" class="dialoga-survey-nps-btn" data-value="' + n + '">' + n + '</button>';
                        }
                        formHtml += '<div class="dialoga-survey-nps-labels"><span>Not likely</span><span>Very likely</span></div>';
                        formHtml += '<input type="hidden" class="dialoga-survey-value" value="" /></div>';
                        break;

                    case 'choice':
                        formHtml += '<div class="dialoga-survey-choices" data-field="' + (q.field || 'q' + idx) + '">';
                        (q.options || []).forEach(function(opt) {
                            formHtml += '<button type="button" class="dialoga-survey-choice-btn" data-value="' + self.escapeHtml(opt) + '">' +
                                self.escapeHtml(opt) + '</button>';
                        });
                        formHtml += '<input type="hidden" class="dialoga-survey-value" value="" /></div>';
                        break;

                    case 'text':
                        formHtml += '<textarea class="dialoga-survey-text dialoga-survey-value" data-field="' + (q.field || 'q' + idx) + '" ' +
                            'placeholder="' + self.escapeHtml(q.placeholder || 'Your thoughts...') + '" rows="3"></textarea>';
                        break;

                    default:
                        formHtml += '<input type="text" class="dialoga-survey-text dialoga-survey-value" data-field="' + (q.field || 'q' + idx) + '" ' +
                            'placeholder="' + self.escapeHtml(q.placeholder || '') + '" />';
                }
                formHtml += '</div>';
            });
            formHtml += '<button type="button" class="dialoga-survey-submit dialoga-option-btn">Submit Feedback</button>';
            formHtml += '</div>';

            // Add to chat
            const $msg = $('<div class="dialoga-message dialoga-message-bot"></div>');
            $msg.html(formHtml);
            $('#dialoga-messages').append($msg);
            this.scrollToBottom();

            // Bind star/NPS/choice selection
            $msg.find('.dialoga-survey-star').on('click', function() {
                const $parent = $(this).closest('.dialoga-survey-rating');
                const val = $(this).data('value');
                $parent.find('.dialoga-survey-value').val(val);
                $parent.find('.dialoga-survey-star').removeClass('selected');
                $parent.find('.dialoga-survey-star').each(function() {
                    if (parseInt($(this).data('value')) <= val) $(this).addClass('selected');
                });
            });

            $msg.find('.dialoga-survey-nps-btn').on('click', function() {
                const $parent = $(this).closest('.dialoga-survey-nps');
                $parent.find('.dialoga-survey-value').val($(this).data('value'));
                $parent.find('.dialoga-survey-nps-btn').removeClass('selected');
                $(this).addClass('selected');
            });

            $msg.find('.dialoga-survey-choice-btn').on('click', function() {
                const $parent = $(this).closest('.dialoga-survey-choices');
                $parent.find('.dialoga-survey-value').val($(this).data('value'));
                $parent.find('.dialoga-survey-choice-btn').removeClass('selected');
                $(this).addClass('selected');
            });

            // Submit
            $msg.find('.dialoga-survey-submit').on('click', function() {
                var responses = {};
                $msg.find('.dialoga-survey-value').each(function() {
                    var field = $(this).data('field') || $(this).closest('[data-field]').data('field');
                    var val = $(this).val();
                    if (field && val) responses[field] = val;
                });

                $(this).text('Submitting...').prop('disabled', true);

                $.ajax({
                    url: dialogaConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'dialoga_submit_survey',
                        nonce: dialogaConfig.nonce,
                        session_id: self.sessionId || '',
                        session_token: self.sessionToken || '',
                        flow_id: self.flowId || 0,
                        responses: JSON.stringify(responses)
                    },
                    success: function(response) {
                        $msg.find('.dialoga-survey-form').html(
                            '<div style="text-align:center;padding:16px;"><p style="font-size:15px;color:#16A34A;font-weight:500;">' +
                            self.escapeHtml(thankYou) + '</p></div>'
                        );
                    }
                });
            });
        },

        renderQualifyForm: function(config) {
            const self = this;
            const questions = config.questions || [];
            const successMsg = config.success_message || 'Thanks! We\'ll be in touch shortly.';
            const collectContact = config.collect_contact !== false;
            const scoringRules = config.scoring_rules || {};
            var currentQ = 0;

            if (questions.length === 0) {
                this.displayBotMessage({ text: 'Qualification configuration error.', type: 'bot' });
                return;
            }

            // Ask questions one at a time as chat messages
            const askQuestion = function(idx) {
                if (idx >= questions.length) {
                    // All questions answered — collect contact info or submit
                    if (collectContact) {
                        self.renderCaptureLeadForm({
                            fields: {
                                name: { enabled: true, label: 'Your Name', required: true },
                                email: { enabled: true, label: 'Email', required: true },
                                phone: { enabled: true, label: 'Phone', required: false }
                            },
                            success_message: successMsg,
                            _qualify_responses: self._qualifyResponses,
                            _scoring_rules: scoringRules
                        });
                    } else {
                        // Submit without contact info
                        $.ajax({
                            url: dialogaConfig.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'dialoga_submit_qualification',
                                nonce: dialogaConfig.nonce,
                                session_id: self.sessionId || '',
                                flow_id: self.flowId || 0,
                                responses: JSON.stringify(self._qualifyResponses || {}),
                                lead_data: '{}',
                                scoring_rules: JSON.stringify(scoringRules)
                            },
                            success: function() {
                                self.displayBotMessage({ text: successMsg, type: 'bot' });
                            }
                        });
                    }
                    return;
                }

                var q = questions[idx];
                self.displayBotMessage({ text: q.label, type: 'bot' });

                // Show options as buttons
                setTimeout(function() {
                    var $optionsDiv = $('<div class="dialoga-options"></div>');
                    (q.options || []).forEach(function(opt) {
                        var $btn = $('<button class="dialoga-option-btn"></button>').text(opt.text);
                        $btn.on('click', function() {
                            if (!self._qualifyResponses) self._qualifyResponses = {};
                            self._qualifyResponses[q.field] = opt.text;
                            self.displayUserMessage(opt.text);
                            $optionsDiv.remove();
                            askQuestion(idx + 1);
                        });
                        $optionsDiv.append($btn);
                    });
                    $('#dialoga-messages').append($optionsDiv);
                    self.scrollToBottom();
                }, 500);
            };

            askQuestion(0);
        },

        showAIChatInput: function(placeholder) {
            const self = this;
            placeholder = placeholder || (this.aiChatConfig && this.aiChatConfig.placeholder) || 'Type your message...';
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            const $wrap = $(`
                <div class="dialoga-text-input-container">
                    <input type="text"
                           class="dialoga-text-input"
                           placeholder="${self.escapeHtml(placeholder)}"
                           id="dialoga-ai-input" />
                    <button class="dialoga-send-btn" id="dialoga-ai-send">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M2 10L18 2L10 18L8 11L2 10Z" fill="currentColor"/>
                        </svg>
                    </button>
                </div>
            `);

            $inputArea.append($wrap);

            // Connect with our team button — shows an inline lead capture form
            const $connectBtn = $('<button class="dialoga-connect-btn">Connect with our team →</button>');
            $connectBtn.on('click', function() {
                self.showInlineLeadForm();
            });
            $inputArea.append($connectBtn);

            const sendMessage = function() {
                const text = $('#dialoga-ai-input').val().trim();
                if (!text) return;
                $('#dialoga-ai-input').val('');
                $inputArea.empty();
                self.sendAIMessage(text);
            };

            $('#dialoga-ai-send').on('click', sendMessage);
            $('#dialoga-ai-input').on('keypress', function(e) {
                if (e.which === 13) sendMessage();
            });

            setTimeout(function() { $('#dialoga-ai-input').focus(); }, 100);
        },

        showInlineLeadForm: function() {
            const self = this;
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            this.displayBotMessage({
                type: 'bot',
                text: "I'd love to connect you with our team! Please leave your details below and someone will reach out to you personally."
            });

            const config = {
                fields: {
                    name:  { enabled: true, label: 'Your Name',     required: true },
                    email: { enabled: true, label: 'Email Address', required: true },
                    phone: { enabled: true, label: 'Phone Number',  required: false }
                },
                success_message: "Thanks! A member of our team will be in touch with you shortly.",
                // Marks this as the limit-hit fallback from an AI Chat node, so after
                // lead submit we walk past the AI node instead of ending the chat —
                // lets downstream Message/Question nodes render and webhook chain fires.
                _postAILimitFlow: true
            };

            this.renderCaptureLeadForm(config);
        },

        sendAIMessage: function(text) {
            const self = this;
            _log('🤖 Sending AI message:', text);

            // Show user message
            this.displayUserMessage(text);

            // Add to AI history
            if (!this.aiChatHistory) this.aiChatHistory = [];
            this.aiChatHistory.push({ role: 'user', content: text });

            // Persist so cross-page navigation keeps the AI conversation intact
            this.saveSession();

            // Show typing indicator
            this.showTyping();

            // Include lead_draft state + node context so the server can extract and save
            // leads conversationally when the AI Chat node has auto_extract_lead enabled
            if (!this.aiLeadDraft) this.aiLeadDraft = {};

            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                // v0.38.24 — 25s timeout so slow AI providers don't leave the visitor
                // staring at a typing indicator forever. If the AJAX exceeds this,
                // the error handler renders a friendly retry message.
                timeout: 25000,
                data: {
                    action: 'dialoga_ai_chat',
                    nonce: dialogaConfig.nonce,
                    session_id: this.sessionId || '',
                    session_token: this.sessionToken || '',
                    message: text,
                    history: JSON.stringify(this.aiChatHistory.slice(-10)),
                    lead_draft: JSON.stringify(this.aiLeadDraft || {}),
                    node_id: this.currentNode || '',
                    flow_id: this.flowId || 0,
                    _h: '' // honeypot — must stay empty; bots often fill all fields
                },
                success: function(response) {
                    self.hideTyping();
                    if (response.success && response.data.reply) {
                        const reply = response.data.reply;
                        self.aiChatHistory.push({ role: 'assistant', content: reply });

                        // Sync lead_draft back from the server (may include new extracted fields)
                        if (response.data.lead_draft) {
                            self.aiLeadDraft = response.data.lead_draft;
                        }

                        // If the server confirmed the lead was saved, mark it and end AI chat
                        if (response.data.lead_captured) {
                            self.leadCaptured = true;
                            self.aiLeadDraft = {};
                            // v0.39.0: fire conversion tracking for AI-extracted leads,
                            // same payload shape as the form-capture path.
                            if (response.data.tracking) {
                                self.fireConversionEvent(response.data.tracking);
                            }
                        }

                        self.saveSession();
                        // Capture the first bubble so we can scroll to its top
                        // after any follow-up helper bubbles are appended.
                        const $firstBubble = self.displayBotMessage(
                            { text: reply, type: 'bot' },
                            { suppressScroll: true }
                        );

                        // Show limit warning when 1 message remains
                        if (response.data.remaining === 1) {
                            self.displayBotMessage({
                                type: 'bot',
                                text: "I can answer one more question. After that, connect with our team directly for more help!"
                            }, { suppressScroll: true });
                        }

                        if (response.data.lead_captured) {
                            // Lead saved via AI extraction — advance past the AI Chat node
                            // so any downstream nodes (Message confirmation, more flow steps) render.
                            // The webhook walker has already fired any chained Webhook nodes server-side.
                            self.advancePastAIChat();
                        } else if (response.data.remaining > 0) {
                            self.showAIChatInput();
                        } else {
                            // Limit reached — nudge to lead form
                            self.displayBotMessage({
                                type: 'bot',
                                text: "I've reached my limit for this session. Our team can answer anything else — connect with them below!"
                            }, { suppressScroll: true });
                            self.showInlineLeadForm();
                        }

                        // After all bubbles + input are in the DOM, pin the top
                        // of the first AI bubble at the top of the viewport so
                        // the visitor sees the beginning of the answer.
                        self.scrollToNewMessage($firstBubble);

                        // v0.38.24 — persist to DB immediately so a visitor who
                        // bounces before the next auto-save (page unload / chat
                        // close) doesn't leave the transcript missing its bot
                        // response. Fixes "user asked a question, bot never
                        // responded" phantom transcripts.
                        self.saveTranscript();
                    } else {
                        // Check if it's a rate/message limit error
                        if (response.data && response.data.limit) {
                            self.displayBotMessage({
                                type: 'bot',
                                text: "I've reached my limit for this session. Let me connect you with our team!"
                            });
                            self.showInlineLeadForm();
                        } else {
                            const errMsg = (response.data && typeof response.data === 'string')
                                ? response.data
                                : 'Sorry, I had trouble responding. Please try again.';
                            self.displayBotMessage({ text: errMsg, type: 'bot' });
                            self.showAIChatInput();
                        }
                        self.saveTranscript();
                    }
                },
                error: function(xhr, status) {
                    self.hideTyping();
                    // v0.38.24 — distinguish timeout from generic network error
                    var msg = (status === 'timeout')
                        ? "Sorry, that took too long to respond. Please try again — sometimes the AI service gets slow."
                        : 'Sorry, something went wrong. Please try again.';
                    self.displayBotMessage({ text: msg, type: 'bot' });
                    self.showAIChatInput();
                    self.saveTranscript();
                }
            });
        },

        // v0.39.0: Fire the conversion-tracking event for a captured lead.
        //
        // Two-fold delivery so every downstream analytics stack can pick it up:
        //   1. window.dataLayer.push() — Google Tag Manager listens for the
        //      `dialoga_lead_captured` event and fires whatever tags the site
        //      admin has configured (GA4, Google Ads conversion, Meta Pixel, etc.).
        //   2. `dialoga:lead-captured` CustomEvent — Dialoga Pro (or any custom
        //      integration) hooks this to fire gtag()/fbq() calls directly when
        //      the site doesn't use GTM.
        //
        // Payload always includes: event, dialoga_lead_id, dialoga_flow_id,
        // dialoga_flow_name, dialoga_source_node, value, currency.
        fireConversionEvent: function(payload) {
            if (!payload || typeof payload !== 'object') return;
            try {
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push(payload);
            } catch (e) { _error && _error('dataLayer.push failed:', e); }
            try {
                var evt = new CustomEvent('dialoga:lead-captured', {
                    detail: payload,
                    bubbles: true
                });
                document.dispatchEvent(evt);
            } catch (e) { _error && _error('dialoga:lead-captured dispatch failed:', e); }
            _log && _log('📊 Conversion event fired:', payload);
        },

        // Advance the flow one step past the current AI Chat node.
        // Called after the AI successfully extracts + confirms + saves a lead.
        // Server-side webhook walker already fired any downstream Webhook nodes;
        // this ensures visitor sees the next Message/Question/etc. in the flow.
        advancePastAIChat: function() {
            const self = this;
            this.showTyping();

            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialoga_send_response',
                    nonce: dialogaConfig.nonce,
                    session_id: this.sessionId || '',
                    current_node: this.currentNode || '',
                    response: '__ai_lead_captured__',
                    variables: JSON.stringify(this.variables || {})
                },
                success: function(response) {
                    self.hideTyping();
                    if (response.success && response.data) {
                        if (response.data.current_node) self.currentNode = response.data.current_node;
                        if (response.data.message) {
                            self.displayBotMessage(response.data.message);
                        }
                        self.saveSession();
                    } else {
                        // Flow ended naturally
                        self.endConversation();
                    }
                },
                error: function() {
                    self.hideTyping();
                    self.endConversation();
                }
            });
        },

        renderCaptureLeadForm: function(config) {
            const self = this;
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();
            
            const fields = config.fields || {};
            
            // Build form HTML
            let formHtml = '<div class="dialoga-lead-form">';
            
            // Add each enabled field
            Object.keys(fields).forEach(function(fieldKey) {
                const field = fields[fieldKey];
                if (!field.enabled) return;
                
                const isRequired = field.required ? 'required' : '';
                const requiredMark = field.required ? '<span class="dialoga-required">*</span>' : '';
                
                // Determine input type
                let inputType = 'text';
                if (fieldKey === 'email') inputType = 'email';
                if (fieldKey === 'phone') inputType = 'tel';
                
                formHtml += `
                    <div class="dialoga-form-field">
                        <label for="dialoga-lead-${fieldKey}">
                            ${self.escapeHtml(field.label)}${requiredMark}
                        </label>
                        <input 
                            type="${inputType}" 
                            id="dialoga-lead-${fieldKey}"
                            name="${fieldKey}"
                            class="dialoga-form-input"
                            ${isRequired}
                            placeholder="${self.escapeHtml(field.label)}"
                        />
                    </div>
                `;
            });
            
            // Add submit button
            formHtml += `
                <button type="button" class="dialoga-submit-lead-btn">
                    Submit
                </button>
            </div>`;
            
            $inputArea.append(formHtml);
            
            // Bind submit handler
            $('.dialoga-submit-lead-btn').on('click', function() {
                self.submitLead(config);
            });
            
            // Also submit on Enter key in form
            $('.dialoga-form-input').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.submitLead(config);
                }
            });
            
            this.scrollToBottom();
        },
        
        submitLead: function(config) {
            const self = this;
            
            // Collect form data
            const leadData = {};
            let hasErrors = false;
            
            $('.dialoga-form-input').each(function() {
                const $input = $(this);
                const fieldName = $input.attr('name');
                const value = $input.val().trim();
                const isRequired = $input.prop('required');
                
                // Clear previous errors
                $input.removeClass('dialoga-input-error');
                
                // Validate required fields
                if (isRequired && !value) {
                    $input.addClass('dialoga-input-error');
                    hasErrors = true;
                } else {
                    leadData[fieldName] = value;
                }
            });
            
            if (hasErrors) {
                this.displayBotMessage({
                    type: 'message',
                    text: 'Please fill in all required fields.'
                });
                return;
            }
            
            // Disable submit button
            const $submitBtn = $('.dialoga-submit-lead-btn');
            $submitBtn.prop('disabled', true).text('Submitting...');
            
            _log('📤 Submitting lead:', leadData);
            
            // Submit via AJAX
            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialoga_submit_lead',
                    nonce: dialogaConfig.nonce,
                    session_id: this.sessionId,
                    session_token: this.sessionToken || '',
                    flow_id: this.flowId,
                    lead_data: leadData,
                    pending_tags: JSON.stringify(this.pendingTags),
                    history: JSON.stringify(this.conversationHistory),
                    node_id: this.currentNode || '',
                    // v0.39.3: pass the success message so the server-composed email
                    // transcript can include the "Thanks!" bubble the visitor sees.
                    // (Email is sent inside this AJAX; the client-side "Thanks!" bubble
                    // renders AFTER this response, so without this the email cuts off.)
                    success_message: (config && config.success_message) ? config.success_message : "Thanks! We'll be in touch soon."
                },
                success: function(response) {
                    _log('✅ Lead submitted:', response);
                    
                    if (response.success) {
                        self.leadCaptured = true;
                        self.saveSession();

                        // v0.39.0: fire conversion tracking (GTM dataLayer + DOM event
                        // for Pro's direct-fire module). Server built the payload.
                        if (response.data && response.data.tracking) {
                            self.fireConversionEvent(response.data.tracking);
                        }

                        // Clear the form
                        $('#dialoga-input-area').empty();

                        // v0.39.2: render a compact user-side bubble representing the
                        // form submission so the transcript reads coherently instead of
                        // appearing to skip from the intro bubble straight to the success
                        // message. Actual lead data (name/email/phone) still lives in the
                        // Leads table — no PII duplication needed here.
                        self.displayUserMessage('✓ Contact details submitted');

                        // Show success message. If no redirect is configured, this is the
                        // end of the flow — tag the message so the widget renders the
                        // Restart Chat button instead of the broken text-input fallback.
                        const hasRedirect = !!config.redirect_url;
                        const successMessage = config.success_message || "Thanks! We'll be in touch soon.";
                        self.displayBotMessage({
                            type: 'message',
                            text: successMessage,
                            is_end: !hasRedirect
                        });

                        // v0.39.2: belt-and-suspenders — the server's ajax_submit_lead
                        // already wrote the conversation with HISTORY-up-to-intro-bubble,
                        // and showEndState() below will fire saveTranscript() for is_end
                        // messages. This explicit call guarantees the final "Thanks!" +
                        // "Contact details submitted" bubbles are persisted even in the
                        // redirect / _postAILimitFlow branches that skip showEndState.
                        self.saveTranscript();

                        if (!hasRedirect) {
                            // AI Chat limit fallback: continue the flow past the AI node
                            // so downstream Message/Question nodes render. Regular capture
                            // lead nodes still end the flow here as before.
                            if (config._postAILimitFlow) {
                                self.advancePastAIChat();
                            } else {
                                self.endConversation();
                            }
                        }

                        // Handle optional redirect
                        if (hasRedirect) {
                            setTimeout(function() {
                                const target = config.redirect_target || '_self';
                                if (target === '_blank') {
                                    window.open(config.redirect_url, '_blank');
                                } else {
                                    window.location.href = config.redirect_url;
                                }
                            }, 2000);
                        }
                    } else {
                        $submitBtn.prop('disabled', false).text('Submit');
                        self.displayBotMessage({
                            type: 'message',
                            text: 'Sorry, there was an error. Please try again.'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    _error('❌ Lead submission error:', error);
                    $submitBtn.prop('disabled', false).text('Submit');
                    self.displayBotMessage({
                        type: 'message',
                        text: 'Connection error. Please try again.'
                    });
                }
            });
        },
        
        executeRedirect: function(config) {
            const self = this;
            const url = config.url;
            const message = config.message || '';
            // Enforce a minimum 2-second countdown so the visitor sees what's happening.
            const configuredDelay = parseInt(config.delay);
            const delay = isNaN(configuredDelay) || configuredDelay < 2 ? 2 : configuredDelay;
            const target = config.target || '_self';

            _log('🔀 Executing redirect:', url, 'in', delay, 'seconds');

            // Always display a bot message before redirecting so the visitor has
            // context for the pending navigation. Use the configured message, or
            // a friendly default if the flow designer left it blank.
            const displayMessage = message || 'Taking you there now...';
            const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            const $messageHtml = $(
                '<div class="dialoga-message dialoga-bot-message">' +
                    '<div class="dialoga-message-content">' +
                        '<div class="dialoga-message-text">' + this.escapeHtml(displayMessage) + '</div>' +
                        '<div class="dialoga-message-time">' + timestamp + '</div>' +
                    '</div>' +
                '</div>'
            );
            $('#dialoga-messages').append($messageHtml);
            this.scrollToBottom();

            // Always show a countdown so the visitor knows what's happening.
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            let countdown = delay;
            const $countdown = $(
                '<div class="dialoga-redirect-countdown">' +
                    '<p class="dialoga-countdown">Redirecting in <span>' + countdown + '</span>s...</p>' +
                '</div>'
            );
            $inputArea.append($countdown);

            const timer = setInterval(function() {
                countdown--;
                $countdown.find('.dialoga-countdown span').text(countdown);

                if (countdown <= 0) {
                    clearInterval(timer);
                    self.performRedirect(url, target);
                }
            }, 1000);

            this.scrollToBottom();
        },
        
        performRedirect: function(url, target) {
            _log('🚀 Redirecting to:', url, 'target:', target);

            if (target === '_blank') {
                window.open(url, '_blank');
            } else {
                // For _self redirects, add parameter to keep widget open
                const separator = url.includes('?') ? '&' : '?';
                const newUrl = url + separator + 'dialoga_open=1';
                window.location.href = newUrl;
            }
        },

        /**
         * v0.39.4 — Link Button action.
         *
         * Shows a bot bubble with intro copy, followed by a prominent CTA button
         * that opens the configured URL when clicked. Distinct from Redirect (which
         * auto-navigates); here the visitor chooses whether to click.
         *
         * On click: fires `link_clicked` analytics event, opens URL in configured
         * target, ends flow silently (visitor sees Restart Chat button).
         *
         * Config shape (from admin config editor):
         *   { message, button_label, button_url, target ('_blank'|'_self') }
         */
        renderLinkButton: function(config, nodeId) {
            const self = this;
            const message     = (config && config.message)      ? config.message      : 'Click below to continue.';
            const buttonLabel = (config && config.button_label) ? config.button_label : 'Open Link';
            const buttonUrl   = (config && config.button_url)   ? config.button_url   : '';
            const target      = (config && config.target)       ? config.target       : '_blank';

            if (!buttonUrl) {
                _warn('⚠️ Link Button node has no URL — rendering intro only, ending flow.');
                this.displayBotMessage({ text: message, type: 'bot', is_end: true });
                return;
            }

            // 1. Show the intro bubble (goes into conversationHistory naturally)
            this.displayBotMessage({ text: message, type: 'bot' });

            // 2. Render the button. Live in the input area so it visually mimics a CTA,
            //    matching how question-node buttons appear (bottom of the chat).
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            const $button = $(
                '<a class="dialoga-link-button" href="' + buttonUrl + '"' +
                    (target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' +
                    this.escapeHtml(buttonLabel) +
                    ' <span class="dialoga-link-button__arrow">→</span>' +
                '</a>'
            );

            const isConversion = !!(config && config.is_conversion);

            $button.on('click', function(e) {
                // Fire analytics before navigation (sendBeacon-style — best effort even
                // when a new-tab click happens quickly; for _self, the navigation itself
                // may cancel the AJAX so ideally the server sees this before nav)
                self.trackLinkClick(nodeId, buttonUrl, buttonLabel);

                // v0.40.0 — if the admin marked this button as a conversion, fire the
                // dataLayer + DOM event so GTM / GA4 / Pro tracking all see it. Same
                // envelope shape as `dialoga_lead_captured` so downstream tags can reuse
                // their existing variables; the `dialoga_conversion_type` field
                // distinguishes link clicks from form-submitted leads.
                //
                // Note: this real-time dataLayer push only fires for clicks where the
                // node WAS marked as a conversion at the time of click. If an admin
                // enables the checkbox later, past clicks retroactively count in the
                // Dialoga dashboard (server-side lookup), but GA4/GTM never saw those
                // events fire so downstream platforms won't count them.
                if (isConversion) {
                    self.fireLinkConversionEvent(nodeId, buttonUrl, buttonLabel);
                }

                // Also log the click as a user-side message in the transcript so admin
                // can see "visitor clicked the button" in the conversation.
                self.displayUserMessage('🔗 Clicked: ' + buttonLabel);

                // Persist the updated transcript. For _blank this happens naturally;
                // for _self we still get one shot before the page unloads.
                self.saveTranscript();

                // v0.39.8: attempt to advance the flow after the click. If the flow
                // designer wired an outgoing edge from this Link Button node (e.g.,
                // to a Message node asking "Want to do anything else?"), the server
                // returns the next node and the widget renders it. If no outgoing
                // edge exists, the server returns end state and showEndState fires
                // naturally via sendResponse's handler. Sending a sentinel string
                // that server-side flow_engine treats as an auto-advance without
                // displaying the payload as a user message (we already displayed
                // "🔗 Clicked: <label>" above).
                setTimeout(function() {
                    self.sendResponse('__link_clicked__');
                }, 400);

                // Native anchor click handles the navigation — no e.preventDefault().
            });

            $inputArea.append($button);
            this.scrollToBottom();

            // Persist the transcript now so the intro bubble + link-shown state are
            // recorded even if the visitor closes the tab before clicking.
            this.saveTranscript();
        },

        /**
         * v0.39.4 — Fire the `link_clicked` analytics event.
         * Called when a visitor clicks a Link Button. Uses conversation_id (looked up
         * server-side via session_id inside ajax_track_event's counterpart is not
         * needed — the widget carries conversationId once conversation started).
         *
         * v0.40.0 — is_conversion is intentionally NOT passed. Conversion status
         * is resolved server-side at metric-query time from the current flow
         * config, so toggling the "Count as conversion" checkbox retroactively
         * includes/excludes past clicks on that node.
         */
        trackLinkClick: function(nodeId, url, label) {
            if (!this.conversationId) return;
            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action:          'dialoga_track_event',
                    nonce:           dialogaConfig.nonce,
                    conversation_id: this.conversationId,
                    event_type:      'link_clicked',
                    flow_id:         this.flowId || 0,
                    node_id:         nodeId || (this.currentNode || ''),
                    url:             url,
                    label:           label
                }
            });
        },

        /**
         * v0.40.0 — Fire the `dialoga_conversion` event for a Link Button click
         * that the admin has marked as "Count as conversion".
         *
         * Two-fold delivery mirrors `fireConversionEvent` for lead captures:
         *   1. window.dataLayer.push() with a NEW event name so GTM tags built
         *      against `dialoga_lead_captured` don't silently double-count when
         *      a flow has both a Capture Lead node AND a marked Link Button.
         *   2. `dialoga:conversion` CustomEvent for Pro (and any custom listener)
         *      to fire gtag()/fbq() directly.
         *
         * Payload includes `dialoga_conversion_type: 'link_click'` so downstream
         * consumers can distinguish source; lead-capture events get their own
         * `dialoga_conversion` push with type 'lead_capture' via fireConversionEvent.
         */
        fireLinkConversionEvent: function(nodeId, url, label) {
            const payload = {
                event: 'dialoga_conversion',
                dialoga_conversion_type: 'link_click',
                dialoga_flow_id: this.flowId || 0,
                dialoga_flow_name: (dialogaConfig && dialogaConfig.flowName) || '',
                dialoga_source_node: nodeId || '',
                dialoga_link_url: url || '',
                dialoga_link_label: label || '',
                value: 0,
                currency: 'USD'
            };
            try {
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push(payload);
            } catch (e) { _error && _error('dataLayer.push failed:', e); }
            try {
                var evt = new CustomEvent('dialoga:conversion', {
                    detail: payload,
                    bubbles: true
                });
                document.dispatchEvent(evt);
            } catch (e) { _error && _error('dialoga:conversion dispatch failed:', e); }
            _log && _log('📊 Link conversion event fired:', payload);
        },

        showInput: function(message) {
            const self = this;
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            // Multi-select mode: render checkboxes + Continue button
            if (message.allow_multiple && message.options && message.options.length > 0) {
                const $multiContainer = $('<div class="dialoga-multi-select"></div>');

                message.options.forEach(function(option) {
                    const label = self.escapeHtml(option.label);
                    const $row = $(
                        '<label class="dialoga-multi-option">' +
                            '<input type="checkbox" class="dialoga-multi-checkbox" value="' + label + '" />' +
                            '<span>' + label + '</span>' +
                        '</label>'
                    );
                    $multiContainer.append($row);
                });

                const $continueBtn = $('<button class="dialoga-button dialoga-multi-continue" type="button" disabled>Continue</button>');

                $multiContainer.append($continueBtn);
                $inputArea.append($multiContainer);

                // Enable Continue only when at least one box is checked
                $multiContainer.on('change', '.dialoga-multi-checkbox', function() {
                    const anyChecked = $multiContainer.find('.dialoga-multi-checkbox:checked').length > 0;
                    $continueBtn.prop('disabled', !anyChecked);
                });

                $continueBtn.on('click', function() {
                    const selected = $multiContainer
                        .find('.dialoga-multi-checkbox:checked')
                        .map(function() { return $(this).val(); })
                        .get();
                    if (!selected.length) return;
                    const joined = selected.join(', ');
                    self.sendResponse(joined);
                    $inputArea.empty();
                });

                return; // Skip the single-click button + text-input fallback
            }

            // v0.42.1 — Single-select option pills now render inline in the
            // conversation area (right below the bot's question message)
            // instead of the fixed input area at the bottom. Matches the
            // CAASI/modern chatbot UX where the choices feel like part of
            // the chat, not a detached toolbar. Any prior pending choice
            // set is removed first so old button rows don't accumulate if
            // the flow re-prompts.
            if (message.options && message.options.length > 0) {
                const $messages = $('#dialoga-messages');
                $messages.find('.dialoga-inline-choices').remove();

                const $buttonsContainer = $('<div class="dialoga-buttons dialoga-inline-choices"></div>');

                message.options.forEach(function(option) {
                    const $button = $(`
                        <button class="dialoga-button" data-value="${self.escapeHtml(option.label)}">
                            ${self.escapeHtml(option.label)}
                        </button>
                    `);

                    $button.on('click', function() {
                        const value = $(this).data('value');
                        // Freeze the pill row so the transcript preserves
                        // what the visitor picked, but disable further
                        // clicks. `data-selected` styles it as chosen.
                        $buttonsContainer.find('.dialoga-button')
                            .prop('disabled', true)
                            .css('cursor', 'default');
                        $(this).attr('data-selected', '1');
                        self.sendResponse(value);
                    });

                    $buttonsContainer.append($button);
                });

                $messages.append($buttonsContainer);
                $inputArea.empty(); // Clear any stale content in the input row
                self.scrollToBottom();
            }
            
            // Legacy flows may still carry allow_text on a Question node. New flows
            // can't enable it — the AI Chat node handles free-form input. Render
            // the text input only for those legacy cases so existing flows keep
            // working until their designers re-save the node.
            if (message.allow_text) {
                const placeholder = message.placeholder || 'Type your message...';

                const $textInput = $(
                    '<div class="dialoga-text-input-container">' +
                        '<input type="text" class="dialoga-text-input" placeholder="' + placeholder + '" id="dialoga-user-input" />' +
                        '<button class="dialoga-send-btn" id="dialoga-send" type="button">' +
                            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none">' +
                                '<path d="M2 10L18 2L10 18L8 11L2 10Z" fill="currentColor"/>' +
                            '</svg>' +
                        '</button>' +
                    '</div>'
                );

                $inputArea.append($textInput);

                $('#dialoga-send').on('click', function() {
                    const text = $('#dialoga-user-input').val().trim();
                    if (text) {
                        self.sendResponse(text);
                        $('#dialoga-user-input').val('');
                        $inputArea.empty();
                    }
                });

                $('#dialoga-user-input').on('keypress', function(e) {
                    if (e.which === 13) {
                        $('#dialoga-send').click();
                    }
                });

                setTimeout(function() { $('#dialoga-user-input').focus(); }, 100);
                return;
            }

            // Well-formed question but with zero options configured — the flow
            // designer made a mistake. Render a friendly end-state with Restart
            // rather than leaving the widget frozen.
            if (!message.options || message.options.length === 0) {
                _warn('⚠️ Question node has no options and is not an AI Chat. Showing Restart fallback.');
                self.showEndState();
            }
        },
        
        showTyping: function() {
            const $typing = $(`
                <div class="dialoga-typing" id="dialoga-typing">
                    <span></span><span></span><span></span>
                </div>
            `);
            $('#dialoga-messages').append($typing);
            this.scrollToBottom();
        },
        
        hideTyping: function() {
            $('#dialoga-typing').remove();
        },
        
        scrollToBottom: function() {
            const $messages = $('#dialoga-messages');
            $messages.scrollTop($messages[0].scrollHeight);
        },

        /**
         * Scroll to pin the top of a newly-added bot message at the top of the
         * chat viewport. This guarantees the visitor sees the start of the
         * response regardless of how long it is or how many follow-up bubbles
         * arrive after it (e.g. AI limit-warnings).
         */
        scrollToNewMessage: function($newMessage) {
            const $messages = $('#dialoga-messages');
            if (!$messages.length || !$newMessage || !$newMessage.length) {
                this.scrollToBottom();
                return;
            }
            const container = $messages[0];
            const el = $newMessage[0];
            // Give layout a tick to settle before measuring
            setTimeout(function() {
                container.scrollTop = Math.max(0, el.offsetTop - 8);
            }, 0);
        },

        // Darken a hex color by a given percentage (for hover states)
        darkenColor: function(hex, percent) {
            const num = parseInt(hex.replace('#', ''), 16);
            const amt = Math.round(2.55 * percent);
            const r = Math.max((num >> 16) - amt, 0);
            const g = Math.max((num >> 8 & 0x00FF) - amt, 0);
            const b = Math.max((num & 0x0000FF) - amt, 0);
            return '#' + (0x1000000 + r * 0x10000 + g * 0x100 + b).toString(16).slice(1);
        },

        contrastColor: function(hex) {
            const value = hex.replace('#', '');
            if (!/^[0-9a-f]{6}$/i.test(value)) {
                return '#FFFFFF';
            }
            const relativeLuminance = function(color) {
                const channels = [0, 2, 4].map(function(offset) {
                    const channel = parseInt(color.slice(offset, offset + 2), 16) / 255;
                    return channel <= 0.03928
                        ? channel / 12.92
                        : Math.pow((channel + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
            };
            const background = relativeLuminance(value);
            const dark = relativeLuminance('26233A');
            const whiteContrast = 1.05 / (background + 0.05);
            const darkContrast = (background + 0.05) / (dark + 0.05);
            return darkContrast >= whiteContrast ? '#26233A' : '#FFFFFF';
        },
        
        saveSession: function() {
            const session = {
                sessionId:      this.sessionId,
                sessionToken:   this.sessionToken || null,
                conversationId: this.conversationId,
                flowId:         this.flowId,
                currentNode:    this.currentNode,
                history:        this.conversationHistory,
                leadCaptured:   this.leadCaptured,
                pendingTags:    this.pendingTags,
                variables:      this.variables,
                aiChatHistory:  this.aiChatHistory || [],
                aiChatConfig:   this.aiChatConfig || null,
                aiLeadDraft:    this.aiLeadDraft || {}
            };
            localStorage.setItem('dialoga_session', JSON.stringify(session));
            _log('💾 Session saved');
        },

        loadSession: function() {
            const saved = localStorage.getItem('dialoga_session');
            if (saved) {
                const session = JSON.parse(saved);
                this.sessionId      = session.sessionId;
                this.sessionToken   = session.sessionToken || null;
                this.conversationId = session.conversationId || 0;
                this.flowId         = session.flowId;
                this.currentNode    = session.currentNode;
                this.conversationHistory = session.history || [];
                this.aiChatHistory  = session.aiChatHistory || [];
                this.aiChatConfig   = session.aiChatConfig || null;
                this.aiLeadDraft    = session.aiLeadDraft || {};
                this.leadCaptured   = session.leadCaptured || false;
                this.pendingTags    = session.pendingTags || [];
                this.variables      = session.variables || {};
                _log('📥 Session loaded:', this.sessionId);
            }
        },
        
        restoreConversation: function(isAfterRedirect) {
            const self = this;
            _log('📚 Restoring', this.conversationHistory.length, 'messages');
            
            // CRITICAL: Clear messages first to avoid duplicates (v0.29.3)
            $('#dialoga-messages').empty();
            
            // Restore messages without adding to history again
            this.conversationHistory.forEach(function(item) {
                const timestamp = item.timestamp || new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
                
                if (item.type === 'bot') {
                    const $messageHtml = $(`
                        <div class="dialoga-message dialoga-bot-message">
                            <div class="dialoga-message-content">
                                <div class="dialoga-message-text">${self.formatBotText(item.message.text)}</div>
                                <div class="dialoga-message-time">${timestamp}</div>
                            </div>
                        </div>
                    `);
                    $('#dialoga-messages').append($messageHtml);
                } else {
                    const $messageHtml = $(`
                        <div class="dialoga-message dialoga-user-message">
                            <div class="dialoga-message-content">
                                <div class="dialoga-message-text">${self.escapeHtml(item.text)}</div>
                                <div class="dialoga-message-time">${timestamp}</div>
                            </div>
                        </div>
                    `);
                    $('#dialoga-messages').append($messageHtml);
                }
            });
            
            self.scrollToBottom();

            // ONLY continue flow if this is after a redirect (v0.29.4 fix)
            if (isAfterRedirect) {
                _log('🔗 After redirect - continuing flow');
                self.continueFlowAfterRedirect();
            } else {
                _log('📖 Normal restore - not continuing flow');

                // If the visitor was in AI Chat when they navigated pages (even
                // if they hadn't typed anything yet after the greeting), bring
                // back the AI input so they can start/continue typing.
                if (self.aiChatConfig) {
                    _log('🤖 Restoring AI Chat input for continued conversation');
                    self.showAIChatInput(self.aiChatConfig.placeholder);
                    return;
                }

                // v0.38.7 fix — guarantee the visitor always has a way forward
                // after a restore. Without this, sessions resumed mid-flow show
                // the message history with no input area, no buttons, no escape
                // hatch, and the visitor is stuck staring at a dead widget.
                if (self.currentNode) {
                    _log('⏯️ Restore with currentNode set — rendering Continue + Restart fallback');
                    self.showResumeFallback();
                } else {
                    _log('🏁 Restore with no currentNode — flow already ended, showing Restart');
                    self.showEndState();
                }
            }
        },

        // Fallback UI shown after a session restore when we don't know what input
        // the current node expected. Gives the visitor two clear options:
        // continue from where they left off, or start over.
        showResumeFallback: function() {
            const self = this;
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            const $continueBtn = $(
                '<button class="dialoga-button" type="button" style="margin-bottom:8px;">' +
                    'Continue from where you left off' +
                '</button>'
            );
            const $restartBtn = $(
                '<button class="dialoga-restart-btn" type="button">' +
                    '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" style="margin-right:6px;vertical-align:middle;">' +
                        '<path d="M10 3v2a5 5 0 1 1-4.95 4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>' +
                        '<path d="M10 1l3 2-3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
                    '</svg>' +
                    'Restart Chat' +
                '</button>'
            );

            $continueBtn.on('click', function() {
                self.continueFlowAfterRedirect();
            });
            $restartBtn.on('click', function() {
                self.restartChat();
            });

            $inputArea.append($continueBtn).append($restartBtn);
        },
        
        continueFlowAfterRedirect: function() {
            const self = this;

            // If the flow already ended before the redirect (no currentNode), we still
            // need to give the visitor a way to start over — render the Restart UI.
            if (!this.currentNode) {
                _log('⏸️ No current node on post-redirect load — flow already ended, showing Restart');
                self.showEndState();
                return;
            }

            _log('🔄 Continuing flow from node:', this.currentNode);

            $.ajax({
                url: dialogaConfig.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialoga_process_response',
                    nonce: dialogaConfig.nonce,
                    session_id: this.sessionId,
                    session_token: this.sessionToken || '',
                    conversation_id: this.conversationId || 0,
                    flow_id: this.flowId,
                    current_node: this.currentNode,
                    response: '__auto_continue__'
                },
                success: function(response) {
                    _log('✅ Continue flow response:', response);

                    if (!response.success) {
                        _error('❌ Post-redirect continue failed:', response.data);
                        self.displayBotMessage({
                            type: 'message',
                            text: 'Something went wrong continuing the chat. Please restart.',
                            is_end: true
                        });
                        self.endConversation();
                        return;
                    }

                    if (response.data.end) {
                        if (response.data.message) {
                            response.data.message.is_end = true;
                            self.displayBotMessage(response.data.message);
                        } else {
                            self.showEndState();
                        }
                        self.endConversation();
                        return;
                    }

                    if (!response.data.message) {
                        _error('❌ Post-redirect: no message in response', response.data);
                        self.showEndState();
                        return;
                    }

                    // Flow continues — display the next node normally
                    self.currentNode = response.data.current_node;
                    self.saveSession();
                    self.displayBotMessage(response.data.message);
                },
                error: function(xhr, status, error) {
                    _error('❌ Error continuing flow:', error, xhr.responseText);
                    self.displayBotMessage({
                        type: 'message',
                        text: 'Connection error. Please restart the chat.',
                        is_end: true
                    });
                    self.endConversation();
                }
            });
        },
        
        endConversation: function() {
            localStorage.removeItem('dialoga_session');
            this.sessionId = null;
            this.sessionToken = null;
            this.flowId = null;
            this.currentNode = null;
            this.conversationHistory = [];
            this.pendingTags = [];
            this.variables = {};
            _log('✅ Conversation ended');
        },

        showEndState: function() {
            const self = this;
            const $inputArea = $('#dialoga-input-area');
            $inputArea.empty();

            // Persist the completed transcript when the flow reaches its natural end
            this.saveTranscript();

            const $restartBtn = $(
                '<button class="dialoga-restart-btn" id="dialoga-restart" type="button">' +
                    '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" style="margin-right:6px;vertical-align:middle;">' +
                        '<path d="M10 3v2a5 5 0 1 1-4.95 4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>' +
                        '<path d="M10 1l3 2-3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>' +
                    '</svg>' +
                    'Restart Chat' +
                '</button>'
            );

            $restartBtn.on('click', function() {
                self.restartChat();
            });

            $inputArea.append($restartBtn);
        },

        restartChat: function() {
            _log('🔄 Restarting chat from top');

            // Clear messages and input area
            $('#dialoga-messages').empty();
            $('#dialoga-input-area').empty();

            // Full state reset (endConversation already did most of this,
            // but we re-run to ensure clean slate if called from elsewhere)
            localStorage.removeItem('dialoga_session');
            this.sessionId = null;
            this.sessionToken = null;
            this.flowId = null;
            this.currentNode = null;
            this.conversationHistory = [];
            this.pendingTags = [];
            this.variables = {};

            // Show typing indicator while the flow boots up
            this.showTyping();
            this.startConversation();
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        // Format bot text — escape HTML first (XSS safety), THEN convert a
        // whitelist of markdown patterns to real HTML. Safe because at this
        // point < and > are already entities, so our injected <strong>/<em>/<br>
        // are the only tags the browser will render.
        formatBotText: function(text) {
            if (!text) return '';
            let out = this.escapeHtml(text);
            // Bold: **text**
            out = out.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            // Italic: *text* (single-asterisk, not inside a bold)
            out = out.replace(/(^|[^\*])\*(?!\*)(.+?)\*(?!\*)/g, '$1<em>$2</em>');
            // Inline code: `text`
            out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
            // Bullet lines: line starting with "- " or "* " → bullet char
            out = out.replace(/(^|\n)[-\*] (.+)/g, '$1&bull; $2');
            // Line breaks → <br>
            out = out.replace(/\n/g, '<br>');
            return out;
        }
    };
    
    // Expose to window so the inline onclick fallbacks (window.dialogaRestartChat
    // and window.dialogaCloseChat in render_chat_widget) can route through the
    // full widget API once jQuery has loaded. Defined here, not inside init(),
    // so the global is available even if init runs after a user click.
    window.dialogaWidget = dialogaWidget;

    // Initialize when document ready
    $(document).ready(function() {
        dialogaWidget.init();
    });

})(jQuery);
