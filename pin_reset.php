<?php
/**
 * EcoCycle — password-verified wallet PIN reset.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
$userId = (int) $user['id'];
$attemptKey = 'pin-reset:user:' . $userId;
$ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$errors = [];
$success = '';
$authorizedUntil = (int) ($_SESSION['wallet_pin_reset_authorized_until'] ?? 0);
$authorizedUserId = (int) ($_SESSION['wallet_pin_reset_authorized_user_id'] ?? 0);
$canSetPin = $authorizedUserId === $userId && $authorizedUntil > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Your request could not be verified. Reload the page and try again.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'verify_password') {
            $genericPasswordError = 'Unable to verify your account details. Please try again later.';
            try {
                if (loginIsRateLimited($attemptKey, $ipAddress)) {
                    $errors[] = $genericPasswordError;
                } else {
                    $passwordStmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
                    $passwordStmt->execute([$userId]);
                    $passwordHash = $passwordStmt->fetchColumn();
                    $passwordAttempt = is_string($_POST['account_password'] ?? null)
                        ? $_POST['account_password']
                        : '';
                    $validPassword = is_string($passwordHash)
                        && password_verify($passwordAttempt, $passwordHash);
                    recordLoginAttempt($attemptKey, $ipAddress, $validPassword);

                    if ($validPassword) {
                        $_SESSION['wallet_pin_reset_authorized_user_id'] = $userId;
                        $_SESSION['wallet_pin_reset_authorized_until'] = time() + 600;
                        header('Location: pin_reset.php?step=new');
                        exit;
                    }
                    $errors[] = $genericPasswordError;
                }
            } catch (Throwable $ex) {
                error_log('Wallet PIN reset password verification failed for user ' . $userId . ': ' . $ex->getMessage());
                $errors[] = $genericPasswordError;
            }
        } elseif ($action === 'set_pin' && $canSetPin) {
            $pin = is_string($_POST['new_pin'] ?? null) ? $_POST['new_pin'] : '';
            $confirmation = is_string($_POST['confirm_pin'] ?? null) ? $_POST['confirm_pin'] : '';
            if (!preg_match('/^[0-9]{4,6}$/D', $pin)
                || $pin !== $confirmation
                || isTrivialWalletPin($pin)) {
                $errors[] = 'Unable to reset your PIN. Choose a new 4–6 digit PIN and confirm it.';
            } else {
                try {
                    resetWalletPin($userId, $pin);
                    unset(
                        $_SESSION['wallet_pin_reset_authorized_user_id'],
                        $_SESSION['wallet_pin_reset_authorized_until']
                    );
                    $success = 'Your wallet PIN has been reset. Any open withdrawals were cancelled and their reserved points returned.';
                    try {
                        sendWalletPinChangedNotice($userId);
                    } catch (Throwable $mailError) {
                        error_log('Wallet PIN reset notice could not be sent for user ' . $userId . ': ' . $mailError->getMessage());
                    }
                    $canSetPin = false;
                } catch (Throwable $ex) {
                    error_log('Wallet PIN reset failed for user ' . $userId . ': ' . $ex->getMessage());
                    $errors[] = 'Your PIN could not be reset. Please try again.';
                }
            }
        } else {
            unset(
                $_SESSION['wallet_pin_reset_authorized_user_id'],
                $_SESSION['wallet_pin_reset_authorized_until']
            );
            $canSetPin = false;
            $errors[] = 'Your reset session expired. Verify your account password again.';
        }
    }
}

if ((string) ($_GET['step'] ?? '') === 'new' && !$canSetPin) {
    unset(
        $_SESSION['wallet_pin_reset_authorized_user_id'],
        $_SESSION['wallet_pin_reset_authorized_until']
    );
    $canSetPin = false;
}

$pageTitle = 'Reset EcoWallet PIN';
require __DIR__ . '/includes/header.php';
?>

<main id="main" class="flex-1 max-w-xl mx-auto px-4 py-12 w-full">
    <section class="checkout-surface rounded-2xl border p-6 sm:p-8">
        <a href="wallet.php" class="checkout-logo justify-center mb-6">
            <span class="brand-mark" aria-hidden="true">♻️</span>
            <span>EcoCycle</span>
        </a>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-center mb-2">Reset wallet PIN</h1>
        <p class="checkout-muted text-sm text-center mb-6">For your security, confirm your EcoCycle account password before changing your wallet PIN.</p>

        <?php if ($errors): ?>
            <div class="checkout-error rounded-xl p-4 mb-5" role="alert" aria-live="polite">
                <?= e(implode(' ', $errors)) ?>
            </div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="checkout-info-banner rounded-xl p-4 mb-5" role="status">
                <?= e($success) ?>
                <?php if ($cooldownUntil = getWalletPinResetCooldownUntil($userId)): ?>
                    Withdrawals are on hold until <?= e(date('M j, Y g:i A', strtotime($cooldownUntil))) ?> (<?= e(APP_TIMEZONE) ?> time).
                <?php endif; ?>
            </div>
        <?php elseif ($canSetPin): ?>
            <form method="post" class="space-y-5">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_pin">
                <div>
                    <label for="new_pin" class="checkout-label">New wallet PIN</label>
                    <input id="new_pin" name="new_pin" type="password" class="checkout-input text-center tracking-[0.5em] text-xl" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" required>
                </div>
                <div>
                    <label for="confirm_pin" class="checkout-label">Confirm new wallet PIN</label>
                    <input id="confirm_pin" name="confirm_pin" type="password" class="checkout-input text-center tracking-[0.5em] text-xl" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" required>
                </div>
                <p class="checkout-muted text-sm">Use 4–6 digits. Avoid repeated digits or simple sequences such as 1234 or 4321, and do not reuse your current PIN.</p>
                <button type="submit" class="checkout-primary-button w-full">Reset wallet PIN</button>
            </form>
        <?php else: ?>
            <form method="post" class="space-y-5">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="verify_password">
                <div>
                    <label for="account_password" class="checkout-label">EcoCycle account password</label>
                    <input id="account_password" name="account_password" type="password" class="checkout-input" autocomplete="current-password" required>
                </div>
                <button type="submit" class="checkout-primary-button w-full">Continue</button>
            </form>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <a href="wallet.php" class="checkout-link">Back to EcoWallet</a>
        </div>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
