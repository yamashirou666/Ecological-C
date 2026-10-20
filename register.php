<?php
/**
 * EcoCycle — single-page account registration.
 */
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['signup_wizard']);

if (isset($_GET['check_username'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');

    $username = is_string($_GET['check_username']) ? $_GET['check_username'] : '';
    if (!preg_match('/\A[A-Za-z0-9]{3,12}\z/', $username)) {
        http_response_code(400);
        echo json_encode(['available' => false, 'message' => 'Use 3–12 letters and numbers.']);
        exit;
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        echo json_encode(['available' => !$stmt->fetchColumn()]);
    } catch (Throwable $ex) {
        error_log('Username availability check failed: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'We could not check that username right now. Please try again.']);
    }
    exit;
}

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$values = [
    'username' => '',
    'email' => '',
    'birth_month' => '',
    'birth_day' => '',
    'birth_year' => '',
];
$fieldErrors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $value) {
        if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
            $values[$field] = trim((string) $_POST[$field]);
        }
    }
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';

    if (!verifyCsrf()) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } elseif (!empty($_POST['website'])) {
        $formError = 'We could not submit your details. Please try again.';
    } else {
        $username = $values['username'];
        $email = strtolower($values['email']);

        if (!preg_match('/\A[A-Za-z0-9]{3,12}\z/', $username)) {
            $fieldErrors['username'] = 'Choose 3–12 letters and numbers only.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $fieldErrors['email'] = 'Enter a valid email address.';
        }

        if ($policyError = passwordPolicyError($password)) {
            $fieldErrors['password'] = $policyError;
        }
        if ($password !== $confirmPassword) {
            $fieldErrors['confirm_password'] = 'Those passwords do not match yet.';
        }

        $monthValue = $values['birth_month'];
        $dayValue = $values['birth_day'];
        $yearValue = $values['birth_year'];
        $month = (int) $monthValue;
        $day = (int) $dayValue;
        $year = (int) $yearValue;
        $birthdate = null;
        if (!preg_match('/\A(?:[1-9]|1[0-2])\z/', $monthValue)
            || !preg_match('/\A(?:[1-9]|[12][0-9]|3[01])\z/', $dayValue)
            || !preg_match('/\A[0-9]{4}\z/', $yearValue)
            || !checkdate($month, $day, $year)) {
            $fieldErrors['birthdate'] = 'Choose a valid month, day, and year.';
        } else {
            $birthdate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
            $dateOfBirth = new DateTimeImmutable($birthdate, new DateTimeZone('UTC'));
            if ($dateOfBirth > $today->modify('-13 years')) {
                $fieldErrors['birthdate'] = 'You need to be at least 13 to create an account.';
            }
        }

        if (!$fieldErrors) {
            try {
                $stmt = db()->prepare(
                    'SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 2'
                );
                $stmt->execute([$username, $email]);
                foreach ($stmt->fetchAll() as $existing) {
                    if (strcasecmp($existing['username'], $username) === 0) {
                        $formError = 'We could not create an account with those details. Please review them and try again.';
                    }
                    if (strcasecmp($existing['email'], $email) === 0) {
                        $formError = 'We could not create an account with those details. Please review them and try again.';
                    }
                }
            } catch (Throwable $ex) {
                error_log('Registration uniqueness check failed: ' . $ex->getMessage());
                $formError = 'We could not validate your details right now. Please try again.';
            }
        }

        if (!$fieldErrors && !$formError) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, username, email, password_hash, birthdate, neighborhood)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $username,
                    $username,
                    $email,
                    hashPassword($password),
                    $birthdate,
                    '',
                ]);

                $userId = (int) $pdo->lastInsertId();
                createWallet($userId);
                applyWelcomeBonus($userId);
                $pdo->commit();

                if (EMAIL_FEATURES_ENABLED) {
                    try {
                        $verificationToken = issueAuthToken('email_verification', $userId, 1440);
                        sendAppEmail(
                            $email,
                            'Verify your EcoCycle email address',
                            "Welcome to EcoCycle!\n\n" .
                            "Confirm your email address within 24 hours:\n" .
                            appEmailUrl('verify_email.php', $verificationToken) . "\n\n" .
                            "If you did not create this account, you can ignore this email."
                        );
                    } catch (Throwable $mailEx) {
                        error_log('Registration verification email failed: ' . $mailEx->getMessage());
                    }
                }

                loginUser($userId);
                setFlash('success', 'Welcome to EcoCycle — your EcoWallet is ready!');
                header('Location: dashboard.php');
                exit;
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (($ex->errorInfo[0] ?? $ex->getCode()) === '23000') {
                    $formError = 'We could not create an account with those details. Please review them and try again.';
                } else {
                    error_log('Registration database operation failed: ' . $ex->getMessage());
                    $formError = 'We could not finish creating your account. Please try again.';
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Registration failed: ' . $ex->getMessage());
                $formError = 'We could not finish creating your account. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create your account';
require __DIR__ . '/includes/header.php';
$currentYear = (int) gmdate('Y');
?>
<section class="register-page">
    <div class="register-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1>Create Account</h1>
            <p>Join EcoCycle and start making a difference.</p>
        </header>

        <div class="register-form-message" role="status" aria-live="polite" aria-atomic="true" <?= e($formError === '' ? 'hidden' : '') ?>><?= e($formError) ?></div>

        <form id="registerForm" method="post" class="register-form" novalidate>
            <?= csrfField() ?>
            <div class="register-honeypot" aria-hidden="true">
                <label for="website">Leave this field empty</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div class="register-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" value="<?= e($values['username']) ?>"
                       placeholder="Username" minlength="3" maxlength="12" pattern="[A-Za-z0-9]{3,12}"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required
                       aria-describedby="username-help username-status username-error"
                       aria-invalid="<?= isset($fieldErrors['username']) ? 'true' : 'false' ?>">
                <p id="username-help" class="register-helper">3–12 letters and numbers</p>
                <p id="username-status" class="register-field-status" aria-live="polite"></p>
                <p id="username-error" class="register-field-error" aria-live="polite"><?= e($fieldErrors['username'] ?? '') ?></p>
            </div>

            <div class="register-field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="<?= e($values['email']) ?>"
                       placeholder="Email address" maxlength="190" autocomplete="email" required
                       aria-describedby="email-error"
                       aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>">
                <p id="email-error" class="register-field-error" aria-live="polite"><?= e($fieldErrors['email'] ?? '') ?></p>
            </div>

            <div class="register-field">
                <label for="password">Password</label>
                <div class="register-password-input">
                    <input id="password" name="password" type="password" placeholder="Password"
                           minlength="8" maxlength="64" autocomplete="new-password" required
                           aria-describedby="password-meter-label password-checks password-error"
                           aria-invalid="<?= isset($fieldErrors['password']) ? 'true' : 'false' ?>">
                    <button class="register-password-toggle" type="button" id="passwordToggle"
                            aria-label="Show password" aria-pressed="false">Show</button>
                </div>
                <div class="register-strength" role="meter" aria-label="Password strength" aria-valuemin="0"
                     aria-valuemax="4" aria-valuenow="0">
                    <?php for ($segment = 1; $segment <= 4; $segment++): ?>
                        <span data-strength-segment="<?= e($segment) ?>"></span>
                    <?php endfor; ?>
                </div>
                <p id="password-meter-label" class="register-helper">Password strength</p>
                <ul id="password-checks" class="register-checklist">
                    <li data-password-check="length">8–64 characters</li>
                    <li data-password-check="uppercase">An uppercase letter (A–Z)</li>
                    <li data-password-check="lowercase">A lowercase letter (a–z)</li>
                    <li data-password-check="number">A number (0–9)</li>
                </ul>
                <p id="password-error" class="register-field-error" aria-live="polite"><?= e($fieldErrors['password'] ?? '') ?></p>
            </div>

            <div class="register-field">
                <label for="confirm_password">Confirm password</label>
                <div class="register-password-input">
                    <input id="confirm_password" name="confirm_password" type="password"
                           placeholder="Confirm password" autocomplete="new-password" required
                           aria-describedby="confirm-status confirm_password-error"
                           aria-invalid="<?= isset($fieldErrors['confirm_password']) ? 'true' : 'false' ?>">
                    <button class="register-password-toggle" type="button" id="confirmToggle"
                            aria-label="Show confirmation password" aria-pressed="false">Show</button>
                </div>
                <p id="confirm-status" class="register-field-status" aria-live="polite"></p>
                <p id="confirm_password-error" class="register-field-error" aria-live="polite"><?= e($fieldErrors['confirm_password'] ?? '') ?></p>
            </div>

            <fieldset class="register-field register-birthdate">
                <legend>Birthdate</legend>
                <div class="register-birthdate-selects">
                    <label class="sr-only" for="birth_month">Birth month</label>
                    <select id="birth_month" name="birth_month" autocomplete="bday-month" required
                            aria-describedby="birthdate-error"
                            aria-invalid="<?= isset($fieldErrors['birthdate']) ? 'true' : 'false' ?>">
                        <option value="">Month</option>
                        <?php foreach ([
                            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
                        ] as $monthNumber => $monthName): ?>
                            <option value="<?= e($monthNumber) ?>" <?= e($values['birth_month'] === (string) $monthNumber ? 'selected' : '') ?>><?= e($monthName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="register-birthdate-row">
                        <label class="sr-only" for="birth_day">Birth day</label>
                        <select id="birth_day" name="birth_day" autocomplete="bday-day" required
                                aria-describedby="birthdate-error"
                                aria-invalid="<?= isset($fieldErrors['birthdate']) ? 'true' : 'false' ?>">
                            <option value="">Day</option>
                        </select>
                        <label class="sr-only" for="birth_year">Birth year</label>
                        <select id="birth_year" name="birth_year" autocomplete="bday-year" required
                                aria-describedby="birthdate-error"
                                aria-invalid="<?= isset($fieldErrors['birthdate']) ? 'true' : 'false' ?>">
                            <option value="">Year</option>
                            <?php for ($year = $currentYear - 13; $year >= 1900; $year--): ?>
                                <option value="<?= e($year) ?>" <?= e($values['birth_year'] === (string) $year ? 'selected' : '') ?>><?= e($year) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <p id="birthdate-help" class="register-helper">You must be at least 13 years old.</p>
                <p id="birthdate-error" class="register-field-error" aria-live="polite"><?= e($fieldErrors['birthdate'] ?? '') ?></p>
            </fieldset>

            <button id="createAccountButton" class="register-submit" type="submit" disabled>
                <span class="register-button-label">Create Account</span>
                <span class="register-button-loading" aria-hidden="true">Creating account…</span>
            </button>
            <a class="register-signin" href="login.php">Sign in</a>
        </form>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('registerForm');
    const username = document.getElementById('username');
    const email = document.getElementById('email');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const monthSelect = document.getElementById('birth_month');
    const daySelect = document.getElementById('birth_day');
    const yearSelect = document.getElementById('birth_year');
    const submitButton = document.getElementById('createAccountButton');
    const usernameStatus = document.getElementById('username-status');
    const confirmStatus = document.getElementById('confirm-status');
    const strengthMeter = document.querySelector('.register-strength');
    const strengthLabel = document.getElementById('password-meter-label');
    const formMessage = document.querySelector('.register-form-message');
    const birthdayError = document.getElementById('birthdate-error');
    const csrf = form.querySelector('input[name="csrf"]').value;
    let usernameState = 'idle';
    let usernameRequest = 0;
    let usernameTimer;
    let submitting = false;

    const passwordRules = {
        length: value => Array.from(value).length >= 8 && Array.from(value).length <= 64,
        uppercase: value => /[A-Z]/.test(value),
        lowercase: value => /[a-z]/.test(value),
        number: value => /[0-9]/.test(value)
    };

    function setFieldError(field, message) {
        const error = document.getElementById(field + '-error');
        const input = document.getElementById(field);
        if (error) error.textContent = message;
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
    }

    function checkUsername() {
        const value = username.value.trim();
        const valid = /^[A-Za-z0-9]{3,12}$/.test(value);
        setFieldError('username', valid ? '' : (value ? 'Use 3–12 letters and numbers only.' : ''));
        if (!valid) {
            usernameState = 'idle';
            usernameStatus.textContent = '';
            usernameStatus.classList.remove('is-success', 'is-error');
            updateFormValidity();
            return;
        }

        usernameState = 'checking';
        usernameStatus.textContent = 'Checking availability…';
        usernameStatus.classList.remove('is-success', 'is-error');
        const requestId = ++usernameRequest;
        window.clearTimeout(usernameTimer);
        usernameTimer = window.setTimeout(function () {
            fetch('register.php?check_username=' + encodeURIComponent(value), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Username check request failed.');
                return response.json();
            }).then(function (result) {
                if (requestId !== usernameRequest || value !== username.value.trim()) return;
                if (typeof result.available !== 'boolean') throw new Error(result.error || 'Username check failed.');
                usernameState = result.available ? 'available' : 'taken';
                usernameStatus.textContent = result.available ? 'That username is available.' : 'That username is already taken.';
                usernameStatus.classList.toggle('is-success', result.available);
                usernameStatus.classList.toggle('is-error', !result.available);
                setFieldError('username', result.available ? '' : 'Choose a different username.');
                updateFormValidity();
            }).catch(function () {
                if (requestId !== usernameRequest) return;
                usernameState = 'error';
                usernameStatus.textContent = 'We could not check availability. Please try again.';
                usernameStatus.classList.remove('is-success');
                usernameStatus.classList.add('is-error');
                updateFormValidity();
            });
        }, 250);
        updateFormValidity();
    }

    function updatePasswordState() {
        const rules = Object.keys(passwordRules).map(function (rule) {
            const passed = passwordRules[rule](password.value);
            const item = document.querySelector('[data-password-check="' + rule + '"]');
            item.classList.toggle('is-valid', passed);
            return passed;
        });
        const score = rules.filter(Boolean).length;
        strengthMeter.setAttribute('aria-valuenow', String(score));
        strengthMeter.setAttribute('aria-valuetext', ['No strength', 'Weak', 'Fair', 'Good', 'Strong'][score]);
        const strengthNames = ['No strength', 'Weak', 'Fair', 'Good', 'Strong'];
        strengthLabel.textContent = password.value
            ? 'Password strength: ' + strengthNames[score].toLowerCase()
            : 'Password strength';
        strengthMeter.querySelectorAll('[data-strength-segment]').forEach(function (segment, index) {
            segment.classList.toggle('is-filled', index < score);
            segment.classList.toggle('is-strong', score === 4 && index < score);
        });

        const valid = rules.every(Boolean);
        setFieldError('password', password.value && !valid ? 'Meet all four password requirements.' : '');
        updateConfirmState();
        updateFormValidity();
    }

    function updateConfirmState() {
        const match = confirmPassword.value !== '' && confirmPassword.value === password.value;
        const differs = confirmPassword.value !== '' && !match;
        confirmStatus.textContent = match ? 'Passwords match.' : (differs ? 'Passwords do not match yet.' : '');
        confirmStatus.classList.toggle('is-success', match);
        confirmStatus.classList.toggle('is-error', differs);
        setFieldError('confirm_password', differs ? 'Those passwords do not match yet.' : '');
        return match;
    }

    function updateDays() {
        const previousDay = daySelect.value;
        const month = Number(monthSelect.value);
        const year = Number(yearSelect.value) || (new Date()).getFullYear();
        const maxDays = month ? new Date(Date.UTC(year, month, 0)).getUTCDate() : 31;
        daySelect.replaceChildren(new Option('Day', ''));
        for (let day = 1; day <= maxDays; day++) {
            daySelect.add(new Option(String(day), String(day)));
        }
        if (previousDay && Number(previousDay) <= maxDays) {
            daySelect.value = previousDay;
        }
        updateBirthdateState();
    }

    function isOldEnough(year, month, day) {
        const today = new Date();
        const cutoffYear = today.getFullYear() - 13;
        const cutoffMonth = today.getMonth() + 1;
        const cutoffDay = today.getDate();
        return year < cutoffYear ||
            (year === cutoffYear && (month < cutoffMonth ||
            (month === cutoffMonth && day <= cutoffDay)));
    }

    function updateBirthdateState() {
        const month = Number(monthSelect.value);
        const day = Number(daySelect.value);
        const year = Number(yearSelect.value);
        if (!month || !day || !year) {
            birthdayError.textContent = '';
            monthSelect.setAttribute('aria-invalid', 'false');
            daySelect.setAttribute('aria-invalid', 'false');
            yearSelect.setAttribute('aria-invalid', 'false');
            updateFormValidity();
            return false;
        }
        const validDate = new Date(Date.UTC(year, month - 1, day));
        const matchesDate = validDate.getUTCFullYear() === year &&
            validDate.getUTCMonth() === month - 1 && validDate.getUTCDate() === day;
        const oldEnough = matchesDate && isOldEnough(year, month, day);
        const message = !matchesDate
            ? 'Choose a valid month, day, and year.'
            : (!oldEnough ? 'You need to be at least 13 to create an account.' : '');
        birthdayError.textContent = message;
        [monthSelect, daySelect, yearSelect].forEach(function (select) {
            select.setAttribute('aria-invalid', message ? 'true' : 'false');
        });
        updateFormValidity();
        return matchesDate && oldEnough;
    }

    function updateFormValidity() {
        const usernameValid = /^[A-Za-z0-9]{3,12}$/.test(username.value.trim()) &&
            usernameState === 'available';
        const emailValid = email.validity.valid && email.value.trim() !== '';
        const passwordValid = Object.keys(passwordRules).every(function (rule) {
            return passwordRules[rule](password.value);
        });
        const confirmValid = confirmPassword.value !== '' && confirmPassword.value === password.value;
        const birthdayValid = Boolean(monthSelect.value && daySelect.value && yearSelect.value) &&
            birthdayError.textContent === '';
        submitButton.disabled = submitting || !(
            usernameValid && emailValid && passwordValid && confirmValid && birthdayValid
        );
    }

    function bindPasswordToggle(buttonId, input) {
        const button = document.getElementById(buttonId);
        button.addEventListener('click', function () {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') +
                (input === password ? 'password' : 'confirmation password'));
        });
    }

    username.addEventListener('input', checkUsername);
    email.addEventListener('input', function () {
        setFieldError('email', email.value && !email.validity.valid ? 'Enter a valid email address.' : '');
        updateFormValidity();
    });
    password.addEventListener('input', updatePasswordState);
    confirmPassword.addEventListener('input', updateConfirmState);
    monthSelect.addEventListener('change', updateDays);
    yearSelect.addEventListener('change', updateDays);
    daySelect.addEventListener('change', updateBirthdateState);
    bindPasswordToggle('passwordToggle', password);
    bindPasswordToggle('confirmToggle', confirmPassword);
    updateDays();
    setFieldError('email', email.value && !email.validity.valid ? 'Enter a valid email address.' : '');
    checkUsername();
    updatePasswordState();

    form.addEventListener('submit', function (event) {
        updatePasswordState();
        updateConfirmState();
        const birthdateValid = updateBirthdateState();
        if (usernameState !== 'available' || !email.validity.valid ||
            !Object.keys(passwordRules).every(function (rule) { return passwordRules[rule](password.value); }) ||
            !updateConfirmState() || !birthdateValid) {
            event.preventDefault();
            formMessage.hidden = false;
            formMessage.textContent = 'Please check the highlighted fields before continuing.';
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) firstInvalid.focus();
            return;
        }

        submitting = true;
        submitButton.classList.add('is-loading');
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
