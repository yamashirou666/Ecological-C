<?php
/**
 * EcoCycle — Receipt & Transaction Export Page
 * View detailed receipt for a transaction or download all as CSV.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
$action = $_GET['action'] ?? '';
$transactionId = (int) ($_GET['txn_id'] ?? 0);

// Get wallet and transaction details
$wallet = getUserWallet((int) $user['id']);
if (!$wallet) {
    setFlash('error', 'You have no wallet.');
    header('Location: dashboard.php');
    exit;
}

if ($action === 'receipt' && $transactionId) {
    // View single receipt
    $stmt = db()->prepare('SELECT * FROM transactions WHERE id = ? AND wallet_id = ?');
    $stmt->execute([$transactionId, $wallet['id']]);
    $txn = $stmt->fetch();

    if (!$txn) {
        setFlash('error', 'Transaction not found.');
        header('Location: wallet.php');
        exit;
    }

    // Generate receipt HTML
    $receipt = generateReceipt($txn, (int) $user['id']);

    // Check if a downloadable receipt was requested.
    if (($_GET['format'] ?? '') === 'pdf') {
        // For now, return HTML (PDF generation would require a library like mPDF or Dompdf)
        header('Content-Type: text/html; charset=utf-8');
        $safeReference = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $txn['reference_id']);
        header('Content-Disposition: attachment; filename="receipt-' . $safeReference . '.html"');
        echo $receipt;
        exit;
    }

    // Display receipt page
    $pageTitle = 'Receipt — ' . $txn['reference_id'];
    require __DIR__ . '/includes/header.php';
    ?>
    <main id="main" class="flex-1 max-w-2xl mx-auto px-4 py-8 w-full">
        <div class="mb-8">
            <a href="wallet.php" class="text-emerald-600 hover:text-emerald-700 font-semibold">← Back to Wallet</a>
            <h1 class="text-3xl font-extrabold text-slate-900 mt-4">📄 Receipt</h1>
        </div>

        <?php echo $receipt; ?>

        <div class="mt-8 flex gap-4 justify-center">
            <a href="?action=receipt&amp;txn_id=<?= e($transactionId) ?>&amp;format=pdf" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-3 rounded-lg transition">
                📥 Download as HTML
            </a>
            <button onclick="window.print()" class="bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold px-6 py-3 rounded-lg transition">
                🖨 Print Receipt
            </button>
            <a href="wallet.php" class="bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold px-6 py-3 rounded-lg transition">
                Back
            </a>
        </div>
    </main>

    <?php require __DIR__ . '/includes/footer.php'; ?>
    <?php
} elseif ($action === 'export_csv') {
    // Export all transactions as CSV
    $csv = exportTransactionsCSV((int) $user['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecocycle-wallet-export-' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
} else {
    // No valid action
    setFlash('error', 'Invalid request.');
    header('Location: wallet.php');
    exit;
}
?>
