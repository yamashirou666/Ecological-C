<?php
/**
 * EcoCycle — account login.
 */
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$identifier = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = isset($_POST['identifier']) && is_string($_POST['identifier'])
        ? trim($_POST['identifier'])
        : '';
    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';

    if (!verifyCsrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($identifier === '' || strlen($identifier) > 190 || $password === '' || strlen($password) > 4096) {
        $error = 'Invalid credentials';
    } else {
        $ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) !== false
            ? (string) $_SERVER['REMOTE_ADDR']
            : '';
        try {
            if (!empty($_POST['website'])) {
                if (!loginIsRateLimited($identifier, $ipAddress)) {
                    recordLoginAttempt($identifier, $ipAddress, false);
                }
                $error = 'Invalid credentials';
            } else {
                $stmt = db()->prepare(
                    'SELECT id, name, password_hash, must_change_password FROM users
                     WHERE username = ? OR email = ?
                     LIMIT 1'
                );
                $stmt->execute([$identifier, strtolower($identifier)]);
                $user = $stmt->fetch();
                $rateLimitKey = $user ? 'user:' . (string) $user['id'] : $identifier;
                if (loginIsRateLimited($rateLimitKey, $ipAddress)) {
                    $error = 'Invalid credentials';
                } else {
                    $authenticated = $user && password_verify($password, $user['password_hash']);
                    recordLoginAttempt($rateLimitKey, $ipAddress, (bool) $authenticated);

                    if ($authenticated) {
                        loginUser((int) $user['id']);
                        if ((int) $user['must_change_password'] === 1) {
                            setFlash('info', 'For your security, choose a new password before continuing.');
                            header('Location: force_password_change.php');
                            exit;
                        }
                        setFlash('success', 'Welcome back, ' . $user['name'] . '!');
                        header('Location: dashboard.php');
                        exit;
                    }
                    $error = 'Invalid credentials';
                }
            }
        } catch (Throwable $ex) {
            error_log('Login authentication operation failed: ' . $ex->getMessage());
            $error = 'Invalid credentials';
        }
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<section class="register-page">
    <div class="register-card login-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1>Welcome Back</h1>
            <p>Sign in to continue your EcoCycle journey.</p>
        </header>

        <div class="register-form-message" role="alert" aria-live="polite" aria-atomic="true" <?= e($error === '' ? 'hidden' : '') ?>><?= e($error) ?></div>

        <form id="loginForm" method="post" class="register-form" novalidate>
            <?= csrfField() ?>
            <div class="register-honeypot" aria-hidden="true">
                <label for="website">Leave this field empty</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div class="register-field">
                <label for="identifier">Username or email</label>
                <input id="identifier" name="identifier" type="text" value="<?= e($identifier) ?>"
                       placeholder="Username or email" autocomplete="username" autocapitalize="none"
                       spellcheck="false" required>
            </div>

            <div class="register-field">
                <label for="password">Password</label>
                <div class="register-password-input">
                    <input id="password" name="password" type="password" placeholder="Password"
                           autocomplete="current-password" required>
                    <button class="register-password-toggle" type="button" id="passwordToggle"
                            aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <?php if (EMAIL_FEATURES_ENABLED): ?>
                <div class="login-actions">
                    <a class="login-forgot-link" href="forgot_password.php">Forgot password?</a>
                </div>
            <?php endif; ?>

            <button id="loginButton" class="register-submit" type="submit">
                <span class="register-button-label">Sign In</span>
                <span class="register-button-loading" aria-hidden="true">Signing in…</span>
            </button>
            <a class="register-signin" href="register.php">Create account</a>
        </form>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    const password = document.getElementById('password');
    const toggle = document.getElementById('passwordToggle');
    const button = document.getElementById('loginButton');
    let submitting = false;

    toggle.addEventListener('click', function () {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.textContent = visible ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', String(visible));
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    });

    form.addEventListener('submit', function (event) {
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        button.classList.add('is-loading');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
