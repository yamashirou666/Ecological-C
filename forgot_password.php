<?php
/**
 * EcoCycle — request a single-use password reset link.
 */
require_once __DIR__ . '/includes/functions.php';

if (!EMAIL_FEATURES_ENABLED) {
    header('Location: login.php');
    exit;
}

$message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) && is_string($_POST['email'])
        ? strtolower(trim($_POST['email']))
        : '';
    $message = 'If an account exists for that email, we’ll send a password reset link shortly.';

    if (!verifyCsrf()) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (empty($_POST['website']) && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190) {
        $ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) !== false
            ? (string) $_SERVER['REMOTE_ADDR']
            : '';
        try {
            if (!emailActionRateLimited('password-reset', $email, $ipAddress)) {
                recordEmailAction('password-reset', $email, $ipAddress);
                $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    $token = issueAuthToken('password_reset', (int) $user['id'], 30);
                    $url = appEmailUrl('reset_password.php', $token);
                    sendAppEmail(
                        $user['email'],
                        'Reset your EcoCycle password',
                        "We received a request to reset your EcoCycle password.\n\n" .
                        "Use this one-time link within 30 minutes:\n{$url}\n\n" .
                        "If you did not request this, you can ignore this email."
                    );
                }
            }
        } catch (Throwable $ex) {
            error_log('Password reset email operation failed: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/includes/header.php';
?>
<section class="register-page">
    <div class="register-card login-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1>Reset your password</h1>
            <p>Enter the email address linked to your EcoCycle account.</p>
        </header>

        <?php if ($message !== ''): ?>
            <div class="register-form-message" role="status" aria-live="polite"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="post" class="register-form">
            <?= csrfField() ?>
            <div class="register-honeypot" aria-hidden="true">
                <label for="website">Leave this field empty</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
            <div class="register-field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="<?= e($email) ?>"
                       autocomplete="email" maxlength="190" required>
            </div>
            <button class="register-submit" type="submit">Send reset link</button>
            <a class="register-signin" href="login.php">Back to sign in</a>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
