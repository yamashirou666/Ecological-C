<?php
/**
 * EcoCycle — recycling log: submit a new entry and view recent history.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user      = currentUser();
$catalogue = materials();
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $material = $_POST['material'] ?? '';
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $weight   = (float) ($_POST['weight_kg'] ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $note     = $note !== '' ? mb_substr($note, 0, 255) : null;

        if (!isset($catalogue[$material])) {
            $errors[] = 'Please choose a valid material type.';
        }
        if ($weight <= 0 || $weight > RECYCLING_MAX_KG_PER_ENTRY) {
            $errors[] = 'Enter a weight between 0.01 and ' . num(RECYCLING_MAX_KG_PER_ENTRY) . ' kg.';
        }

        if (!$errors) {
            try {
                $result = recordRecyclingLog((int) $user['id'], $material, $quantity, round($weight, 2), $note);
            } catch (DomainException $ex) {
                $errors[] = $ex->getMessage();
            }

            if (isset($result)) {
                $msg = sprintf(
                    'Nice work! You earned %s points and saved %s kg of CO₂.',
                    num($result['points']),
                    num($result['co2'], 2)
                );
                setFlash('success', $msg);
                foreach ($result['new_badges'] as $badge) {
                    setFlash('info', 'Badge unlocked: ' . $badge['icon'] . ' ' . $badge['name'] . '!');
                }
                header('Location: log.php');
                exit;
            }
        }
    }
}

// Recent history (last 25).
$histStmt = db()->prepare(
    'SELECT * FROM recycling_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 25'
);
$histStmt->execute([$user['id']]);
$history = $histStmt->fetchAll();

$pageTitle = 'Log recycling';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-5xl mx-auto px-4 py-10">
    <h1 class="text-2xl font-extrabold text-slate-900">Log your recycling ♻️</h1>
    <p class="mt-1 text-slate-600">Record what you diverted from landfill and earn EcoPoints instantly.</p>

    <?php if ($errors): ?>
        <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm" role="alert">
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mt-6 grid gap-8 lg:grid-cols-5">
        <!-- Form -->
        <form method="post" id="logForm" class="lg:col-span-3 bg-white rounded-2xl shadow-sm border border-eco-100 p-6 space-y-5" novalidate>
            <?= csrfField() ?>
            <div>
                <span class="block text-sm font-semibold text-slate-700 mb-2">Material type</span>
                <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Material type">
                    <?php foreach ($catalogue as $key => $meta): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="material" value="<?= e($key) ?>" class="peer sr-only" data-ppk="<?= e($meta['points_per_kg']) ?>" data-avg="<?= e($meta['avg_item_kg']) ?>" required>
                            <span class="flex flex-col items-center gap-1 rounded-xl border border-slate-200 py-3 text-center text-sm font-semibold text-slate-600 peer-checked:border-eco-500 peer-checked:bg-eco-50 peer-checked:text-eco-700 hover:border-eco-300 transition">
                                <span class="text-2xl" aria-hidden="true"><?= e($meta['icon']) ?></span>
                                <?= e($meta['label']) ?>
                                <span class="text-[11px] font-normal text-slate-400"><?= e($meta['points_per_kg']) ?> pts/kg</span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label for="quantity" class="block text-sm font-semibold text-slate-700">Number of items</label>
                    <input id="quantity" name="quantity" type="number" min="1" value="1"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                    <p class="mt-1 text-xs text-slate-400">We can estimate weight from this.</p>
                </div>
                <div>
                    <label for="weight_kg" class="block text-sm font-semibold text-slate-700">Weight (kg)</label>
                    <input id="weight_kg" name="weight_kg" type="number" step="0.01" min="0.01" required placeholder="0.00"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                    <button type="button" id="estimateBtn" class="mt-1 text-xs font-semibold text-eco-700 hover:underline">↳ Estimate from item count</button>
                </div>
            </div>

            <div>
                <label for="note" class="block text-sm font-semibold text-slate-700">Note <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="note" name="note" type="text" maxlength="255" placeholder="e.g. Dropped off at Greendale Center"
                       class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
            </div>

            <div class="flex items-center justify-between bg-eco-50 rounded-xl px-4 py-3">
                <span class="text-sm font-semibold text-slate-600">Estimated reward</span>
                <span class="text-lg font-extrabold text-eco-700"><span id="pointsPreview">0</span> pts</span>
            </div>

            <button type="submit" class="w-full py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">Log it &amp; earn points</button>
        </form>

        <!-- Side: quick stats -->
        <aside class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6 text-center">
                <div class="text-sm font-semibold text-slate-500">Current balance</div>
                <div class="mt-1 text-4xl font-extrabold text-eco-700"><?= num($user['points_balance']) ?></div>
                <div class="text-xs text-slate-400">EcoPoints</div>
                <div class="mt-4 flex items-center justify-center gap-2 text-sm text-slate-600">
                    🔥 <span class="font-bold"><?= (int) $user['streak_count'] ?></span>-day streak
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
                <h2 class="font-bold text-slate-900 text-sm">Points by material</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <?php foreach ($catalogue as $meta): ?>
                        <li class="flex items-center justify-between">
                            <span class="text-slate-600"><span aria-hidden="true"><?= e($meta['icon']) ?></span> <?= e($meta['label']) ?></span>
                            <span class="font-semibold text-eco-700"><?= e($meta['points_per_kg']) ?> /kg</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
    </div>

    <!-- History -->
    <div class="mt-10 bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <h2 class="font-bold text-slate-900">Recent activity</h2>
        <?php if (!$history): ?>
            <p class="mt-3 text-sm text-slate-500">No entries yet — log your first item above to earn the <strong>First Steps</strong> badge! 🌱</p>
        <?php else: ?>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-100">
                            <th class="py-2 pr-4 font-semibold">Material</th>
                            <th class="py-2 pr-4 font-semibold">Qty</th>
                            <th class="py-2 pr-4 font-semibold">Weight</th>
                            <th class="py-2 pr-4 font-semibold">Points</th>
                            <th class="py-2 pr-4 font-semibold">CO₂ saved</th>
                            <th class="py-2 font-semibold">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): $m = $catalogue[$row['material_type']] ?? ['icon' => '♻️', 'label' => $row['material_type']]; ?>
                            <tr class="border-b border-slate-50">
                                <td class="py-2.5 pr-4"><span aria-hidden="true"><?= e($m['icon']) ?></span> <?= e($m['label']) ?><?php if ($row['note']): ?><div class="text-xs text-slate-400"><?= e($row['note']) ?></div><?php endif; ?></td>
                                <td class="py-2.5 pr-4"><?= (int) $row['quantity'] ?></td>
                                <td class="py-2.5 pr-4"><?= num($row['weight_kg'], 2) ?> kg</td>
                                <td class="py-2.5 pr-4 font-semibold text-eco-700">+<?= num($row['points_awarded']) ?></td>
                                <td class="py-2.5 pr-4"><?= num($row['co2_saved_kg'], 2) ?> kg</td>
                                <td class="py-2.5 text-slate-500"><?= e(date('M j, g:i a', strtotime($row['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
    // Live points preview + weight estimator.
    (function () {
        const form = document.getElementById('logForm');
        const weight = document.getElementById('weight_kg');
        const qty = document.getElementById('quantity');
        const preview = document.getElementById('pointsPreview');
        const estimateBtn = document.getElementById('estimateBtn');

        function selectedMaterial() {
            return form.querySelector('input[name="material"]:checked');
        }
        function updatePreview() {
            const mat = selectedMaterial();
            const ppk = mat ? parseFloat(mat.dataset.ppk) : 0;
            const w = parseFloat(weight.value) || 0;
            preview.textContent = Math.round(ppk * w).toLocaleString();
        }
        estimateBtn.addEventListener('click', function () {
            const mat = selectedMaterial();
            if (!mat) { alert('Pick a material first.'); return; }
            const avg = parseFloat(mat.dataset.avg);
            const count = parseInt(qty.value, 10) || 1;
            weight.value = (avg * count).toFixed(2);
            updatePreview();
        });
        form.addEventListener('change', updatePreview);
        weight.addEventListener('input', updatePreview);
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
