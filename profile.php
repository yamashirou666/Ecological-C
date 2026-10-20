<?php
/**
 * EcoCycle — user profile: edit details, view badges & redemption history.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user   = currentUser();
$errors = [];
$profileNeighborhood = (string) ($user['neighborhood'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['theme_only'])) {
        if (!verifyCsrf()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Session expired.']);
            exit;
        }

        $theme = sanitizeThemePreference((string) ($_POST['theme_preference'] ?? 'system'));
        setThemePreferenceForCurrentUser($theme);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'theme' => $theme]);
        exit;
    }

    if (!verifyCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name             = trim($_POST['name'] ?? '');
        $neighborhood     = trim((string) ($_POST['neighborhood'] ?? ''));
        $newPassword      = $_POST['password'] ?? '';
        $themePreference  = sanitizeThemePreference((string) ($_POST['theme_preference'] ?? $user['theme_preference'] ?? 'system'));
        $profileNeighborhood = $neighborhood;

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Please enter a valid name.';
        }
        if (mb_strlen($neighborhood) > 120) {
            $errors[] = 'Neighborhood must be 120 characters or fewer.';
        }
        if ($newPassword !== '' && ($passwordError = passwordPolicyError($newPassword))) {
            $errors[] = $passwordError;
        }

        if (!$errors) {
            if ($newPassword !== '') {
                $stmt = db()->prepare('UPDATE users SET name = ?, neighborhood = ?, theme_preference = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$name, $neighborhood, $themePreference, hashPassword($newPassword), $user['id']]);
            } else {
                $stmt = db()->prepare('UPDATE users SET name = ?, neighborhood = ?, theme_preference = ? WHERE id = ?');
                $stmt->execute([$name, $neighborhood, $themePreference, $user['id']]);
            }
            setThemePreferenceForCurrentUser($themePreference);
            setFlash('success', 'Your profile and theme have been updated.');
            header('Location: profile.php');
            exit;
        }
    }
}

// Badges (earned + locked).
$allBadges = db()->query('SELECT * FROM badges ORDER BY id')->fetchAll();
$earnedStmt = db()->prepare('SELECT badge_id, awarded_at FROM user_badges WHERE user_id = ?');
$earnedStmt->execute([$user['id']]);
$earned = [];
foreach ($earnedStmt->fetchAll() as $row) {
    $earned[(int) $row['badge_id']] = $row['awarded_at'];
}

// Redemption history.
$redStmt = db()->prepare(
    'SELECT r.*, w.title, w.icon, p.business_name
       FROM redemptions r
       JOIN rewards w  ON w.id = r.reward_id
       JOIN partners p ON p.id = w.partner_id
      WHERE r.user_id = ?
      ORDER BY r.created_at DESC'
);
$redStmt->execute([$user['id']]);
$redemptions = $redStmt->fetchAll();

$level = levelForPoints((int) $user['total_points']);

$pageTitle = 'My profile';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-4xl mx-auto px-4 py-10">
    <div class="flex items-center gap-4">
        <div class="w-16 h-16 rounded-2xl bg-eco-100 text-eco-700 grid place-items-center text-2xl font-extrabold" aria-hidden="true"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900"><?= e($user['name']) ?></h1>
            <p class="text-slate-500 text-sm"><?= e($level['icon']) ?> <?= e($level['name']) ?><?php if ($profileNeighborhood !== ''): ?> · <?= e($profileNeighborhood) ?><?php endif; ?></p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm" role="alert">
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mt-8 grid gap-8 lg:grid-cols-2">
        <!-- Edit details -->
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">Account details</h2>
            <form method="post" class="mt-4 space-y-4" novalidate>
                <?= csrfField() ?>
                <div>
                    <label for="name" class="block text-sm font-semibold text-slate-700">Name</label>
                    <input id="name" name="name" type="text" required value="<?= e($user['name']) ?>"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700">Email</label>
                    <input type="email" value="<?= e($user['email']) ?>" disabled
                           class="mt-1 w-full rounded-xl border border-slate-100 bg-slate-50 text-slate-400 px-4 py-2.5">
                </div>
                <div>
                    <label for="neighborhood" class="block text-sm font-semibold text-slate-700">Neighborhood <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="neighborhood" name="neighborhood" type="text" maxlength="120" placeholder="Add your neighborhood (optional)" value="<?= e($profileNeighborhood) ?>"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                </div>
                <div>
                    <label for="password" class="block text-sm font-semibold text-slate-700">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" placeholder="Leave blank to keep current"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                </div>
                <div>
                    <label for="theme_preference" class="block text-sm font-semibold text-slate-700">Appearance</label>
                    <select id="theme_preference" name="theme_preference" class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
                        <option value="system" <?= (($user['theme_preference'] ?? 'system') === 'system') ? 'selected' : '' ?>>Use system setting</option>
                        <option value="light" <?= (($user['theme_preference'] ?? 'system') === 'light') ? 'selected' : '' ?>>Daylight (Light mode)</option>
                        <option value="dark" <?= (($user['theme_preference'] ?? 'system') === 'dark') ? 'selected' : '' ?>>Night Grove (Dark mode)</option>
                    </select>
                </div>
                <button type="submit" class="w-full py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">Save changes</button>
            </form>
        </div>

        <!-- Badges -->
        <div class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
            <h2 class="font-bold text-slate-900">Achievements</h2>
            <p class="text-sm text-slate-500 mt-1"><?= count($earned) ?> of <?= count($allBadges) ?> badges earned</p>
            <ul class="mt-4 space-y-3">
                <?php foreach ($allBadges as $badge):
                    $isEarned = isset($earned[(int) $badge['id']]); ?>
                    <li class="flex items-center gap-3 <?= e($isEarned ? '' : 'opacity-40') ?>">
                        <span class="w-11 h-11 rounded-xl grid place-items-center text-xl <?= e($isEarned ? 'bg-eco-100' : 'bg-slate-100 grayscale') ?>" aria-hidden="true"><?= e($badge['icon']) ?></span>
                        <div>
                            <div class="font-semibold text-slate-800 text-sm"><?= e($badge['name']) ?> <?php if ($isEarned): ?><span class="text-eco-600">✓</span><?php endif; ?></div>
                            <div class="text-xs text-slate-500"><?= e($badge['description']) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Redemption history -->
    <div class="mt-8 bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
        <h2 class="font-bold text-slate-900">Redemption history</h2>
        <?php if (!$redemptions): ?>
            <p class="mt-3 text-sm text-slate-500">No redemptions yet. Visit the <a href="rewards.php" class="text-eco-700 font-semibold hover:underline">Rewards marketplace</a> to spend your points.</p>
        <?php else: ?>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-100">
                            <th class="py-2 pr-4 font-semibold">Reward</th>
                            <th class="py-2 pr-4 font-semibold">Code</th>
                            <th class="py-2 pr-4 font-semibold">Points</th>
                            <th class="py-2 pr-4 font-semibold">Date</th>
                            <th class="py-2 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($redemptions as $r): ?>
                            <tr class="border-b border-slate-50">
                                <td class="py-2.5 pr-4"><span aria-hidden="true"><?= e($r['icon']) ?></span> <?= e($r['title']) ?><div class="text-xs text-slate-400"><?= e($r['business_name']) ?></div></td>
                                <td class="py-2.5 pr-4 font-mono text-eco-700 font-semibold"><?= e($r['redemption_code']) ?></td>
                                <td class="py-2.5 pr-4"><?= num($r['points_spent']) ?></td>
                                <td class="py-2.5 pr-4 text-slate-500"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
                                <td class="py-2.5"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-eco-100 text-eco-800"><?= e(ucfirst($r['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
