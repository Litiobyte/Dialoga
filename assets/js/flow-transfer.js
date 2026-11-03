/** Admin-only portable flow packages and in-tab canvas expansion. */
(function($) {
    'use strict';

    function t(message) {
        const i18n = window.dialogaAdmin && window.dialogaAdmin.i18n;
        return i18n && Object.prototype.hasOwnProperty.call(i18n, message) ? i18n[message] : message;
    }

    $(function() {
        const builder = window.dialogaBuilder;
        if (!builder) return;

        const maxBytes = 5242880;
        let busy = false;
        let dialog = null;
        let fullscreen = false;
        let previousFocus = null;
        let previousScroll = null;

        async function request(action, fields) {
            const data = new FormData();
            data.append('action', action);
            data.append('nonce', dialogaAdmin.nonce);
            Object.entries(fields).forEach(([key, value]) => data.append(key, JSON.stringify(value)));
            const response = await fetch(dialogaAdmin.ajaxUrl, { method: 'POST', body: data });
            let result;
            try {
                result = await response.json();
            } catch (error) {
                throw new Error(t('The server returned an invalid response. Check the upload limit and try again.'));
            }
            if (!response.ok || !result.success) {
                let message = result.data?.message || t('Flow transfer failed. Please try again.');
                if (result.data?.cleanup_failed) {
                    message += ' ' + t('Flow IDs: %s.').replace('%s', result.data.remaining_flow_ids.join(', '));
                    builder.loadFlows();
                }
                throw new Error(message);
            }
            return result.data;
        }

        function setBusy(value) {
            busy = value;
            $('.dialoga-import-flows, .dialoga-export-flows, .dialoga-export-current').prop('disabled', value);
            if (dialog) $(dialog).find('button, input').prop('disabled', value);
        }

        function closeDialog() {
            if (!dialog || busy) return;
            const focus = dialog._returnFocus;
            dialog.remove();
            dialog = null;
            if (focus?.isConnected) focus.focus();
        }

        function openDialog(title, submitLabel, onSubmit) {
            closeDialog();
            const returnFocus = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'dialoga-transfer-overlay';
            const box = document.createElement('section');
            box.className = 'dialoga-transfer-dialog';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-labelledby', 'dialoga-transfer-title');
            const heading = document.createElement('h2');
            heading.id = 'dialoga-transfer-title';
            heading.textContent = title;
            const content = document.createElement('div');
            content.className = 'dialoga-transfer-content';
            const status = document.createElement('p');
            status.className = 'dialoga-transfer-status';
            status.setAttribute('role', 'status');
            const actions = document.createElement('div');
            actions.className = 'dialoga-transfer-actions';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'button';
            cancel.textContent = t('Cancel');
            cancel.onclick = closeDialog;
            const submit = document.createElement('button');
            submit.type = 'button';
            submit.className = 'button button-primary';
            submit.textContent = submitLabel;
            submit.onclick = async function() {
                if (busy) return;
                status.textContent = t('Working...');
                setBusy(true);
                try {
                    await onSubmit(content);
                    setBusy(false);
                    closeDialog();
                } catch (error) {
                    status.textContent = error.message;
                    setBusy(false);
                }
            };
            actions.append(cancel, submit);
            box.append(heading, content, status, actions);
            overlay.append(box);
            overlay._returnFocus = returnFocus;
            overlay.onclick = e => { if (e.target === overlay) closeDialog(); };
            document.body.append(overlay);
            dialog = overlay;
            cancel.focus();
            return content;
        }

        function addText(parent, text) {
            const p = document.createElement('p');
            p.textContent = text;
            parent.append(p);
        }

        function addWarnings(parent, warnings) {
            if (!warnings.length) return;
            const list = document.createElement('ul');
            list.className = 'dialoga-transfer-warnings';
            warnings.forEach(warning => {
                const item = document.createElement('li');
                item.textContent = warning;
                list.append(item);
            });
            parent.append(list);
        }

        async function currentFlow() {
            if (!builder.currentFlowId) return null;
            // Commit the open form before reading React's graph; retain fresh position/edge state.
            if (builder.selectedNode && $('#dialoga-node-editor').is(':visible')) {
                const latest = window.dialogaReactInstance?.getFlow();
                if (latest) {
                    const selected = latest.nodes.find(node => node.id === builder.selectedNode.id);
                    if (!selected) throw new Error(t('The selected node no longer exists. Close its editor and try again.'));
                    builder.selectedNode = { ...selected, data: { ...builder.selectedNode.data } };
                    builder.nodes = latest.nodes;
                    builder.edges = latest.edges;
                }
                builder.saveNodeEdits(true);
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            }
            const graph = window.dialogaReactInstance?.getFlow() || { nodes: builder.nodes, edges: builder.edges };
            const nodes = graph.nodes.map(node => builder.selectedNode?.id === node.id
                ? { ...node, data: builder.selectedNode.data } : node);
            return {
                id: Number(builder.currentFlowId),
                flow_name: $('#dialoga-flow-name').val(),
                visibility_mode: $('#dialoga-flow-visibility').val(),
                visible_roles: $('.dialoga-role-checkbox:checked').map(function() { return this.value; }).get(),
                flow_data: { nodes, edges: graph.edges }
            };
        }

        async function exportFlows(ids) {
            if (!ids.length) throw new Error(t('Select at least one flow.'));
            if (ids.length > 100) throw new Error(t('Export up to 100 flows per package.'));
            const fields = { flow_ids: ids };
            if (ids.includes(Number(builder.currentFlowId))) fields.current_flow = await currentFlow();
            const result = await request('dialoga_export_flows', fields);
            const blob = new Blob([JSON.stringify(result.package)], { type: 'application/json' });
            if (blob.size > maxBytes) throw new Error(t('Export package exceeds 5 MiB; select fewer flows.'));
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'dialoga-flows-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            // Delay feedback until the selection dialog has closed.
            if (result.warnings.length) setTimeout(() => dialogaModal.alert(result.warnings.join('\n\n'), t('Export warnings')), 0);
        }

        $('.dialoga-export-current').on('click', async function() {
            if (busy || !builder.currentFlowId) return;
            setBusy(true);
            try {
                await exportFlows([Number(builder.currentFlowId)]);
            } catch (error) {
                dialogaModal.error(error.message);
            } finally {
                setBusy(false);
            }
        });

        $(document).on('click', '.dialoga-export-flows', async function() {
            if (busy) return;
            setBusy(true);
            try {
                const flows = await request('dialoga_get_flows', {});
                if (!flows.length) throw new Error(t('There are no flows to export.'));
                setBusy(false);
                const content = openDialog(t('Export flows'), t('Download JSON'), async parent => {
                    const ids = Array.from(parent.querySelectorAll('.dialoga-export-choice:checked'), input => Number(input.value));
                    await exportFlows(ids);
                });
                addText(content, t('Select up to 100 flows. The open flow includes current editor changes. Credentials and connector bindings are excluded.'));
                const allLabel = document.createElement('label');
                const all = document.createElement('input');
                all.type = 'checkbox';
                allLabel.append(all, document.createTextNode(' ' + t('Select all')));
                content.append(allLabel);
                flows.forEach(flow => {
                    const label = document.createElement('label');
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.className = 'dialoga-export-choice';
                    input.value = flow.id;
                    input.checked = Number(flow.id) === Number(builder.currentFlowId);
                    label.append(input, document.createTextNode(' ' + flow.flow_name));
                    content.append(label);
                });
                all.onchange = () => content.querySelectorAll('.dialoga-export-choice').forEach(input => { input.checked = all.checked; });
            } catch (error) {
                dialogaModal.error(error.message);
            } finally {
                setBusy(false);
            }
        });

        $(document).on('click', '.dialoga-import-flows', function() {
            if (!busy) $('#dialoga-import-file').val('').trigger('click');
        });

        $(document).on('change', '#dialoga-import-file', async function() {
            const file = this.files[0];
            if (!file || busy) return;
            setBusy(true);
            try {
                if (!/\.json$/i.test(file.name) || file.size > maxBytes) throw new Error(t('Choose a JSON file no larger than 5 MiB.'));
                let pkg;
                try {
                    pkg = JSON.parse((await file.text()).replace(/^\uFEFF/, ''));
                } catch (error) {
                    throw new Error(t('The file does not contain valid JSON.'));
                }
                const preview = await request('dialoga_validate_flow_import', { package: pkg });
                setBusy(false);
                const content = openDialog(t('Import %s flow(s)').replace('%s', preview.flows.length), t('Import inactive copies'), async () => {
                    const result = await request('dialoga_import_flows', { package: pkg });
                    // The list is server-rendered; refresh it after import. The editor stays mounted.
                    if (builder.isEditor) builder.loadFlows();
                    else window.location.reload();
                    setTimeout(() => dialogaModal.success(t('%s flow(s) imported as inactive copies. Select them from the list and review settings before activation.').replace('%s', result.flow_ids.length)), 0);
                });
                addText(content, t('Existing flows will not be replaced. Imported copies remain inactive until you explicitly activate them.'));
                preview.flows.forEach(flow => addText(content, flow.flow_name + ' (' + t('%s nodes, %s connections').replace('%s', flow.node_count).replace('%s', flow.edge_count) + ')'));
                addWarnings(content, preview.warnings);
            } catch (error) {
                dialogaModal.error(error.message);
            } finally {
                setBusy(false);
                this.value = '';
            }
        });

        function toggleFullscreen(value) {
            if (value === fullscreen) return;
            fullscreen = value;
            if (value) {
                previousFocus = document.activeElement;
                previousScroll = { x: window.scrollX, y: window.scrollY };
            }
            document.body.classList.toggle('dialoga-canvas-fullscreen', value);
            const button = document.querySelector('.dialoga-fullscreen-toggle');
            button.setAttribute('aria-pressed', String(value));
            button.querySelector('.dialoga-fullscreen-label').textContent = value ? t('Exit full screen') : t('Full screen');
            button.querySelector('.dashicons').className = 'dashicons ' + (value ? 'dashicons-editor-contract' : 'dashicons-editor-expand');
            if (value) button.focus();
            else {
                if (previousScroll) window.scrollTo(previousScroll.x, previousScroll.y);
                if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
            }
            window.dispatchEvent(new Event('resize'));
        }

        $('.dialoga-fullscreen-toggle').on('click', () => toggleFullscreen(!fullscreen));
        document.addEventListener('keydown', function(e) {
            if (dialog && e.key === 'Tab') {
                const focusable = Array.from(dialog.querySelectorAll('button:not(:disabled), input:not(:disabled)'));
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (!first) { e.preventDefault(); return; }
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
            if (e.key !== 'Escape') return;
            if (dialog) {
                e.preventDefault();
                e.stopImmediatePropagation();
                closeDialog();
                return;
            }
            if (document.querySelector('.dialoga-modal-overlay') || $('.dialoga-modal:visible').length) return;
            if (fullscreen) {
                e.preventDefault();
                toggleFullscreen(false);
            }
        }, true);

        // If the active flow is deleted, its toolbar disappears. Do not leave the user trapped.
        const activeBuilder = document.getElementById('dialoga-builder-active');
        if (activeBuilder) {
            const observer = new MutationObserver(() => {
                if (fullscreen && !$('#dialoga-builder-active').is(':visible')) toggleFullscreen(false);
            });
            observer.observe(activeBuilder, { attributes: true, attributeFilter: ['style'] });
        }
    });
})(jQuery);
