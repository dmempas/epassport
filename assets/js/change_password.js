(function () {
    const form = document.getElementById('change-password-form');
    if (!form) {
        return;
    }

    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const matchHint = document.getElementById('password-match-hint');
    const submitButton = form.querySelector('[name="change_password"]');
    const strengthFill = document.getElementById('password-strength-fill');
    const strengthLabel = document.getElementById('password-strength-label');
    const requirementItems = form.querySelectorAll('[data-requirement]');

    function setHint(text, tone) {
        if (!matchHint) {
            return;
        }

        const icon = tone === 'success'
            ? '<i class="bi bi-check-circle-fill"></i>'
            : tone === 'danger'
                ? '<i class="bi bi-x-circle-fill"></i>'
                : '';

        matchHint.innerHTML = icon + (text ? '<span>' + text + '</span>' : '');
        matchHint.className = 'password-hint password-hint--' + tone;
    }

    const WEAK_PASSWORDS = {
        '12345678': true,
        '87654321': true,
        '123456789': true,
        '987654321': true,
        '01234567': true,
        '00000000': true,
        '0000000000': true,
        '11111111': true,
        '11111111111': true,
        'password': true,
        'password1': true,
        'password123': true,
        'qwerty': true,
        'qwerty123': true,
        'admin123': true,
        'letmein': true,
        'iloveyou': true,
        'abc12345': true,
        'abcdefgh': true
    };

    function isRepeatedCharacter(value) {
        return value.length > 0 && /^(.)\1+$/.test(value);
    }

    function hasSequentialRun(value, runLength) {
        const normalized = value.toLowerCase();
        if (normalized.length < runLength) {
            return false;
        }

        function isSeq(chunk, step) {
            for (let i = 1; i < chunk.length; i++) {
                if (chunk.charCodeAt(i) !== chunk.charCodeAt(i - 1) + step) {
                    return false;
                }
            }
            return true;
        }

        for (let i = 0; i <= normalized.length - runLength; i++) {
            const chunk = normalized.slice(i, i + runLength);
            const digits = /^\d+$/.test(chunk);
            const letters = /^[a-z]+$/.test(chunk);
            if ((digits || letters) && (isSeq(chunk, 1) || isSeq(chunk, -1))) {
                return true;
            }
        }

        return false;
    }

    function hasLetterNumberAndSymbol(value) {
        return /[A-Za-z]/.test(value)
            && /\d/.test(value)
            && /[^A-Za-z0-9]/.test(value);
    }

    function isStrongPassword(value) {
        return value.length >= 8
            && hasLetterNumberAndSymbol(value)
            && !isRepeatedCharacter(value)
            && !hasSequentialRun(value, 4)
            && !WEAK_PASSWORDS[value.toLowerCase()];
    }

    function strongPasswordMessage(value) {
        if (value.length < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!hasLetterNumberAndSymbol(value)) {
            return 'Password must include letters, numbers, and symbols.';
        }
        if (isRepeatedCharacter(value) || hasSequentialRun(value, 4) || WEAK_PASSWORDS[value.toLowerCase()]) {
            return 'Do not use sequences such as 12345678 or repeated digits such as 00000000.';
        }
        return '';
    }

    function evaluateStrength(value) {
        if (!value) {
            return { score: 0, label: '', className: '' };
        }

        if (!isStrongPassword(value)) {
            return { score: 25, label: 'Too weak', className: 'is-weak' };
        }

        let score = 3;
        if (value.length >= 12) score += 1;
        if (/[^A-Za-z0-9]/.test(value) && /\d/.test(value) && /[A-Z]/.test(value)) score += 1;

        if (score <= 3) {
            return { score: 75, label: 'Good', className: 'is-good' };
        }

        return { score: 100, label: 'Strong', className: 'is-strong' };
    }

    function updateStrength() {
        const value = (newPassword && newPassword.value) || '';

        if (!strengthFill || !strengthLabel) {
            return;
        }

        const result = evaluateStrength(value);
        strengthFill.style.width = result.score + '%';

        if (!value) {
            strengthFill.style.background = '#e8edf8';
            strengthLabel.textContent = '';
            strengthLabel.className = 'password-strength__label';
            return;
        }

        const colors = {
            25: '#dc3545',
            50: '#c47d0e',
            75: '#0d6efd',
            100: '#198754',
        };

        strengthFill.style.background = colors[result.score] || '#e8edf8';
        strengthLabel.textContent = 'Strength: ' + result.label;
        strengthLabel.className = 'password-strength__label ' + result.className;
    }

    function updateRequirements() {
        const value = (newPassword && newPassword.value) || '';
        const confirm = (confirmPassword && confirmPassword.value) || '';

        requirementItems.forEach(function (item) {
            const rule = item.getAttribute('data-requirement');
            let met = false;

            switch (rule) {
                case 'length':
                    met = value.length >= 8;
                    break;
                case 'mix':
                    met = hasLetterNumberAndSymbol(value);
                    break;
                case 'not-weak':
                    met = value.length > 0
                        && !isRepeatedCharacter(value)
                        && !hasSequentialRun(value, 4)
                        && !WEAK_PASSWORDS[value.toLowerCase()];
                    break;
                case 'student-no':
                    met = value.length > 0 && value !== item.getAttribute('data-student-no');
                    break;
                case 'different':
                    met = value.length > 0;
                    break;
                case 'match':
                    met = confirm.length > 0 && value === confirm;
                    break;
                default:
                    break;
            }

            item.classList.toggle('is-met', met);
        });
    }

    function updateMatchHint() {
        const next = (newPassword && newPassword.value) || '';
        const confirm = (confirmPassword && confirmPassword.value) || '';

        if (confirmPassword) {
            confirmPassword.classList.toggle('is-valid', confirm.length > 0 && next === confirm);
            confirmPassword.classList.toggle('is-invalid', confirm.length > 0 && next !== confirm);
        }

        if (confirm === '') {
            setHint('', 'neutral');
            updateRequirements();
            return;
        }

        if (next === confirm) {
            setHint('Passwords match', 'success');
        } else {
            setHint('Passwords do not match', 'danger');
        }

        updateRequirements();
    }

    function bindToggle(buttonId, inputId) {
        const button = document.getElementById(buttonId);
        const input = document.getElementById(inputId);

        if (!button || !input) {
            return;
        }

        button.addEventListener('click', function () {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            button.innerHTML = isHidden
                ? '<i class="bi bi-eye-slash"></i>'
                : '<i class="bi bi-eye"></i>';
        });
    }

    bindToggle('toggle-new-password', 'new_password');
    bindToggle('toggle-confirm-password', 'confirm_password');
    bindToggle('toggle-current-password', 'current_password');

    if (newPassword) {
        newPassword.addEventListener('input', function () {
            updateStrength();
            updateRequirements();
            updateMatchHint();
        });
    }

    if (confirmPassword) {
        confirmPassword.addEventListener('input', updateMatchHint);
    }

    updateStrength();
    updateRequirements();

    form.addEventListener('submit', function (event) {
        const submitter = event.submitter;

        if (!submitter || submitter.name !== 'change_password') {
            return;
        }

        updateMatchHint();

        const next = (newPassword && newPassword.value) || '';
        const weakMessage = strongPasswordMessage(next);

        if (weakMessage) {
            event.preventDefault();
            setHint(weakMessage, 'danger');
            if (newPassword) {
                newPassword.focus();
            }
            return;
        }

        if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
            event.preventDefault();
            setHint('Passwords do not match', 'danger');
            confirmPassword.focus();
            return;
        }

        if (submitButton) {
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Updating...';
        }
    });
})();
