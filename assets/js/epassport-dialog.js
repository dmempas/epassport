(function () {
    const style = document.createElement('style');
    style.textContent = [
        '.ep-dialog-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(23,35,103,.45);display:flex;align-items:center;justify-content:center;padding:1rem;}',
        '.ep-dialog{width:min(420px,100%);background:#fff;color:#1c2556;border:1px solid #d7def2;border-radius:12px;padding:1.15rem 1.15rem 1rem;}',
        '.ep-dialog h2{margin:0 0 .4rem;font-size:1.05rem;font-weight:700;}',
        '.ep-dialog p{margin:0;font-size:.95rem;line-height:1.45;color:#3d4674;}',
        '.ep-dialog-actions{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem;}',
        '.ep-dialog-actions button{border-radius:8px;padding:.4rem .85rem;font-size:.9rem;border:1px solid #d7def2;background:#fff;color:#1c2556;cursor:pointer;}',
        '.ep-dialog-actions .ep-dialog-primary{background:#1f2f88;border-color:#1f2f88;color:#fff;}',
        '.ep-dialog-actions .ep-dialog-danger{background:#b42318;border-color:#b42318;color:#fff;}'
    ].join('');
    document.head.appendChild(style);

    let active = null;

    function closeDialog(result) {
        if (!active) return;
        const finish = active.finish;
        active.backdrop.remove();
        active = null;
        document.body.style.overflow = '';
        finish(result);
    }

    function openDialog(options) {
        return new Promise(function (resolve) {
            if (active) closeDialog(false);

            const backdrop = document.createElement('div');
            backdrop.className = 'ep-dialog-backdrop';
            backdrop.setAttribute('role', 'presentation');

            const dialog = document.createElement('div');
            dialog.className = 'ep-dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');

            const title = document.createElement('h2');
            title.textContent = options.title || (options.confirm ? 'Please confirm' : 'E-Passport');

            const message = document.createElement('p');
            message.textContent = options.message || '';

            const actions = document.createElement('div');
            actions.className = 'ep-dialog-actions';

            let primary = null;

            if (options.confirm) {
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.textContent = options.cancelLabel || 'Cancel';
                cancel.addEventListener('click', function () { closeDialog(false); });
                actions.appendChild(cancel);
            }

            primary = document.createElement('button');
            primary.type = 'button';
            primary.className = options.danger ? 'ep-dialog-danger' : 'ep-dialog-primary';
            primary.textContent = options.confirmLabel || (options.confirm ? 'Confirm' : 'OK');
            primary.addEventListener('click', function () { closeDialog(true); });
            actions.appendChild(primary);

            dialog.appendChild(title);
            dialog.appendChild(message);
            dialog.appendChild(actions);
            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);
            document.body.style.overflow = 'hidden';

            active = { backdrop: backdrop, finish: resolve };

            backdrop.addEventListener('click', function (event) {
                if (event.target === backdrop && options.confirm) closeDialog(false);
            });

            primary.focus();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (!active) return;
        if (event.key === 'Escape') closeDialog(false);
    });

    window.epassportAlert = function (message, title) {
        return openDialog({
            title: title || 'E-Passport',
            message: String(message || ''),
            confirm: false
        });
    };

    window.epassportConfirm = function (message, options) {
        options = options || {};
        return openDialog({
            title: options.title || 'Please confirm',
            message: String(message || ''),
            confirm: true,
            confirmLabel: options.confirmLabel || 'Confirm',
            cancelLabel: options.cancelLabel || 'Cancel',
            danger: !!options.danger
        });
    };

    function bindConfirmForms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            if (form.dataset.epConfirmBound === '1') return;
            form.dataset.epConfirmBound = '1';
            form.addEventListener('submit', function (event) {
                if (form.dataset.epConfirmed === '1') {
                    form.dataset.epConfirmed = '';
                    return;
                }
                event.preventDefault();
                const danger = form.getAttribute('data-confirm-danger') === '1';
                window.epassportConfirm(form.getAttribute('data-confirm') || '', {
                    title: form.getAttribute('data-confirm-title') || 'Please confirm',
                    confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm',
                    danger: danger
                }).then(function (ok) {
                    if (!ok) return;
                    form.dataset.epConfirmed = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
            });
        });
    }

    function clock12(value) {
        const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ''));
        if (!match) return '';
        let hour = parseInt(match[1], 10);
        const minute = match[2];
        const suffix = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12;
        if (hour === 0) hour = 12;
        return hour + ':' + minute + ' ' + suffix;
    }

    function bindClockHints() {
        document.querySelectorAll('input[type="time"]').forEach(function (input) {
            if (input.dataset.epClockBound === '1') return;
            input.dataset.epClockBound = '1';
            const hint = document.createElement('div');
            hint.className = 'form-text ep-clock-hint';
            input.insertAdjacentElement('afterend', hint);
            const paint = function () {
                hint.textContent = input.value ? clock12(input.value) : '';
            };
            input.addEventListener('input', paint);
            input.addEventListener('change', paint);
            paint();
        });
    }

    window.epassportRefreshClocks = bindClockHints;

    function bindUi() {
        bindConfirmForms();
        bindClockHints();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUi);
    } else {
        bindUi();
    }
})();
