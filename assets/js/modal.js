/**
 * Dialoga Modal — Drop-in replacement for alert() and confirm()
 *
 * Usage:
 *   dialogaModal.alert('Message here');
 *   dialogaModal.alert('Message here', 'Custom Title');
 *   dialogaModal.confirm('Are you sure?', function() { // on yes });
 *   dialogaModal.confirm('Are you sure?', onYes, 'Custom Title');
 *   dialogaModal.success('Saved successfully!');
 *   dialogaModal.error('Something went wrong.');
 */

(function() {
    'use strict';

    function t(message) {
        var i18n = window.dialogaAdmin && window.dialogaAdmin.i18n;
        return i18n && Object.prototype.hasOwnProperty.call(i18n, message) ? i18n[message] : message;
    }

    // Inject CSS once
    var styleEl = document.createElement('style');
    styleEl.textContent =
        '.dialoga-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.45);z-index:999999;display:flex;align-items:center;justify-content:center;animation:dialogaModalFadeIn .2s ease;}' +
        '@keyframes dialogaModalFadeIn{from{opacity:0}to{opacity:1}}' +
        '@keyframes dialogaModalSlideIn{from{opacity:0;transform:translateY(-20px) scale(0.95)}to{opacity:1;transform:translateY(0) scale(1)}}' +
        '.dialoga-modal-box{background:#fff;border-radius:12px;padding:32px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.25);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;animation:dialogaModalSlideIn .25s ease;position:relative;}' +
        '.dialoga-modal-icon{text-align:center;font-size:40px;margin-bottom:12px;line-height:1;}' +
        '.dialoga-modal-title{text-align:center;font-size:18px;font-weight:700;color:#26233A;margin:0 0 8px;}' +
        '.dialoga-modal-message{text-align:center;font-size:14px;color:#64748B;line-height:1.6;margin:0 0 24px;word-wrap:break-word;}' +
        '.dialoga-modal-actions{display:flex;gap:10px;justify-content:center;}' +
        '.dialoga-modal-btn{padding:9px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:background .15s,opacity .15s;}' +
        '.dialoga-modal-btn:hover{opacity:0.9;}' +
        '.dialoga-modal-btn-primary{background:#190F4F;color:#fff;}' +
        '.dialoga-modal-btn-danger{background:#DC2626;color:#fff;}' +
        '.dialoga-modal-btn-cancel{background:#F1F5F9;color:#475569;border:1px solid #E5E7EF;}' +
        '.dialoga-modal-btn-cancel:hover{background:#E5E7EF;}' +
        '.dialoga-modal-btn-success{background:#16A34A;color:#fff;}';
    document.head.appendChild(styleEl);

    var icons = {
        info: '\u2139\uFE0F',
        success: '\u2705',
        error: '\u26A0\uFE0F',
        confirm: '\u2753',
        warning: '\u26A0\uFE0F'
    };

    function createModal(opts) {
        // Remove any existing modal
        var existing = document.getElementById('dialoga-modal-overlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'dialoga-modal-overlay';
        overlay.className = 'dialoga-modal-overlay';

        var box = document.createElement('div');
        box.className = 'dialoga-modal-box';

        // Icon
        if (opts.icon) {
            var iconDiv = document.createElement('div');
            iconDiv.className = 'dialoga-modal-icon';
            iconDiv.textContent = opts.icon;
            box.appendChild(iconDiv);
        }

        // Title
        if (opts.title) {
            var titleDiv = document.createElement('div');
            titleDiv.className = 'dialoga-modal-title';
            titleDiv.textContent = opts.title;
            box.appendChild(titleDiv);
        }

        // Message
        var msgDiv = document.createElement('div');
        msgDiv.className = 'dialoga-modal-message';
        msgDiv.textContent = opts.message || '';
        box.appendChild(msgDiv);

        // Actions
        var actions = document.createElement('div');
        actions.className = 'dialoga-modal-actions';

        if (opts.type === 'confirm') {
            var cancelBtn = document.createElement('button');
            cancelBtn.className = 'dialoga-modal-btn dialoga-modal-btn-cancel';
            cancelBtn.textContent = opts.cancelText || t('Cancel');
            cancelBtn.addEventListener('click', function() {
                overlay.remove();
                if (opts.onCancel) opts.onCancel();
            });
            actions.appendChild(cancelBtn);

            var okBtn = document.createElement('button');
            okBtn.className = 'dialoga-modal-btn ' + (opts.danger ? 'dialoga-modal-btn-danger' : 'dialoga-modal-btn-primary');
            okBtn.textContent = opts.okText || t('Yes');
            okBtn.addEventListener('click', function() {
                overlay.remove();
                if (opts.onConfirm) opts.onConfirm();
            });
            actions.appendChild(okBtn);
        } else {
            var btnClass = 'dialoga-modal-btn-primary';
            if (opts.type === 'success') btnClass = 'dialoga-modal-btn-success';
            if (opts.type === 'error') btnClass = 'dialoga-modal-btn-primary';

            var closeBtn = document.createElement('button');
            closeBtn.className = 'dialoga-modal-btn ' + btnClass;
            closeBtn.textContent = opts.okText || t('OK');
            closeBtn.addEventListener('click', function() {
                overlay.remove();
                if (opts.onClose) opts.onClose();
            });
            actions.appendChild(closeBtn);
        }

        box.appendChild(actions);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        // Focus the primary button
        var primaryBtn = box.querySelector('.dialoga-modal-btn-primary, .dialoga-modal-btn-success, .dialoga-modal-btn-danger');
        if (primaryBtn) primaryBtn.focus();

        // ESC key closes (only for alert, not confirm)
        if (opts.type !== 'confirm') {
            var escHandler = function(e) {
                if (e.key === 'Escape') {
                    overlay.remove();
                    document.removeEventListener('keydown', escHandler);
                    if (opts.onClose) opts.onClose();
                }
            };
            document.addEventListener('keydown', escHandler);
        }

        return overlay;
    }

    window.dialogaModal = {
        alert: function(message, title, onClose) {
            createModal({
                type: 'alert',
                icon: icons.info,
                title: title || '',
                message: message,
                onClose: onClose
            });
        },

        success: function(message, title, onClose) {
            createModal({
                type: 'success',
                icon: icons.success,
                title: title || t('Success'),
                message: message,
                okText: t('OK'),
                onClose: onClose
            });
        },

        error: function(message, title, onClose) {
            createModal({
                type: 'error',
                icon: icons.error,
                title: title || t('Error'),
                message: message,
                onClose: onClose
            });
        },

        confirm: function(message, onConfirm, titleOrOpts) {
            var opts = {};
            if (typeof titleOrOpts === 'string') {
                opts.title = titleOrOpts;
            } else if (typeof titleOrOpts === 'object') {
                opts = titleOrOpts;
            }
            createModal({
                type: 'confirm',
                icon: opts.icon || icons.confirm,
                title: opts.title || t('Confirm'),
                message: message,
                okText: opts.okText || t('Yes'),
                cancelText: opts.cancelText || t('Cancel'),
                danger: opts.danger || false,
                onConfirm: onConfirm,
                onCancel: opts.onCancel || null
            });
        }
    };
})();
