<?php
/**
 * EcoCycle — personal impact dashboard.
 * Headline stats, level progress, material breakdown, and a 7-day trend chart.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user      = currentUser();
$userId    = (int) $user['id'];
$catalogue = materials();

// Personal totals.
$totals = db()->prepare(
    'SELECT COALESCE(SUM(weight_kg),0) AS total_kg,
            COALESCE(SUM(co2_saved_kg),0) AS total_co2,
            COUNT(*) AS total_logs
       FROM recycling_logs WHERE user_id = ?'
);
$totals->execute([$userId]);
$totals = $totals->fetch();
$trees  = treesEquivalent((float) $totals['total_co2']);

// Material breakdown (for doughnut chart).
$byMaterial = db()->prepare(
    'SELECT material_type, SUM(weight_kg) AS kg
       FROM recycling_logs WHERE user_id = ?
       GROUP BY material_type'
);
$byMaterial->execute([$userId]);
$materialData = [];
foreach ($byMaterial->fetchAll() as $row) {
    $label = $catalogue[$row['material_type']]['label'] ?? $row['material_type'];
    $materialData[$label] = round((float) $row['kg'], 2);
}

// Last 7 days of CO2 saved (for line chart).
$trend = array_fill_keys(
    array_map(fn ($i) => (new DateTimeImmutable("-$i days"))->format('Y-m-d'), range(6, 0)),
    0.0
);
$trendStmt = db()->prepare(
    'SELECT DATE(created_at) AS d, SUM(co2_saved_kg) AS co2
       FROM recycling_logs
      WHERE user_id = ? AND created_at >= (CURDATE() - INTERVAL 6 DAY)
      GROUP BY DATE(created_at)'
);
$trendStmt->execute([$userId]);
foreach ($trendStmt->fetchAll() as $row) {
    if (isset($trend[$row['d']])) {
        $trend[$row['d']] = round((float) $row['co2'], 2);
    }
}

// Community average kg (for comparison).
$communityAvg = db()->query(
    'SELECT COALESCE(AVG(u_kg), 0) FROM (
        SELECT SUM(weight_kg) AS u_kg FROM recycling_logs GROUP BY user_id
     ) t'
)->fetchColumn();

$progress = levelProgress((int) $user['total_points']);

// Prepare chart payloads.
$trendLabels = array_map(fn ($d) => date('D', strtotime($d)), array_keys($trend));
$trendValues = array_values($trend);

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-6xl mx-auto px-4 py-10">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Hi <?= e(explode(' ', $user['name'])[0]) ?> 👋</h1>
            <p class="mt-1 text-slate-600">Here's the impact you've made so far.</p>
        </div>
        <a href="log.php" class="px-5 py-2.5 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">+ Log recycling</a>
    </div>

    <!-- Headline stats -->
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $cards = [
            ['♻️', num($totals['total_kg'], 1) . ' kg', 'Diverted from landfill'],
            ['💨', num($totals['total_co2'], 1) . ' kg', 'CO₂ emissions saved'],
            ['🌳', num($trees, 2), 'Tree-years equivalent'],
            ['⭐', num($user['points_balance']), 'EcoPoints to spend'],
        ];
        foreach ($cards as [$icon, $value, $label]): ?>
            <div class="eco-card bg-white rounded-2xl shadow-sm border border-eco-100 p-5">
                <div class="text-2xl" aria-hidden="true"><?= e($icon) ?></div>
                <div class="mt-2 text-2xl font-extrabold text-slate-900"><?= e($value) ?></div>
                <div class="text-xs font-semibold text-slate-500"><?= e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Level progress -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <span class="text-3xl" aria-hidden="true"><?= e($progress['current']['icon']) ?></span>
                <div>
                    <div class="font-extrabold text-slate-900"><?= e($progress['current']['name']) ?></div>
                    <div class="text-xs text-slate-500"><?= num($user['total_points']) ?> lifetime points</div>
                </div>
            </div>
            <?php if ($progress['next']): ?>
                <div class="text-sm text-slate-500 text-right">
                    <span class="font-semibold text-eco-700"><?= num($progress['to_next']) ?></span> pts to
                    <?= e($progress['next']['icon']) ?> <?= e($progress['next']['name']) ?>
                </div>
            <?php else: ?>
                <div class="text-sm font-semibold text-eco-700">Max level reached! 🏆</div>
            <?php endif; ?>
        </div>
        <div class="mt-4 h-3 w-full rounded-full bg-eco-100 overflow-hidden">
            <div class="h-full rounded-full bg-eco-500 transition-all" style="width: <?= (int) $progress['percent'] ?>%"></div>
        </div>
    </div>

    <!-- Charts -->
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">CO₂ saved — last 7 days</h2>
            <?php if ((int) $totals['total_logs'] === 0): ?>
                <p class="mt-6 text-sm text-slate-500 text-center py-8">Log your first item to see your trend appear here.</p>
            <?php else: ?>
                <canvas id="trendChart" height="220" class="mt-4" role="img" aria-label="Line chart of CO2 saved over the last 7 days"></canvas>
            <?php endif; ?>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">Recycling by material</h2>
            <?php if (!$materialData): ?>
                <p class="mt-6 text-sm text-slate-500 text-center py-8">No materials logged yet.</p>
            <?php else: ?>
                <canvas id="materialChart" height="220" class="mt-4" role="img" aria-label="Doughnut chart of recycling by material type"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Community comparison -->
    <div class="mt-6 bg-gradient-to-br from-eco-600 to-eco-800 rounded-2xl p-6 text-white">
        <h2 class="font-bold">How you compare</h2>
        <p class="mt-2 text-eco-50 text-sm">
            You've recycled <strong><?= num($totals['total_kg'], 1) ?> kg</strong>.
            The community average is <strong><?= num($communityAvg, 1) ?> kg</strong> per member.
            <?php if ((float) $totals['total_kg'] >= (float) $communityAvg && (float) $totals['total_kg'] > 0): ?>
                You're above average — keep leading the way! 🌟
            <?php else: ?>
                A few more logs and you'll be ahead of the pack! 💪
            <?php endif; ?>
        </p>
        <a href="leaderboard.php" class="mt-4 inline-block text-sm font-bold bg-white/15 hover:bg-white/25 px-4 py-2 rounded-lg transition">View leaderboard →</a>
    </div>
</section>

<?php if ((int) $totals['total_logs'] > 0): ?>
<script src="assets/js/chart.umd.js"></script>
<script>
    (function () {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        Chart.defaults.font.family = 'Nunito, sans-serif';
        Chart.defaults.color = isDark ? '#a9b7b2' : '#64748b';
        Chart.defaults.borderColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(15,23,42,0.08)';

        const trendEl = document.getElementById('trendChart');
        if (trendEl) {
            new Chart(trendEl, {
                type: 'line',
                data: {
                    labels: <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    datasets: [{
                        label: 'kg CO₂ saved',
                        data: <?= json_encode($trendValues) ?>,
                        borderColor: isDark ? '#7ef0a7' : '#16a34a',
                        backgroundColor: isDark ? 'rgba(126,240,167,0.14)' : 'rgba(22,163,74,0.12)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: isDark ? '#7ef0a7' : '#16a34a',
                        pointBorderColor: '#ffffff',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)' } },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            grid: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)' }
                        }
                    }
                }
            });
        }

        const matEl = document.getElementById('materialChart');
        if (matEl) {
            new Chart(matEl, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_keys($materialData)) ?>,
                    datasets: [{
                        data: <?= json_encode(array_values($materialData), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        backgroundColor: isDark ? ['#5ce18e','#7ef0a7','#f2b84b','#f7d78b','#7dd3fc','#a7f3d0'] : ['#16a34a','#4ade80','#a9855f','#f59e0b','#0ea5e9','#84cc16'],
                        borderWidth: 2,
                        borderColor: isDark ? '#1b231d' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'bottom', labels: { color: isDark ? '#e7ede9' : '#64748b' } } }
                }
            });
        }
    })();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
