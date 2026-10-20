<?php
/**
 * EcoCycle — optional demo data seeder.
 *
 * Creates a handful of sample residents with recycling history so the
 * landing page, leaderboard, and community stats look alive out of the box.
 *
 * Run once (after setup.php):  php seed_demo.php
 * Set ECOCYCLE_DEMO_PASSWORD in the environment before running.
 *
 * Safe to re-run: existing demo accounts are removed first.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This seeder must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo   = db();
$demos = [
    ['Maya Chen',      'maya@example.com',    'Greendale'],
    ['Leo Martins',    'leo@example.com',     'Riverside'],
    ['Aisha Khan',     'aisha@example.com',   'Greendale'],
    ['Tom Becker',     'tom@example.com',     'Hillcrest'],
    ['Sofia Rossi',    'sofia@example.com',   'Riverside'],
    ['Noah Williams',  'noah@example.com',    'Hillcrest'],
];

$materialKeys = array_keys(materials());
$demoPassword = getenv('ECOCYCLE_DEMO_PASSWORD');
if ($demoPassword === false || $demoPassword === '') {
    throw new RuntimeException('Set ECOCYCLE_DEMO_PASSWORD before seeding demo accounts.');
}
if ($passwordError = passwordPolicyError($demoPassword)) {
    throw new RuntimeException('Demo password rejected: ' . $passwordError);
}
$hash = hashPassword($demoPassword);
$created = 0;

foreach ($demos as [$name, $email, $hood]) {
    // Remove any prior demo user with this email (cascades logs/redemptions).
    $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);

    $usernameBase = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 12));
    $username = $usernameBase;
    $suffix = 1;
    $usernameCheck = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $usernameCheck->execute([$username]);
        if (!$usernameCheck->fetchColumn()) {
            break;
        }
        $suffixText = (string) $suffix;
        $username = substr($usernameBase, 0, 12 - strlen($suffixText)) . $suffixText;
        $suffix++;
    }

    $pdo->prepare(
        'INSERT INTO users (name, username, email, password_hash, neighborhood) VALUES (?,?,?,?,?)'
    )->execute([$name, $username, $email, $hash, $hood]);
    $uid = (int) $pdo->lastInsertId();

    // Generate a random-but-plausible history over the past ~20 days.
    $logCount = random_int(6, 16);
    for ($i = 0; $i < $logCount; $i++) {
        $material = $materialKeys[array_rand($materialKeys)];
        $meta     = materials()[$material];
        $weight   = round(random_int(20, 400) / 100, 2); // 0.20–4.00 kg
        $points   = (int) round($weight * $meta['points_per_kg']);
        $co2      = round($weight * $meta['co2_per_kg'], 2);
        $daysAgo  = random_int(0, 20);

        $pdo->prepare(
            'INSERT INTO recycling_logs (user_id, material_type, quantity, weight_kg, points_awarded, co2_saved_kg, created_at)
             VALUES (?,?,?,?,?,?, (NOW() - INTERVAL ? DAY))'
        )->execute([$uid, $material, 1, $weight, $points, $co2, $daysAgo]);
    }

    // Roll up totals onto the user record.
    $sum = $pdo->query("SELECT COALESCE(SUM(points_awarded),0) FROM recycling_logs WHERE user_id=$uid")->fetchColumn();
    $pdo->prepare('UPDATE users SET points_balance = ?, total_points = ?, streak_count = ? WHERE id = ?')
        ->execute([$sum, $sum, random_int(1, 9), $uid]);

    evaluateBadges($uid);
    $created++;
    echo "[OK] Created demo user: $name ($hood) with $logCount logs, $sum points.\n";
}

echo "\nSeeded $created demo residents. Use the configured demo password to sign in,\n";
echo "or just open index.php to see the community stats populated.\n";
