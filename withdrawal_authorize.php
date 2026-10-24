<?php
/**
 * Single-use authorization target encoded by EcoCycle withdrawal QR codes.
 */
require_once __DIR__ . '/includes/functions.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$requestId = filter_var(
    $isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null),
    FILTER_VALIDATE_INT
);
$token = (string) ($isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$authorized = false;
$readyToAuthorize = false;
$error = '';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

try {
    if (!$requestId || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('This authorization link is invalid.');
    }

    $requestStmt = db()->prepare(
        'SELECT user_id, status, pin_verified_at, expires_at
           FROM withdrawal_requests
          WHERE id = ? AND authorization_token_hash = ?'
    );
    $requestStmt->execute([$requestId, hash('sha256', $token)]);
    $request = $requestStmt->fetch();
    if (!$request) {
        throw new RuntimeException('This authorization link is invalid or has already been used.');
    }

    if ($isPost) {
        if (!verifyCsrf()) {
            throw new RuntimeException('Your authorization page expired. Reopen the QR link and try again.');
        }
        sweepExpiredWithdrawalRequests((int) $request['user_id']);
        consumeWithdrawalAuthorizationTokenById($requestId, $token);
        $authorized = true;
    } else {
        $readyToAuthorize = $request['status'] === 'pending'
            && $request['pin_verified_at'] !== null
            && strtotime($request['expires_at']) > time();
        if (!$readyToAuthorize) {
            throw new RuntimeException('This authorization request has expired or is no longer ready.');
        }
    }
} catch (Throwable $ex) {
    if ($ex instanceof PDOException) {
        error_log('Withdrawal QR authorization failed: ' . $ex->getMessage());
        $error = 'Authorization could not be completed. Return to EcoWallet and try again.';
    } else {
        $error = $ex->getMessage();
    }
}

$pageTitle = 'Payout Authorization — EcoCycle';
require __DIR__ . '/includes/header.php';
?>

<main id="main" class="flex-1 max-w-xl mx-auto px-4 py-12 w-full">
    <section class="checkout-surface rounded-2xl border p-6 sm:p-8 text-center" aria-live="polite">
        <a href="wallet.php" class="checkout-logo justify-center mb-6">
            <span class="brand-mark" aria-hidden="true">♻️</span>
            <span>EcoCycle</span>
        </a>
        <?php if ($authorized): ?>
            <p class="text-5xl mb-4" aria-hidden="true">✓</p>
            <h1 class="text-2xl font-extrabold mb-3">Payout authorized</h1>
            <p class="checkout-muted">This one-time EcoCycle authorization was accepted. Return to the payout window to see the simulated completion.</p>
        <?php elseif ($readyToAuthorize): ?>
            <p class="text-5xl mb-4" aria-hidden="true">♻️</p>
            <h1 class="text-2xl font-extrabold mb-3">Authorize this simulated payout?</h1>
            <p class="checkout-muted mb-5">This link can authorize only the EcoWallet withdrawal associated with this QR code. Confirm only if you initiated this payout.</p>
            <form method="post" action="withdrawal_authorize.php" class="mt-6">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $requestId ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button type="submit" class="checkout-primary-button">Authorize payout</button>
            </form>
        <?php else: ?>
            <p class="text-5xl mb-4" aria-hidden="true">!</p>
            <h1 class="text-2xl font-extrabold mb-3">Authorization not completed</h1>
            <p class="checkout-muted"><?= e($error) ?></p>
            <p class="checkout-muted text-sm mt-4">The payout is simulated. No real money moves.</p>
        <?php endif; ?>
        <a href="wallet.php" class="checkout-secondary-button mt-7">Return to EcoWallet</a>
        <p class="checkout-muted text-xs mt-5">Payouts are simulated. No real money moves.</p>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
