<?php
/**
 * EcoCycle — consume a single-use password reset link.
 */
require_once __DIR__ . '/includes/functions.php';

if (!EMAIL_FEATURES_ENABLED) {
    header('Location: login.php');
    exit;
}

$token = isset($_POST['token']) && is_string($_POST['token'])
    ? $_POST['token']
    : (isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '');
$tokenValid = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$error = '';
$complete = false;

if ($tokenValid) {
    $stmt = db()->prepare(
        'SELECT id FROM password_resets
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $tokenValid = (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';

    if (!verifyCsrf()) {
        $error = 'Your session expired. Please refresh this page and try again.';
    } elseif (!$tokenValid) {
        $error = 'This reset link is invalid or has expired. Request a new link to continue.';
    } elseif ($password !== $confirmation) {
        $error = 'Those passwords do not match.';
    } elseif ($policyError = passwordPolicyError($password)) {
        $error = $policyError;
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $resetStmt = $pdo->prepare(
                'SELECT id, user_id FROM password_resets
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
                  LIMIT 1 FOR UPDATE'
            );
            $resetStmt->execute([hash('sha256', $token)]);
            $reset = $resetStmt->fetch();
            if (!$reset) {
                $pdo->rollBack();
                $tokenValid = false;
                $error = 'This reset link is invalid or has expired. Request a new link to continue.';
            } else {
                $updatePassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $updatePassword->execute([hashPassword($password), (int) $reset['user_id']]);
                $consumeTokens = $pdo->prepare(
                    'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
                );
                $consumeTokens->execute([(int) $reset['user_id']]);
                $pdo->commit();
                $complete = true;
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password reset failed: ' . $ex->getMessage());
            $error = 'We could not reset your password right now. Please try again.';
        }
    }
}

$pageTitle = 'Choose a new password';
require __DIR__ . '/includes/header.php';
?>
<section class="register-page">
    <div class="register-card login-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1>Choose a new password</h1>
            <p>Use a password you haven’t used for another account.</p>
        </header>

        <?php if ($complete): ?>
            <div class="register-form-message auth-success-message" role="status" aria-live="polite">Your password has been reset. You can now sign in.</div>
            <a class="register-submit auth-card-link-button" href="login.php">Sign in</a>
        <?php elseif (!$tokenValid): ?>
            <div class="register-form-message" role="alert" aria-live="polite">
                <?= e($error !== '' ? $error : 'This reset link is invalid or has expired. Request a new link to continue.') ?>
            </div>
            <a class="register-submit auth-card-link-button" href="forgot_password.php">Request another reset link</a>
        <?php else: ?>
            <div class="register-form-message" role="alert" aria-live="polite" <?= e($error === '' ? 'hidden' : '') ?>><?= e($error) ?></div>
            <form method="post" class="register-form">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="register-field">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" minlength="8" maxlength="64"
                           autocomplete="new-password" required>
                    <p class="register-helper">8–64 characters with uppercase, lowercase, and a number.</p>
                </div>
                <div class="register-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password"
                           autocomplete="new-password" required>
                </div>
                <button class="register-submit" type="submit">Save new password</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
