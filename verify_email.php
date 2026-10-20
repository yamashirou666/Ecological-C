<?php
/**
 * EcoCycle — verify an email address using a one-time link.
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
$message = '';
$verified = false;

if ($tokenValid) {
    $tokenStmt = db()->prepare(
        'SELECT 1 FROM email_verifications
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
          LIMIT 1'
    );
    $tokenStmt->execute([hash('sha256', $token)]);
    $tokenValid = (bool) $tokenStmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$tokenValid) {
        $message = 'This verification link is invalid or has expired. Sign in to request a new one.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $tokenStmt = $pdo->prepare(
                'SELECT id, user_id FROM email_verifications
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
                  LIMIT 1 FOR UPDATE'
            );
            $tokenStmt->execute([hash('sha256', $token)]);
            $verification = $tokenStmt->fetch();
            if (!$verification) {
                $pdo->rollBack();
                $tokenValid = false;
                $message = 'This verification link is invalid or has expired. Sign in to request a new one.';
            } else {
                $verifyUser = $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?');
                $verifyUser->execute([(int) $verification['user_id']]);
                $consume = $pdo->prepare(
                    'UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
                );
                $consume->execute([(int) $verification['user_id']]);
                $pdo->commit();
                $verified = true;
                $message = 'Your email address is verified. Thank you!';
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Email verification failed: ' . $ex->getMessage());
            $message = 'We could not verify your email right now. Please try again.';
        }
    }
} elseif (!$tokenValid && $token !== '') {
    $message = 'This verification link is invalid or has expired. Sign in to request a new one.';
}

$pageTitle = 'Verify your email';
require __DIR__ . '/includes/header.php';
?>
<section class="register-page">
    <div class="register-card login-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1><?= e($verified ? 'Email verified' : 'Verify your email') ?></h1>
            <p><?= e($verified ? 'Your EcoCycle account is ready to use.' : 'Confirm your email address to unlock reward redemption.') ?></p>
        </header>

        <?php if ($message !== ''): ?>
            <div class="register-form-message <?= e($verified ? 'auth-success-message' : '') ?>" role="<?= e($verified ? 'status' : 'alert') ?>" aria-live="polite"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$verified && $tokenValid): ?>
            <form method="post" class="register-form">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button class="register-submit" type="submit">Verify email address</button>
            </form>
        <?php endif; ?>

        <a class="register-signin" href="<?= e(isLoggedIn() ? 'dashboard.php' : 'login.php') ?>">
            <?= e(isLoggedIn() ? 'Back to EcoCycle' : 'Sign in') ?>
        </a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
