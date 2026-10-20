<?php
/**
 * EcoCycle — require users with temporary passwords to choose a new password.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
if (!$user || (int) $user['must_change_password'] !== 1) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';

    if (!verifyCsrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($password !== $confirmation) {
        $error = 'Those passwords do not match.';
    } elseif ($policyError = passwordPolicyError($password)) {
        $error = $policyError;
    } elseif (password_verify($password, (string) $user['password_hash'])) {
        $error = 'Choose a password different from your temporary password.';
    } else {
        try {
            $stmt = db()->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ? AND must_change_password = 1'
            );
            $stmt->execute([hashPassword($password), (int) $user['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The required password change could not be applied.');
            }

            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            setFlash('success', 'Your password has been changed.');
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $ex) {
            error_log('Required password change failed for user ' . (int) $user['id'] . ': ' . $ex->getMessage());
            $error = 'We could not update your password right now. Please try again.';
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
            <p>Your account has a temporary password. Set a new password to continue.</p>
        </header>

        <div class="register-form-message" role="alert" aria-live="polite" aria-atomic="true" <?= e($error === '' ? 'hidden' : '') ?>><?= e($error) ?></div>

        <form method="post" class="register-form">
            <?= csrfField() ?>
            <div class="register-field">
                <label for="password">New password</label>
                <div class="register-password-input">
                    <input id="password" name="password" type="password" minlength="8" maxlength="64"
                           autocomplete="new-password" required>
                    <button class="register-password-toggle" type="button" id="passwordToggle"
                            aria-label="Show new password" aria-pressed="false">Show</button>
                </div>
                <p class="register-helper">8–64 characters with uppercase, lowercase, and a number.</p>
            </div>
            <div class="register-field">
                <label for="confirm_password">Confirm new password</label>
                <div class="register-password-input">
                    <input id="confirm_password" name="confirm_password" type="password"
                           autocomplete="new-password" required>
                    <button class="register-password-toggle" type="button" id="confirmToggle"
                            aria-label="Show confirmation password" aria-pressed="false">Show</button>
                </div>
            </div>
            <button class="register-submit" type="submit">Save new password</button>
        </form>
        <form method="post" action="logout.php" class="mt-4">
            <?= csrfField() ?>
            <button class="register-signin" type="submit">Log out</button>
        </form>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    [
        ['password', 'passwordToggle', 'Show new password', 'Hide new password'],
        ['confirm_password', 'confirmToggle', 'Show confirmation password', 'Hide confirmation password']
    ].forEach(function (fields) {
        const input = document.getElementById(fields[0]);
        const button = document.getElementById(fields[1]);
        button.addEventListener('click', function () {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? fields[3] : fields[2]);
        });
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
