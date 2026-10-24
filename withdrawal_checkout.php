<?php
/**
 * EcoCycle — simulated withdrawal checkout and payout authorization.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (!WALLET_CHECKOUT_ENABLED) {
    header('Location: wallet.php');
    exit;
}

$user = currentUser();
$userId = (int) $user['id'];
sweepExpiredWithdrawalRequests($userId);

$wallet = getUserWallet($userId);
if (!$wallet) {
    setFlash('error', 'You have no wallet.');
    header('Location: dashboard.php');
    exit;
}

$pinResetCooldownUntil = getWalletPinResetCooldownUntil($userId);
$hasPin = hasPin($userId);
$isLocked = isWalletLocked($userId);
$lockoutRemaining = getWalletLockoutRemaining($userId);
$savedPayoutMethods = getSavedPayoutMethods($userId);
$dailyWithdrawalTotal = getDailyWithdrawalTotal($userId);
$withdrawalRemainingQuota = max(0, WALLET_DAILY_WITHDRAWAL_CAP - $dailyWithdrawalTotal);
$availableBalance = (int) $wallet['balance'];
$maximumAmount = min($availableBalance, $withdrawalRemainingQuota);
$requestedStep = (string) ($_GET['step'] ?? 'payout');
$isNewWithdrawal = $requestedStep === 'start';
$step = in_array($requestedStep, ['payout', 'start', 'review', 'authorization', 'complete', 'result'], true)
    ? $requestedStep
    : 'payout';
if ($step === 'start' || $step === 'result') {
    $step = 'payout';
}
$errors = [];
$formValues = [
    'amount' => '',
    'method' => 'voucher',
    'provider' => 'EcoCycle Eco-Shop Voucher',
    'account_holder' => '',
    'account' => '',
    'saved_method_id' => '0',
    'save_method' => false,
];
$checkoutKey = 'withdrawal_checkout';
$startIdempotencyToken = getWithdrawalIdempotencyToken('checkout');
$terminalResult = null;
if ($isNewWithdrawal) {
    unset($_SESSION['withdrawal_checkout_completed'], $_SESSION['withdrawal_checkout_terminal']);
}
if ($requestedStep === 'result') {
    $terminalResult = $_SESSION['withdrawal_checkout_terminal'] ?? null;
    unset($_SESSION['withdrawal_checkout_terminal']);
    if (!is_array($terminalResult)) {
        header('Location: withdrawal_checkout.php?step=start');
        exit;
    }
}
$checkoutState = $_SESSION[$checkoutKey] ?? null;
if (is_array($checkoutState) && array_key_exists('authorization_token', $checkoutState)) {
    unset($checkoutState['authorization_token']);
    $_SESSION[$checkoutKey] = $checkoutState;
}
$request = null;
$completedVoucher = $_SESSION['withdrawal_checkout_completed'] ?? null;
$authorizationToken = '';
$activeRequest = false;

if (is_array($checkoutState)) {
    $request = getWithdrawalRequest(
        $userId,
        (int) ($checkoutState['id'] ?? 0),
        (string) ($checkoutState['token'] ?? '')
    );
    if ($request) {
        $derivedAuthorizationToken = deriveWithdrawalAuthorizationToken(
            $userId,
            (string) $request['reference_id'],
            (string) ($checkoutState['token'] ?? '')
        );
        if (hash_equals(
            (string) $request['authorization_token_hash'],
            hash('sha256', $derivedAuthorizationToken)
        )) {
            $authorizationToken = $derivedAuthorizationToken;
        }
    }
    if (!$request) {
        unset($_SESSION[$checkoutKey]);
        $checkoutState = null;
        $authorizationToken = '';
        if (in_array($requestedStep, ['review', 'authorization'], true)) {
            $errors[] = 'That withdrawal request is no longer active. Start a new withdrawal below.';
            $step = 'payout';
        }
    } elseif (in_array($request['status'], ['completed', 'expired', 'cancelled'], true)) {
        unset($_SESSION[$checkoutKey]);
        $checkoutState = null;
        $authorizationToken = '';
        if (in_array($requestedStep, ['review', 'authorization'], true)) {
            $errors[] = 'That withdrawal is no longer active. Start a new withdrawal below.';
        }
        $request = null;
    } elseif (in_array($request['status'], ['pending', 'authorized'], true)
        && (int) $request['seconds_remaining'] > 0) {
        $activeRequest = true;
        if ($requestedStep === 'review') {
            $step = 'review';
        } elseif ($requestedStep === 'authorization') {
            $step = ($request['status'] === 'authorized' || $request['pin_verified_at'] !== null)
                ? 'authorization'
                : 'review';
        } else {
            $step = 'payout';
        }
    } else {
        unset($_SESSION[$checkoutKey]);
        $checkoutState = null;
        $authorizationToken = '';
        $request = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestedStep === 'authorization' && !$activeRequest) {
    header('Location: withdrawal_checkout.php?step=start');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array((string) ($_POST['action'] ?? ''), ['poll', 'simulate_scan'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the payout page and try again.']);
        exit;
    }

    try {
        sweepExpiredWithdrawalRequests($userId);
        $checkoutState = $_SESSION[$checkoutKey] ?? null;
        if (!is_array($checkoutState)) {
            throw new RuntimeException('This payout request is no longer available.');
        }
        $requestId = (int) ($checkoutState['id'] ?? 0);
        $checkoutToken = (string) ($checkoutState['token'] ?? '');
        $request = getWithdrawalRequest($userId, $requestId, $checkoutToken);
        if (!$request) {
            throw new RuntimeException('This payout request could not be verified.');
        }

        if ($_POST['action'] === 'simulate_scan') {
            $qrToken = deriveWithdrawalAuthorizationToken(
                $userId,
                (string) $request['reference_id'],
                $checkoutToken
            );
            $request = consumeWithdrawalAuthorizationToken($userId, $requestId, $qrToken);
        } elseif ($request['status'] === 'authorized'
            && $request['authorization_token_used_at'] !== null
            && strtotime($request['expires_at']) > time()) {
            $completed = completeAuthorizedWithdrawalRequest($userId, $requestId, $checkoutToken);
            $request = getWithdrawalRequest($userId, $requestId, $checkoutToken);
            $warnings = [];
            try {
                logWithdrawalAudit(
                    $userId,
                    'withdrawal_completed',
                    (int) $completed['amount'],
                    (string) $completed['payout_method'],
                    (int) $completed['id'],
                    ['reference_id' => $completed['reference_id']]
                );
            } catch (Throwable $ex) {
                $warnings[] = 'The withdrawal completed, but its audit record could not be saved.';
            }
            try {
                sendWalletNotification($userId, 'withdrawal', [
                    'amount' => $completed['amount'],
                    'method' => $completed['payout_method'],
                    'reference_id' => $completed['reference_id'],
                    'status' => 'completed',
                    'new_balance' => $completed['balance_after'],
                ]);
            } catch (Throwable $ex) {
                $warnings[] = 'The withdrawal completed, but its notification could not be recorded.';
            }
        }

        $statusMessages = [
            'pending' => 'Pending — waiting for the one-time QR authorization.',
            'authorized' => 'Authorized — finalizing your simulated payout.',
            'completed' => 'Completed — your simulated payout is recorded in Transaction History.',
            'expired' => 'Expired — your reserved points have been returned.',
            'cancelled' => 'Cancelled — your reserved points have been returned.',
        ];
        $response = [
            'status' => $request['status'],
            'expires_at' => $request['expires_at'],
            'seconds_remaining' => (int) $request['seconds_remaining'],
            'reference_id' => $request['reference_id'],
            'transaction_id' => $request['transaction_id'] === null ? null : (int) $request['transaction_id'],
            'message' => $statusMessages[$request['status']] ?? 'Payout status updated.',
            'warnings' => $warnings ?? [],
        ];
        if (in_array($request['status'], ['completed', 'expired', 'cancelled'], true)) {
            $_SESSION['withdrawal_checkout_terminal'] = [
                'status' => $request['status'],
                'points_amount' => (int) $request['points_amount'],
                'reference_id' => (string) $request['reference_id'],
                'transaction_id' => $request['transaction_id'] === null
                    ? null
                    : (int) $request['transaction_id'],
            ];
            unset($_SESSION[$checkoutKey]);
            $response['redirect_url'] = 'withdrawal_checkout.php?step=result';
        }
        echo json_encode($response);
    } catch (Throwable $ex) {
        http_response_code(400);
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'start') {
            if (!isWithdrawalIdempotencyTokenValid('checkout', (string) ($_POST['idempotency_token'] ?? ''))) {
                $errors[] = 'This checkout form has already been submitted or expired. Refresh and try again.';
            }
            $amountText = trim((string) ($_POST['amount'] ?? ''));
            $method = (string) ($_POST['payout_method'] ?? '');
            $provider = trim((string) ($_POST['provider'] ?? ''));
            $accountHolder = trim((string) ($_POST['account_holder'] ?? ''));
            $account = trim((string) ($_POST['account'] ?? ''));
            $savedMethodId = (int) ($_POST['saved_method_id'] ?? 0);
            $saveMethod = !empty($_POST['save_payout_method']);
            $formValues = [
                'amount' => $amountText,
                'method' => $method,
                'provider' => $provider,
                'account_holder' => $accountHolder,
                'account' => $account,
                'saved_method_id' => (string) $savedMethodId,
                'save_method' => $saveMethod,
            ];

            $amount = ctype_digit($amountText) ? (int) $amountText : 0;
            $allowedVoucherBrands = ['EcoCycle Eco-Shop Voucher', 'Community Market Voucher', 'General Gift Card'];
            $allowedWalletProviders = ['GCash', 'Maya', 'PayPal'];
            $allowedBanks = ['BDO', 'BPI', 'Metrobank', 'LandBank', 'UnionBank', 'PNB', 'RCBC', 'Security Bank', 'Other'];
            $details = [
                'provider' => $provider,
                'account' => $account,
            ];

            if (!$hasPin) {
                $errors[] = 'Set up your wallet PIN before starting a withdrawal.';
            } elseif ($isLocked) {
                $errors[] = sprintf('Wallet locked. Try again in %d seconds.', $lockoutRemaining);
            }

            if ($amountText === '' || !ctype_digit($amountText)) {
                $errors[] = 'Enter a whole-number points amount.';
            } elseif ($amount < WALLET_MIN_WITHDRAWAL) {
                $errors[] = 'Minimum withdrawal is ' . num(WALLET_MIN_WITHDRAWAL) . ' points.';
            } elseif ($amount > $maximumAmount) {
                $errors[] = 'Amount exceeds your available balance or remaining daily quota.';
            }

            if (!in_array($method, ['voucher', 'ewallet', 'bank_transfer'], true)) {
                $errors[] = 'Choose a valid payout method.';
            }

            if ($savedMethodId > 0) {
                $savedStmt = db()->prepare(
                    'SELECT method, provider, account_label, account_last4
                       FROM saved_payout_methods
                      WHERE id = ? AND user_id = ?'
                );
                $savedStmt->execute([$savedMethodId, $userId]);
                $savedMethod = $savedStmt->fetch();
                if (!$savedMethod || $savedMethod['method'] !== $method) {
                    $errors[] = 'Choose a saved payout method that belongs to your account.';
                } else {
                    $provider = (string) $savedMethod['provider'];
                    $accountHolder = (string) $savedMethod['account_label'];
                    $accountLast4 = (string) $savedMethod['account_last4'];
                    $label = (string) $savedMethod['account_label'];
                    $account = '';
                    $saveMethod = false;
                }
            } elseif ($method === 'voucher') {
                if (!in_array($provider, $allowedVoucherBrands, true)) {
                    $errors[] = 'Choose a valid gift card or voucher brand.';
                }
                $accountHolder = '';
                $account = '';
                $accountLast4 = '';
                $label = $provider;
            } elseif ($method === 'ewallet') {
                if (!in_array($provider, $allowedWalletProviders, true)) {
                    $errors[] = 'Choose a valid e-wallet provider.';
                }
                if (!preg_match("/^[A-Za-z0-9 .'-]{2,100}$/", $accountHolder)) {
                    $errors[] = 'Enter the account holder name.';
                }
                if ($detailErrors = validateWithdrawalDetails('ewallet', $details)) {
                    $errors = array_merge($errors, $detailErrors);
                }
                $accountLast4 = substr($account, -4);
                $label = $accountHolder;
            } elseif ($method === 'bank_transfer') {
                if (!in_array($provider, $allowedBanks, true)) {
                    $errors[] = 'Choose a valid bank.';
                }
                if (!preg_match("/^[A-Za-z0-9 .'-]{2,100}$/", $accountHolder)) {
                    $errors[] = 'Enter the account holder name.';
                }
                if (!preg_match('/^[0-9]{6,24}$/', $account)) {
                    $errors[] = 'Bank account number must contain 6-24 digits.';
                }
                $accountLast4 = substr($account, -4);
                $label = $accountHolder;
            }

            if (!$errors) {
                try {
                    $created = createWithdrawalRequest($userId, [
                        'points' => $amount,
                        'method' => $method,
                        'provider' => $provider,
                        'account_holder' => $accountHolder,
                        'account' => $account,
                        'account_last4' => $accountLast4,
                        'label' => $label,
                        'saved_method_id' => $savedMethodId,
                        'save_method' => $saveMethod,
                        'idempotency_token' => (string) $_POST['idempotency_token'],
                    ]);
                    unset($_SESSION['withdrawal_checkout_idempotency']);
                    $_SESSION[$checkoutKey] = [
                        'id' => $created['id'],
                        'token' => $created['token'],
                    ];
                    unset($_SESSION['withdrawal_checkout_completed']);
                    header('Location: withdrawal_checkout.php?step=review');
                    exit;
                } catch (Throwable $ex) {
                    $errors[] = 'Unable to start withdrawal: ' . $ex->getMessage();
                }
            }
        } elseif ($action === 'change') {
            if (!is_array($checkoutState) || !$request) {
                $errors[] = 'Start a withdrawal before changing its payout details.';
            } else {
                try {
                    cancelWithdrawalRequest($userId, (int) $request['id'], (string) $checkoutState['token']);
                    unset($_SESSION[$checkoutKey]);
                    header('Location: withdrawal_checkout.php?step=payout');
                    exit;
                } catch (Throwable $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
        } elseif ($action === 'confirm') {
            $termsAccepted = !empty($_POST['agree_terms']);
            $pinAttempt = (string) ($_POST['pin_attempt'] ?? '');
            if (!is_array($checkoutState) || !$request) {
                $errors[] = 'Start a withdrawal before confirming it.';
            } elseif (!$termsAccepted) {
                $errors[] = 'Please agree to the EcoWallet Withdrawal Terms.';
            } elseif (!$hasPin) {
                $errors[] = 'Set up your wallet PIN before confirming a withdrawal.';
            } elseif ($isLocked) {
                $errors[] = sprintf('Wallet locked. Try again in %d seconds.', $lockoutRemaining);
            } elseif (!preg_match('/^\d{4,6}$/', $pinAttempt)) {
                $errors[] = 'PIN must be 4-6 numeric digits.';
            } elseif (!verifyPin($userId, $pinAttempt)) {
                $attemptWallet = getUserWallet($userId);
                $attempts = (int) ($attemptWallet['failed_pin_attempts'] ?? 0);
                logWithdrawalAudit(
                    $userId,
                    'pin_failed',
                    (int) $request['points_amount'],
                    (string) $request['payout_method'],
                    null,
                    ['attempts' => $attempts, 'reference_id' => $request['reference_id']]
                );
                $errors[] = $attempts >= 3
                    ? 'Wallet locked for 15 minutes after too many failed attempts.'
                    : 'Incorrect PIN. ' . (3 - $attempts) . ' attempts remaining.';
                $isLocked = isWalletLocked($userId);
                $lockoutRemaining = getWalletLockoutRemaining($userId);
            } else {
                if ($request['payout_method'] === 'voucher') {
                    try {
                        $result = completeVoucherWithdrawalRequest(
                            $userId,
                            (int) $request['id'],
                            (string) $checkoutState['token']
                        );
                    } catch (Throwable $ex) {
                        $errors[] = $ex->getMessage();
                    }

                    if (isset($result)) {
                        $completedVoucher = $result;
                        $request = null;
                        $checkoutState = null;
                        $_SESSION['withdrawal_checkout_completed'] = $result;
                        unset($_SESSION[$checkoutKey]);
                        $step = 'complete';
                        try {
                            logWithdrawalAudit(
                                $userId,
                                'withdrawal_completed',
                                (int) $result['amount'],
                                'voucher',
                                (int) $result['id'],
                                ['reference_id' => $result['reference_id']]
                            );
                        } catch (Throwable $ex) {
                            $errors[] = 'Withdrawal completed, but its audit entry could not be saved: ' . $ex->getMessage();
                        }
                        try {
                            sendWalletNotification($userId, 'withdrawal', [
                                'amount' => $result['amount'],
                                'method' => 'voucher',
                                'reference_id' => $result['reference_id'],
                                'status' => 'completed',
                                'new_balance' => $result['balance_after'],
                            ]);
                        } catch (Throwable $ex) {
                            $errors[] = 'Withdrawal completed, but its notification could not be recorded: ' . $ex->getMessage();
                        }
                        if (!$errors) {
                            header('Location: withdrawal_checkout.php?step=complete');
                            exit;
                        }
                    }
                } else {
                    try {
                        markWithdrawalPinVerified(
                            $userId,
                            (int) $request['id'],
                            (string) $checkoutState['token']
                        );
                        header('Location: withdrawal_checkout.php?step=authorization');
                        exit;
                    } catch (Throwable $ex) {
                        $errors[] = $ex->getMessage();
                    }
                }
            }
        } elseif ($action === 'cancel_request' && $request && $checkoutState) {
            try {
                cancelWithdrawalRequest($userId, (int) $request['id'], (string) $checkoutState['token']);
                $request = getWithdrawalRequest($userId, (int) $request['id'], (string) $checkoutState['token']);
                unset($_SESSION[$checkoutKey]);
                $checkoutState = null;
                $authorizationToken = '';
                $step = 'authorization';
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
}

if (isset($_GET['step']) && $_GET['step'] === 'review' && $request) {
    $step = 'review';
}
if (isset($_GET['step']) && $_GET['step'] === 'complete') {
    $step = 'complete';
}
if ($requestedStep === 'result' && is_array($terminalResult)) {
    $step = 'result';
}

$pageTitle = $step === 'payout'
    ? 'PAYOUT METHOD, STEP 2'
    : ($step === 'authorization'
        ? 'Payout Authorization — EcoWallet'
        : ($step === 'result' ? 'Withdrawal Result — EcoWallet' : 'Review + Confirm — EcoWallet Checkout'));
require __DIR__ . '/includes/header.php';
?>

<main id="main" class="flex-1 max-w-6xl mx-auto px-4 py-8 w-full">
    <div class="checkout-topbar flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <nav class="checkout-steps flex items-center gap-2 sm:gap-4" aria-label="Withdrawal checkout steps">
            <span class="<?= $step === 'payout' ? 'checkout-step-active' : 'checkout-step-muted' ?>" <?= $step === 'payout' ? 'aria-current="step"' : '' ?>>Payout Info</span>
            <span class="checkout-step-separator" aria-hidden="true">›</span>
            <span class="<?= in_array($step, ['review', 'authorization', 'complete', 'result'], true) ? 'checkout-step-active' : 'checkout-step-muted' ?>" <?= in_array($step, ['review', 'authorization', 'complete', 'result'], true) ? 'aria-current="step"' : '' ?>>Review + Confirm</span>
        </nav>
        <a href="wallet.php" class="checkout-logo" aria-label="EcoCycle home">
            <span class="brand-mark" aria-hidden="true">♻️</span>
            <span>EcoCycle</span>
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="checkout-error mb-6 rounded-xl px-4 py-3 text-sm" role="alert" aria-live="polite">
            <ul class="space-y-1"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($step === 'result' && is_array($terminalResult)): ?>
        <section class="checkout-surface max-w-2xl mx-auto rounded-2xl border p-6 sm:p-8 text-center" aria-live="polite">
            <?php if ($terminalResult['status'] === 'completed'): ?>
                <p class="text-5xl mb-4" aria-hidden="true">✓</p>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-3">Withdrawal completed</h1>
                <p class="checkout-muted mb-6"><?= num($terminalResult['points_amount']) ?> points were withdrawn. No real money moved.</p>
                <?php if (!empty($terminalResult['transaction_id'])): ?>
                    <a href="receipt.php?action=receipt&amp;txn_id=<?= (int) $terminalResult['transaction_id'] ?>" class="checkout-primary-button">View receipt</a>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-5xl mb-4" aria-hidden="true"><?= $terminalResult['status'] === 'expired' ? '⌛' : '↩' ?></p>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-3">Withdrawal <?= e(ucfirst($terminalResult['status'])) ?></h1>
                <p class="checkout-muted mb-6">Your reserved points have been returned to your wallet. No real money moved.</p>
            <?php endif; ?>
            <div class="mt-6 flex flex-col sm:flex-row justify-center gap-3">
                <a href="wallet.php" class="checkout-secondary-button">Back to EcoWallet</a>
                <a href="withdrawal_checkout.php?step=start" class="checkout-primary-button">New withdrawal</a>
            </div>
        </section>
    <?php elseif ($step === 'authorization' && $request): ?>
        <?php
        $authorizationUsername = (string) ($user['username'] ?? $user['name']);
        $authorizationQrUrl = APP_BASE_URL . '/withdrawal_authorize.php?id=' . (int) $request['id']
            . '&token=' . rawurlencode($authorizationToken);
        $secondsRemaining = (int) $request['seconds_remaining'];
        $countdownDisplay = $secondsRemaining >= 3600
            ? sprintf(
                '%d:%02d:%02d',
                intdiv($secondsRemaining, 3600),
                intdiv($secondsRemaining % 3600, 60),
                $secondsRemaining % 60
            )
            : sprintf('%02d:%02d', intdiv($secondsRemaining, 60), $secondsRemaining % 60);
        $initialAuthorizationStatus = $request['status'] === 'authorized' ? 'authorized' : 'pending';
        $initialAuthorizationMessage = $initialAuthorizationStatus === 'authorized'
            ? 'Authorized — finalizing your simulated payout.'
            : 'Pending — waiting for the one-time QR authorization.';
        ?>
        <h1 class="text-2xl sm:text-3xl font-extrabold mb-2">Payout Authorization</h1>
        <p class="checkout-muted mb-6">Authorize your simulated withdrawal using this one-time EcoCycle QR code.</p>

        <?php if ($request['status'] === 'completed'): ?>
            <section class="checkout-surface max-w-3xl mx-auto rounded-2xl border p-6 sm:p-8" aria-labelledby="payout-complete-title">
                <div class="text-center">
                    <p class="text-5xl mb-4" aria-hidden="true">✓</p>
                    <h2 id="payout-complete-title" class="text-2xl sm:text-3xl font-extrabold mb-3">Withdrawal completed</h2>
                    <p class="checkout-muted mb-6">Your simulated payout is complete. No real money moved.</p>
                    <p class="checkout-info-banner rounded-xl p-4 font-bold mb-6"><?= num($request['points_amount']) ?> points were withdrawn from your EcoWallet.</p>
                    <div class="flex flex-col sm:flex-row justify-center gap-3">
                        <?php if (!empty($request['transaction_id'])): ?>
                            <a href="receipt.php?action=receipt&amp;txn_id=<?= (int) $request['transaction_id'] ?>" class="checkout-primary-button">View receipt</a>
                        <?php endif; ?>
                        <a href="wallet.php" class="checkout-secondary-button">Back to EcoWallet</a>
                        <a href="withdrawal_checkout.php?step=start" class="checkout-primary-button">New withdrawal</a>
                    </div>
                </div>
            </section>
        <?php elseif (in_array($request['status'], ['expired', 'cancelled'], true)): ?>
            <section class="checkout-surface max-w-3xl mx-auto rounded-2xl border p-6 sm:p-8 text-center" aria-live="polite">
                <p class="text-5xl mb-4" aria-hidden="true"><?= $request['status'] === 'expired' ? '⌛' : '↩' ?></p>
                <h2 class="text-2xl font-extrabold mb-3">Withdrawal <?= e(ucfirst($request['status'])) ?></h2>
                <p class="checkout-muted mb-3">
                    <?= $request['status'] === 'expired'
                        ? 'The authorization window expired. Your reserved points have been returned to your wallet.'
                        : 'You cancelled this withdrawal. Your reserved points have been returned to your wallet.' ?>
                </p>
                <p class="checkout-muted text-sm mb-6">No real money moves through EcoCycle payouts.</p>
                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <a href="wallet.php" class="checkout-secondary-button">Back to EcoWallet</a>
                    <a href="withdrawal_checkout.php?step=start" class="checkout-primary-button">New withdrawal</a>
                </div>
            </section>
        <?php else: ?>
            <div class="grid lg:grid-cols-[minmax(0,1fr)_21rem] gap-6 items-start">
                <section class="checkout-surface rounded-2xl border p-5 sm:p-7 text-center" aria-labelledby="qr-title">
                    <h2 id="qr-title" class="text-xl sm:text-2xl font-extrabold mb-2">Scan QR Code to Authorize Payout</h2>
                    <p class="checkout-muted text-sm mb-5">Scan this EcoCycle code with a device camera to authorize the simulated payout.</p>
                    <?php if ($authorizationToken !== ''): ?>
                        <div class="checkout-qr-frame inline-flex justify-center rounded-xl p-3 mb-3">
                            <canvas id="authorizationQr" width="240" height="240" role="img" aria-label="Single-use EcoCycle payout authorization QR code"></canvas>
                        </div>
                        <p class="checkout-muted text-sm font-bold">This QR code can be scanned once only</p>
                    <?php else: ?>
                        <p class="checkout-error rounded-xl p-4" role="alert">This request predates QR authorization. Cancel it and start a new withdrawal to continue.</p>
                    <?php endif; ?>
                    <details class="checkout-how-to mt-4 text-left">
                        <summary class="checkout-link cursor-pointer">How to authorize</summary>
                        <p class="checkout-muted text-sm mt-2">Open your camera or QR scanner on another device and scan the code. The single-use EcoCycle authorization link confirms this simulated payout. Return here to see the result. No real money moves.</p>
                    </details>

                    <div id="authorizationStatus" class="checkout-status checkout-status-<?= e($initialAuthorizationStatus) ?> rounded-xl p-4 mt-6 text-left" role="status" aria-live="polite">
                        <p class="font-extrabold"><?= e(ucfirst($initialAuthorizationStatus)) ?></p>
                        <p id="authorizationStatusText" class="text-sm mt-1"><?= e($initialAuthorizationMessage) ?></p>
                    </div>
                    <p class="checkout-countdown mt-4 text-lg font-extrabold" aria-live="off">
                        Payment page expires in <span id="authorizationCountdown"><?= e($countdownDisplay) ?></span>
                    </p>
                    <p class="checkout-muted text-xs mt-1">Countdown refreshes from the server; the server deadline is authoritative.</p>

                    <div class="flex flex-col sm:flex-row justify-center gap-3 mt-6">
                        <button type="button" id="simulateScanButton" class="checkout-primary-button" <?= $authorizationToken === '' || $request['status'] !== 'pending' || $request['pin_verified_at'] === null ? 'disabled' : '' ?>>Simulate scan</button>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="cancel_request">
                            <button type="submit" class="checkout-secondary-button">Cancel withdrawal</button>
                        </form>
                    </div>
                    <?php if ($authorizationToken !== ''): ?>
                        <a class="checkout-link inline-block mt-4 text-sm" href="<?= e($authorizationQrUrl) ?>" target="_blank" rel="noopener noreferrer">Open the one-time authorization link</a>
                    <?php endif; ?>
                    <p class="checkout-muted text-xs mt-5">Payouts are simulated. No real money moves.</p>
                </section>

                <aside class="checkout-surface rounded-2xl border p-5 sm:p-6">
                    <h2 class="text-sm font-extrabold uppercase tracking-wider mb-4">Order Summary</h2>
                    <dl class="checkout-order-summary">
                        <div><dt>Pay to</dt><dd><?= e($authorizationUsername) ?></dd></div>
                        <div><dt>Order info</dt><dd>EcoWallet Withdrawal</dd></div>
                        <div><dt>Amount in points</dt><dd><?= num($request['points_amount']) ?> pts</dd></div>
                        <div><dt>Transaction no.</dt><dd><?= e($request['reference_id']) ?></dd></div>
                        <div class="checkout-order-total"><dt>Total</dt><dd>₱<?= number_format((float) $request['net_amount'], 2) ?></dd></div>
                    </dl>
                </aside>
            </div>
        <?php endif; ?>
    <?php elseif ($step === 'complete' && is_array($completedVoucher)): ?>
        <section class="checkout-surface max-w-2xl mx-auto rounded-2xl border p-6 sm:p-8" aria-labelledby="voucher-complete-title">
            <div class="text-center">
                <p class="text-5xl mb-4" aria-hidden="true">🎁</p>
                <h1 id="voucher-complete-title" class="text-2xl sm:text-3xl font-extrabold mb-3">Your voucher is ready</h1>
                <p class="checkout-muted mb-6">This is a simulated payout. No real money moved.</p>
                <div class="checkout-voucher-code rounded-xl p-5 mb-6">
                    <p class="text-xs uppercase tracking-wider font-bold mb-2">Voucher code</p>
                    <p class="text-2xl sm:text-3xl font-extrabold tracking-widest" aria-label="Voucher code"><?= e($completedVoucher['voucher_code']) ?></p>
                </div>
                <dl class="checkout-details-grid text-left">
                    <div><dt>Reference</dt><dd><?= e($completedVoucher['reference_id']) ?></dd></div>
                    <div><dt>Points withdrawn</dt><dd><?= num($completedVoucher['amount']) ?> pts</dd></div>
                    <div><dt>Value</dt><dd>₱<?= number_format((float) $completedVoucher['net_amount'], 2) ?></dd></div>
                </dl>
                <div class="mt-8 flex flex-col sm:flex-row justify-center gap-3">
                    <a href="receipt.php?action=receipt&amp;txn_id=<?= (int) $completedVoucher['id'] ?>" class="checkout-primary-button">View receipt</a>
                    <a href="wallet.php" class="checkout-secondary-button">Back to EcoWallet</a>
                    <a href="withdrawal_checkout.php?step=start" class="checkout-primary-button">New withdrawal</a>
                </div>
            </div>
        </section>
    <?php elseif ($step === 'review' && $request): ?>
        <?php
        $requestPoints = (int) $request['points_amount'];
        $remainingAfter = max(0, $availableBalance);
        $quotaAfter = max(0, $withdrawalRemainingQuota);
        $methodLabel = match ($request['payout_method']) {
            'ewallet' => 'E-Wallet',
            'bank_transfer' => 'Bank Transfer',
            default => 'Gift Card / Voucher',
        };
        $destinationLabel = $request['payout_method'] === 'voucher'
            ? (string) $request['provider']
            : trim((string) $request['provider'] . ' · ' . (string) $request['payout_label'] .
                (!empty($request['account_last4']) ? ' ····' . $request['account_last4'] : ''));
        $username = (string) ($user['username'] ?? $user['name']);
        ?>
        <h1 class="text-2xl sm:text-3xl font-extrabold mb-2">Review + Confirm</h1>
        <p class="checkout-muted mb-6">Check your withdrawal details before confirming.</p>

        <div class="grid lg:grid-cols-[minmax(0,1fr)_19rem] gap-6 items-start">
            <section class="checkout-surface rounded-2xl border p-5 sm:p-7" aria-label="Withdrawal review">
                <div class="flex justify-between gap-4 border-b checkout-divider pb-5">
                    <div>
                        <p class="font-extrabold">Withdraw <?= num($requestPoints) ?> points</p>
                        <p class="checkout-muted text-sm"><?= e($methodLabel) ?> · <?= e($destinationLabel) ?></p>
                    </div>
                    <p class="font-extrabold whitespace-nowrap">₱<?= number_format((float) $request['gross_amount'], 2) ?></p>
                </div>
                <dl class="checkout-totals py-4 border-b checkout-divider">
                    <div><dt>Subtotal</dt><dd>₱<?= number_format((float) $request['gross_amount'], 2) ?></dd></div>
                    <div><dt>Fee</dt><dd>₱<?= number_format((float) $request['fee'], 2) ?></dd></div>
                    <div class="font-extrabold"><dt>Total</dt><dd>₱<?= number_format((float) $request['net_amount'], 2) ?></dd></div>
                </dl>
                <div class="checkout-info-banner my-5 rounded-xl p-4 grid sm:grid-cols-2 gap-3">
                    <div><p class="text-xs uppercase tracking-wide font-bold">Balance after withdrawal</p><p class="font-extrabold mt-1"><?= num($remainingAfter) ?> pts available</p></div>
                    <div><p class="text-xs uppercase tracking-wide font-bold">Daily quota left</p><p class="font-extrabold mt-1"><?= num($quotaAfter) ?> pts</p></div>
                </div>

                <dl class="checkout-details-grid mb-5">
                    <div><dt>Payout method:</dt><dd><?= e($methodLabel) ?> — <?= e($destinationLabel) ?></dd></div>
                    <div><dt>EcoCycle account:</dt><dd><?= e($username) ?></dd></div>
                    <div><dt>Reference:</dt><dd><?= e($request['reference_id']) ?></dd></div>
                </dl>

                <?php if ($request['status'] === 'pending'): ?>
                    <?php if (!$hasPin): ?>
                        <div class="checkout-error rounded-xl p-4 mb-5" role="alert">Set up a wallet PIN before confirming this withdrawal. <a class="font-bold underline" href="wallet.php">Return to EcoWallet</a>.</div>
                    <?php elseif ($isLocked): ?>
                        <div class="checkout-error rounded-xl p-4 mb-5" role="alert">Wallet locked. Try again in <?= e($lockoutRemaining) ?> seconds.</div>
                    <?php endif; ?>

                    <form method="post" class="space-y-5" id="reviewForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="confirm">
                        <label class="checkout-check-row">
                            <input type="checkbox" name="agree_terms" value="1" id="agreeTerms">
                            <span>I agree to the EcoWallet Withdrawal Terms.</span>
                        </label>
                        <div>
                            <label for="pin_attempt" class="checkout-label">Wallet PIN</label>
                            <input type="password" id="pin_attempt" name="pin_attempt" class="checkout-input max-w-xs text-center tracking-[0.5em] text-xl" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="current-password" required aria-describedby="pin-help">
                            <p id="pin-help" class="checkout-muted text-sm mt-2">Enter your 4–6 digit wallet PIN. <a href="pin_reset.php" class="checkout-link">Forgot PIN?</a></p>
                        </div>
                        <?php if ($request['payout_method'] === 'voucher'): ?>
                            <div class="checkout-info-banner rounded-xl p-4" role="note">Your voucher code is shown right after confirmation.</div>
                        <?php else: ?>
                            <div class="checkout-info-banner rounded-xl p-4" role="note">Payouts are authorized in a separate window. Click the button below to open it.</div>
                        <?php endif; ?>
                        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                            <button type="submit" form="changePayoutForm" class="checkout-secondary-button">Change</button>
                            <button type="submit" id="confirmButton" class="checkout-primary-button" disabled>
                                <?= $request['payout_method'] === 'voucher' ? 'Confirm withdrawal' : 'Continue to Payout Authorization' ?>
                            </button>
                        </div>
                    </form>
                    <form method="post" id="changePayoutForm" class="hidden">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="change">
                    </form>
                <?php else: ?>
                    <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-3 pt-2">
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="change">
                            <button type="submit" class="checkout-secondary-button">Change payout information</button>
                        </form>
                        <a href="wallet.php" class="checkout-primary-button">Back to EcoWallet</a>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="space-y-4">
                <section class="checkout-surface rounded-2xl border p-5">
                    <h2 class="text-sm font-extrabold uppercase tracking-wider mb-3">Withdrawing from EcoWallet</h2>
                    <p class="checkout-muted text-sm">When a simulated payout is completed, the points are deducted and a receipt appears in your Transaction History.</p>
                </section>
                <?php if ($request['payout_method'] === 'ewallet'): ?>
                    <section class="checkout-tips rounded-2xl border p-5">
                        <h2 class="text-sm font-extrabold uppercase tracking-wider mb-3">Tips for E-Wallet Customers</h2>
                        <ul class="checkout-muted text-sm list-disc pl-5 space-y-2">
                            <li>Double-check the account holder and destination details.</li>
                            <li>Only continue with payout destinations you control.</li>
                            <li>This demonstration does not send real funds.</li>
                        </ul>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
        <p class="checkout-muted text-sm text-center mt-6">A receipt will be saved in your Transaction History. Payouts are simulated; no real money moves.</p>
    <?php else: ?>
        <div class="grid lg:grid-cols-[minmax(0,1fr)_19rem] gap-6 items-start">
            <section>
                <p class="checkout-kicker mb-2">PAYOUT METHOD, STEP 2</p>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-2">Payout Info</h1>
                <p class="checkout-muted mb-6">Choose how you would like your simulated points withdrawal to be paid out.</p>
                <p class="checkout-muted text-xs mb-5">
                    Daily limit: used <?= num($dailyWithdrawalTotal) ?> / <?= num(WALLET_DAILY_WITHDRAWAL_CAP) ?>
                </p>
                <?php if ($activeRequest && $request && $checkoutState): ?>
                    <?php
                    $resumeStep = $request['status'] === 'authorized' || $request['pin_verified_at'] !== null
                        ? 'authorization'
                        : 'review';
                    ?>
                    <div class="checkout-info-banner rounded-xl p-4 mb-5" role="status">
                        <p class="font-extrabold">You have a withdrawal in progress</p>
                        <p class="text-sm mt-1"><?= num($request['points_amount']) ?> points are reserved until you resume or cancel this request.</p>
                        <div class="flex flex-col sm:flex-row gap-3 mt-4">
                            <a href="withdrawal_checkout.php?step=<?= e($resumeStep) ?>" class="checkout-primary-button">Resume withdrawal</a>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel_request">
                                <button type="submit" class="checkout-secondary-button">Cancel withdrawal</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!$hasPin): ?>
                    <div class="checkout-error rounded-xl p-4 mb-5" role="alert">
                        Set up your EcoWallet PIN before starting a withdrawal. <a class="font-bold underline" href="wallet.php">Return to EcoWallet</a>.
                    </div>
                <?php elseif ($isLocked): ?>
                    <div class="checkout-error rounded-xl p-4 mb-5" role="alert">Wallet locked. Try again in <?= e($lockoutRemaining) ?> seconds.</div>
                <?php endif; ?>
                <?php if (!$activeRequest && $pinResetCooldownUntil !== null): ?>
                    <div class="checkout-info-banner rounded-xl p-4" role="status">
                        Withdrawals are on hold until <?= e(date('M j, Y g:i A', strtotime($pinResetCooldownUntil))) ?> after a PIN reset, to protect your account (<?= e(APP_TIMEZONE) ?> time).
                    </div>
                <?php elseif (!$activeRequest && $availableBalance < WALLET_MIN_WITHDRAWAL): ?>
                    <div class="checkout-info-banner rounded-xl p-4" role="status">
                        You need at least <?= num(WALLET_MIN_WITHDRAWAL) ?> points to withdraw. Your balance is <?= num($availableBalance) ?> points.
                    </div>
                <?php elseif (!$activeRequest && $maximumAmount < WALLET_MIN_WITHDRAWAL): ?>
                    <div class="checkout-info-banner rounded-xl p-4" role="status">
                        You've withdrawn <?= num($dailyWithdrawalTotal) ?> of <?= num(WALLET_DAILY_WITHDRAWAL_CAP) ?> points today and have <?= num($withdrawalRemainingQuota) ?> left (the minimum withdrawal is <?= num(WALLET_MIN_WITHDRAWAL) ?>). Your limit resets at midnight (<?= e(APP_TIMEZONE) ?> time).
                    </div>
                <?php elseif (!$activeRequest): ?>
                <form method="post" id="payoutForm" class="checkout-surface rounded-2xl border p-5 sm:p-7 space-y-5">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="idempotency_token" value="<?= e($startIdempotencyToken) ?>">
                    <div>
                        <label for="withdrawAmount" class="checkout-label">Amount (points)</label>
                        <input type="number" id="withdrawAmount" name="amount" class="checkout-input" min="<?= WALLET_MIN_WITHDRAWAL ?>" max="<?= (int) $maximumAmount ?>" step="1" value="<?= e($formValues['amount']) ?>" required aria-describedby="amountHelp amountError">
                        <p id="amountHelp" class="checkout-muted text-sm mt-2">Minimum <?= num(WALLET_MIN_WITHDRAWAL) ?> points · Available <?= num($maximumAmount) ?> points</p>
                        <p id="amountError" class="checkout-error-text text-sm mt-2" aria-live="polite"></p>
                        <div class="flex flex-wrap gap-2 mt-3" aria-label="Quick amount selection">
                            <?php foreach ([25, 50, 75] as $percent): ?>
                                <button type="button" data-percent="<?= $percent ?>" class="checkout-quick-button"><?= $percent ?>%</button>
                            <?php endforeach; ?>
                            <button type="button" data-percent="100" class="checkout-quick-button">Max</button>
                        </div>
                    </div>

                    <div>
                        <label for="payoutMethod" class="checkout-label">Payout method</label>
                        <select id="payoutMethod" name="payout_method" class="checkout-input" required>
                            <option value="voucher" <?= $formValues['method'] === 'voucher' ? 'selected' : '' ?>>Gift Card / Voucher</option>
                            <option value="ewallet" <?= $formValues['method'] === 'ewallet' ? 'selected' : '' ?>>E-Wallet</option>
                            <option value="bank_transfer" <?= $formValues['method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        </select>
                    </div>

                    <?php if ($savedPayoutMethods): ?>
                        <div>
                            <label for="savedPayoutMethod" class="checkout-label">Saved payout information</label>
                            <select id="savedPayoutMethod" name="saved_method_id" class="checkout-input">
                                <option value="0">Enter payout information</option>
                                <?php foreach ($savedPayoutMethods as $saved): ?>
                                    <option value="<?= (int) $saved['id'] ?>"
                                        data-method="<?= e($saved['method']) ?>"
                                        data-provider="<?= e($saved['provider']) ?>"
                                        data-label="<?= e($saved['account_label']) ?>"
                                        data-last4="<?= e($saved['account_last4']) ?>"
                                        <?= (string) $formValues['saved_method_id'] === (string) $saved['id'] ? 'selected' : '' ?>>
                                        <?= e($saved['account_label']) ?> ····<?= e($saved['account_last4']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="checkout-muted text-sm mt-2">Select a saved method to use its label and last four digits without retyping.</p>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="saved_method_id" value="0">
                    <?php endif; ?>

                    <div id="voucherFields" class="checkout-method-fields">
                        <label for="voucherBrand" class="checkout-label">Gift card / voucher brand</label>
                        <select id="voucherBrand" name="provider" class="checkout-input">
                            <?php foreach (['EcoCycle Eco-Shop Voucher', 'Community Market Voucher', 'General Gift Card'] as $brand): ?>
                                <option value="<?= e($brand) ?>" <?= $formValues['provider'] === $brand ? 'selected' : '' ?>><?= e($brand) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="checkout-muted text-sm mt-2">Available immediately after confirmation.</p>
                    </div>

                    <div id="ewalletFields" class="checkout-method-fields hidden space-y-4">
                        <div>
                            <label for="ewalletProvider" class="checkout-label">E-Wallet provider</label>
                            <select id="ewalletProvider" name="provider" class="checkout-input">
                                <?php foreach (['GCash', 'Maya', 'PayPal'] as $provider): ?>
                                    <option value="<?= e($provider) ?>" <?= $formValues['provider'] === $provider ? 'selected' : '' ?>><?= e($provider) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="ewalletHolder" class="checkout-label">Account name</label>
                            <input id="ewalletHolder" name="account_holder" class="checkout-input" type="text" maxlength="100" autocomplete="name" value="<?= e($formValues['account_holder']) ?>">
                        </div>
                        <div>
                            <label for="ewalletAccount" class="checkout-label">Mobile number or email</label>
                            <input id="ewalletAccount" name="account" class="checkout-input" type="text" autocomplete="off" value="<?= e($formValues['account']) ?>">
                        </div>
                    </div>

                    <div id="bankFields" class="checkout-method-fields hidden space-y-4">
                        <div>
                            <label for="bankProvider" class="checkout-label">Bank</label>
                            <select id="bankProvider" name="provider" class="checkout-input">
                                <?php foreach (['BDO', 'BPI', 'Metrobank', 'LandBank', 'UnionBank', 'PNB', 'RCBC', 'Security Bank', 'Other'] as $bank): ?>
                                    <option value="<?= e($bank) ?>" <?= $formValues['provider'] === $bank ? 'selected' : '' ?>><?= e($bank) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="bankHolder" class="checkout-label">Account holder name</label>
                            <input id="bankHolder" name="account_holder" class="checkout-input" type="text" maxlength="100" autocomplete="name" value="<?= e($formValues['account_holder']) ?>">
                        </div>
                        <div>
                            <label for="bankAccount" class="checkout-label">Account number</label>
                            <input id="bankAccount" name="account" class="checkout-input" type="text" inputmode="numeric" minlength="6" maxlength="24" autocomplete="off" value="<?= e($formValues['account']) ?>">
                            <p class="checkout-muted text-sm mt-2">Enter 6 to 24 digits.</p>
                        </div>
                    </div>

                    <div id="savedMethodSummary" class="checkout-info-banner rounded-xl p-4 hidden" role="status"></div>
                    <label class="checkout-check-row">
                        <input type="checkbox" name="save_payout_method" value="1" id="savePayoutMethod" <?= $formValues['save_method'] ? 'checked' : '' ?>>
                        <span>Save my payout information so withdrawing is easy next time</span>
                    </label>
                    <p class="checkout-muted text-sm">You’ll have a chance to review your withdrawal before it’s placed.</p>
                    <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                        <a href="wallet.php" class="checkout-secondary-button">Back</a>
                        <button type="submit" id="continueButton" class="checkout-primary-button" disabled>Continue</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <aside class="checkout-surface rounded-2xl border p-5 sm:p-6">
                <h2 class="text-sm font-extrabold uppercase tracking-wider mb-4">Payout Methods</h2>
                <ul class="space-y-3 text-sm">
                    <li class="checkout-method-item">🎁 <span>Gift Card / Voucher</span></li>
                    <li class="checkout-method-item">📱 <span>E-Wallet</span></li>
                    <li class="checkout-method-item">🏦 <span>Bank Transfer</span></li>
                </ul>
                <div class="checkout-divider border-t my-5"></div>
                <p class="checkout-muted text-sm">Payouts are simulated for EcoCycle. No real money moves.</p>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php if ($step === 'authorization' && $request && $authorizationToken !== '' && in_array($request['status'], ['pending', 'authorized'], true)): ?>
<script src="assets/js/withdrawal-qr.bundle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('authorizationQr');
    const simulateButton = document.getElementById('simulateScanButton');
    const statusBox = document.getElementById('authorizationStatus');
    const statusText = document.getElementById('authorizationStatusText');
    const countdown = document.getElementById('authorizationCountdown');
    const qrPayload = <?= json_encode(
        $authorizationQrUrl,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
    const csrfToken = window.ECO_THEME_CSRF;
    let secondsRemaining = <?= (int) $secondsRemaining ?>;

    function updateCountdown() {
        const seconds = secondsRemaining % 60;
        if (secondsRemaining >= 3600) {
            const hours = Math.floor(secondsRemaining / 3600);
            const minutes = Math.floor((secondsRemaining % 3600) / 60);
            countdown.textContent = String(hours) + ':' + String(minutes).padStart(2, '0')
                + ':' + String(seconds).padStart(2, '0');
        } else {
            const minutes = Math.floor(secondsRemaining / 60);
            countdown.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        }
        if (secondsRemaining > 0) secondsRemaining -= 1;
    }

    function showStatus(status, message) {
        statusBox.classList.remove('checkout-status-pending', 'checkout-status-authorized', 'checkout-status-completed', 'checkout-status-error');
        const classByStatus = {
            pending: 'checkout-status-pending',
            authorized: 'checkout-status-authorized',
            completed: 'checkout-status-completed',
            expired: 'checkout-status-error',
            cancelled: 'checkout-status-error'
        };
        statusBox.classList.add(classByStatus[status] || 'checkout-status-pending');
        statusBox.querySelector('p').textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusText.textContent = message;
        if (status !== 'pending') simulateButton.disabled = true;
    }

    async function requestStatus(action) {
        const body = new URLSearchParams({ action: action, csrf: csrfToken || '' });
        const response = await fetch('withdrawal_checkout.php?step=authorization', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not update payout status.');
        return result;
    }

    async function pollStatus() {
        try {
            const result = await requestStatus('poll');
            secondsRemaining = Math.max(0, Number(result.seconds_remaining) || 0);
            updateCountdown();
            showStatus(result.status, result.message);
            if (result.warnings && result.warnings.length) {
                statusText.textContent += ' ' + result.warnings.join(' ');
            }
            if (result.status === 'completed' || result.status === 'expired' || result.status === 'cancelled') {
                window.clearInterval(pollTimer);
                window.location.href = result.redirect_url || 'withdrawal_checkout.php?step=start';
            }
        } catch (error) {
            statusText.textContent = error.message;
        }
    }

    if (window.EcoCycleQR && canvas) {
        window.EcoCycleQR.toCanvas(canvas, qrPayload, {
            width: 240,
            margin: 2,
            errorCorrectionLevel: 'M',
            color: { dark: '#14532d', light: '#ffffff' }
        }).catch(function (error) {
            statusText.textContent = 'Could not generate the local QR code: ' + error.message;
        });
    } else {
        statusText.textContent = 'The local QR generator did not load. Reload this page or use Simulate scan.';
    }

    updateCountdown();
    window.setInterval(updateCountdown, 1000);
    const pollTimer = window.setInterval(pollStatus, 3000);
    if (simulateButton && !simulateButton.disabled) {
        simulateButton.addEventListener('click', async function () {
            simulateButton.disabled = true;
            try {
                const result = await requestStatus('simulate_scan');
                showStatus(result.status, result.message);
            } catch (error) {
                simulateButton.disabled = false;
                statusText.textContent = error.message;
            }
        });
    }
});
</script>
<?php elseif ($step === 'payout'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('payoutForm');
    if (!form) return;

    const amount = document.getElementById('withdrawAmount');
    const method = document.getElementById('payoutMethod');
    const saved = document.getElementById('savedPayoutMethod');
    const continueButton = document.getElementById('continueButton');
    const amountError = document.getElementById('amountError');
    const maxAmount = <?= (int) $maximumAmount ?>;
    const minimum = <?= (int) WALLET_MIN_WITHDRAWAL ?>;
    const canWithdraw = <?= $hasPin && !$isLocked ? 'true' : 'false' ?>;
    const methodFields = {
        voucher: document.getElementById('voucherFields'),
        ewallet: document.getElementById('ewalletFields'),
        bank_transfer: document.getElementById('bankFields')
    };
    const savedSummary = document.getElementById('savedMethodSummary');
    const saveCheckbox = document.getElementById('savePayoutMethod');

    function inputsFor(container) {
        return Array.from(container.querySelectorAll('input, select'));
    }

    function checkForm() {
        const value = Number(amount.value);
        let message = '';
        if (amount.value !== '' && (!Number.isInteger(value) || value < minimum)) {
            message = 'Minimum withdrawal is ' + minimum.toLocaleString() + ' points.';
        } else if (amount.value !== '' && (value > maxAmount || value > Number(amount.max))) {
            message = 'Amount exceeds your available balance or daily quota.';
        }
        amountError.textContent = message;

        const selectedSaved = saved && saved.value !== '0';
        const active = methodFields[method.value];
        const fieldsValid = selectedSaved || inputsFor(active).every(function (field) {
            if (field.tagName === 'SELECT') return Boolean(field.value);
            if (field.id === 'ewalletHolder' || field.id === 'bankHolder') {
                return /^[A-Za-z0-9 .'-]{2,100}$/.test(field.value.trim());
            }
            if (field.id === 'ewalletAccount') {
                return /^(?:\+?63|0)9[0-9]{9}$|^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(field.value.trim());
            }
            if (field.id === 'bankAccount') return /^[0-9]{6,24}$/.test(field.value.trim());
            return true;
        });
        continueButton.disabled = !canWithdraw || Boolean(message) || !Number.isInteger(value) || value < minimum
            || value > maxAmount || !fieldsValid;
    }

    function updateFields() {
        Object.keys(methodFields).forEach(function (key) {
            const active = key === method.value;
            methodFields[key].classList.toggle('hidden', !active);
            inputsFor(methodFields[key]).forEach(function (field) {
                field.disabled = !active || Boolean(saved && saved.value !== '0');
            });
        });

        const useSaved = Boolean(saved && saved.value !== '0');
        if (savedSummary) {
            savedSummary.classList.toggle('hidden', !useSaved);
            if (useSaved) {
                const option = saved.selectedOptions[0];
                savedSummary.textContent = 'Using saved payout information: ' + option.dataset.label
                    + ' ····' + option.dataset.last4 + ' (' + option.dataset.provider + ')';
            }
        }
        if (saveCheckbox) {
            saveCheckbox.disabled = method.value === 'voucher' || useSaved;
            if (saveCheckbox.disabled) saveCheckbox.checked = false;
        }
        checkForm();
    }

    if (saved) {
        saved.addEventListener('change', function () {
            if (saved.value !== '0') {
                const option = saved.selectedOptions[0];
                method.value = option.dataset.method;
                const providerSelect = methodFields[method.value].querySelector('select[name="provider"]');
                if (providerSelect && Array.from(providerSelect.options).some(function (item) {
                    return item.value === option.dataset.provider;
                })) {
                    providerSelect.value = option.dataset.provider;
                }
            }
            updateFields();
        });
    }

    method.addEventListener('change', function () {
        if (saved && saved.value !== '0' && saved.selectedOptions[0].dataset.method !== method.value) {
            saved.value = '0';
        }
        updateFields();
    });
    form.addEventListener('input', checkForm);
    form.addEventListener('change', checkForm);
    form.addEventListener('submit', function (event) {
        checkForm();
        if (continueButton.disabled || !form.reportValidity()) event.preventDefault();
    });
    document.querySelectorAll('[data-percent]').forEach(function (button) {
        button.addEventListener('click', function () {
            const percent = Number(button.dataset.percent);
            const selected = Math.floor(maxAmount * percent / 100);
            amount.value = Math.max(minimum, selected);
            amount.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    updateFields();
});
</script>
<?php else: ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('reviewForm');
    if (!form) return;
    const terms = document.getElementById('agreeTerms');
    const pin = document.getElementById('pin_attempt');
    const button = document.getElementById('confirmButton');
    function updateConfirmButton() {
        button.disabled = !terms.checked || !/^[0-9]{4,6}$/.test(pin.value);
    }
    terms.addEventListener('change', updateConfirmButton);
    pin.addEventListener('input', updateConfirmButton);
    updateConfirmButton();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
