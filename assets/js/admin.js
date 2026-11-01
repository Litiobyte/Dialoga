/**
 * DIALOGAWP Admin JavaScript
 * Flow Builder functionality using custom React canvas
 * Version: 0.26.0 - Added Action Node Configurations
 */

(function($) {
    'use strict';

    function t(message) {
        const i18n = window.dialogawpAdmin && window.dialogawpAdmin.i18n;
        return i18n && Object.prototype.hasOwnProperty.call(i18n, message) ? i18n[message] : message;
    }

    // Debug logging — only active when WP_DEBUG is true
    var _log = (window.dialogawpAdmin && window.dialogawpAdmin.debug) ? console.log.bind(console) : function() {};
    var _warn = (window.dialogawpAdmin && window.dialogawpAdmin.debug) ? console.warn.bind(console) : function() {};
    var _error = console.error.bind(console); // always show errors
    
    const dialogawpBuilder = {
        currentFlowId: null,
        currentFlow: null,
        reactFlowInstance: null,
        isDirty: false,
        autoSaveTimer: null,
        autoSaveDelayMs: 5000, // Auto-save 5s after last change (was 30s, made shorter for responsive UX)
        isAutoSaving: false,
        nodes: [],
        edges: [],
        selectedNode: null,
        nodeIdCounter: 1,
        
        init: function() {
            this.bindEvents();
            this.bindDragDropEvents();
            this.bindWebhookNodeEvents();
            this.bindAIChatNodeEvents();
            this.loadFlows();

            // Hide canvas until a flow is loaded (but keep it in DOM for React)
            $('#dialogawp-canvas').hide();

            // Wait a bit for React to be available, then initialize canvas
            setTimeout(() => {
                this.initCanvas();

                // Auto-load a specific flow if redirected from the AI wizard
                const params    = new URLSearchParams(window.location.search);
                const autoLoad  = params.get('flow_id');
                if (autoLoad) {
                    setTimeout(() => this.loadFlow(parseInt(autoLoad)), 300);
                }
            }, 500);

            // Warn before leaving page if there are unsaved changes
            window.addEventListener('beforeunload', (e) => {
                if (this.isDirty) {
                    // Try to save synchronously before unload (best effort)
                    this.performAutoSave(true);
                    e.preventDefault();
                    e.returnValue = '';
                    return '';
                }
            });

            // Listen for changes from React canvas (node moves, edge changes)
            $(document).on('dialogawp:canvas-changed', () => {
                this.markDirty();
            });

            // Watch the flow name input
            $(document).on('input change', '#dialogawp-flow-name', () => {
                this.markDirty();
            });

            // Watch visibility changes
            $(document).on('change', '#dialogawp-flow-visibility, .dialogawp-role-checkbox', () => {
                this.markDirty();
            });

            // Note: #dialogawp-flow-active is intentionally NOT auto-saved
            // (activating a flow makes it live on the frontend — should require explicit Save click)
        },

        /**
         * Update the save status indicator
         * @param {string} state - 'saved' | 'unsaved' | 'saving' | 'error'
         * @param {string} text - Optional custom text
         */
        updateSaveStatus: function(state, text) {
            const $status = $('#dialogawp-save-status');
            if (!$status.length) return;

            const labels = {
                saved: t('All changes saved'),
                unsaved: '● ' + t('Unsaved changes'),
                saving: t('Saving…'),
                error: t('Save failed')
            };

            $status
                .removeClass('dialogawp-save-status--saved dialogawp-save-status--unsaved dialogawp-save-status--saving dialogawp-save-status--error')
                .addClass('dialogawp-save-status--' + state)
                .find('.dialogawp-save-status-text')
                .text(text || labels[state] || '');
        },

        /**
         * Mark the flow as having unsaved changes and schedule auto-save
         */
        markDirty: function() {
            // Don't track dirty state if no flow is loaded
            if (!this.currentFlowId && this.nodes.length === 0) {
                return;
            }

            this.isDirty = true;
            this.updateSaveStatus('unsaved');

            // Reset the auto-save timer
            if (this.autoSaveTimer) {
                clearTimeout(this.autoSaveTimer);
            }
            this.autoSaveTimer = setTimeout(() => {
                this.performAutoSave();
            }, this.autoSaveDelayMs);
        },

        /**
         * Snapshot the current canvas state (for change detection)
         */
        getCanvasSnapshot: function() {
            if (!window.dialogawpReactInstance) return '';
            try {
                const flow = window.dialogawpReactInstance.getFlow();
                return JSON.stringify({
                    nodes: flow.nodes || [],
                    edges: flow.edges || [],
                    name: $('#dialogawp-flow-name').val(),
                    visibility: $('#dialogawp-flow-visibility').val()
                });
            } catch (e) {
                return '';
            }
        },

        /**
         * Start polling the canvas for changes (catches node moves, edge changes
         * that happen inside React without firing a JS event)
         */
        startChangeDetection: function() {
            if (this.changeDetectionTimer) {
                clearInterval(this.changeDetectionTimer);
            }
            // Take initial snapshot
            this.lastSnapshot = this.getCanvasSnapshot();
            // Check every 2 seconds
            this.changeDetectionTimer = setInterval(() => {
                if (!this.currentFlowId) return;
                const current = this.getCanvasSnapshot();
                if (current && current !== this.lastSnapshot) {
                    this.lastSnapshot = current;
                    this.markDirty();
                }
            }, 2000);
        },

        /**
         * Stop change detection (called when no flow is loaded)
         */
        stopChangeDetection: function() {
            if (this.changeDetectionTimer) {
                clearInterval(this.changeDetectionTimer);
                this.changeDetectionTimer = null;
            }
        },

        /**
         * Perform a silent auto-save (does not show modal popups)
         * @param {boolean} synchronous - If true, use synchronous AJAX (for beforeunload)
         */
        performAutoSave: function(synchronous = false) {
            // Skip if no flow loaded, no changes, or already saving
            if (!this.currentFlowId || !this.isDirty || this.isAutoSaving) {
                return;
            }

            // Sync nodes/edges from React canvas
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                if (currentFlow.nodes) this.nodes = currentFlow.nodes;
                if (currentFlow.edges) this.edges = currentFlow.edges;
            }

            const flowName = $('#dialogawp-flow-name').val();
            if (!flowName || flowName.trim() === '') {
                return; // Skip — name is required
            }

            // NOTE: Empty node arrays ARE allowed in auto-save (name/visibility-only changes)
            // The backend accepts empty flows; only the manual "Save Flow" button enforces "must have nodes"

            // IMPORTANT: Auto-save preserves the existing is_active state from the loaded flow
            // (do NOT auto-save the checkbox state — that requires explicit Save click)
            const isActive = this.currentFlow ? parseInt(this.currentFlow.is_active) === 1 : false;
            const visibilityMode = $('#dialogawp-flow-visibility').val();
            const visibleRoles = [];
            $('.dialogawp-role-checkbox:checked').each(function() {
                visibleRoles.push($(this).val());
            });

            this.isAutoSaving = true;
            this.updateSaveStatus('saving');

            const formData = new FormData();
            formData.append('action', 'dialogawp_save_flow');
            formData.append('nonce', dialogawpAdmin.nonce);
            formData.append('flow_id', this.currentFlowId);
            formData.append('flow_name', flowName);
            formData.append('is_active', isActive ? 1 : 0);
            formData.append('visibility_mode', visibilityMode);
            formData.append('visible_roles', JSON.stringify(visibleRoles));
            formData.append('flow_data', JSON.stringify({ nodes: this.nodes, edges: this.edges }));
            formData.append('autosave', '1');

            const self = this;

            if (synchronous) {
                // Synchronous request for beforeunload (best effort)
                const xhr = new XMLHttpRequest();
                xhr.open('POST', dialogawpAdmin.ajaxUrl, false); // false = synchronous
                try {
                    xhr.send(formData);
                    self.isDirty = false;
                } catch (e) {
                    _error('Auto-save (sync) failed:', e);
                }
                self.isAutoSaving = false;
                return;
            }

            fetch(dialogawpAdmin.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(response => {
                self.isAutoSaving = false;
                if (response.success) {
                    self.isDirty = false;
                    self.lastSnapshot = self.getCanvasSnapshot();
                    self.updateSaveStatus('saved');
                    _log('💾 Auto-saved');
                } else {
                    self.updateSaveStatus('error', t('Auto-save failed'));
                    _error('Auto-save failed:', response);
                }
            })
            .catch(error => {
                self.isAutoSaving = false;
                self.updateSaveStatus('error', t('Auto-save failed'));
                _error('Auto-save error:', error);
            });
        },
        
        bindEvents: function() {
            const self = this;
            
            // New flow button
            $('.dialogawp-new-flow').on('click', function(e) {
                e.preventDefault();
                self.createNewFlow();
            });
            
            // Save flow button
            $('.dialogawp-save-flow').on('click', function(e) {
                e.preventDefault();
                self.saveFlow();
            });
            
            // Add node buttons
            $('.dialogawp-add-node').on('click', function(e) {
                e.preventDefault();
                const nodeType = $(this).data('type');
                self.addNode(nodeType);
            });
            
            // Close node editor
            $(document).on('click', '.dialogawp-close-editor', function(e) {
                e.preventDefault();
                $('#dialogawp-node-editor').hide();
            });

            $(document).on('keydown', '#dialogawp-node-editor', function(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    $('#dialogawp-node-editor').hide();
                }
            });
            
            // Save node
            $(document).on('click', '.dialogawp-save-node', function(e) {
                e.preventDefault();
                self.saveNodeEdits();
            });
            
            // Delete node
            $(document).on('click', '.dialogawp-delete-node', function(e) {
                e.preventDefault();
                self.deleteNode();
            });
            
            // Flow card click (anywhere on the card loads the flow)
            $(document).on('click', '.dialogawp-flow-item', function(e) {
                // Don't load the flow if an action button was clicked
                if ($(e.target).closest('.dialogawp-flow-item-actions, button, a').length) {
                    return;
                }
                e.preventDefault();
                const flowId = $(this).data('flow-id');
                if (flowId && parseInt(flowId) !== parseInt(self.currentFlowId)) {
                    self.loadFlow(flowId);
                }
            });

            $(document).on('keydown', '.dialogawp-flow-item', function(e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                if ($(e.target).closest('.dialogawp-flow-item-actions, button, a').length) return;
                e.preventDefault();
                const flowId = $(this).data('flow-id');
                if (flowId && parseInt(flowId) !== parseInt(self.currentFlowId)) self.loadFlow(flowId);
            });

            // Legacy Edit button (if still present anywhere)
            $(document).on('click', '.dialogawp-edit-flow', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const flowId = $(this).data('flow-id');
                self.loadFlow(flowId);
            });

            // Delete flow
            $(document).on('click', '.dialogawp-delete-flow', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const flowId = $(this).data('flow-id');
                dialogawpModal.confirm(t('Are you sure you want to delete this flow?'), function() {
                    self.deleteFlow(flowId);
                }, { title: t('Delete Flow'), okText: t('Delete'), danger: true });
            });

            // Duplicate flow
            $(document).on('click', '.dialogawp-duplicate-flow', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const flowId = $(this).data('flow-id');
                self.duplicateFlow(flowId);
            });
            
            // Add option button
            $(document).on('click', '.dialogawp-add-option', function(e) {
                e.preventDefault();
                self.addQuestionOption();
            });
            
            // Add condition button (old - keep for backward compat)
            $(document).on('click', '.dialogawp-add-condition', function(e) {
                e.preventDefault();
                self.addBranchCondition();
            });
            
            // Add output group button (new)
            $(document).on('click', '.dialogawp-add-output-group', function(e) {
                e.preventDefault();
                self.addOutputGroup();
            });
            
            // Add button (for Output nodes)
            $(document).on('click', '.dialogawp-add-button', function(e) {
                e.preventDefault();
                self.addOutputButton();
            });
            
            // Visibility mode change
            $('#dialogawp-flow-visibility').on('change', function() {
                const mode = $(this).val();
                if (mode === 'specific_roles') {
                    $('#dialogawp-visibility-settings').show();
                } else {
                    $('#dialogawp-visibility-settings').hide();
                }
            });
            
            // Configure roles button
            $(document).on('click', '#dialogawp-visibility-settings', function(e) {
                e.preventDefault();
                $('#dialogawp-visibility-modal').fadeIn(200);
                window.setTimeout(function() {
                    $('#dialogawp-visibility-modal').focus();
                }, 0);
            });
            
            // Close visibility modal
            $(document).on('click', '.dialogawp-modal-close, .dialogawp-modal-backdrop', function(e) {
                e.preventDefault();
                $('#dialogawp-visibility-modal').fadeOut(200);
                $('#dialogawp-visibility-settings').focus();
            });

            $(document).on('keydown', '#dialogawp-visibility-modal', function(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    $('#dialogawp-visibility-modal').fadeOut(200);
                    $('#dialogawp-visibility-settings').focus();
                }
            });
            
            // Save roles
            $(document).on('click', '#dialogawp-save-roles', function(e) {
                e.preventDefault();
                $('#dialogawp-visibility-modal').fadeOut(200);
                // Update button text to show how many roles selected
                const selectedCount = $('.dialogawp-role-checkbox:checked').length;
                $('#dialogawp-visibility-settings').text(t('%s role(s) selected').replace('%s', selectedCount));
            });
        },

        

        bindDragDropEvents: function() {
            const self = this;
            
            _log('🎯 Binding palette drag events...');
            
            $('.dialogawp-draggable-node').on('dragstart', function(e) {
                const nodeType = $(this).data('type');
                _log('🎨 Palette drag started:', nodeType);
                
                e.originalEvent.dataTransfer.setData('dialogawp/node-type', nodeType);
                e.originalEvent.dataTransfer.effectAllowed = 'copy';
                
                $(this).addClass('dragging');
                $(this).one('dragend', function() {
                    $(this).removeClass('dragging');
                });
            });
            
            _log('✅ Palette drag events bound (React handles drops)');
        },
        
        
        addNodeAtPosition: function(nodeType, x, y, isStart = false) {
            _log('🔵 addNodeAtPosition called:', nodeType, 'at', x, y);
            
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                if (currentFlow.nodes) this.nodes = [...currentFlow.nodes];
                if (currentFlow.edges) this.edges = [...currentFlow.edges];
            }
            
            const nodeId = 'node_' + this.nodeIdCounter++;
            const adjustedX = x - 125;
            const adjustedY = y - 50;
            
            const newNode = {
                id: nodeId,
                type: 'custom',
                position: { x: adjustedX, y: adjustedY },
                data: {
                    nodeType: nodeType,
                    label: this.getNodeLabel(nodeType),
                    is_start: isStart
                }
            };
            
            // Add type-specific defaults (same as addNode)
            switch (nodeType) {
                case 'question':
                    newNode.data.message = 'What are you interested in?';
                    newNode.data.auto_continue = false;
                    newNode.data.delay = 0;
                    newNode.data.allow_multiple = false;
                    newNode.data.options = [
                        { id: 'opt_1', label: 'Option 1' },
                        { id: 'opt_2', label: 'Option 2' }
                    ];
                    newNode.data.label = '🔘 What are you interested in?';
                    break;
                case 'branch':
                    newNode.data.output_groups = [
                        { 
                            id: 'group_1', 
                            label: 'Output 1',
                            conditions: [{ id: 'cond_1', operator: 'equals', value: '' }],
                            logic: 'and'
                        }
                    ];
                    newNode.data.label = 'Branch Logic';
                    break;
                case 'action':
                    // v0.39.8: don't preselect an action type on new nodes — force the
                    // admin to choose from the dropdown. This surfaces all available
                    // action types (Capture Lead, Link Button, Redirect, Send Email,
                    // Set Variable, Tag Lead) so the admin knows what's available
                    // instead of silently landing on Capture Lead.
                    newNode.data.action_type = '';
                    newNode.data.label = 'Action (choose type)';
                    newNode.data.config = {};
                    break;
                case 'ai_chat':
                    newNode.data.label = 'AI Chat';
                    newNode.data.config = {
                        greeting: "Hi! I'm your AI assistant. How can I help you today?",
                        placeholder: 'Type your message...',
                        instructions: ''
                    };
                    break;
                case 'loop':
                    newNode.data.label = 'Loop Back';
                    newNode.data.target_node_id = '';
                    break;
                case 'webhook':
                    newNode.data.label = '⚡ Webhook';
                    newNode.data.config = {
                        connector_id: '',
                        method: 'POST',
                        include_lead_data: true,
                        include_flow_data: true,
                        custom_headers: ''
                    };
                    break;
            }
            
            this.nodes.push(newNode);

            // Auto-connect Chat Starts Here → first node added to an empty canvas
            const hasStartEdge = this.edges.some(e => e.source === '__start__');
            if (!hasStartEdge && this.nodes.length === 1) {
                this.edges.push({
                    id: 'edge-start-' + nodeId,
                    source: '__start__',
                    sourceHandle: null,
                    target: nodeId,
                    type: 'smoothstep',
                    animated: true
                });
                _log('🟢 Auto-connected Chat Starts Here →', nodeId);
            }

            _log('✅ Node created at:', newNode.position);

            if (window.dialogawpReactInstance) {
                window.dialogawpReactInstance.setFlow({
                    nodes: this.nodes,
                    edges: this.edges
                });
            }

            this.selectNode(newNode);
            this.markDirty();
        },

        initCanvas: function() {
            const self = this;
            
            _log('🎬 initCanvas starting...');
            
            // Check for React and ReactDOM
            if (typeof React === 'undefined' || typeof ReactDOM === 'undefined') {
                _log('⏳ Waiting for React/ReactDOM...');
                setTimeout(() => self.initCanvas(), 200);
                return;
            }
            
            _log('✅ React and ReactDOM ready');
            
            // Check if FlowBuilder component is ready (loaded via ES module)
            if (typeof window.dialogawpCanvasComponent === 'undefined') {
                _log('⏳ Waiting for DIALOGAWPCanvasComponent...');
                setTimeout(() => self.initCanvas(), 200);
                return;
            }
            
            _log('✅ DIALOGAWPCanvasComponent found');
            
            const container = document.getElementById('dialogawp-canvas');
            if (!container) {
                _error('❌ Canvas container #dialogawp-canvas not found');
                return;
            }
            
            _log('✅ Canvas container found');
            _log('🚀 Creating React root and rendering...');
            
            try {
                // Create React root
                const root = ReactDOM.createRoot(container);
                
                // Create FlowBuilder props
                const flowBuilderProps = {
                    initialNodes: self.nodes || [],
                    initialEdges: self.edges || [],
                    onNodeClick: (node) => {
                        _log('👆 Node clicked from React:', node.id);
                        self.selectNode(node);
                    }
                };
                
                _log('📦 Rendering FlowBuilder with props:', flowBuilderProps);
                
                // Render the FlowCanvas component
                root.render(React.createElement(window.dialogawpCanvasComponent, flowBuilderProps));
                
                self.reactFlowRoot = root;
                
                _log('✅✅✅ React canvas mounted successfully! ✅✅✅');
            } catch (error) {
                _error('❌ Error mounting canvas:', error);
                _error('Stack:', error.stack);
            }
        },
        
        showBuilder: function() {
            $('#dialogawp-builder-empty-state').hide();
            $('#dialogawp-builder-active').show();
            $('#dialogawp-canvas').show();
        },

        hideBuilder: function() {
            $('#dialogawp-builder-empty-state').show();
            $('#dialogawp-builder-active').hide();
            $('#dialogawp-canvas').hide();
            $('#dialogawp-node-editor').hide();
        },

        createNewFlow: function() {
            const self = this;

            // Auto-name: ChatFlow N based on existing flow count
            var flowCount = $('#dialogawp-flows-list .dialogawp-flow-item').length;
            var flowName = 'ChatFlow ' + (flowCount + 1);

            this.currentFlowId = null;
            this.currentFlow = {
                flow_name: flowName,
                flow_data: {
                    nodes: [],
                    edges: []
                },
                is_active: 0  // New flows are inactive by default — user must explicitly activate
            };

            $('#dialogawp-flow-name').val(flowName);
            $('#dialogawp-flow-active').prop('checked', false);
            $('#dialogawp-flow-visibility').val('everyone');
            this.showBuilder();

            // Clear local state
            this.nodes = [];
            this.edges = [];
            this.nodeIdCounter = 1;

            // Clear canvas
            if (window.dialogawpReactInstance) {
                window.dialogawpReactInstance.setFlow({ nodes: [], edges: [] });
            }
            $('#dialogawp-node-editor').hide();

            // Show saving status while we persist the new (empty) flow
            this.updateSaveStatus('saving', t('Creating flow…'));

            // Persist the empty flow shell immediately so it appears in "Your Flows"
            const formData = new FormData();
            formData.append('action', 'dialogawp_save_flow');
            formData.append('nonce', dialogawpAdmin.nonce);
            formData.append('flow_id', 0);
            formData.append('flow_name', flowName);
            formData.append('is_active', 0);
            formData.append('visibility_mode', 'everyone');
            formData.append('visible_roles', '[]');
            formData.append('flow_data', JSON.stringify({ nodes: [], edges: [] }));

            fetch(dialogawpAdmin.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(response => {
                if (response.success) {
                    self.currentFlowId = response.data.flow_id;
                    self.currentFlow.id = response.data.flow_id;
                    self.isDirty = false;
                    self.updateSaveStatus('saved');
                    self.lastSnapshot = self.getCanvasSnapshot();
                    self.loadFlows();
                    if (!self.changeDetectionTimer) self.startChangeDetection();
                    _log('✅ New flow created with ID:', response.data.flow_id);
                } else {
                    self.updateSaveStatus('error', t('Could not create flow'));
                    _error('Failed to create new flow:', response);
                }
            })
            .catch(error => {
                self.updateSaveStatus('error', t('Could not create flow'));
                _error('Error creating flow:', error);
            });
        },
        
        addNode: function(nodeType, isStart) {
            // Auto-mark as start if this is the first node on canvas
            if (typeof isStart === 'undefined') {
                isStart = this.nodes.length === 0;
            }
            _log('🔵 addNode called with type:', nodeType);
            
            // CRITICAL: Sync BOTH nodes and edges from canvas before adding new node
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                if (currentFlow.nodes) {
                    this.nodes = [...currentFlow.nodes];
                }
                if (currentFlow.edges) {
                    this.edges = [...currentFlow.edges];
                }
            }
            
            // Use grid positioning for clicked adds
            const nodeWidth = 250;
            const nodeHeight = 100;
            const startX = 200;
            const startY = 200;
            const gridSize = 300;
            
            let newX = startX;
            let newY = startY;
            
            if (this.nodes.length > 0) {
                const occupied = new Set();
                this.nodes.forEach(node => {
                    const gridX = Math.round(node.position.x / gridSize);
                    const gridY = Math.round(node.position.y / gridSize);
                    occupied.add(`${gridX},${gridY}`);
                });
                
                let found = false;
                let radius = 0;
                const maxRadius = 20;
                
                while (!found && radius < maxRadius) {
                    for (let dx = -radius; dx <= radius && !found; dx++) {
                        for (let dy = -radius; dy <= radius && !found; dy++) {
                            if (radius === 0 || Math.abs(dx) === radius || Math.abs(dy) === radius) {
                                const gridX = dx;
                                const gridY = dy;
                                const key = `${gridX},${gridY}`;
                                
                                if (!occupied.has(key)) {
                                    newX = gridX * gridSize + startX;
                                    newY = gridY * gridSize + startY;
                                    found = true;
                                }
                            }
                        }
                    }
                    radius++;
                }
                
                if (!found) {
                    let maxX = startX;
                    this.nodes.forEach(node => {
                        if (node.position.x > maxX) {
                            maxX = node.position.x;
                        }
                    });
                    newX = maxX + gridSize;
                    newY = startY;
                }
            }
            
            // Use the new addNodeAtPosition method
            this.addNodeAtPosition(nodeType, newX + 125, newY + 50, isStart);
        },
        
        getNodeLabel: function(nodeType, localized = false) {
            if (localized) {
                return ({ message: t('Message'), question: t('Message / Question'), output: t('Output'),
                    click: t('Click'), branch: t('Branch'), action: t('Action'), ai_chat: t('AI Chat'),
                    loop: t('Loop Back'), webhook: t('Webhook') })[nodeType] || t('Node');
            }
            const labels = {
                'message': 'Message',
                'question': 'Message / Question',
                'output': 'Output',
                'click': 'Click',
                'branch': 'Branch',
                'action': 'Action',
                'ai_chat': 'AI Chat',
                'loop': 'Loop Back',
                'webhook': 'Webhook'
            };
            // Keep the canonical labels used when creating nodes in saved data.
            return labels[nodeType] || 'Node';
        },
        
        selectNode: function(node) {
            // Auto-save edits from the previously selected node before switching
            const editorVisible = $('#dialogawp-node-editor').is(':visible');
            if (this.selectedNode && editorVisible && (!node || this.selectedNode.id !== node.id)) {
                _log('💾 Auto-saving previous node before switch:', this.selectedNode.id);
                this.saveNodeEdits(true); // silent: don't close editor
            }

            // IMPORTANT: Sync edges from canvas to get latest connections
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                if (currentFlow.edges) {
                    this.edges = currentFlow.edges;
                }
            }

            this.selectedNode = node;
            this.showNodeEditor(node);
        },
        
        // Escape only at HTML sinks; editor values and node data remain plain text.
        escapeEditorHtml: function(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        showNodeEditor: function(node) {
            if (this._suppressEditor) return;
            const $editor = $('#dialogawp-node-editor');
            const $content = $('#dialogawp-node-editor-content');

            let template = '';

            const nodeType = node.data.nodeType || node.type;

            _log('🎨 Showing editor for node type:', nodeType, 'Node:', node);

            switch (nodeType) {
                case 'message':
                    template = this.renderMessageNodeEditor(node);
                    break;
                case 'question':
                    template = this.renderQuestionNodeEditor(node);
                    break;
                case 'output':
                    template = this.renderOutputNodeEditor(node);
                    break;
                case 'click':
                    template = this.renderClickNodeEditor(node);
                    break;
                case 'branch':
                    template = this.renderBranchNodeEditor(node);
                    break;
                case 'action':
                    template = this.renderActionNodeEditor(node);
                    break;
                case 'ai_chat':
                    template = this.renderAIChatNodeEditor(node);
                    break;
                case 'loop':
                    template = this.renderLoopNodeEditor(node);
                    break;
                case 'webhook':
                    template = this.renderWebhookNodeEditor(node);
                    break;
                default:
                    _error('Unknown node type:', nodeType);
                    template = '<p>' + this.escapeEditorHtml(t('Unknown node type: %s').replace('%s', nodeType)) + '</p>';
            }

            // Prepend the available-variables picker so users can click to insert {{var}}
            const variablePicker = this.renderVariablePicker(node.id);
            $content.html(variablePicker + template);
            this.bindVariablePickerEvents();
            $editor.show();
            window.setTimeout(function() {
                const firstControl = $editor.find('input, select, textarea, button').filter(':visible').first();
                (firstControl.length ? firstControl : $editor).focus();
            }, 0);
        },

        getAvailableVariables: function(excludeNodeId) {
            const vars = [];
            const seen = {};
            (this.nodes || []).forEach((n) => {
                if (!n || !n.data) return;
                if (excludeNodeId && n.id === excludeNodeId) return;
                const key = n.data.save_as;
                if (key && typeof key === 'string' && key.trim() && !seen[key]) {
                    seen[key] = true;
                    // Label = the question/message text so user recognizes the source
                    const sourceLabel = (n.data.message || n.data.label || '').toString();
                    vars.push({ key: key.trim(), source: sourceLabel });
                }
            });
            return vars;
        },

        renderVariablePicker: function(currentNodeId) {
            const vars = this.getAvailableVariables(currentNodeId);
            if (!vars.length) return '';

            const esc = (s) => this.escapeEditorHtml(s);

            const chips = vars.map((v) => {
                return `<button type="button" class="dialogawp-var-chip" data-var="${esc(v.key)}" title="${esc(t('From: %s').replace('%s', v.source.substring(0, 60)))}">{{${esc(v.key)}}}</button>`;
            }).join('');

            return `
                <div class="dialogawp-variable-picker" style="margin-bottom:14px;padding:10px 12px;background:#f0f7ff;border:1px solid #cfe3ff;border-radius:6px;">
                    <div style="font-size:12px;font-weight:600;color:#1565c0;margin-bottom:6px;">
                        ${esc(t('Available Variables'))}
                        <span style="font-weight:400;color:#5a6a7d;">&mdash; ${esc(t('click to insert into the last focused field'))}</span>
                    </div>
                    <div class="dialogawp-var-chip-list" style="display:flex;flex-wrap:wrap;gap:6px;">
                        ${chips}
                    </div>
                </div>
            `;
        },

        bindVariablePickerEvents: function() {
            const self = this;
            const $editor = $('#dialogawp-node-editor-content');

            // Track the last focused text-like field inside the editor
            $editor.off('focusin.dialogawpVar').on('focusin.dialogawpVar', 'input[type="text"], input:not([type]), textarea', function() {
                self._lastFocusedField = this;
            });

            $editor.off('click.dialogawpVar', '.dialogawp-var-chip').on('click.dialogawpVar', '.dialogawp-var-chip', function(e) {
                e.preventDefault();
                const varName = $(this).attr('data-var');
                const token = '{{' + varName + '}}';
                const field = self._lastFocusedField;

                if (field && $editor[0].contains(field)) {
                    const start = field.selectionStart || 0;
                    const end = field.selectionEnd || 0;
                    const value = field.value || '';
                    field.value = value.substring(0, start) + token + value.substring(end);
                    const newPos = start + token.length;
                    field.focus();
                    try { field.setSelectionRange(newPos, newPos); } catch (err) {}
                    $(field).trigger('input').trigger('change');
                } else {
                    // No focused field: copy to clipboard as a graceful fallback
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(token);
                        _log('📋 Variable copied to clipboard:', token);
                    }
                }
            });
        },

        bindAIChatNodeEvents: function() {
            $(document).on('change', '#ai-chat-auto-extract', function() {
                $('#ai-chat-extract-fields').toggle(this.checked);
            });
        },

        bindWebhookNodeEvents: function() {
            const self = this;

            // Show the inline "new connector" form
            $(document).on('click', '#dialogawp-webhook-new-connector', function(e) {
                e.preventDefault();
                $('#dialogawp-webhook-new-form').slideDown(120);
                setTimeout(() => $('#dialogawp-webhook-new-name').focus(), 130);
            });

            // Cancel the inline form
            $(document).on('click', '#dialogawp-webhook-new-cancel', function(e) {
                e.preventDefault();
                $('#dialogawp-webhook-new-form').slideUp(120);
                $('#dialogawp-webhook-new-name, #dialogawp-webhook-new-url, #dialogawp-webhook-new-secret').val('');
                $('#dialogawp-webhook-new-status').text('');
            });

            // Save a new connector via AJAX, then add it to the dropdown and select it
            $(document).on('click', '#dialogawp-webhook-new-save', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const $status = $('#dialogawp-webhook-new-status');
                const name = ($('#dialogawp-webhook-new-name').val() || '').trim();
                const url  = ($('#dialogawp-webhook-new-url').val() || '').trim();
                const secret = $('#dialogawp-webhook-new-secret').val() || '';

                if (!name || !url) {
                    $status.css('color', '#dc2626').text(t('Name and URL are required.'));
                    return;
                }

                $btn.prop('disabled', true);
                $status.css('color', '#6b7280').text(t('Saving…'));

                const data = new FormData();
                data.append('action', 'dialogawp_save_webhook_connector');
                data.append('nonce', (window.dialogawpAdmin && window.dialogawpAdmin.nonce) || '');
                data.append('name', name);
                data.append('url', url);
                data.append('secret', secret);

                fetch((window.dialogawpAdmin && window.dialogawpAdmin.ajaxUrl) || '', {
                    method: 'POST',
                    body: data
                })
                    .then((r) => r.json())
                    .then((res) => {
                        $btn.prop('disabled', false);
                        if (!res.success) {
                            $status.css('color', '#dc2626').text(t('Failed: %s').replace('%s', res.data || t('Unknown error')));
                            return;
                        }
                        // Update local cache and the dropdown; pre-select the new connector
                        const connectors = (res.data && res.data.connectors) || [];
                        window.dialogawpProConnectors = window.dialogawpProConnectors || {};
                        window.dialogawpProConnectors.connectors = connectors;

                        const newest = connectors[connectors.length - 1];
                        const $select = $('#dialogawp-webhook-connector');
                        $select.empty().append($('<option>').val('').text(t('Select a connector')));
                        connectors.forEach((c) => {
                            $select.append($('<option>').val(c.id).text(c.name).prop('selected', !!newest && c.id === newest.id));
                        });

                        // Reset the inline form
                        $('#dialogawp-webhook-new-name, #dialogawp-webhook-new-url, #dialogawp-webhook-new-secret').val('');
                        $status.css('color', '#16a34a').text(t('Saved!'));
                        setTimeout(() => {
                            $('#dialogawp-webhook-new-form').slideUp(120);
                            $status.text('');
                        }, 800);
                    })
                    .catch(() => {
                        $btn.prop('disabled', false);
                        $status.css('color', '#dc2626').text(t('Network error'));
                    });
            });
        },

        renderMessageNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.messageNode) || '';
            return template.replace(/{{(message|delay)}}/g, (match, field) =>
                this.escapeEditorHtml(node.data[field] || (field === 'delay' ? 0 : '')));
        },
        
        renderQuestionNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.questionNode) || '';
            
            // Handle backward compatibility
            const message = node.data.message || node.data.question || '';
            const autoContinue = node.data.auto_continue || false;
            const allowMultiple = node.data.allow_multiple || false;
            const delay = node.data.delay || 0;

            const autoContinueChecked = autoContinue ? 'checked' : '';
            const allowMultipleChecked = allowMultiple ? 'checked' : '';

            // Show/hide delay field based on auto_continue
            const delayVisibility = autoContinue ? '' : 'display: none;';

            const saveAs = node.data.save_as || '';

            const values = {
                message, auto_continue_checked: autoContinueChecked,
                allow_multiple_checked: allowMultipleChecked, delay,
                delay_visibility: delayVisibility, save_as: saveAs
            };
            let html = template.replace(/{{(message|auto_continue_checked|allow_multiple_checked|delay|delay_visibility|save_as)}}/g,
                (match, field) => this.escapeEditorHtml(values[field]));
            
            setTimeout(() => {
                this.renderQuestionOptions(node.data.options || []);
                
                // Add event listener for auto_continue checkbox to show/hide delay
                $('input[data-field="auto_continue"]').on('change', function() {
                    const isChecked = $(this).is(':checked');
                    $('.dialogawp-delay-field').toggle(isChecked);
                });
                
                // Add event listener for allow_text_input checkbox to show/hide placeholder
                $('input[data-field="allow_text_input"]').on('change', function() {
                    const isChecked = $(this).is(':checked');
                    $('.dialogawp-text-placeholder-field').toggle(isChecked);
                });
            }, 0);
            
            return html;
        },
        
        renderOutputNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.outputNode) || '';
            const html = template.replace(/{{(output_message|delay)}}/g, (match, field) =>
                this.escapeEditorHtml(node.data[field] || (field === 'delay' ? 0 : '')));
            
            setTimeout(() => {
                this.renderOutputButtons(node.data.buttons || []);
            }, 0);
            
            return html;
        },
        
        renderOutputButtons: function(buttons) {
            const $container = $('#dialogawp-output-buttons');
            $container.empty();
            
            buttons.forEach((button, index) => {
                const buttonHtml = `
                    <div class="dialogawp-option-item" data-button-id="${this.escapeEditorHtml(button.id)}">
                        <input type="text" 
                               class="dialogawp-button-label" 
                               value="${this.escapeEditorHtml(button.label)}" 
                               placeholder="${this.escapeEditorHtml(t('Button text'))}" 
                               style="flex: 1;" />
                        <select class="dialogawp-button-action" style="width: 120px;">
                            <option value="link" ${button.action === 'link' ? 'selected' : ''}>${this.escapeEditorHtml(t('Open Link'))}</option>
                            <option value="continue" ${button.action === 'continue' ? 'selected' : ''}>${this.escapeEditorHtml(t('Continue Flow'))}</option>
                        </select>
                        <input type="url" 
                               class="dialogawp-button-url" 
                               value="${this.escapeEditorHtml(button.url || '')}" 
                               placeholder="https://..." 
                               style="flex: 1; ${button.action === 'link' ? '' : 'display:none;'}" />
                        <button class="button button-small dialogawp-remove-button" type="button">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                `;
                $container.append(buttonHtml);
            });
            
            // Show/hide URL field based on action type
            $('.dialogawp-button-action').on('change', function() {
                const $select = $(this);
                const $urlField = $select.siblings('.dialogawp-button-url');
                if ($select.val() === 'link') {
                    $urlField.show();
                } else {
                    $urlField.hide();
                }
            });
            
            // Remove button event
            $('.dialogawp-remove-button').on('click', function(e) {
                e.preventDefault();
                $(this).closest('.dialogawp-option-item').remove();
            });
        },
        
        renderClickNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.clickNode) || '';
            
            // Find connected Output node to show available click options
            let connectedOutputNode = null;
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                const incomingEdge = currentFlow.edges?.find(e => e.target === node.id);
                if (incomingEdge) {
                    connectedOutputNode = this.nodes.find(n => 
                        n.id === incomingEdge.source && n.data.nodeType === 'output'
                    );
                }
            }
            
            setTimeout(() => {
                if (connectedOutputNode && connectedOutputNode.data.buttons && connectedOutputNode.data.buttons.length > 0) {
                    const buttonsList = connectedOutputNode.data.buttons
                        .map(b => `<div style="padding: 8px; background: #f0f0f0; margin: 5px 0; border-radius: 3px;">
                            <strong>${this.escapeEditorHtml(b.label)}</strong> 
                            ${b.action === 'link' ? `<small style="color: #666;">→ ${this.escapeEditorHtml(b.url)}</small>` : `<small style="color: #666;">→ ${this.escapeEditorHtml(t('Continue'))}</small>`}
                        </div>`)
                        .join('');
                    
                    $('#dialogawp-click-options').html(`
                        <div class="dialogawp-notice" style="background: #e3f2fd;">
                            <p><strong>💡 ${this.escapeEditorHtml(t('Available Buttons:'))}</strong></p>
                            ${buttonsList}
                            <small style="color: #666; margin-top: 10px; display: block;">${this.escapeEditorHtml(t('Connect this Click node to different paths based on which button is clicked.'))}</small>
                        </div>
                    `);
                } else {
                    $('#dialogawp-click-options').html(`
                        <div class="dialogawp-notice warning">
                            <p><strong>⚠️ ${this.escapeEditorHtml(t('No buttons detected'))}</strong></p>
                            <p>${this.escapeEditorHtml(t('Connect an Output node (with buttons configured) to this Click node to detect button clicks.'))}</p>
                        </div>
                    `);
                }
            }, 0);
            
            return template;
        },
        
        renderQuestionOptions: function(options) {
            const $container = $('#dialogawp-question-options');
            $container.empty();
            
            options.forEach((option, index) => {
                const optionHtml = `
                    <div class="dialogawp-option-item" data-option-id="${this.escapeEditorHtml(option.id)}">
                        <input type="text" 
                               class="dialogawp-option-label" 
                               value="${this.escapeEditorHtml(option.label)}" 
                               placeholder="${this.escapeEditorHtml(t('Option %s').replace('%s', index + 1))}" />
                        <button class="button button-small dialogawp-remove-option" type="button">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                `;
                $container.append(optionHtml);
            });
            
            $('.dialogawp-remove-option').on('click', function(e) {
                e.preventDefault();
                $(this).closest('.dialogawp-option-item').remove();
            });
        },
        
        renderBranchNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.branchNode) || '';
            
            let connectedQuestionNode = null;
            if (window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                const incomingEdge = currentFlow.edges?.find(e => e.target === node.id);
                if (incomingEdge) {
                    connectedQuestionNode = this.nodes.find(n => 
                        n.id === incomingEdge.source && n.data.nodeType === 'question'
                    );
                }
            }
            
            let helpText = '';
            if (connectedQuestionNode && connectedQuestionNode.data.options) {
                const optionsList = connectedQuestionNode.data.options
                    .map(o => `<code style="background: #f0f0f0; padding: 2px 6px; margin: 0 4px; border-radius: 3px;">${this.escapeEditorHtml(o.label)}</code>`)
                    .join('');
                    
                helpText = `<div class="dialogawp-notice" style="padding: 12px; margin-bottom: 15px; background: #e3f2fd; border-left: 4px solid #190F4F; font-size: 13px; line-height: 1.6;">
                    <strong>💡 ${this.escapeEditorHtml(t('Connected Question Options:'))}</strong><br>
                    ${optionsList}<br>
                    <small style="color: #666; margin-top: 6px; display: block;">${this.escapeEditorHtml(t("Use these values in your conditions below to route based on the user's answer."))}</small>
                </div>`;
            }
            
            setTimeout(() => {
                if (helpText) {
                    $('#dialogawp-branch-groups').before(helpText);
                }
                this.renderBranchGroups(node.data.output_groups || []);
            }, 0);
            
            return template;
        },
        
        renderBranchConditions: function(conditions) {
            const $container = $('#dialogawp-branch-conditions');
            $container.empty();
            
            let questionOptions = null;
            if (this.selectedNode && window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                const incomingEdge = currentFlow.edges?.find(e => e.target === this.selectedNode.id);
                if (incomingEdge) {
                    const connectedNode = this.nodes.find(n => 
                        n.id === incomingEdge.source && n.data.nodeType === 'question'
                    );
                    if (connectedNode && connectedNode.data.options) {
                        questionOptions = connectedNode.data.options;
                    }
                }
            }
            
            conditions.forEach((condition, index) => {
                let valueInput;
                
                if (questionOptions && questionOptions.length > 0) {
                    const optionsHtml = questionOptions.map(opt => 
                        `<option value="${this.escapeEditorHtml(opt.label)}" ${condition.value === opt.label ? 'selected' : ''}>${this.escapeEditorHtml(opt.label)}</option>`
                    ).join('');
                    
                    valueInput = `
                        <select class="dialogawp-condition-value" style="flex: 1;">
                            <option value="">${this.escapeEditorHtml(t('Select an option'))}</option>
                            ${optionsHtml}
                        </select>
                    `;
                } else {
                    valueInput = `
                        <input type="text" 
                               class="dialogawp-condition-value" 
                               value="${this.escapeEditorHtml(condition.value || '')}" 
                               placeholder="${this.escapeEditorHtml(t('Enter value'))}" 
                               style="flex: 1;" />
                    `;
                }
                
                const logicDropdown = index > 0 ? `
                    <select class="dialogawp-condition-logic" style="width: 80px; margin-right: 8px;">
                        <option value="and" ${(condition.logic === 'and' || !condition.logic) ? 'selected' : ''}>${this.escapeEditorHtml(t('AND'))}</option>
                        <option value="or" ${condition.logic === 'or' ? 'selected' : ''}>${this.escapeEditorHtml(t('OR'))}</option>
                    </select>
                ` : '';
                
                const conditionHtml = `
                    <div class="dialogawp-condition-item" data-condition-id="${this.escapeEditorHtml(condition.id)}" style="display: flex; gap: 8px; margin-bottom: 10px; align-items: center;">
                        ${logicDropdown}
                        <select class="dialogawp-condition-operator" style="width: 140px;">
                            <option value="equals" ${condition.operator === 'equals' ? 'selected' : ''}>${this.escapeEditorHtml(t('Equals'))}</option>
                            <option value="contains" ${condition.operator === 'contains' ? 'selected' : ''}>${this.escapeEditorHtml(t('Contains'))}</option>
                            <option value="starts_with" ${condition.operator === 'starts_with' ? 'selected' : ''}>${this.escapeEditorHtml(t('Starts with'))}</option>
                            <option value="not_empty" ${condition.operator === 'not_empty' ? 'selected' : ''}>${this.escapeEditorHtml(t('Not empty'))}</option>
                        </select>
                        ${valueInput}
                        <button class="button button-small dialogawp-remove-condition" type="button" style="flex-shrink: 0;">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                `;
                $container.append(conditionHtml);
            });
            
            $('.dialogawp-remove-condition').on('click', function(e) {
                e.preventDefault();
                $(this).closest('.dialogawp-condition-item').remove();
            });
        },
        
        renderBranchGroups: function(outputGroups) {
            const esc = (s) => this.escapeEditorHtml(s);
            const $container = $('#dialogawp-branch-groups');
            $container.empty();
            
            // Get connected Question options for dropdown values
            let questionOptions = null;
            if (this.selectedNode && window.dialogawpReactInstance) {
                const currentFlow = window.dialogawpReactInstance.getFlow();
                const incomingEdge = currentFlow.edges?.find(e => e.target === this.selectedNode.id);
                if (incomingEdge) {
                    const connectedNode = this.nodes.find(n => 
                        n.id === incomingEdge.source && n.data.nodeType === 'question'
                    );
                    if (connectedNode && connectedNode.data.options) {
                        questionOptions = connectedNode.data.options;
                    }
                }
            }
            
            outputGroups.forEach((group, groupIndex) => {
                const groupHtml = `
                    <div class="dialogawp-output-group" data-group-id="${esc(group.id)}" style="background: #F7F8FC; border: 2px solid #E5E7EF; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #555;">${esc(t('Output %s').replace('%s', groupIndex + 1))}</h4>
                            <button class="button button-small dialogawp-remove-group" type="button" style="color: #d32f2f;">
                                <span class="dashicons dashicons-no"></span> ${esc(t('Remove Output'))}
                            </button>
                        </div>
                        <div class="dialogawp-group-conditions" data-group-id="${esc(group.id)}">
                            <!-- Conditions for this group -->
                        </div>
                        <div style="margin-top: 10px;">
                            <button class="button button-small dialogawp-add-condition-to-group" data-group-id="${esc(group.id)}">
                                <span class="dashicons dashicons-plus"></span> ${esc(t('Add Condition'))}
                            </button>
                            <label style="margin-left: 15px;">
                                <span style="margin-right: 5px;">${esc(t('Conditions must:'))}</span>
                                <select class="dialogawp-group-logic" data-group-id="${esc(group.id)}" style="width: 160px;">
                                    <option value="and" ${group.logic === 'and' ? 'selected' : ''}>${esc(t('ALL match (AND)'))}</option>
                                    <option value="or" ${group.logic === 'or' ? 'selected' : ''}>${esc(t('ANY match (OR)'))}</option>
                                </select>
                            </label>
                        </div>
                    </div>
                `;
                $container.append(groupHtml);
                
                // Render conditions for this group
                const $groupConditions = $container.children('.dialogawp-output-group').last().find('.dialogawp-group-conditions');
                (group.conditions || []).forEach((condition, condIndex) => {
                    let valueInput;
                    if (questionOptions && questionOptions.length > 0) {
                        const optionsHtml = questionOptions.map(opt => 
                            `<option value="${esc(opt.label)}" ${condition.value === opt.label ? 'selected' : ''}>${esc(opt.label)}</option>`
                        ).join('');
                        valueInput = `
                            <select class="dialogawp-condition-value" style="flex: 1;">
                                <option value="">${esc(t('Select'))}</option>
                                ${optionsHtml}
                            </select>
                        `;
                    } else {
                        valueInput = `
                            <input type="text" 
                                   class="dialogawp-condition-value" 
                                   value="${esc(condition.value || '')}" 
                                   placeholder="${esc(t('Enter value'))}" 
                                   style="flex: 1;" />
                        `;
                    }
                    
                    const conditionHtml = `
                        <div class="dialogawp-condition-item" data-condition-id="${esc(condition.id)}" style="display: flex; gap: 8px; margin-bottom: 8px; align-items: center;">
                            <select class="dialogawp-condition-operator" style="width: 120px;">
                                <option value="equals" ${condition.operator === 'equals' ? 'selected' : ''}>${esc(t('Equals'))}</option>
                                <option value="contains" ${condition.operator === 'contains' ? 'selected' : ''}>${esc(t('Contains'))}</option>
                                <option value="starts_with" ${condition.operator === 'starts_with' ? 'selected' : ''}>${esc(t('Starts with'))}</option>
                                <option value="not_empty" ${condition.operator === 'not_empty' ? 'selected' : ''}>${esc(t('Not empty'))}</option>
                            </select>
                            ${valueInput}
                            <button class="button button-small dialogawp-remove-condition" type="button">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </div>
                    `;
                    $groupConditions.append(conditionHtml);
                });
            });
            
            // Event handlers
            $('.dialogawp-remove-group').on('click', function(e) {
                e.preventDefault();
                var groupEl = $(this).closest('.dialogawp-output-group');
                if (outputGroups.length > 1) {
                    groupEl.remove();
                } else {
                    dialogawpModal.confirm(t('Remove this output group?'), function() {
                        groupEl.remove();
                    }, { title: t('Remove Group'), okText: t('Remove'), danger: true });
                }
            });
            
            $('.dialogawp-remove-condition').on('click', function(e) {
                e.preventDefault();
                $(this).closest('.dialogawp-condition-item').remove();
            });
            
            $('.dialogawp-add-condition-to-group').on('click', function(e) {
                e.preventDefault();
                const $groupConditions = $(this).closest('.dialogawp-output-group').find('.dialogawp-group-conditions');
                
                let valueInput = `<input type="text" class="dialogawp-condition-value" placeholder="${esc(t('Enter value'))}" style="flex: 1;" />`;
                if (questionOptions && questionOptions.length > 0) {
                    const optionsHtml = questionOptions.map(opt => 
                        `<option value="${esc(opt.label)}">${esc(opt.label)}</option>`
                    ).join('');
                    valueInput = `
                        <select class="dialogawp-condition-value" style="flex: 1;">
                            <option value="">${esc(t('Select'))}</option>
                            ${optionsHtml}
                        </select>
                    `;
                }
                
                const newCondHtml = `
                    <div class="dialogawp-condition-item" data-condition-id="cond_${Date.now()}" style="display: flex; gap: 8px; margin-bottom: 8px;">
                        <select class="dialogawp-condition-operator" style="width: 120px;">
                            <option value="equals">${esc(t('Equals'))}</option>
                            <option value="contains">${esc(t('Contains'))}</option>
                            <option value="starts_with">${esc(t('Starts with'))}</option>
                            <option value="not_empty">${esc(t('Not empty'))}</option>
                        </select>
                        ${valueInput}
                        <button class="button button-small dialogawp-remove-condition" type="button">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                `;
                $groupConditions.append(newCondHtml);
                
                // Rebind remove handler
                $groupConditions.find('.dialogawp-remove-condition').last().on('click', function(e) {
                    e.preventDefault();
                    $(this).closest('.dialogawp-condition-item').remove();
                });
            });
        },
        
        renderActionNodeEditor: function(node) {
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.actionNode) || '';
            // v0.39.8: don't default to 'capture_lead' — respect the empty state on
            // brand-new Action nodes so the dropdown shows "-- Select an action type --"
            // and forces the admin to pick something.
            const actionType = node.data.action_type || '';

            let html = template
                .replace(/{{selected_none}}/g,          actionType === '' ? 'selected' : '')
                .replace(/{{selected_capture_lead}}/g, actionType === 'capture_lead' ? 'selected' : '')
                .replace(/{{selected_send_email}}/g, actionType === 'send_email' ? 'selected' : '')
                .replace(/{{selected_redirect}}/g, actionType === 'redirect' ? 'selected' : '')
                .replace(/{{selected_link_button}}/g, actionType === 'link_button' ? 'selected' : '')
                .replace(/{{selected_set_variable}}/g, actionType === 'set_variable' ? 'selected' : '')
                .replace(/{{selected_tag_lead}}/g, actionType === 'tag_lead' ? 'selected' : '');
            
            setTimeout(() => {
                this.renderActionConfig(node);
                
                $('#dialogawp-action-type').on('change', () => {
                    const newType = $('#dialogawp-action-type').val();
                    if (!node.data.config) node.data.config = {};
                    node.data.action_type = newType;
                    this.renderActionConfig(node);
                });
            }, 0);
            
            return html;
        },

        renderAIChatNodeEditor: function(node) {
            const config = node.data.config || {};
            const greeting     = config.greeting     ?? "Hi! I'm your AI assistant. How can I help you today?";
            const placeholder  = config.placeholder  ?? 'Type your message...';
            const instructions = config.instructions  || '';

            const autoExtract  = !!config.auto_extract_lead;
            const leadFields   = config.lead_fields || { name: true, email: true, phone: true };

            return `
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Greeting Message'))}</label>
                    <input type="text" id="ai-chat-greeting" class="regular-text" value="${this.escapeEditorHtml(greeting)}"
                           placeholder="${this.escapeEditorHtml(t('Hi! How can I help you today?'))}" />
                    <p class="description">${this.escapeEditorHtml(t('The first message the AI sends to open the conversation.'))}</p>
                </div>
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Input Placeholder'))}</label>
                    <input type="text" id="ai-chat-placeholder" class="regular-text" value="${this.escapeEditorHtml(placeholder)}" />
                </div>
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Node-Specific Instructions'))} <span style="font-weight:400;color:#787c82;">${this.escapeEditorHtml(t('(optional)'))}</span></label>
                    <textarea id="ai-chat-instructions" rows="4" style="width:100%;"
                              placeholder="${this.escapeEditorHtml(t('Any extra context for the AI at this point in the flow...'))}">${this.escapeEditorHtml(instructions)}</textarea>
                </div>

                <div class="dialogawp-field" style="border-top:1px solid #E5E7EF;padding-top:14px;margin-top:14px;">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                        <input type="checkbox" id="ai-chat-auto-extract" ${autoExtract ? 'checked' : ''} />
                        ${this.escapeEditorHtml(t('Auto-extract lead from conversation'))}
                    </label>
                    <p class="description">
                        ${this.escapeEditorHtml(t('When enabled, the AI gathers contact fields naturally in chat, confirms them with the visitor, and saves a lead on confirmation. No separate form needed.'))}
                        ${this.escapeEditorHtml(t('Fires %s so any downstream Webhook nodes fire too.')).replace('%s', '<code>dialogawp_lead_captured</code>')}
                    </p>
                </div>
                <div id="ai-chat-extract-fields" class="dialogawp-field" style="padding:10px 12px;background:#F7F8FC;border:1px solid #E5E7EF;border-radius:6px;display:${autoExtract ? 'block' : 'none'};">
                    <label style="font-weight:600;font-size:12px;margin-bottom:8px;display:block;">${this.escapeEditorHtml(t('Required fields'))}</label>
                    <label style="display:block;margin-bottom:6px;font-weight:400;">
                        <input type="checkbox" id="ai-chat-field-name" ${leadFields.name !== false ? 'checked' : ''} /> ${this.escapeEditorHtml(t('Name'))}
                    </label>
                    <label style="display:block;margin-bottom:6px;font-weight:400;">
                        <input type="checkbox" id="ai-chat-field-email" ${leadFields.email !== false ? 'checked' : ''} /> ${this.escapeEditorHtml(t('Email'))}
                    </label>
                    <label style="display:block;font-weight:400;">
                        <input type="checkbox" id="ai-chat-field-phone" ${leadFields.phone === true ? 'checked' : ''} /> ${this.escapeEditorHtml(t('Phone'))}
                    </label>
                    <p class="description" style="margin-top:8px;">${this.escapeEditorHtml(t("The AI won't save the lead until all checked fields are collected AND the visitor confirms."))}</p>
                </div>`;
        },

        renderLoopNodeEditor: function(node) {
            // Find the wire from this Loop node to determine the loop target
            const loopEdge = this.edges.find(e => e.source === node.id);
            let targetInfo = '';
            if (loopEdge) {
                const targetNode = this.nodes.find(n => n.id === loopEdge.target);
                if (targetNode) {
                    const label = targetNode.data.message || targetNode.data.label || targetNode.data.nodeType || targetNode.id;
                    const safeLabel = this.escapeEditorHtml(label);
                    targetInfo = `
                        <div style="padding:12px 16px;background:#e8f5e9;border:1px solid #4caf50;border-radius:6px;margin-bottom:12px;">
                            <strong style="color:#2e7d32;">↩ ${this.escapeEditorHtml(t('Loops back to:'))}</strong>
                            <div style="margin-top:4px;color:#1b5e20;">${safeLabel} <span style="color:#666;font-size:12px;">[${this.escapeEditorHtml(this.getNodeLabel(targetNode.data.nodeType, true))}]</span></div>
                        </div>`;
                }
            } else {
                targetInfo = `
                    <div style="padding:12px 16px;background:#fff3e0;border:1px solid #ff9800;border-radius:6px;margin-bottom:12px;">
                        <strong style="color:#e65100;">⚠ ${this.escapeEditorHtml(t('No loop target connected'))}</strong>
                        <div style="margin-top:4px;color:#bf360c;font-size:13px;">${this.escapeEditorHtml(t('Drag a connection from this Loop node to the target step. The flow will end if no wire is connected.'))}</div>
                    </div>`;
            }

            const delayMs = node.data.delay_ms || 0;
            const delaySeconds = delayMs / 1000;
            const message = node.data.message || '';

            return `
                <div class="dialogawp-field">
                    ${targetInfo}
                    <p class="description">
                        ${this.escapeEditorHtml(t('The connection wire from this Loop node determines where the flow jumps back to. Drag a connection from this node to any earlier step.'))}
                    </p>
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Optional Loopback Message'))}</label>
                    <textarea id="dialogawp-loop-message" rows="2" style="width:100%;" placeholder="${this.escapeEditorHtml(t('e.g. Anything else I can help with?'))}">${this.escapeEditorHtml(message)}</textarea>
                    <p class="description">${this.escapeEditorHtml(t('Show this message before looping back. Leave blank to skip.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Optional Delay Before Loop'))}</label>
                    <input type="number" id="dialogawp-loop-delay" min="0" max="30" step="0.5" value="${delaySeconds}" style="width:120px;" />
                    <span style="color:#666;margin-left:6px;">${this.escapeEditorHtml(t('seconds'))}</span>
                    <p class="description">${this.escapeEditorHtml(t('Wait this many seconds before looping back. 0 = instant.'))}</p>
                </div>`;
        },

        renderWebhookNodeEditor: function(node) {
            const config = node.data.config || {};
            const esc = (s) => this.escapeEditorHtml(s);

            const connectorsCfg = window.dialogawpProConnectors || {};
            const connectors    = connectorsCfg.connectors || [];
            const manageUrl     = connectorsCfg.manageUrl  || '';

            let connectorOptions = `<option value="">${esc(t('Select a connector'))}</option>`;
            connectors.forEach((c) => {
                const sel = (config.connector_id === c.id) ? ' selected' : '';
                connectorOptions += `<option value="${esc(c.id)}"${sel}>${esc(c.name)}</option>`;
            });

            return `
                <div class="dialogawp-field">
                    <label>${esc(t('Webhook Connector'))}</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <select id="dialogawp-webhook-connector" style="flex:1;">
                            ${connectorOptions}
                        </select>
                        <button type="button" class="button button-small" id="dialogawp-webhook-new-connector"
                                style="white-space:nowrap;">${esc(t('+ New'))}</button>
                    </div>
                    <p class="description">
                        ${esc(t('Credentials are managed centrally on the %s.')).replace('%s', `<a href="${esc(manageUrl)}" target="_blank">${esc(t('Integrations page'))}</a>`)}
                        ${esc(t('Click %s to create a connector without leaving the builder.')).replace('%s', `<strong>${esc(t('+ New'))}</strong>`)}
                    </p>
                </div>

                <!-- Inline "create new connector" form (hidden by default) -->
                <div id="dialogawp-webhook-new-form" style="display:none;padding:12px;background:#F7F8FC;border:1px solid #E5E7EF;border-radius:6px;margin-bottom:14px;">
                    <div class="dialogawp-field">
                        <label style="font-size:12px;">${esc(t('Name'))}</label>
                        <input type="text" id="dialogawp-webhook-new-name" placeholder="${esc(t('Zapier - Lead Notifier'))}" style="width:100%;" />
                    </div>
                    <div class="dialogawp-field">
                        <label style="font-size:12px;">${esc(t('Webhook URL'))}</label>
                        <input type="url" id="dialogawp-webhook-new-url" placeholder="https://hooks.zapier.com/..." style="width:100%;" />
                    </div>
                    <div class="dialogawp-field">
                        <label style="font-size:12px;">${esc(t('Shared Secret'))} <span style="color:#9ca3af;font-weight:400;">${esc(t('(optional)'))}</span></label>
                        <input type="text" id="dialogawp-webhook-new-secret" placeholder="${esc(t('For HMAC-SHA256 signing'))}" style="width:100%;" />
                    </div>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="button button-primary button-small" id="dialogawp-webhook-new-save">${esc(t('Save Connector'))}</button>
                        <button type="button" class="button button-small" id="dialogawp-webhook-new-cancel">${esc(t('Cancel'))}</button>
                        <span id="dialogawp-webhook-new-status" style="font-size:12px;color:#6b7280;align-self:center;"></span>
                    </div>
                </div>

                <div class="dialogawp-field">
                    <label>${esc(t('HTTP Method'))}</label>
                    <select id="dialogawp-webhook-method" style="width:140px;">
                        <option value="POST"${config.method === 'POST' || !config.method ? ' selected' : ''}>POST</option>
                        <option value="PUT"${config.method === 'PUT' ? ' selected' : ''}>PUT</option>
                        <option value="PATCH"${config.method === 'PATCH' ? ' selected' : ''}>PATCH</option>
                    </select>
                </div>

                <div class="dialogawp-field">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="checkbox" id="dialogawp-webhook-include-lead" ${config.include_lead_data !== false ? 'checked' : ''} />
                        ${esc(t('Include lead data (name, email, phone, tags)'))}
                    </label>
                </div>

                <div class="dialogawp-field">
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                        <input type="checkbox" id="dialogawp-webhook-include-flow" ${config.include_flow_data !== false ? 'checked' : ''} />
                        ${esc(t('Include flow data (flow name, variables, session)'))}
                    </label>
                </div>

                <div class="dialogawp-field">
                    <label>${esc(t('Custom Headers'))} <span style="color:#9ca3af;font-weight:400;font-size:11px;">${esc(t('(optional, one per line, key:value)'))}</span></label>
                    <textarea id="dialogawp-webhook-headers" rows="3"
                              placeholder="Authorization: Bearer token123&#10;X-Custom: value"
                              style="width:100%;font-family:monospace;font-size:12px;">${esc(config.custom_headers || '')}</textarea>
                </div>

                <div style="padding:10px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;margin-top:8px;">
                    <p style="margin:0;font-size:12px;color:#1E40AF;">
                        <strong>${esc(t('Tip:'))}</strong> ${esc(t('Drop multiple Webhook nodes in a flow to fan data out to different integrations.'))}
                    </p>
                </div>
            `;
        },

        renderActionConfig: function(node) {
            // v0.39.10: preserve empty action_type instead of falling back to
            // 'capture_lead'. Fallback was silently defeating the empty-state hint
            // added in v0.39.8 — even brand-new Action nodes with no type chosen
            // still rendered the Capture Lead form because the fallback flipped
            // '' → 'capture_lead' before the !actionType guard below could catch it.
            const actionType = node.data.action_type || '';
            const config = node.data.config || {};
            const $container = $('#dialogawp-action-config');
            
            _log('🎨 Rendering action config for:', actionType);
            _log('📋 Current config:', config);
            
            $container.empty();

            // v0.39.8: friendly empty-state prompt when no action type is chosen yet.
            // Replaces the previous silent "just render Capture Lead by default"
            // behavior, which hid the other action types from admin awareness.
            if (!actionType) {
                $container.html(
                    '<div class="dialogawp-notice" style="background:#f0f6fc;border-left:4px solid #190F4F;padding:14px 16px;margin:16px 0;color:#26233A;border-radius:4px;">' +
                        '<p style="margin:0 0 6px;font-weight:600;">👋 ' + this.escapeEditorHtml(t('Choose an action type above to get started.')) + '</p>' +
                        '<p style="margin:0;font-size:13px;color:#475569;">' + this.escapeEditorHtml(t('Each action type does something different when a visitor reaches this node: capture their contact info, show a booking link, redirect to a page, send an email, tag them, or set a variable.')) + '</p>' +
                    '</div>'
                );
                return;
            }

            switch(actionType) {
                case 'capture_lead':
                    this.renderCaptureLeadConfig($container, config);
                    break;
                case 'redirect':
                    _log('🔗 Rendering redirect config');
                    this.renderRedirectConfig($container, config);
                    break;
                case 'link_button':
                    _log('🔘 Rendering link_button config');
                    this.renderLinkButtonConfig($container, config);
                    break;
                case 'ai_chat':
                    this.renderAIChatConfig($container, config);
                    break;
                case 'send_email':
                    this.renderSendEmailConfig($container, config);
                    break;
                case 'set_variable':
                    this.renderSetVariableConfig($container, config);
                    break;
                case 'tag_lead':
                    this.renderTagLeadConfig($container, config);
                    break;
            }
            
            _log('✅ Config rendered, fields:', $('.dialogawp-action-config-field').length);
        },
        
        renderCaptureLeadConfig: function($container, config) {
            const fields = config.fields || {
                name: { enabled: true, label: 'Your Name', required: true },
                email: { enabled: true, label: 'Email Address', required: true },
                phone: { enabled: false, label: 'Phone Number', required: false },
                company: { enabled: false, label: 'Company', required: false }
            };

            const successMessage = config.success_message ?? "Thanks! We'll be in touch soon.";
            const redirectUrl = config.redirect_url || '';

            // v0.39.11 — email notification overrides. Optional per-node override
            // of the global email notification recipient / subject / intro copy.
            // Off by default; when on, this node's config replaces the global
            // DialogaWP → Settings → Email Notifications values for THIS Capture Lead.
            const overrideEmail   = !!config.override_email_settings;
            const emailRecipients = config.email_recipients || '';
            const emailSubject    = config.email_subject    || '';
            const emailIntro      = config.email_intro      || '';

            const html = `
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Fields to Capture'))}</label>
                    <p class="description">${this.escapeEditorHtml(t('Choose which fields to collect from visitors.'))}</p>

                    <div class="dialogawp-capture-fields">
                        ${this.renderCaptureField('name', 'Name', fields.name)}
                        ${this.renderCaptureField('email', 'Email', fields.email)}
                        ${this.renderCaptureField('phone', 'Phone', fields.phone)}
                        ${this.renderCaptureField('company', 'Company', fields.company)}
                    </div>
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Success Message'))}</label>
                    <textarea class="dialogawp-action-config-field"
                              data-config-field="success_message"
                              rows="2"
                              placeholder="${this.escapeEditorHtml(t('Message shown after form submission'))}">${this.escapeEditorHtml(successMessage)}</textarea>
                    <p class="description">${this.escapeEditorHtml(t('Message displayed after the visitor submits their information.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Redirect After Capture (Optional)'))}</label>
                    <input type="url"
                           class="dialogawp-action-config-field"
                           data-config-field="redirect_url"
                           value="${this.escapeEditorHtml(redirectUrl)}"
                           placeholder="https://example.com/thank-you" />
                    <p class="description">${this.escapeEditorHtml(t('Optional: Redirect to a thank-you page after form submission.'))}</p>
                </div>

                <!-- v0.39.11 — Email Notification Overrides -->
                <div class="dialogawp-field" style="border-top:1px solid #E5E7EF;padding-top:16px;margin-top:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox"
                               id="dialogawp-override-email-toggle"
                               class="dialogawp-action-config-field"
                               data-config-field="override_email_settings"
                               value="1"
                               ${overrideEmail ? 'checked' : ''} />
                        <strong>${this.escapeEditorHtml(t('Override global email notification settings'))}</strong>
                    </label>
                    <p class="description">${this.escapeEditorHtml(t('When on, this Capture Lead node uses its own recipient / subject / intro for the lead notification email instead of the global settings under %s. Useful when different flows (wellness / therapy) should notify different people.')).replace('%s', `<em>DialogaWP &rarr; ${this.escapeEditorHtml(t('Settings'))} &rarr; ${this.escapeEditorHtml(t('Email Notifications'))}</em>`)}</p>
                </div>

                <div id="dialogawp-email-override-fields" style="display:${overrideEmail ? 'block' : 'none'};padding-left:16px;border-left:3px solid #dbeafe;margin-top:8px;">
                    <div class="dialogawp-field">
                        <label>${this.escapeEditorHtml(t('Recipient(s)'))}</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="email_recipients"
                               value="${this.escapeEditorHtml(emailRecipients)}"
                               placeholder="wellness@yourdomain.com, owner@yourdomain.com" />
                        <p class="description">${this.escapeEditorHtml(t('Comma-separated email addresses. Where to send the lead notification for THIS node.'))}</p>
                    </div>

                    <div class="dialogawp-field">
                        <label>${this.escapeEditorHtml(t('Subject Line'))}</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="email_subject"
                               value="${this.escapeEditorHtml(emailSubject)}"
                                placeholder="${this.escapeEditorHtml(t('New Wellness Lead - {user_name}'))}" />
                        <p class="description">${this.escapeEditorHtml(t('Variables: %s, or any flow variable set by a Question node\'s "Save As".')).replace('%s', '<code>{user_name}</code>, <code>{user_email}</code>, <code>{user_phone}</code>')}</p>
                    </div>

                    <div class="dialogawp-field">
                        <label>${this.escapeEditorHtml(t('Additional Intro (Optional)'))}</label>
                        <textarea class="dialogawp-action-config-field"
                                  data-config-field="email_intro"
                                  rows="3"
                                  placeholder="${this.escapeEditorHtml(t('Prepended above the standard lead details and chat transcript.'))}">${this.escapeEditorHtml(emailIntro)}</textarea>
                        <p class="description">${this.escapeEditorHtml(t('Extra text prepended to the standard "A new lead was captured..." body. Chat transcript + lead details still included below. Supports the same variables as the subject line.'))}</p>
                    </div>
                </div>
            `;

            $container.html(html);

            $('.dialogawp-field-enable').on('change', function() {
                const $checkbox = $(this);
                const fieldKey = $checkbox.data('field');
                const $fieldRow = $checkbox.closest('.dialogawp-capture-field-row');
                const $inputs = $fieldRow.find('.dialogawp-field-label, .dialogawp-field-required');

                if ($checkbox.is(':checked')) {
                    $inputs.prop('disabled', false);
                    $fieldRow.removeClass('disabled');
                } else {
                    $inputs.prop('disabled', true);
                    $fieldRow.addClass('disabled');
                }
            });

            // v0.39.11 — toggle the override fields visibility
            $('#dialogawp-override-email-toggle').on('change', function() {
                $('#dialogawp-email-override-fields').toggle($(this).is(':checked'));
            });
        },
        
        renderCaptureField: function(fieldKey, fieldName, fieldConfig) {
            const enabled = fieldConfig.enabled !== false;
            const label = fieldConfig.label ?? fieldName;
            const required = fieldConfig.required !== false;
            const disabledClass = enabled ? '' : 'disabled';
            
            return `
                <div class="dialogawp-capture-field-row ${disabledClass}" data-field="${this.escapeEditorHtml(fieldKey)}">
                    <label class="dialogawp-capture-field-enable-label">
                        <input type="checkbox" 
                               class="dialogawp-field-enable" 
                               data-field="${this.escapeEditorHtml(fieldKey)}" 
                               ${enabled ? 'checked' : ''} />
                        <strong>${this.escapeEditorHtml(({ name: t('Name'), email: t('Email'), phone: t('Phone'), company: t('Company') })[fieldKey] || fieldName)}</strong>
                    </label>
                    
                    <input type="text" 
                           class="dialogawp-field-label" 
                           data-field="${this.escapeEditorHtml(fieldKey)}" 
                           value="${this.escapeEditorHtml(label)}" 
                           placeholder="${this.escapeEditorHtml(t('Field label'))}" 
                           ${enabled ? '' : 'disabled'} />
                    
                    <label class="dialogawp-field-required-label">
                        <input type="checkbox" 
                               class="dialogawp-field-required" 
                               data-field="${this.escapeEditorHtml(fieldKey)}" 
                               ${required ? 'checked' : ''} 
                               ${enabled ? '' : 'disabled'} />
                        ${this.escapeEditorHtml(t('Required'))}
                    </label>
                </div>
            `;
        },
        
        renderAIChatConfig: function($container, config) {
            const greeting = config.greeting ?? 'Hi! I\'m your AI assistant. How can I help you today?';
            const placeholder = config.placeholder ?? 'Type your message...';
            const instructions = config.instructions || '';

            const html = `
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Greeting Message'))}</label>
                    <input type="text"
                           class="dialogawp-action-config-field"
                           data-config-field="greeting"
                           value="${this.escapeEditorHtml(greeting)}"
                           placeholder="${this.escapeEditorHtml(t('Hi! How can I help you today?'))}" />
                    <p class="description">${this.escapeEditorHtml(t('The first message the AI sends to start the conversation.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Input Placeholder'))}</label>
                    <input type="text"
                           class="dialogawp-action-config-field"
                           data-config-field="placeholder"
                           value="${this.escapeEditorHtml(placeholder)}"
                           placeholder="${this.escapeEditorHtml(t('Type your message...'))}" />
                </div>

                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Node-Specific Instructions'))} ${this.escapeEditorHtml(t('(optional)'))}</label>
                    <textarea class="dialogawp-action-config-field"
                              data-config-field="instructions"
                              rows="4"
                              placeholder="${this.escapeEditorHtml(t('Additional context for this specific AI chat node...'))}">${this.escapeEditorHtml(instructions)}</textarea>
                    <p class="description">${this.escapeEditorHtml(t('Adds extra context to the AI for this node only. Global instructions are set in %s.')).replace('%s', `<a href="admin.php?page=dialogawp-ai-settings" target="_blank">${this.escapeEditorHtml(t('AI Settings'))}</a>`)}</p>
                </div>

                <p class="description" style="background:#f0f6ff;border:1px solid #c3d9f7;padding:10px;border-radius:4px;margin-top:12px;">
                    ℹ️ ${this.escapeEditorHtml(t('Make sure you have configured your AI provider in %s before activating this flow.')).replace('%s', `<a href="admin.php?page=dialogawp-ai-settings" target="_blank">${this.escapeEditorHtml(t('AI Settings'))}</a>`)}
                </p>
            `;

            $container.html(html);
        },

        renderRedirectConfig: function($container, config) {
            _log('🔗 renderRedirectConfig called with config:', config);
            const url = config.url || '';
            const target = config.target || '_self';
            const delay = config.delay || 0;
            const message = config.message || '';
            
            _log('  URL:', url);
            _log('  Target:', target);
            _log('  Delay:', delay);
            _log('  Message:', message);
            
            const html = `
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Redirect URL'))} *</label>
                    <input type="url" 
                           class="dialogawp-action-config-field" 
                           data-config-field="url" 
                           value="${this.escapeEditorHtml(url)}" 
                           placeholder="https://example.com/contact" 
                           required />
                    <p class="description">${this.escapeEditorHtml(t('Full URL including https:// where visitors will be redirected.'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Open In'))}</label>
                    <select class="dialogawp-action-config-field" data-config-field="target">
                        <option value="_self" ${target === '_self' ? 'selected' : ''}>${this.escapeEditorHtml(t('Same Window'))}</option>
                        <option value="_blank" ${target === '_blank' ? 'selected' : ''}>${this.escapeEditorHtml(t('New Tab'))}</option>
                    </select>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Delay (seconds)'))}</label>
                    <input type="number" 
                           class="dialogawp-action-config-field" 
                           data-config-field="delay" 
                           value="${this.escapeEditorHtml(delay)}" 
                           min="0" 
                           max="10" 
                           step="1" />
                    <p class="description">${this.escapeEditorHtml(t('Optional delay before redirect (0-10 seconds).'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Message Before Redirect (Optional)'))}</label>
                    <textarea class="dialogawp-action-config-field" 
                              data-config-field="message" 
                              rows="2" 
                              placeholder="${this.escapeEditorHtml(t('Taking you there now...'))}">${this.escapeEditorHtml(message)}</textarea>
                    <p class="description">${this.escapeEditorHtml(t('Optional message shown during the delay period.'))}</p>
                </div>
            `;
            
            _log('✅ About to set HTML');
            $container.html(html);
            _log('✅ HTML set, checking if fields have values...');
            
            setTimeout(() => {
                const urlField = $('.dialogawp-action-config-field[data-config-field="url"]');
                _log('  URL field value after render:', urlField.val());
                _log('  URL field element:', urlField[0]);
            }, 100);
        },

        /**
         * v0.39.4: Link Button — presents a CTA button inside a bot bubble that
         * links to an external URL (booking page, external form, etc.). Distinct
         * from Redirect: Redirect auto-navigates the visitor; Link Button lets
         * them choose to click. Flow ends after button is shown.
         *
         * v0.40.0: `is_conversion` checkbox lets the admin count clicks on this
         * specific button as conversions in the DialogaWP dashboard and (if Pro is
         * active) fire multi-platform conversion tracking (GA4, Google Ads, Meta).
         * Default OFF so existing flows don't retroactively inflate metrics.
         *
         * v0.41.0: added Contact Type dropdown with presets for WhatsApp,
         * Telegram, Facebook Messenger, Instagram DM, SMS, Phone Call, and
         * Email. Choosing a type reveals type-specific fields (phone number,
         * username, etc.) and auto-generates the appropriate button URL
         * (wa.me / t.me / m.me / ig.me / sms: / tel: / mailto:). The custom
         * URL flow is preserved for anything not covered by a preset.
         * The widget renders these exactly like any other Link Button —
         * only the URL construction differs, so all existing tracking
         * (link_clicked events, conversion counting, dataLayer events) works
         * for every channel with zero backend changes.
         */
        renderLinkButtonConfig: function($container, config) {
            const message      = config.message || '';
            const buttonLabel  = config.button_label ?? 'Book Now';
            const buttonUrl    = config.button_url || '';
            const target       = config.target || '_blank';
            const isConversion = !!config.is_conversion;
            const contactType  = config.contact_type || 'custom';
            const waPhone      = config.wa_phone   || '';
            const waMessage    = config.wa_message || '';
            const tgHandle     = config.tg_handle  || '';
            const fbHandle     = config.fb_handle  || '';
            const igHandle     = config.ig_handle  || '';
            const smsPhone     = config.sms_phone  || '';
            const smsBody      = config.sms_body   || '';
            const telPhone     = config.tel_phone  || '';
            const emailTo      = config.email_to      || '';
            const emailSubject = config.email_subject || '';
            const emailBody    = config.email_body    || '';

            const esc = (s) => this.escapeEditorHtml(s);

            const html = `
                <div class="dialogawp-field">
                    <label>${esc(t('Intro Message'))} *</label>
                    <textarea class="dialogawp-action-config-field"
                              data-config-field="message"
                              rows="2"
                              placeholder="${esc(t('Great! Book your session below.'))}">${esc(message)}</textarea>
                    <p class="description">${esc(t('Bot bubble shown above the button.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label>${esc(t('Contact Type'))}</label>
                    <select class="dialogawp-action-config-field dialogawp-link-contact-type" data-config-field="contact_type">
                        <option value="custom"    ${contactType === 'custom'    ? 'selected' : ''}>${esc(t('Custom URL'))}</option>
                        <option value="whatsapp"  ${contactType === 'whatsapp'  ? 'selected' : ''}>🟢 WhatsApp</option>
                        <option value="telegram"  ${contactType === 'telegram'  ? 'selected' : ''}>✈️ Telegram</option>
                        <option value="messenger" ${contactType === 'messenger' ? 'selected' : ''}>💬 Facebook Messenger</option>
                        <option value="instagram" ${contactType === 'instagram' ? 'selected' : ''}>📷 ${esc(t('Instagram DM'))}</option>
                        <option value="sms"       ${contactType === 'sms'       ? 'selected' : ''}>📱 SMS</option>
                        <option value="phone"     ${contactType === 'phone'     ? 'selected' : ''}>📞 ${esc(t('Phone Call'))}</option>
                        <option value="email"     ${contactType === 'email'     ? 'selected' : ''}>📧 ${esc(t('Email'))}</option>
                    </select>
                    <p class="description">${esc(t("Pick a preset for common contact channels; we'll build the correct link format for you."))}</p>
                </div>

                <!-- Custom URL panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-custom" style="display:${contactType === 'custom' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Button URL'))} *</label>
                        <input type="url"
                               class="dialogawp-action-config-field"
                               data-config-field="button_url"
                               value="${esc(buttonUrl)}"
                               placeholder="https://yourbusiness.janeapp.com/..." />
                        <p class="description">${esc(t('Full URL including https:// where the button links.'))}</p>
                    </div>
                </div>

                <!-- WhatsApp panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-whatsapp" style="display:${contactType === 'whatsapp' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('WhatsApp Number'))} *</label>
                        <input type="tel"
                               class="dialogawp-action-config-field"
                               data-config-field="wa_phone"
                               value="${esc(waPhone)}"
                               placeholder="+16105551234" />
                        <p class="description">${esc(t('International format with country code (e.g., %s). Digits only, plus optional leading +.')).replace('%s', '<code>+16105551234</code>')}</p>
                    </div>
                    <div class="dialogawp-field">
                        <label>${esc(t('Pre-filled Message (Optional)'))}</label>
                        <textarea class="dialogawp-action-config-field"
                                  data-config-field="wa_message"
                                  rows="2"
                                  placeholder="${esc(t("Hi! I'd like to book a session."))}">${esc(waMessage)}</textarea>
                        <p class="description">${esc(t('Optional starting message. WhatsApp opens with this text pre-typed, ready to send.'))}</p>
                    </div>
                </div>

                <!-- Telegram panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-telegram" style="display:${contactType === 'telegram' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Telegram Username'))} *</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="tg_handle"
                               value="${esc(tgHandle)}"
                               placeholder="yourbusiness" />
                        <p class="description">${esc(t('Username without the %s (e.g., just %s).')).replace('%s', '<code>@</code>').replace('%s', '<code>yourbusiness</code>')}</p>
                    </div>
                </div>

                <!-- Messenger panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-messenger" style="display:${contactType === 'messenger' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Facebook Page Username'))} *</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="fb_handle"
                               value="${esc(fbHandle)}"
                               placeholder="YourPageName" />
                        <p class="description">${esc(t('Your Facebook Page username (the part after %s).')).replace('%s', '<code>facebook.com/</code>')}</p>
                    </div>
                </div>

                <!-- Instagram panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-instagram" style="display:${contactType === 'instagram' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Instagram Username'))} *</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="ig_handle"
                               value="${esc(igHandle)}"
                               placeholder="yourbusiness" />
                        <p class="description">${esc(t('Instagram handle without %s. Business account recommended for DM support.')).replace('%s', '<code>@</code>')}</p>
                    </div>
                </div>

                <!-- SMS panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-sms" style="display:${contactType === 'sms' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Phone Number'))} *</label>
                        <input type="tel"
                               class="dialogawp-action-config-field"
                               data-config-field="sms_phone"
                               value="${esc(smsPhone)}"
                               placeholder="+16105551234" />
                        <p class="description">${esc(t("Full phone number with country code. Opens the visitor's native SMS app."))}</p>
                    </div>
                    <div class="dialogawp-field">
                        <label>${esc(t('Pre-filled Message (Optional)'))}</label>
                        <textarea class="dialogawp-action-config-field"
                                  data-config-field="sms_body"
                                  rows="2"
                                  placeholder="${esc(t("Hi! I'd like to book a massage."))}">${esc(smsBody)}</textarea>
                        <p class="description">${esc(t('Optional starting text.'))}</p>
                    </div>
                </div>

                <!-- Phone panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-phone" style="display:${contactType === 'phone' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Phone Number'))} *</label>
                        <input type="tel"
                               class="dialogawp-action-config-field"
                               data-config-field="tel_phone"
                               value="${esc(telPhone)}"
                               placeholder="+16105551234" />
                        <p class="description">${esc(t('On mobile, taps to call. On desktop, opens the default phone app (FaceTime, Skype, etc.).'))}</p>
                    </div>
                </div>

                <!-- Email panel -->
                <div class="dialogawp-contact-panel dialogawp-contact-panel-email" style="display:${contactType === 'email' ? 'block' : 'none'};">
                    <div class="dialogawp-field">
                        <label>${esc(t('Email Address'))} *</label>
                        <input type="email"
                               class="dialogawp-action-config-field"
                               data-config-field="email_to"
                               value="${esc(emailTo)}"
                               placeholder="hello@yourbusiness.com" />
                    </div>
                    <div class="dialogawp-field">
                        <label>${esc(t('Subject (Optional)'))}</label>
                        <input type="text"
                               class="dialogawp-action-config-field"
                               data-config-field="email_subject"
                               value="${esc(emailSubject)}"
                               placeholder="${esc(t('Booking inquiry'))}" />
                    </div>
                    <div class="dialogawp-field">
                        <label>${esc(t('Body (Optional)'))}</label>
                        <textarea class="dialogawp-action-config-field"
                                  data-config-field="email_body"
                                  rows="3"
                                  placeholder="${esc(t("Hi, I'd like to book a session."))}">${esc(emailBody)}</textarea>
                    </div>
                </div>

                <div class="dialogawp-field">
                    <label>${esc(t('Button Label'))} *</label>
                    <input type="text"
                           class="dialogawp-action-config-field"
                           data-config-field="button_label"
                           value="${esc(buttonLabel)}"
                           placeholder="${esc(t('Book Massage'))}" />
                    <p class="description">${esc(t('Text shown on the CTA button.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label>${esc(t('Open In'))}</label>
                    <select class="dialogawp-action-config-field" data-config-field="target">
                        <option value="_blank" ${target === '_blank' ? 'selected' : ''}>${esc(t('New Tab (recommended)'))}</option>
                        <option value="_self"  ${target === '_self'  ? 'selected' : ''}>${esc(t('Same Window'))}</option>
                    </select>
                    <p class="description">${esc(t('New Tab keeps the chat open so visitors can return.'))}</p>
                </div>

                <div class="dialogawp-field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox"
                               class="dialogawp-action-config-field"
                               data-config-field="is_conversion"
                               data-config-type="bool"
                               ${isConversion ? 'checked' : ''} />
                        <span>${esc(t('Count as conversion'))}</span>
                    </label>
                    <p class="description">
                        ${esc(t('Clicks on this button will count toward your Conversions metric on the dashboard and fire the %s dataLayer event for GTM / GA4 tracking. Enable for high-intent CTAs like booking links; leave off for social or resource links.')).replace('%s', '<code>dialogawp_conversion</code>')}
                    </p>
                </div>
            `;

            $container.html(html);

            // v0.41.0 — toggle panels when contact_type changes. Default
            // button label auto-suggested when switching to a channel that
            // has no label yet OR still has the previous channel's default.
            setTimeout(function () {
                const $sel = $container.find('.dialogawp-link-contact-type');
                const defaults = {
                    'custom':    'Book Now',
                    'whatsapp':  'Chat on WhatsApp',
                    'telegram':  'Message on Telegram',
                    'messenger': 'Chat on Messenger',
                    'instagram': 'DM on Instagram',
                    'sms':       'Send us a Text',
                    'phone':     'Call Us',
                    'email':     'Email Us',
                };
                $sel.on('change', function () {
                    const type = $(this).val();
                    $container.find('.dialogawp-contact-panel').hide();
                    $container.find('.dialogawp-contact-panel-' + type).show();

                    // If the label is one of the known defaults, upgrade it
                    // to the new channel's default (so switching from
                    // WhatsApp → Telegram gives "Message on Telegram"). If
                    // the admin has customized it, leave it alone.
                    const $label = $container.find('input[data-config-field="button_label"]');
                    const cur = $label.val();
                    for (const key in defaults) {
                        if (cur === defaults[key]) {
                            $label.val(defaults[type]);
                            break;
                        }
                    }
                });
            }, 0);
        },

        renderSendEmailConfig: function($container, config) {
            const to = config.to || '';
            const subject = config.subject ?? 'New Lead from DIALOGAWP';
            const body = config.body ?? 'Name: {user_name}\nEmail: {user_email}\nPhone: {user_phone}';
            const cc = config.cc || '';
            const bcc = config.bcc || '';
            
            const html = `
                <div class="dialogawp-notice" style="margin-bottom: 20px;">
                    <p>${this.escapeEditorHtml(t("Email is sent with WordPress's built-in mailer. If your host blocks outgoing mail, install an SMTP plugin so these notifications deliver reliably."))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Recipient Email'))} *</label>
                    <input type="email" 
                           class="dialogawp-action-config-field" 
                           data-config-field="to" 
                           value="${this.escapeEditorHtml(to)}" 
                           placeholder="sales@example.com" 
                           required />
                    <p class="description">${this.escapeEditorHtml(t('Where to send the notification email.'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Subject Line'))}</label>
                    <input type="text" 
                           class="dialogawp-action-config-field" 
                           data-config-field="subject" 
                           value="${this.escapeEditorHtml(subject)}" 
                           placeholder="${this.escapeEditorHtml(t('New Lead from DIALOGAWP'))}" />
                    <p class="description">${this.escapeEditorHtml(t('Use variables like {user_name}, {user_email}, {user_phone}.'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Email Body'))}</label>
                    <textarea class="dialogawp-action-config-field" 
                              data-config-field="body" 
                              rows="6" 
                              placeholder="${this.escapeEditorHtml(t('Name: {user_name}...'))}">${this.escapeEditorHtml(body)}</textarea>
                    <p class="description">${this.escapeEditorHtml(t('Email content. Use {variable_name} for captured data.'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('CC (Optional)'))}</label>
                    <input type="text" 
                           class="dialogawp-action-config-field" 
                           data-config-field="cc" 
                           value="${this.escapeEditorHtml(cc)}" 
                           placeholder="manager@example.com, team@example.com" />
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('BCC (Optional)'))}</label>
                    <input type="text" 
                           class="dialogawp-action-config-field" 
                           data-config-field="bcc" 
                           value="${this.escapeEditorHtml(bcc)}" 
                           placeholder="archive@example.com" />
                </div>
            `;
            
            $container.html(html);
        },
        
        renderSetVariableConfig: function($container, config) {
            const variableName = config.variable_name || '';
            const variableValue = config.variable_value ?? '';
            const valueType = config.value_type || 'static';
            
            const html = `
                <div class="dialogawp-notice" style="margin-bottom: 20px;">
                    <p>${this.escapeEditorHtml(t('Store a value here, then reference it later in any message with %s.')).replace('%s', '<code>{{variable_name}}</code>')}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Variable Name'))} *</label>
                    <input type="text" 
                           class="dialogawp-action-config-field" 
                           data-config-field="variable_name" 
                           value="${this.escapeEditorHtml(variableName)}" 
                           placeholder="user_score" 
                           pattern="[a-z_]+" 
                           required />
                    <p class="description">${this.escapeEditorHtml(t('Use lowercase letters and underscores only (e.g., user_score, interest_level).'))}</p>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Value Type'))}</label>
                    <select class="dialogawp-action-config-field" data-config-field="value_type">
                        <option value="static" ${valueType === 'static' ? 'selected' : ''}>${this.escapeEditorHtml(t('Static Value'))}</option>
                        <option value="reference" ${valueType === 'reference' ? 'selected' : ''}>${this.escapeEditorHtml(t('Reference Previous Answer'))}</option>
                    </select>
                </div>
                
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Variable Value'))} *</label>
                    <input type="text" 
                           class="dialogawp-action-config-field" 
                           data-config-field="variable_value" 
                           value="${this.escapeEditorHtml(variableValue)}" 
                           placeholder="${this.escapeEditorHtml(t('10 or {previous_answer}'))}" 
                           required />
                    <p class="description">${this.escapeEditorHtml(t('Static value (e.g., "10") or reference like {previous_answer}.'))}</p>
                </div>
                
                <div class="dialogawp-notice" style="margin-top: 15px;">
                    <p><strong>💡 ${this.escapeEditorHtml(t('Use Case:'))}</strong> ${this.escapeEditorHtml(t('Store values to use in Branch conditions later. Example: Set "score" based on answers, then branch if score > 50.'))}</p>
                </div>
            `;
            
            $container.html(html);
        },

        renderTagLeadConfig: function($container, config) {
            const tags = config.tags || [];
            const tagsStr = Array.isArray(tags) ? tags.join(', ') : '';

            const html = `
                <div class="dialogawp-field">
                    <label>${this.escapeEditorHtml(t('Tags to Apply'))}</label>
                    <input type="text"
                           class="dialogawp-action-config-field"
                           data-config-field="tags_input"
                           value="${this.escapeEditorHtml(tagsStr)}"
                           placeholder="hot-lead, contacted, vip" />
                    <p class="description">${this.escapeEditorHtml(t('Comma-separated tags to apply when this node is reached (e.g., %s).')).replace('%s', '<code>hot-lead, insurance</code>')}</p>
                </div>

                <div class="dialogawp-notice" style="margin-top: 15px;">
                    <p><strong>💡 ${this.escapeEditorHtml(t('How it works:'))}</strong> ${this.escapeEditorHtml(t('Tags are applied to the lead when it is captured later in the flow. Place this node %s a Capture Lead node. You can use multiple Tag Lead nodes to accumulate tags.')).replace('%s', `<em>${this.escapeEditorHtml(t('before'))}</em>`)}</p>
                </div>
            `;

            $container.html(html);
        },

        saveNodeEdits: function(silent) {
            if (!this.selectedNode) return;

            $('.dialogawp-node-field').each((index, field) => {
                const $field = $(field);
                const fieldName = $field.data('field');
                let value = $field.val();
                
                if ($field.is(':checkbox')) {
                    value = $field.is(':checked');
                }
                
                this.selectedNode.data[fieldName] = value;
            });
            
            const nodeType = this.selectedNode.data?.nodeType || this.selectedNode.type;
            
            if (nodeType === 'question') {
                const options = [];
                $('.dialogawp-option-item').each(function() {
                    const $item = $(this);
                    const optionId = $item.data('option-id') || 'opt_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const label = $item.find('.dialogawp-option-label').val();
                    if (label) {
                        options.push({
                            id: optionId,
                            label: label
                        });
                    }
                });
                this.selectedNode.data.options = options;

                // Free-form text on Question nodes has been removed in favor of AI Chat.
                // Strip legacy fields when saving so old nodes clean themselves up.
                delete this.selectedNode.data.allow_text_input;
                delete this.selectedNode.data.text_placeholder;
            }
            
            if (nodeType === 'output') {
                const buttons = [];
                $('.dialogawp-option-item').each(function() {
                    const $item = $(this);
                    const buttonId = $item.data('button-id') || 'btn_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const label = $item.find('.dialogawp-button-label').val();
                    const action = $item.find('.dialogawp-button-action').val();
                    const url = $item.find('.dialogawp-button-url').val();
                    if (label) {
                        buttons.push({
                            id: buttonId,
                            label: label,
                            action: action,
                            url: url
                        });
                    }
                });
                this.selectedNode.data.buttons = buttons;
            }
            
            if (nodeType === 'branch') {
                const outputGroups = [];
                $('.dialogawp-output-group').each(function() {
                    const $group = $(this);
                    const groupId = $group.data('group-id');
                    const logic = $group.find('.dialogawp-group-logic').val() || 'and';
                    const conditions = [];
                    
                    $group.find('.dialogawp-condition-item').each(function() {
                        const $cond = $(this);
                        const operator = $cond.find('.dialogawp-condition-operator').val();
                        const value = $cond.find('.dialogawp-condition-value').val();
                        if (value) {
                            conditions.push({
                                id: $cond.data('condition-id') || 'cond_' + Date.now(),
                                operator: operator,
                                value: value
                            });
                        }
                    });
                    
                    if (conditions.length > 0) {
                        outputGroups.push({
                            id: groupId,
                            label: 'Output ' + (outputGroups.length + 1),
                            conditions: conditions,
                            logic: logic
                        });
                    }
                });
                this.selectedNode.data.output_groups = outputGroups;
                
                // Update label
                if (outputGroups.length > 0) {
                    const labels = outputGroups.map(g => {
                        const vals = g.conditions.map(c => c.value).join(' ' + g.logic.toUpperCase() + ' ');
                        return vals;
                    }).join(' | ');
                    this.selectedNode.data.label = labels || 'Branch Logic';
                }
            }
            
            // Collect action configuration if this is an action node
            if (nodeType === 'action') {
                const actionType = $('#dialogawp-action-type').val();
                _log('💾 Saving action node:', actionType);
                this.selectedNode.data.action_type = actionType;
                
                if (!this.selectedNode.data.config) {
                    this.selectedNode.data.config = {};
                }
                
                if (actionType === 'capture_lead') {
                    const fields = {};
                    $('.dialogawp-capture-field-row').each(function() {
                        const $row = $(this);
                        const fieldKey = $row.data('field');
                        const enabled = $row.find('.dialogawp-field-enable').is(':checked');
                        const label = $row.find('.dialogawp-field-label').val();
                        const required = $row.find('.dialogawp-field-required').is(':checked');

                        fields[fieldKey] = { enabled, label, required };
                    });

                    this.selectedNode.data.config.fields = fields;
                    this.selectedNode.data.config.success_message =
                        $('.dialogawp-action-config-field[data-config-field="success_message"]').val();
                    this.selectedNode.data.config.redirect_url =
                        $('.dialogawp-action-config-field[data-config-field="redirect_url"]').val();

                    // v0.39.11 — email notification overrides
                    this.selectedNode.data.config.override_email_settings =
                        $('#dialogawp-override-email-toggle').is(':checked');
                    this.selectedNode.data.config.email_recipients =
                        $('.dialogawp-action-config-field[data-config-field="email_recipients"]').val() || '';
                    this.selectedNode.data.config.email_subject =
                        $('.dialogawp-action-config-field[data-config-field="email_subject"]').val() || '';
                    this.selectedNode.data.config.email_intro =
                        $('.dialogawp-action-config-field[data-config-field="email_intro"]').val() || '';

                } else {
                    // For redirect, send_email, set_variable - collect all config fields
                    _log('💾 Collecting config fields for:', actionType);
                    
                    const self = this; // Save reference to DIALOGAWPBuilder
                    
                    // CRITICAL: Scope selector to ONLY the editor modal to avoid picking up random objects
                    const $fields = $('#dialogawp-node-editor-content').find('input.dialogawp-action-config-field[data-config-field], select.dialogawp-action-config-field[data-config-field], textarea.dialogawp-action-config-field[data-config-field]');
                    
                    _log('🔍 Found', $fields.length, 'fields');
                    
                    $fields.each(function(index) {
                        // 'this' is the DOM element (DON'T use .bind!)
                        const element = this;
                        const $field = $(element);

                        // Use .attr() to get field name
                        const fieldName = $field.attr('data-config-field');

                        // v0.40.0 — checkboxes stored as booleans, not their string
                        // `value` attribute. Link Button's is_conversion flag is the
                        // first checkbox to travel this generic save path; without
                        // this branch it would always save as the literal "on".
                        let value;
                        if ($field.attr('type') === 'checkbox') {
                            value = !!element.checked;
                        } else {
                            value = element.value !== undefined ? element.value : '';
                            if ($field.attr('type') === 'number') {
                                value = parseFloat(value) || 0;
                            }
                        }

                        _log('  📝 Field:', fieldName, '=', value);

                        // Use 'self' to access DIALOGAWPBuilder
                        self.selectedNode.data.config[fieldName] = value;
                    });
                    
                    // Convert tags_input comma string to tags array for tag_lead
                    if (actionType === 'tag_lead' && this.selectedNode.data.config.tags_input) {
                        this.selectedNode.data.config.tags = this.selectedNode.data.config.tags_input
                            .split(',')
                            .map(t => t.trim().toLowerCase().replace(/[^a-z0-9\-_]/g, ''))
                            .filter(t => t.length > 0);
                        delete this.selectedNode.data.config.tags_input;
                    }

                    // v0.41.0 — Link Button contact-type presets. Build the
                    // final `button_url` from the type-specific fields the
                    // admin filled in, using each channel's URL scheme. The
                    // widget then renders it as any other Link Button —
                    // no runtime branching needed there. Empty preset fields
                    // fall back to whatever's in button_url (custom mode).
                    if (actionType === 'link_button') {
                        const cfg = this.selectedNode.data.config;
                        const ct  = cfg.contact_type || 'custom';
                        const digits = function (s) {
                            // Keep only 0-9 (WhatsApp requires no + or spaces
                            // in the wa.me URL; tel: is forgiving; sms: too).
                            return String(s || '').replace(/[^0-9]/g, '');
                        };
                        const enc = encodeURIComponent;
                        let built = '';
                        switch (ct) {
                            case 'whatsapp': {
                                const p = digits(cfg.wa_phone);
                                if (p) {
                                    built = 'https://wa.me/' + p;
                                    if (cfg.wa_message) built += '?text=' + enc(cfg.wa_message);
                                }
                                break;
                            }
                            case 'telegram': {
                                const h = String(cfg.tg_handle || '').replace(/^@/, '').trim();
                                if (h) built = 'https://t.me/' + enc(h);
                                break;
                            }
                            case 'messenger': {
                                const h = String(cfg.fb_handle || '').trim();
                                if (h) built = 'https://m.me/' + enc(h);
                                break;
                            }
                            case 'instagram': {
                                const h = String(cfg.ig_handle || '').replace(/^@/, '').trim();
                                if (h) built = 'https://ig.me/m/' + enc(h);
                                break;
                            }
                            case 'sms': {
                                const p = digits(cfg.sms_phone);
                                if (p) {
                                    built = 'sms:+' + p;
                                    if (cfg.sms_body) built += '?body=' + enc(cfg.sms_body);
                                }
                                break;
                            }
                            case 'phone': {
                                const p = digits(cfg.tel_phone);
                                if (p) built = 'tel:+' + p;
                                break;
                            }
                            case 'email': {
                                const to = String(cfg.email_to || '').trim();
                                if (to) {
                                    built = 'mailto:' + to;
                                    const params = [];
                                    if (cfg.email_subject) params.push('subject=' + enc(cfg.email_subject));
                                    if (cfg.email_body)    params.push('body=' + enc(cfg.email_body));
                                    if (params.length)     built += '?' + params.join('&');
                                }
                                break;
                            }
                            case 'custom':
                            default:
                                // Leave button_url as-is from the input field.
                                break;
                        }
                        // Only overwrite button_url when a preset actually
                        // produced one. Custom URL mode leaves it untouched.
                        if (ct !== 'custom' && built) {
                            cfg.button_url = built;
                        }
                    }

                    _log('✅ Saved config:', this.selectedNode.data.config);
                }

                const labelMap = {
                    'capture_lead': 'Capture Lead',
                    'redirect': (() => {
                        try {
                            if (this.selectedNode.data.config.url) {
                                const hostname = new URL(this.selectedNode.data.config.url).hostname;
                                return 'Redirect → ' + hostname;
                            }
                        } catch(e) {
                            // Invalid URL
                        }
                        return 'Redirect (URL needed)';
                    })(),
                    'link_button': (() => {
                        // v0.41.0 — use the channel-specific emoji as the
                        // node badge when a contact-type preset is picked,
                        // so the canvas glance shows "🟢 Chat on WhatsApp"
                        // rather than a generic 🔗 for every channel.
                        const cfg = this.selectedNode.data.config || {};
                        const lbl = cfg.button_label;
                        const iconByType = {
                            'whatsapp':  '🟢',
                            'telegram':  '✈️',
                            'messenger': '💬',
                            'instagram': '📷',
                            'sms':       '📱',
                            'phone':     '📞',
                            'email':     '📧',
                        };
                        const icon = iconByType[cfg.contact_type] || '🔗';
                        if (lbl) return icon + ' ' + lbl;
                        return 'Link Button (label needed)';
                    })(),
                    'send_email': 'Email: ' + (this.selectedNode.data.config.to || 'Not configured'),
                    'set_variable': 'Set ' + (this.selectedNode.data.config.variable_name || 'variable'),
                    'tag_lead': 'Tag: ' + ((this.selectedNode.data.config.tags || []).join(', ') || 'No tags')
                };
                this.selectedNode.data.label = labelMap[actionType] || 'Action';
                _log('✅ Action node label:', this.selectedNode.data.label);
            }

            if (nodeType === 'ai_chat') {
                if (!this.selectedNode.data.config) this.selectedNode.data.config = {};
                this.selectedNode.data.config.greeting          = $('#ai-chat-greeting').val();
                this.selectedNode.data.config.placeholder       = $('#ai-chat-placeholder').val();
                this.selectedNode.data.config.instructions      = $('#ai-chat-instructions').val();
                this.selectedNode.data.config.auto_extract_lead = $('#ai-chat-auto-extract').is(':checked');
                this.selectedNode.data.config.lead_fields       = {
                    name:  $('#ai-chat-field-name').is(':checked'),
                    email: $('#ai-chat-field-email').is(':checked'),
                    phone: $('#ai-chat-field-phone').is(':checked')
                };
                this.selectedNode.data.label = 'AI Chat';
            }

            if (nodeType === 'loop') {
                // Loop target is now derived from the connected wire (no manual selection)
                // We still update target_node_id for backward compat with the flow engine
                const loopEdge = this.edges.find(e => e.source === this.selectedNode.id);
                const targetId = loopEdge ? loopEdge.target : '';
                this.selectedNode.data.target_node_id = targetId;

                const targetNode = this.nodes.find(n => n.id === targetId);
                const targetLabel = targetNode ? (targetNode.data.message || targetNode.data.label || 'node') : '(none)';
                this.selectedNode.data.label = '↩ ' + targetLabel;

                // UI shows seconds; backend stores milliseconds.
                const delaySecondsInput = parseFloat($('#dialogawp-loop-delay').val()) || 0;
                this.selectedNode.data.delay_ms = Math.round(delaySecondsInput * 1000);
                this.selectedNode.data.message = $('#dialogawp-loop-message').val() || '';
            }

            if (nodeType === 'webhook') {
                const connectorId = $('#dialogawp-webhook-connector').val() || '';
                this.selectedNode.data.config = {
                    connector_id:      connectorId,
                    method:            $('#dialogawp-webhook-method').val() || 'POST',
                    include_lead_data: $('#dialogawp-webhook-include-lead').is(':checked'),
                    include_flow_data: $('#dialogawp-webhook-include-flow').is(':checked'),
                    custom_headers:    $('#dialogawp-webhook-headers').val() || ''
                };

                // Label the node by the selected connector name for quick scanning on the canvas
                const connectors = (window.dialogawpProConnectors && window.dialogawpProConnectors.connectors) || [];
                const match = connectors.find((c) => c.id === connectorId);
                this.selectedNode.data.label = match ? ('⚡ ' + match.name) : '⚡ Webhook';
            }

            // Update label based on node type and mode
            if (nodeType === 'message' || nodeType === 'question') {
                const message = this.selectedNode.data.message || this.selectedNode.data.question || '';
                const autoContinue = this.selectedNode.data.auto_continue;
                const allowText = this.selectedNode.data.allow_text_input;
                const hasButtons = this.selectedNode.data.options && this.selectedNode.data.options.length > 0;
                
                // Add mode indicator to label
                let prefix = '';
                if (autoContinue) {
                    prefix = '💬 '; // Display only
                } else if (allowText && hasButtons) {
                    prefix = '✍️💬 '; // Text + buttons
                } else if (allowText) {
                    prefix = '✍️ '; // Text input only
                } else if (hasButtons) {
                    prefix = '🔘 '; // Buttons only
                }
                
                this.selectedNode.data.label = prefix + this.truncate(message, 30);
            } else if (nodeType === 'output') {
                this.selectedNode.data.label = this.truncate(this.selectedNode.data.output_message, 30);
            }
            
            if (!silent) {
                $('#dialogawp-node-editor').hide();
            }

            // Skip the React getFlow/setFlow round-trip on silent saves —
            // React may still be mid-update from a sibling call (e.g. addNodeAtPosition),
            // and reading getFlow too early returns stale state that wipes new nodes.
            if (!silent) {
                if (window.dialogawpReactInstance) {
                    const currentFlow = window.dialogawpReactInstance.getFlow();
                    if (currentFlow.nodes) {
                        const updatedNode = this.selectedNode;
                        this.nodes = currentFlow.nodes.map(node =>
                            node.id === updatedNode.id
                                ? { ...node, data: { ...updatedNode.data } }
                                : node
                        );
                    }
                    if (currentFlow.edges) {
                        this.edges = currentFlow.edges;
                    }

                    window.dialogawpReactInstance.setFlow({
                        nodes: this.nodes,
                        edges: this.edges
                    });
                }
            } else {
                // Silent save: push only the updated selectedNode data into React
                // so the canvas label updates, without resyncing the whole node list.
                if (window.dialogawpReactInstance && this.selectedNode) {
                    const updatedNode = this.selectedNode;
                    const nodesWithUpdate = this.nodes.map(n =>
                        n.id === updatedNode.id ? updatedNode : n
                    );
                    this.nodes = nodesWithUpdate;
                    window.dialogawpReactInstance.setFlow({
                        nodes: this.nodes,
                        edges: this.edges
                    });
                }
            }

            this.markDirty();
        },

        addQuestionOption: function() {
            _log('➕ Add Option clicked');
            if (!this.selectedNode) {
                _log('❌ No selected node');
                return;
            }
            
            const nodeType = this.selectedNode.data?.nodeType || this.selectedNode.type;
            _log('📋 Node type:', nodeType);
            
            if (nodeType !== 'question') {
                _log('❌ Not a question node');
                return;
            }
            
            // CRITICAL: Collect current values before re-rendering
            const currentOptions = [];
            $('.dialogawp-option-item').each(function() {
                const $item = $(this);
                const optionId = $item.data('option-id');
                const label = $item.find('.dialogawp-option-label').val();
                if (optionId) {
                    currentOptions.push({
                        id: optionId,
                        label: label || ''
                    });
                }
            });
            
            // Update even when every option was removed, so deleted rows do not reappear.
            this.selectedNode.data.options = currentOptions;
            
            const newOption = {
                id: 'opt_' + Date.now(),
                label: ''
            };
            
            if (!this.selectedNode.data.options) {
                this.selectedNode.data.options = [];
            }
            
            this.selectedNode.data.options.push(newOption);
            this.renderQuestionOptions(this.selectedNode.data.options);
        },
        
        addBranchCondition: function() {
            _log('➕ Add Condition clicked');
            if (!this.selectedNode) {
                _log('❌ No selected node');
                return;
            }
            
            const nodeType = this.selectedNode.data?.nodeType || this.selectedNode.type;
            _log('📋 Node type:', nodeType);
            
            if (nodeType !== 'branch') {
                _log('❌ Not a branch node');
                return;
            }
            
            const currentConditions = [];
            $('.dialogawp-condition-item').each(function() {
                const $item = $(this);
                const conditionId = $item.data('condition-id');
                const operator = $item.find('.dialogawp-condition-operator').val();
                const value = $item.find('.dialogawp-condition-value').val();
                const logic = $item.find('.dialogawp-condition-logic').val() || 'and';
                currentConditions.push({
                    id: conditionId,
                    operator: operator,
                    value: value,
                    logic: logic
                });
            });
            
            if (currentConditions.length > 0) {
                this.selectedNode.data.conditions = currentConditions;
            }
            
            const newCondition = {
                id: 'cond_' + Date.now(),
                operator: 'equals',
                value: '',
                logic: 'and'
            };
            
            if (!this.selectedNode.data.conditions) {
                this.selectedNode.data.conditions = [];
            }
            
            this.selectedNode.data.conditions.push(newCondition);
            _log('✅ Added condition, total:', this.selectedNode.data.conditions.length);
            this.renderBranchConditions(this.selectedNode.data.conditions);
        },
        
        addOutputGroup: function() {
            _log('➕ Add Output Group clicked');
            if (!this.selectedNode) return;
            
            const nodeType = this.selectedNode.data?.nodeType || this.selectedNode.type;
            if (nodeType !== 'branch') return;
            
            // CRITICAL: Collect current values from form before re-rendering
            const currentGroups = [];
            $('.dialogawp-output-group').each(function() {
                const $group = $(this);
                const groupId = $group.data('group-id');
                const logic = $group.find('.dialogawp-group-logic').val() || 'and';
                const conditions = [];
                
                $group.find('.dialogawp-condition-item').each(function() {
                    const $cond = $(this);
                    const condId = $cond.data('condition-id');
                    const operator = $cond.find('.dialogawp-condition-operator').val();
                    const value = $cond.find('.dialogawp-condition-value').val();
                    
                    conditions.push({
                        id: condId || 'cond_' + Date.now(),
                        operator: operator,
                        value: value
                    });
                });
                
                currentGroups.push({
                    id: groupId,
                    label: 'Output ' + (currentGroups.length + 1),
                    conditions: conditions.length > 0 ? conditions : [{ id: 'cond_' + Date.now(), operator: 'equals', value: '' }],
                    logic: logic
                });
            });
            
            // Update node data with collected values
            if (currentGroups.length > 0) {
                this.selectedNode.data.output_groups = currentGroups;
            }
            
            // Add new group
            const newGroup = {
                id: 'group_' + Date.now(),
                label: 'Output ' + ((this.selectedNode.data.output_groups?.length || 0) + 1),
                conditions: [
                    { id: 'cond_' + Date.now(), operator: 'equals', value: '' }
                ],
                logic: 'and'
            };
            
            if (!this.selectedNode.data.output_groups) {
                this.selectedNode.data.output_groups = [];
            }
            
            this.selectedNode.data.output_groups.push(newGroup);
            this.renderBranchGroups(this.selectedNode.data.output_groups);
        },
        
        addOutputButton: function() {
            _log('➕ Add Button clicked');
            if (!this.selectedNode) {
                _log('❌ No selected node');
                return;
            }
            
            const nodeType = this.selectedNode.data?.nodeType || this.selectedNode.type;
            _log('📋 Node type:', nodeType);
            
            if (nodeType !== 'output') {
                _log('❌ Not an output node');
                return;
            }
            
            // CRITICAL: Collect current values before re-rendering
            const currentButtons = [];
            $('.dialogawp-option-item').each(function() {
                const $item = $(this);
                const buttonId = $item.data('button-id');
                const label = $item.find('.dialogawp-button-label').val();
                const action = $item.find('.dialogawp-button-action').val();
                const url = $item.find('.dialogawp-button-url').val();
                if (buttonId) {
                    currentButtons.push({
                        id: buttonId,
                        label: label || '',
                        action: action || 'link',
                        url: url || ''
                    });
                }
            });
            
            // Update with collected values
            if (currentButtons.length > 0) {
                this.selectedNode.data.buttons = currentButtons;
            }
            
            const newButton = {
                id: 'btn_' + Date.now(),
                label: '',
                action: 'link',
                url: ''
            };
            
            if (!this.selectedNode.data.buttons) {
                this.selectedNode.data.buttons = [];
            }
            
            this.selectedNode.data.buttons.push(newButton);
            this.renderOutputButtons(this.selectedNode.data.buttons);
        },
        
        deleteNode: function() {
            if (!this.selectedNode) return;
            
            var self = this;
            dialogawpModal.confirm(t('Are you sure you want to delete this node?'), function() {
                if (window.dialogawpReactInstance) {
                    const currentFlow = window.dialogawpReactInstance.getFlow();
                    if (currentFlow.nodes) {
                        self.nodes = currentFlow.nodes;
                    }
                    if (currentFlow.edges) {
                        self.edges = currentFlow.edges;
                    }
                }

                self.nodes = self.nodes.filter(n => n.id !== self.selectedNode.id);
                self.edges = self.edges.filter(e =>
                    e.source !== self.selectedNode.id && e.target !== self.selectedNode.id
                );

                $('#dialogawp-node-editor').hide();
                self.selectedNode = null;

                if (window.dialogawpReactInstance) {
                    window.dialogawpReactInstance.setFlow({
                        nodes: self.nodes,
                        edges: self.edges
                    });
                }
                self.markDirty();
            }, { title: t('Delete Node'), okText: t('Delete'), danger: true });
        },
        
        selectNodeById: function(nodeId) {
            const node = this.nodes.find(n => n.id === nodeId);
            if (node) {
                this.selectNode(node);
            }
        },
        
        saveFlow: function() {
            const flowName = $('#dialogawp-flow-name').val();
            const isActive = $('#dialogawp-flow-active').is(':checked');
            const visibilityMode = $('#dialogawp-flow-visibility').val();
            
            // Get selected roles
            const visibleRoles = [];
            $('.dialogawp-role-checkbox:checked').each(function() {
                visibleRoles.push($(this).val());
            });
            
            if (!flowName || flowName.trim() === '') {
                dialogawpModal.error(t('Please enter a flow name!'));
                return;
            }
            
            // Check for visibility conflicts if activating
            if (isActive) {
                this.checkVisibilityConflict(visibilityMode, visibleRoles, () => {
                    this.performSave(flowName, isActive, visibilityMode, visibleRoles);
                });
            } else {
                this.performSave(flowName, isActive, visibilityMode, visibleRoles);
            }
        },
        
        checkVisibilityConflict: function(visibilityMode, visibleRoles, callback) {
            const self = this;
            
            // Get all flows to check for conflicts
            $.ajax({
                url: dialogawpAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialogawp_get_flows',
                    nonce: dialogawpAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const activeFlows = response.data.filter(f => 
                            parseInt(f.is_active) === 1 && 
                            parseInt(f.id) !== parseInt(self.currentFlowId)
                        );
                        
                        // Check for visibility conflicts
                        const conflicts = activeFlows.filter(f => {
                            const flowVisibility = f.visibility_mode || 'everyone';
                            
                            if (flowVisibility !== visibilityMode) {
                                return false; // Different modes, no conflict
                            }
                            
                            if (visibilityMode === 'specific_roles') {
                                const flowRoles = f.visible_roles ? JSON.parse(f.visible_roles) : [];
                                const rolesMatch = JSON.stringify(flowRoles.sort()) === JSON.stringify(visibleRoles.sort());
                                return rolesMatch;
                            }
                            
                            return true; // Same visibility mode
                        });
                        
                        if (conflicts.length > 0) {
                            const conflictNames = conflicts.map(f => f.flow_name).join(', ');
                            dialogawpModal.confirm(
                                t('Another active flow "%s" has the same visibility. Visitors will see the newest flow. Continue anyway?').replace('%s', () => conflictNames),
                                function() { callback(); },
                                { title: t('Visibility Conflict'), okText: t('Continue'), cancelText: t('Go Back'), icon: '\u26A0\uFE0F' }
                            );
                        } else {
                            callback();
                        }
                    }
                }
            });
        },
        
        performSave: function(flowName, isActive, visibilityMode, visibleRoles) {
            if (window.dialogawpReactInstance) {
                const flow = window.dialogawpReactInstance.getFlow();
                this.nodes = flow.nodes;
                this.edges = flow.edges;
            }
            
            _log('💾 Saving flow:', flowName);
            _log('📊 Nodes:', this.nodes.length, 'nodes');
            _log('🔗 Edges:', this.edges.length, 'edges');
            
            // CRITICAL: Deduplicate nodes by ID before saving (keep first occurrence)
            const seenIds = new Set();
            const uniqueNodes = [];
            this.nodes.forEach(node => {
                if (!seenIds.has(node.id)) {
                    seenIds.add(node.id);
                    uniqueNodes.push(node);
                } else {
                    _warn('⚠️ Duplicate node ID found and removed before save:', node.id);
                }
            });
            this.nodes = uniqueNodes;
            
            // VALIDATION: Check for start node
            const hasStartNode = this.nodes.some(n => n.data.is_start);
            if (!hasStartNode && this.nodes.length > 0) {
                _warn('⚠️ No start node found! Marking first node as start.');
                this.nodes[0].data.is_start = true;
            }
            
            // VALIDATION: Check for empty flow
            if (this.nodes.length === 0) {
                dialogawpModal.error(t('Cannot save empty flow! Please add at least one node.'));
                return;
            }
            
            _log('🔍 Full nodes data:', JSON.stringify(this.nodes, null, 2));
            _log('🔍 Full edges data:', JSON.stringify(this.edges, null, 2));
            _log('📍 Visibility:', visibilityMode, visibleRoles);
            
            const flowDataObj = {
                nodes: this.nodes,
                edges: this.edges
            };
            
            const flowDataJSON = JSON.stringify(flowDataObj);
            _log('📦 Flow data JSON length:', flowDataJSON.length, 'characters');
            
            const formData = new FormData();
            formData.append('action', 'dialogawp_save_flow');
            formData.append('nonce', dialogawpAdmin.nonce);
            formData.append('flow_id', this.currentFlowId || 0);
            formData.append('flow_name', flowName);
            formData.append('is_active', isActive ? 1 : 0);
            formData.append('visibility_mode', visibilityMode);
            formData.append('visible_roles', JSON.stringify(visibleRoles));
            formData.append('flow_data', flowDataJSON);

            // Webhook connector — Pro only. Dropdown won't exist for free, so default to empty.

            // Show loading state on save button + current flow card in sidebar
            const $saveBtn = $('#dialogawp-save-flow');
            const originalBtnHtml = $saveBtn.html();
            $saveBtn.prop('disabled', true).text(isActive ? t('Saving & activating…') : t('Saving…'));

            // Loading spinner on the active flow card in the sidebar
            if (this.currentFlowId) {
                $(`.dialogawp-flow-item[data-flow-id="${this.currentFlowId}"]`).addClass('loading');
            }

            const self = this;

            fetch(dialogawpAdmin.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(response => {
                _log('✅ Save response:', response);
                $saveBtn.prop('disabled', false).html(originalBtnHtml);
                if (response.success) {
                    self.currentFlowId = response.data.flow_id;
                    _log('✅ Flow saved successfully! ID:', response.data.flow_id);

                    // Reset dirty state
                    self.isDirty = false;
                    self.lastSnapshot = self.getCanvasSnapshot();
                    self.updateSaveStatus('saved');
                    if (self.autoSaveTimer) clearTimeout(self.autoSaveTimer);

                    // Start change detection if not already started
                    if (!self.changeDetectionTimer) self.startChangeDetection();

                    dialogawpModal.success(t('Flow saved successfully!'));
                    self.loadFlows();
                } else {
                    _error('❌ Save failed:', response);
                    $(`.dialogawp-flow-item`).removeClass('loading');
                    dialogawpModal.error(t('Error saving flow: %s').replace('%s', response.data || t('Unknown error')));
                }
            })
            .catch(error => {
                _error('❌ AJAX Error:', error);
                $saveBtn.prop('disabled', false).html(originalBtnHtml);
                $(`.dialogawp-flow-item`).removeClass('loading');
                dialogawpModal.error(t('Error saving flow. Check browser console for details.'));
            });
        },
        
        loadFlows: function() {
            $.ajax({
                url: dialogawpAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialogawp_get_flows',
                    nonce: dialogawpAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderFlowList(response.data);
                    } else {
                        $('#dialogawp-flows-list').html('<p class="dialogawp-builder-inline-error" role="alert">' + this.escapeEditorHtml(t('Could not load flows. Please refresh and try again.')) + '</p>');
                    }
                },
                error: () => {
                    $('#dialogawp-flows-list').html('<p class="dialogawp-builder-inline-error" role="alert">' + this.escapeEditorHtml(t('Could not load flows. Please refresh and try again.')) + '</p>');
                }
            });
        },
        
        renderFlowList: function(flows) {
            const $list = $('#dialogawp-flows-list');
            
            if (flows.length === 0) {
                $list.html('<p class="dialogawp-loading">' + this.escapeEditorHtml(t('No flows yet. Create your first flow!')) + '</p>');
                return;
            }
            
            const template = (window.dialogawpTemplates && window.dialogawpTemplates.flowItem) || '';
            let html = '';
            
            flows.forEach(flow => {
                const isActive = parseInt(flow.is_active) === 1;
                const isEditing = parseInt(flow.id) === parseInt(this.currentFlowId);
                const updatedDate = new Date(flow.updated_at).toLocaleDateString();

                // Determine visibility display
                const visibilityMode = flow.visibility_mode || 'everyone';
                let visibilityIcon = '👥';
                let visibilityLabel = t('Everyone');

                if (visibilityMode === 'logged_in') {
                    visibilityIcon = '🔐';
                    visibilityLabel = t('Logged-in users');
                } else if (visibilityMode === 'specific_roles') {
                    visibilityIcon = '👑';
                    const roles = flow.visible_roles ? JSON.parse(flow.visible_roles) : [];
                    visibilityLabel = roles.length > 0 ? t('%s role(s)').replace('%s', roles.length) : t('Specific roles');
                }

                html += template
                    .replace(/{{id}}/g, flow.id)
                    .replace(/{{flow_name}}/g, () => this.escapeEditorHtml(flow.flow_name))
                    .replace(/{{editing_class}}/g, isEditing ? 'editing' : '')
                    .replace(/{{status_class}}/g, isActive ? 'active' : 'inactive')
                    .replace(/{{status}}/g, () => this.escapeEditorHtml(isActive ? t('Active') : t('Inactive')))
                    .replace(/{{updated_at}}/g, updatedDate)
                    .replace(/{{visibility_icon}}/g, visibilityIcon)
                    .replace(/{{visibility_label}}/g, () => this.escapeEditorHtml(visibilityLabel));
            });
            
            $list.html(html);
        },
        
        loadFlow: function(flowId) {
            _log('📥 Loading flow ID:', flowId);

            // If we have a different flow loaded, save it first (synchronous)
            // We force-check the snapshot to catch any changes that may have slipped past detection
            if (this.currentFlowId && parseInt(this.currentFlowId) !== parseInt(flowId)) {
                const currentSnapshot = this.getCanvasSnapshot();
                if (currentSnapshot && currentSnapshot !== this.lastSnapshot) {
                    this.isDirty = true;
                }
                if (this.isDirty) {
                    _log('💾 Saving unsaved changes before switching flows...');
                    this.performAutoSave(true);
                }
            }

            // Show loading state on the card being clicked
            $(`.dialogawp-flow-item[data-flow-id="${flowId}"]`).addClass('loading');

            const self = this;
            $.ajax({
                url: dialogawpAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialogawp_get_flows',
                    nonce: dialogawpAdmin.nonce
                },
                success: (response) => {
                    $(`.dialogawp-flow-item`).removeClass('loading');
                    if (response.success) {
                        const flow = response.data.find(f => parseInt(f.id) === parseInt(flowId));
                        if (flow) {
                            _log('✅ Found flow:', flow.flow_name);
                            _log('📦 Raw flow_data:', flow.flow_data);

                            this.showBuilder();
                            this.currentFlowId = flow.id;
                            this.currentFlow = flow;
                            this.selectedNode = null;
                            $('#dialogawp-node-editor').hide();
                            
                            const flowData = JSON.parse(flow.flow_data);
                            _log('🔍 Parsed flow data:', flowData);
                            _log('📊 Nodes in flow:', flowData.nodes?.length || 0);
                            _log('🔗 Edges in flow:', flowData.edges?.length || 0);
                            
                            // CRITICAL: Deduplicate nodes by ID (keep first occurrence)
                            const seenIds = new Set();
                            const uniqueNodes = [];
                            (flowData.nodes || []).forEach(node => {
                                if (!seenIds.has(node.id)) {
                                    seenIds.add(node.id);
                                    uniqueNodes.push(node);
                                } else {
                                    _warn('⚠️ Duplicate node ID found and removed:', node.id);
                                }
                            });
                            
                            this.nodes = uniqueNodes;
                            this.edges = flowData.edges || [];

                            // CRITICAL: Update nodeIdCounter to avoid ID collisions with loaded nodes
                            const maxNodeId = Math.max(0, ...this.nodes.map(n => {
                                const match = n.id.match(/^node_(\d+)$/);
                                return match ? parseInt(match[1]) : 0;
                            }));
                            this.nodeIdCounter = maxNodeId + 1;
                            _log('🔢 nodeIdCounter set to:', this.nodeIdCounter);

                            _log('✅ After deduplication:', this.nodes.length, 'unique nodes');
                            
                            // Log each node
                            this.nodes.forEach((node, i) => {
                                _log(`  Node ${i}: ${node.id} (${node.data.nodeType}) - "${node.data.label}"`);
                                if (node.data.is_start) {
                                    _log(`    ⭐ START NODE`);
                                }
                            });
                            
                            $('#dialogawp-flow-name').val(flow.flow_name);
                            $('#dialogawp-flow-active').prop('checked', parseInt(flow.is_active) === 1);
                            
                            // Load visibility settings
                            const visibilityMode = flow.visibility_mode || 'everyone';
                            $('#dialogawp-flow-visibility').val(visibilityMode);
                            
                            // Show/hide role button
                            if (visibilityMode === 'specific_roles') {
                                $('#dialogawp-visibility-settings').show();
                            } else {
                                $('#dialogawp-visibility-settings').hide();
                            }
                            
                            // Load visible roles
                            $('.dialogawp-role-checkbox').prop('checked', false);
                            if (flow.visible_roles) {
                                const roles = JSON.parse(flow.visible_roles);
                                roles.forEach(role => {
                                    $('.dialogawp-role-checkbox[value="' + role + '"]').prop('checked', true);
                                });
                                $('#dialogawp-visibility-settings').text(t('%s role(s) selected').replace('%s', roles.length));
                            }
                            

                            // Auto-connect __start__ to the start node if missing
                            const hasStartEdge = this.edges.some(e => e.source === '__start__');
                            if (!hasStartEdge) {
                                const startNode = this.nodes.find(n => n.data && n.data.is_start);
                                if (startNode) {
                                    this.edges.push({
                                        id: 'edge-start-' + startNode.id,
                                        source: '__start__',
                                        sourceHandle: null,
                                        target: startNode.id,
                                        type: 'smoothstep',
                                    });
                                }
                            }

                            // Migration: legacy Loop nodes had target_node_id but no wire.
                            // Auto-create a wire from the loop node to its legacy target so
                            // the new wire-based loop logic works seamlessly with old flows.
                            this.nodes.forEach(node => {
                                if (node.data && node.data.nodeType === 'loop' && node.data.target_node_id) {
                                    const hasLoopEdge = this.edges.some(e => e.source === node.id);
                                    if (!hasLoopEdge) {
                                        const targetExists = this.nodes.some(n => n.id === node.data.target_node_id);
                                        if (targetExists) {
                                            this.edges.push({
                                                id: 'edge-loop-' + node.id + '-' + Date.now(),
                                                source: node.id,
                                                sourceHandle: null,
                                                target: node.data.target_node_id,
                                                type: 'smoothstep',
                                            });
                                            _log('🔄 Migrated legacy loop:', node.id, '→', node.data.target_node_id);
                                        }
                                    }
                                }
                            });

                            if (window.dialogawpReactInstance) {
                                _log('🎨 Sending to React canvas:', this.nodes.length, 'nodes,', this.edges.length, 'edges');
                                window.dialogawpReactInstance.setFlow({
                                    nodes: this.nodes,
                                    edges: this.edges
                                });
                            }

                            _log('✅ Flow loaded successfully!');

                            // Re-render flow list to highlight the currently-editing flow
                            self.renderFlowList(response.data);

                            // Reset dirty state (just loaded fresh from DB) and start change detection
                            self.isDirty = false;
                            self.updateSaveStatus('saved');
                            // Wait a moment for React to render before snapshotting
                            setTimeout(() => self.startChangeDetection(), 500);
                        } else {
                            _error('❌ Flow not found:', flowId);
                            dialogawpModal.error(t('The selected flow could not be found. Please refresh and try again.'));
                        }
                    } else {
                        _error('❌ Failed to get flows:', response);
                        dialogawpModal.error(t('Could not load the flow. Please try again.'));
                    }
                },
                error: function(xhr, status, error) {
                    $(`.dialogawp-flow-item`).removeClass('loading');
                    _error('❌ AJAX error loading flow:', error);
                    dialogawpModal.error(t('Could not load the flow. Please try again.'));
                }
            });
        },
        
        deleteFlow: function(flowId) {
            // Show loading state on the card being deleted
            const $card = $(`.dialogawp-flow-item[data-flow-id="${flowId}"]`);
            $card.addClass('loading');

            $.ajax({
                url: dialogawpAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dialogawp_delete_flow',
                    nonce: dialogawpAdmin.nonce,
                    flow_id: flowId
                },
                success: (response) => {
                    if (response.success) {
                        dialogawpModal.success(t('Flow deleted successfully.'));
                        this.loadFlows();

                        if (parseInt(this.currentFlowId) === parseInt(flowId)) {
                            this.currentFlowId = null;
                            this.hideBuilder();
                        }
                    } else {
                        $card.removeClass('loading');
                        dialogawpModal.error(t('Failed to delete flow.'));
                    }
                },
                error: () => {
                    $card.removeClass('loading');
                    dialogawpModal.error(t('Error deleting flow.'));
                }
            });
        },
        
        duplicateFlow: function(flowId) {
            dialogawpModal.alert(t('Duplicate feature coming soon!'), t('Coming Soon'));
        },
        
        truncate: function(str, length) {
            if (!str) return '';
            return str.length > length ? str.substring(0, length) + '...' : str;
        }
    };
    
    $(document).ready(function() {
        if ($('.dialogawp-builder-wrap').length > 0) {
            window.dialogawpBuilder = dialogawpBuilder;
            dialogawpBuilder.init();
        }
    });
    
})(jQuery);
