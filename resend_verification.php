<?php
/**
 * EcoCycle — resend an email verification link to the signed-in user.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!EMAIL_FEATURES_ENABLED) {
    header('Location: rewards.php');
    exit;
}

$user = currentUser();
$userId = (int) $user['id'];
$message = '';
$messageType = 'status';
$stmt = db()->prepare('SELECT email, email_verified_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$account = $stmt->fetch();
if (!$account) {
    throw new RuntimeException('The signed-in account could not be found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $message = 'Your request could not be verified. Reload the page and try again.';
        $messageType = 'alert';
    } elseif ($account['email_verified_at'] !== null) {
        $message = 'Your email address is already verified.';
    } else {
        $ipAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) !== false
            ? (string) $_SERVER['REMOTE_ADDR']
            : '';
        try {
            if (emailActionRateLimited('email-verification', (string) $account['email'], $ipAddress)) {
                $message = 'Too many verification requests. Please wait before trying again.';
            } else {
                recordEmailAction('email-verification', (string) $account['email'], $ipAddress);
                $token = issueAuthToken('email_verification', $userId, 1440);
                sendAppEmail(
                    (string) $account['email'],
                    'Verify your EcoCycle email address',
                    "Confirm your email address within 24 hours:\n" .
                    appEmailUrl('verify_email.php', $token) . "\n\n" .
                    "If you did not request this message, you can ignore it."
                );
                $message = 'If delivery is configured, a new verification link has been sent. It is valid for 24 hours.';
            }
        } catch (Throwable $ex) {
            error_log('Email verification resend failed for user ' . $userId . ': ' . $ex->getMessage());
            $message = 'We could not send a verification link right now. Please try again later.';
            $messageType = 'alert';
        }
    }
}

$pageTitle = 'Verify your email';
require __DIR__ . '/includes/header.php';
?>
<section class="register-page">
    <div class="register-card login-card">
        <header class="register-heading">
            <div class="register-brand-mark" aria-hidden="true">♻️</div>
            <h1>Verify your email</h1>
            <p>Verify your email address to redeem rewards.</p>
        </header>

        <?php if ($message !== ''): ?>
            <div class="register-form-message" role="<?= e($messageType) ?>" aria-live="polite"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($account['email_verified_at'] === null): ?>
            <form method="post" class="register-form">
                <?= csrfField() ?>
                <button class="register-submit" type="submit">Resend verification email</button>
            </form>
        <?php endif; ?>

        <a class="register-signin" href="rewards.php">Back to rewards</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
