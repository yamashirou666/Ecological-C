<?php
/**
 * EcoCycle — community leaderboard.
 * Toggle between top individual recyclers and top neighborhoods.
 */
require_once __DIR__ . '/includes/functions.php';

$user  = currentUser();
$scope = ($_GET['scope'] ?? 'people') === 'neighborhood' ? 'neighborhood' : 'people';

if ($scope === 'people') {
    // Top individuals by lifetime points, joined with recycled weight.
    $rows = db()->query(
        "SELECT u.id, u.name,
                COALESCE(NULLIF(TRIM(u.neighborhood), ''), 'Unassigned') AS neighborhood,
                u.total_points, u.streak_count,
                COALESCE(SUM(l.weight_kg), 0) AS total_kg
           FROM users u
           LEFT JOIN recycling_logs l ON l.user_id = u.id
          GROUP BY u.id
          ORDER BY u.total_points DESC, total_kg DESC
          LIMIT 50"
    )->fetchAll();
} else {
    // Aggregate by neighborhood.
    $rows = db()->query(
        "SELECT COALESCE(NULLIF(TRIM(u.neighborhood), ''), 'Unassigned') AS neighborhood,
                COUNT(DISTINCT u.id)          AS members,
                COALESCE(SUM(l.weight_kg), 0) AS total_kg,
                COALESCE(SUM(l.co2_saved_kg), 0) AS total_co2,
                COALESCE(SUM(u.total_points), 0) AS total_points
           FROM users u
           LEFT JOIN recycling_logs l ON l.user_id = u.id
          GROUP BY COALESCE(NULLIF(TRIM(u.neighborhood), ''), 'Unassigned')
          ORDER BY total_kg DESC, total_points DESC
          LIMIT 50"
    )->fetchAll();
}

$userNeighborhood = $user ? trim((string) $user['neighborhood']) : '';
if ($userNeighborhood === '') {
    $userNeighborhood = 'Unassigned';
}

$medals = ['🥇', '🥈', '🥉'];

$pageTitle = 'Leaderboard';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-4xl mx-auto px-4 py-10">
    <h1 class="text-2xl font-extrabold text-slate-900">Community leaderboard 🏆</h1>
    <p class="mt-1 text-slate-600">See who's leading the charge toward a greener neighborhood.</p>

    <!-- Scope toggle -->
    <div class="mt-6 inline-flex bg-white border border-eco-100 rounded-full p-1 shadow-sm">
        <a href="?scope=people" class="px-5 py-2 rounded-full text-sm font-semibold transition <?= e($scope === 'people' ? 'bg-eco-600 text-white' : 'text-slate-600 hover:text-eco-700') ?>">Top recyclers</a>
        <a href="?scope=neighborhood" class="px-5 py-2 rounded-full text-sm font-semibold transition <?= e($scope === 'neighborhood' ? 'bg-eco-600 text-white' : 'text-slate-600 hover:text-eco-700') ?>">Neighborhoods</a>
    </div>

    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-eco-100 overflow-hidden">
        <?php if (!$rows): ?>
            <p class="p-8 text-center text-slate-500">No data yet — be the first to log some recycling!</p>
        <?php elseif ($scope === 'people'): ?>
            <ul class="divide-y divide-slate-50">
                <?php foreach ($rows as $i => $row):
                    $isMe = $user && (int) $user['id'] === (int) $row['id'];
                    $level = levelForPoints((int) $row['total_points']); ?>
                    <li class="flex items-center gap-4 px-5 py-4 <?= e($isMe ? 'bg-eco-50' : '') ?>">
                        <div class="w-8 text-center font-extrabold text-slate-400 shrink-0">
                            <?= e($i < 3 ? $medals[$i] : ($i + 1)) ?>
                        </div>
                        <div class="w-10 h-10 rounded-full bg-eco-100 text-eco-700 grid place-items-center font-bold shrink-0" aria-hidden="true"><?= e(strtoupper(substr($row['name'], 0, 1))) ?></div>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-slate-800 truncate">
                                <?= e($row['name']) ?><?php if ($isMe): ?> <span class="text-xs font-semibold text-eco-600">(you)</span><?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500"><?= e($level['icon']) ?> <?= e($level['name']) ?> · <?= e($row['neighborhood']) ?> · <?= num($row['total_kg'], 1) ?> kg</div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-extrabold text-eco-700"><?= num($row['total_points']) ?></div>
                            <div class="text-xs text-slate-400">points</div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <ul class="divide-y divide-slate-50">
                <?php foreach ($rows as $i => $row):
                    $isMine = $user && $userNeighborhood === $row['neighborhood']; ?>
                    <li class="flex items-center gap-4 px-5 py-4 <?= e($isMine ? 'bg-eco-50' : '') ?>">
                        <div class="w-8 text-center font-extrabold text-slate-400 shrink-0"><?= e($i < 3 ? $medals[$i] : ($i + 1)) ?></div>
                        <div class="w-10 h-10 rounded-full bg-eco-100 text-eco-700 grid place-items-center text-lg shrink-0" aria-hidden="true">🏘️</div>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-slate-800 truncate">
                                <?= e($row['neighborhood']) ?><?php if ($isMine): ?> <span class="text-xs font-semibold text-eco-600">(yours)</span><?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500"><?= (int) $row['members'] ?> members · <?= num($row['total_co2'], 1) ?> kg CO₂ saved</div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-extrabold text-eco-700"><?= num($row['total_kg'], 1) ?> kg</div>
                            <div class="text-xs text-slate-400">diverted</div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if (!$user): ?>
        <div class="mt-6 text-center">
            <a href="register.php" class="inline-block px-6 py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">Join and climb the ranks</a>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
