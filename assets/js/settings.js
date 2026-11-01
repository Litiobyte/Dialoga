/** Accessible settings navigation and settings-only interactions. */
(function () {
    'use strict';

    function t(msgid) {
        var dictionary = (window.dialogawpAdmin && window.dialogawpAdmin.i18n) || {};
        return Object.prototype.hasOwnProperty.call(dictionary, msgid) ? dictionary[msgid] : msgid;
    }

    function init() {
        var form = document.getElementById('dialogawp-settings-form');
        if (!form) return;
        var nav = form.querySelector('.dialogawp-settings-tabs');
        var tabs = Array.from(nav.querySelectorAll('.dialogawp-settings-tab'));
        var panels = tabs.map(function (tab) { return document.getElementById(tab.hash.slice(1)); });
        var active = 0;

        function activate(index, focus, persist) {
            active = index;
            tabs.forEach(function (tab, i) {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                panels[i].hidden = i !== index;
            });
            if (focus) tabs[index].focus();
            // The fragment also travels with the native form POST action.
            if (persist) {
                try {
                    window.history.replaceState(null, '', tabs[index].hash);
                } catch (error) {
                    // Navigation still works if the browser disallows history updates.
                }
            }
            form.action = window.location.pathname + window.location.search + tabs[index].hash;
        }

        nav.setAttribute('role', 'tablist');
        tabs.forEach(function (tab, index) {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panels[index].id);
            panels[index].setAttribute('role', 'tabpanel');
            panels[index].setAttribute('aria-labelledby', tab.id);
            panels[index].tabIndex = 0;
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activate(index, false, true);
            });
            tab.addEventListener('keydown', function (event) {
                var next;
                switch (event.key) {
                    case 'ArrowRight':
                    case 'ArrowDown': next = (index + 1) % tabs.length; break;
                    case 'ArrowLeft':
                    case 'ArrowUp': next = (index - 1 + tabs.length) % tabs.length; break;
                    case 'Home': next = 0; break;
                    case 'End': next = tabs.length - 1; break;
                    default: return;
                }
                event.preventDefault();
                activate(next, true, true);
            });
        });
        function restoreFragment() {
            var index = tabs.findIndex(function (tab) { return tab.hash === window.location.hash; });
            if (index !== -1) activate(index, false, false);
        }
        var initial = tabs.findIndex(function (tab) { return tab.hash === window.location.hash; });
        activate(initial === -1 ? 0 : initial, false, false);
        window.addEventListener('hashchange', restoreFragment);
        window.addEventListener('popstate', restoreFragment);
        form.addEventListener('submit', function () { activate(active, false, true); });

        // Native validation fires before submit; reveal the first invalid field synchronously.
        var invalidPending = false;
        form.addEventListener('invalid', function (event) {
            if (invalidPending) return;
            invalidPending = true;
            var panel = event.target.closest('.dialogawp-settings-panel');
            var index = panels.indexOf(panel);
            if (index !== -1) activate(index, false, true);
            event.target.focus();
            window.setTimeout(function () { invalidPending = false; }, 0);
        }, true);

        var color = form.querySelector('input[name="primary_color"]');
        var colorText = form.querySelector('input[name="primary_color_text"]');
        if (color && colorText) {
            color.addEventListener('input', function () { colorText.value = color.value; });
            color.addEventListener('change', function () { colorText.value = color.value; });
        }
        var visibility = form.querySelector('[name="visibility_mode"]');
        var roles = document.getElementById('dialogawp-roles-selector');
        function updateRoles() {
            if (visibility && roles) roles.hidden = visibility.value !== 'specific_roles';
        }
        if (visibility) visibility.addEventListener('change', updateRoles);
        updateRoles();

        function wireDependentControl(toggleName, controlNames) {
            var toggle = form.querySelector('[name="' + toggleName + '"]');
            var controls = controlNames.map(function (name) { return form.querySelector('[name="' + name + '"]'); }).filter(Boolean);
            if (!toggle || !controls.length) return;
            function update() {
                controls.forEach(function (control) {
                    // Keep fields operable so native validation and no-JS form semantics
                    // remain intact; the visual state communicates the dependency.
                    control.removeAttribute('aria-disabled');
                    var row = control.closest('.dialogawp-setting-row');
                    if (row) row.classList.toggle('is-dependent-disabled', !toggle.checked);
                });
            }
            toggle.addEventListener('change', update);
            update();
        }
        wireDependentControl('email_notifications_enabled', ['email_notifications_recipient', 'email_notifications_subject']);
        wireDependentControl('lead_retention_enabled', ['lead_retention_days']);
        wireDependentControl('conversation_retention_enabled', ['conversation_retention_days']);
        wireDependentControl('preview_message_enabled', ['preview_message_text', 'preview_message_delay']);

        var config = window.dialogawpAdmin || {};
        function wireBulkDelete(id, action, confirmation, result) {
            var button = document.getElementById(id);
            if (!button) return;
            button.addEventListener('click', function () {
                if (!window.confirm(t(confirmation))) return;
                var typed = window.prompt(t('Type DELETE (in caps) to confirm:'));
                // DELETE is a server-side protocol token, never a translated value.
                if (typed !== 'DELETE') {
                    window.alert(t('Confirmation did not match. Nothing was deleted.'));
                    return;
                }
                var original = button.textContent;
                button.disabled = true;
                button.textContent = t('Deleting...');
                var data = new FormData();
                data.append('action', action);
                data.append('nonce', config.nonce || '');
                data.append('confirm', typed);
                fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (response) {
                        if (!response.ok) throw new Error('HTTP error');
                        return response.json();
                    })
                    .then(function (response) {
                        if (response.success) {
                            var count = Number(response.data && response.data.deleted_count) || 0;
                            window.alert(t(result).replace('%d', String(count)));
                        } else {
                            window.alert((response.data && response.data.message) || t('Delete failed.'));
                        }
                    })
                    .catch(function () { window.alert(t('Network error during delete.')); })
                    .finally(function () {
                        button.disabled = false;
                        button.textContent = original;
                    });
            });
        }
        wireBulkDelete('dialogawp-clear-all-leads-btn', 'dialogawp_delete_all_leads', 'Delete ALL leads?\n\nThis cannot be undone.', 'Deleted %d lead(s).');
        wireBulkDelete('dialogawp-clear-all-convs-btn', 'dialogawp_delete_all_conversations', 'Delete ALL conversations?\n\nThis cannot be undone.', 'Deleted %d conversation(s).');
        wireBulkDelete('dialogawp-reset-analytics-btn', 'dialogawp_reset_analytics_events', 'Delete ALL analytics events?\n\nThis cannot be undone.', 'Deleted %d analytics event(s).');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
