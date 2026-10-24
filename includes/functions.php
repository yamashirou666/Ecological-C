<?php
/**
 * EcoCycle — shared helpers: session, auth, points/levels engine, impact math.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function configureSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; connect-src 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

configureSecurityHeaders();

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ECOCYCLE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
        logoutUser();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function sanitizeThemePreference(string $theme): string
{
    $theme = strtolower(trim($theme));
    return in_array($theme, ['light', 'dark', 'system'], true) ? $theme : 'system';
}

function ensureUserThemePreferenceColumn(): void
{
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'theme_preference'");
    if ($stmt && $stmt->rowCount() === 0) {
        db()->exec("ALTER TABLE users ADD COLUMN theme_preference VARCHAR(20) NOT NULL DEFAULT 'system' AFTER neighborhood");
    }
}

function ensureSignupProfileColumns(): void
{
    $columns = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $additions = [
        ['auth_provider', "VARCHAR(20) NOT NULL DEFAULT 'email' AFTER neighborhood"],
        ['email_verified_at', 'DATETIME NULL AFTER auth_provider'],
        ['avatar_id', 'VARCHAR(40) NULL AFTER email_verified_at'],
        ['interests', 'VARCHAR(255) NULL AFTER avatar_id'],
        ['referral_code_used', 'VARCHAR(50) NULL AFTER interests'],
        ['referred_by_user_id', 'INT NULL AFTER referral_code_used'],
        ['newsletter_opt_in', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER referred_by_user_id'],
    ];

    foreach ($additions as [$name, $definition]) {
        if (!in_array($name, $columns, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
}

function ensureNeighborhoodReference(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS neighborhoods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $insert = db()->prepare('INSERT IGNORE INTO neighborhoods (name) VALUES (?)');
    foreach (['Greendale', 'Maple Ridge', 'Cedar Heights', 'Oakview', 'South Park', 'Riverbend', 'Northfield', 'Lakeside'] as $name) {
        $insert->execute([$name]);
    }
}

ensureUserThemePreferenceColumn();
ensureSignupProfileColumns();
ensureNeighborhoodReference();

function ensureWithdrawalTables(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS saved_payout_methods (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        method ENUM("bank_transfer","ewallet") NOT NULL,
        provider VARCHAR(40) NOT NULL, account_label VARCHAR(100) NOT NULL,
        account_last4 VARCHAR(4) NOT NULL, account_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_saved_payout_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('CREATE TABLE IF NOT EXISTS withdrawal_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        transaction_id INT NULL, action VARCHAR(40) NOT NULL, amount INT NULL,
        payout_method VARCHAR(40) NULL, ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL, details JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_withdrawal_audit_user_date (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

ensureWithdrawalTables();

/* -------------------------------------------------------------------------
 * Domain configuration
 * ---------------------------------------------------------------------- */

/**
 * Material catalogue: points per kg, CO2 saved per kg (kg CO2e), and an
 * average per-item weight used by the quantity estimator.
 */
function materials(): array
{
    return [
        'plastic' => ['label' => 'Plastic',  'icon' => '🧴', 'points_per_kg' => 10, 'co2_per_kg' => 1.50, 'avg_item_kg' => 0.04],
        'glass'   => ['label' => 'Glass',    'icon' => '🍾', 'points_per_kg' => 6,  'co2_per_kg' => 0.30, 'avg_item_kg' => 0.40],
        'paper'   => ['label' => 'Paper',    'icon' => '📰', 'points_per_kg' => 5,  'co2_per_kg' => 0.90, 'avg_item_kg' => 0.10],
        'metal'   => ['label' => 'Metal',    'icon' => '🥫', 'points_per_kg' => 12, 'co2_per_kg' => 4.00, 'avg_item_kg' => 0.03],
        'ewaste'  => ['label' => 'E-waste',  'icon' => '🔌', 'points_per_kg' => 15, 'co2_per_kg' => 2.00, 'avg_item_kg' => 0.30],
        'organic' => ['label' => 'Organic',  'icon' => '🍎', 'points_per_kg' => 4,  'co2_per_kg' => 0.50, 'avg_item_kg' => 0.25],
    ];
}

/**
 * Level ladder, keyed by the minimum lifetime points required to reach it.
 * Ordered ascending.
 */
function levels(): array
{
    return [
        ['name' => 'Sprout',         'min' => 0,    'icon' => '🌱'],
        ['name' => 'Sapling',        'min' => 250,  'icon' => '🌿'],
        ['name' => 'Green Guardian', 'min' => 1000, 'icon' => '🌳'],
        ['name' => 'Eco Champion',   'min' => 3000, 'icon' => '🏆'],
    ];
}

/** Trees-equivalent factor: a mature tree absorbs ~21 kg CO2 per year. */
const CO2_PER_TREE_KG = 21.0;

/* -------------------------------------------------------------------------
 * Level helpers
 * ---------------------------------------------------------------------- */

/** Resolve the current level for a given lifetime points total. */
function levelForPoints(int $points): array
{
    $current = levels()[0];
    foreach (levels() as $level) {
        if ($points >= $level['min']) {
            $current = $level;
        }
    }
    return $current;
}

/** The next level above the current points, or null if already maxed. */
function nextLevelForPoints(int $points): ?array
{
    foreach (levels() as $level) {
        if ($points < $level['min']) {
            return $level;
        }
    }
    return null;
}

/**
 * Progress (0-100) toward the next level, plus the current/next level meta.
 */
function levelProgress(int $points): array
{
    $current = levelForPoints($points);
    $next    = nextLevelForPoints($points);

    if ($next === null) {
        return ['current' => $current, 'next' => null, 'percent' => 100, 'to_next' => 0];
    }

    $span   = $next['min'] - $current['min'];
    $gained = $points - $current['min'];
    $percent = $span > 0 ? (int) round(($gained / $span) * 100) : 0;

    return [
        'current' => $current,
        'next'    => $next,
        'percent' => max(0, min(100, $percent)),
        'to_next' => max(0, $next['min'] - $points),
    ];
}

/* -------------------------------------------------------------------------
 * Impact math
 * ---------------------------------------------------------------------- */

/** Convert kg of CO2 saved into a tree-year equivalent. */
function treesEquivalent(float $co2Kg): float
{
    return $co2Kg / CO2_PER_TREE_KG;
}

/* -------------------------------------------------------------------------
 * Auth / session helpers
 * ---------------------------------------------------------------------- */

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function setThemePreferenceForCurrentUser(string $theme): void
{
    $theme = sanitizeThemePreference($theme);
    $_SESSION['theme_preference'] = $theme;

    if (isLoggedIn()) {
        $stmt = db()->prepare('UPDATE users SET theme_preference = ? WHERE id = ?');
        $stmt->execute([$theme, (int) $_SESSION['user_id']]);
    }
}

/** Fetch the currently logged-in user row, or null. */
function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $cached = $user ?: null;
    return $cached;
}

/** Redirect guests to login. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function enforcePasswordChangeRequirement(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $stmt = db()->prepare('SELECT must_change_password, auth_session_version FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $securityState = $stmt->fetch();
    if (!$securityState || !isset($_SESSION['auth_session_version'])
        || (int) $_SESSION['auth_session_version'] !== (int) $securityState['auth_session_version']) {
        logoutUser();
        header('Location: login.php');
        exit;
    }

    if ((int) $securityState['must_change_password'] === 1
        && basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'force_password_change.php'
        && basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'logout.php') {
        header('Location: force_password_change.php');
        exit;
    }
}

/** Whether the current user is an administrator/owner. */
function isAdmin(): bool
{
    $user = currentUser();
    return $user !== null && (int) ($user['is_admin'] ?? 0) === 1;
}

/** Guard admin-only pages: guests go to login, non-admins back to dashboard. */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'That area is for administrators only.');
        header('Location: dashboard.php');
        exit;
    }
}

function loginUser(int $userId): void
{
    $stmt = db()->prepare('SELECT auth_session_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $sessionVersion = $stmt->fetchColumn();
    if ($sessionVersion === false) {
        throw new RuntimeException('Cannot create a session for an unknown user.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['auth_session_version'] = (int) $sessionVersion;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logoutUser(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}

function loginAttemptIdentifierHash(string $identifier): string
{
    if (strlen(ECOCYCLE_APP_KEY) < 32) {
        throw new RuntimeException('Set a strong app_key in config/secrets.php before enabling authentication.');
    }
    return hash_hmac('sha256', strtolower(trim($identifier)), ECOCYCLE_APP_KEY);
}

function loginIsRateLimited(string $identifier, string $ipAddress): bool
{
    $identifierHash = loginAttemptIdentifierHash($identifier);
    $accountAttempts = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE identifier_hash = ? AND was_successful = 0 AND created_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $accountAttempts->execute([$identifierHash]);
    if ((int) $accountAttempts->fetchColumn() >= 5) {
        return true;
    }

    $ipAttempts = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip_address = ? AND was_successful = 0 AND created_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $ipAttempts->execute([$ipAddress]);
    return (int) $ipAttempts->fetchColumn() >= 20;
}

function recordLoginAttempt(string $identifier, string $ipAddress, bool $successful): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (identifier_hash, ip_address, was_successful) VALUES (?, ?, ?)'
    );
    $stmt->execute([
        loginAttemptIdentifierHash($identifier),
        $ipAddress !== '' ? $ipAddress : null,
        $successful ? 1 : 0,
    ]);
}

/* -------------------------------------------------------------------------
 * CSRF protection
 * ---------------------------------------------------------------------- */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function issueAuthToken(string $purpose, int $userId, int $expiresMinutes): string
{
        $tables = [
            'password_reset' => 'password_resets',
            'email_verification' => 'email_verifications',
        ];
        if (!isset($tables[$purpose]) || $expiresMinutes < 1) {
            throw new InvalidArgumentException('Invalid authentication token configuration.');
        }
        $table = $tables[$purpose];
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $invalidate = $pdo->prepare("UPDATE {$table} SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
            $invalidate->execute([$userId]);
            $insert = $pdo->prepare(
                "INSERT INTO {$table} (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL {$expiresMinutes} MINUTE))"
            );
            $insert->execute([$userId, $tokenHash]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $ex) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }

        return $token;
}

function emailActionRateLimited(string $action, string $identifier, string $ipAddress, int $accountLimit = 3, int $ipLimit = 10): bool
{
        $identifiers = [
            'email-action:' . $action . ':' . strtolower(trim($identifier)) => $accountLimit,
        ];
        if ($ipAddress !== '') {
            $identifiers['email-action-ip:' . $action . ':' . $ipAddress] = $ipLimit;
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier_hash = ? AND was_successful = 0 AND created_at >= NOW() - INTERVAL 15 MINUTE'
        );
        foreach ($identifiers as $value => $limit) {
            $stmt->execute([loginAttemptIdentifierHash($value)]);
            if ((int) $stmt->fetchColumn() >= $limit) {
                return true;
            }
        }
        return false;
}

function recordEmailAction(string $action, string $identifier, string $ipAddress): void
{
        $record = db()->prepare(
            'INSERT INTO login_attempts (identifier_hash, ip_address, was_successful) VALUES (?, NULL, 0)'
        );
        $values = ['email-action:' . $action . ':' . strtolower(trim($identifier))];
        if ($ipAddress !== '') {
            $values[] = 'email-action-ip:' . $action . ':' . $ipAddress;
        }
        foreach ($values as $value) {
            $record->execute([loginAttemptIdentifierHash($value)]);
        }
}

function appEmailUrl(string $path, string $token): string
{
        return APP_BASE_URL . '/' . ltrim($path, '/') . '?token=' . rawurlencode($token);
}

function sendAppEmail(string $to, string $subject, string $body): void
{
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
            throw new InvalidArgumentException('Invalid email message recipient or subject.');
        }

        if (MAIL_DRIVER === 'log') {
            $logDirectory = dirname(__DIR__) . '/logs';
            if (!is_dir($logDirectory) && !mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
                throw new RuntimeException('Could not create the development mail log directory.');
            }
            $entry = json_encode([
                'sent_at' => gmdate(DATE_ATOM),
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (file_put_contents($logDirectory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Could not write to the development mail log.');
            }
            return;
        }

        if (MAIL_DRIVER !== 'phpmailer') {
            throw new RuntimeException('Unsupported mail driver configured.');
        }
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPMailer is selected but Composer dependencies are not installed.');
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not available in the configured Composer dependencies.');
        }

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = MAIL_SMTP_HOST;
        $mailer->Port = (int) MAIL_SMTP_PORT;
        $mailer->SMTPAuth = MAIL_SMTP_AUTH;
        $mailer->Username = MAIL_SMTP_USERNAME;
        $mailer->Password = MAIL_SMTP_PASSWORD;
        if (MAIL_SMTP_ENCRYPTION !== '') {
            $mailer->SMTPSecure = MAIL_SMTP_ENCRYPTION;
        }
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->isHTML(false);
        $mailer->send();
}

/* -------------------------------------------------------------------------
 * View helpers
 * ---------------------------------------------------------------------- */

/** HTML-escape shortcut. */
function e(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a number with thousands separators. */
function num($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals);
}

/** Flash-message helpers (one-shot session messages). */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* -------------------------------------------------------------------------
 * Recycling / points engine
 * ---------------------------------------------------------------------- */

/**
 * Record a recycling entry inside a transaction, updating points, streak,
 * and awarding any newly-earned badges. Returns the created log row id.
 */
function recordRecyclingLog(int $userId, string $material, int $quantity, float $weightKg, ?string $note): array
{
    $catalogue = materials();
    if (!isset($catalogue[$material])) {
        throw new InvalidArgumentException('Unknown material type.');
    }
    if ($weightKg <= 0 || $weightKg > RECYCLING_MAX_KG_PER_ENTRY) {
        throw new InvalidArgumentException(
            'Recycling weight must be greater than 0 and no more than '
            . num(RECYCLING_MAX_KG_PER_ENTRY) . ' kg per entry.'
        );
    }
    $meta = $catalogue[$material];

    $points = (int) round($weightKg * $meta['points_per_kg']);
    $co2    = round($weightKg * $meta['co2_per_kg'], 2);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('SELECT streak_count, last_log_date FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $dailyPointsStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(points_awarded), 0)
               FROM recycling_logs
              WHERE user_id = ? AND created_at >= CURDATE()'
        );
        $dailyPointsStmt->execute([$userId]);
        $pointsEarnedToday = (int) $dailyPointsStmt->fetchColumn();
        if ($pointsEarnedToday + $points > RECYCLING_DAILY_POINT_CAP) {
            throw new DomainException(sprintf(
                'You have earned %s of %s recycling points today. This entry would exceed the daily cap.',
                num($pointsEarnedToday),
                num(RECYCLING_DAILY_POINT_CAP)
            ));
        }

        $stmt = $pdo->prepare(
            'INSERT INTO recycling_logs (user_id, material_type, quantity, weight_kg, points_awarded, co2_saved_kg, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $material, $quantity, $weightKg, $points, $co2, $note]);
        $logId = (int) $pdo->lastInsertId();

        // Streak: increment if last log was yesterday, reset to 1 if older, keep if today.
        $today = new DateTimeImmutable('today');
        $streak = 1;
        if (!empty($user['last_log_date'])) {
            $last = new DateTimeImmutable($user['last_log_date']);
            $diff = (int) $last->diff($today)->format('%a');
            if ($diff === 0) {
                $streak = (int) $user['streak_count']; // already logged today
            } elseif ($diff === 1) {
                $streak = (int) $user['streak_count'] + 1;
            } else {
                $streak = 1;
            }
        }

        $streakUpdate = $pdo->prepare(
            'UPDATE users SET streak_count = ?, last_log_date = CURDATE() WHERE id = ?'
        );
        $streakUpdate->execute([$streak, $userId]);

        $materialLabel = $catalogue[$material]['label'];
        adjustPoints(
            $userId,
            $points,
            'deposit',
            "Verified $materialLabel recycling drop-off",
            [
                'source' => "Verified $materialLabel recycling ({$quantity} items, {$weightKg} kg)",
                'log_id' => $logId,
            ],
            'earned'
        );

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $newBadges = evaluateBadges($userId);

    return [
        'log_id'     => $logId,
        'points'     => $points,
        'co2'        => $co2,
        'streak'     => $streak,
        'new_badges' => $newBadges,
    ];
}

/**
 * Check every badge criterion for a user and award any not yet held.
 * Returns the list of newly-awarded badge rows.
 */
function evaluateBadges(int $userId): array
{
    $pdo = db();

    $stats = $pdo->query(
        'SELECT COUNT(*)                         AS log_count,
                COALESCE(SUM(weight_kg), 0)      AS total_kg,
                COUNT(DISTINCT material_type)    AS material_types
           FROM recycling_logs WHERE user_id = ' . $userId
    )->fetch();

    $user = $pdo->query('SELECT total_points, streak_count FROM users WHERE id = ' . $userId)->fetch();

    $earned = [];
    if ((int) $stats['log_count'] >= 1)          $earned[] = 'first_log';
    if ((int) $user['streak_count'] >= 7)        $earned[] = 'streak_7';
    if ((float) $stats['total_kg'] >= 100)       $earned[] = 'kg_100';
    if ((int) $user['total_points'] >= 1000)     $earned[] = 'points_1000';
    if ((int) $stats['material_types'] >= count(materials())) $earned[] = 'all_materials';

    if (!$earned) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($earned), '?'));
    $badgeStmt = $pdo->prepare("SELECT * FROM badges WHERE code IN ($placeholders)");
    $badgeStmt->execute($earned);
    $badges = $badgeStmt->fetchAll();

    $insert = $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)');
    $newly = [];
    foreach ($badges as $badge) {
        $insert->execute([$userId, $badge['id']]);
        if ($insert->rowCount() > 0) {
            $newly[] = $badge;
        }
    }
    return $newly;
}

/** Generate a human-friendly, unique redemption code. */
function generateRedemptionCode(): string
{
    return 'ECO-' . strtoupper(bin2hex(random_bytes(4)));
}

/* -------------------------------------------------------------------------
 * EcoWallet system
 * ---------------------------------------------------------------------- */

/**
 * Create a new EcoWallet for a user (called on signup or for migration).
 */
function createWallet(int $userId): int
{
    $stmt = db()->prepare('INSERT INTO wallets (user_id) VALUES (?)');
    $stmt->execute([$userId]);
    return (int) db()->lastInsertId();
}

/**
 * Get the wallet row for a user, or null if not found.
 */
function getUserWallet(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * Apply a signed points change to both balances and create its ledger entry.
 * Joins an existing transaction when called from a larger atomic operation.
 */
function adjustPoints(
    int $userId,
    int $balanceChange,
    string $type,
    string $source,
    array $receiptDetails = [],
    string $lifetimeMetric = 'none'
): array {
    if ($balanceChange === 0 && $type !== 'deposit') {
        throw new InvalidArgumentException('Points adjustment must be non-zero.');
    }
    if (!in_array($type, ['deposit', 'redemption', 'adjustment'], true)) {
        throw new InvalidArgumentException('Unsupported points transaction type.');
    }
    if (!in_array($lifetimeMetric, ['none', 'earned', 'redeemed'], true)) {
        throw new InvalidArgumentException('Unsupported points lifetime metric.');
    }
    if (($type === 'deposit' && $balanceChange < 0)
        || ($type === 'redemption' && $balanceChange > 0)
        || ($lifetimeMetric === 'earned' && $balanceChange < 0)
        || ($lifetimeMetric === 'redeemed' && $balanceChange > 0)) {
        throw new InvalidArgumentException('Points adjustment direction does not match its transaction type.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $userStmt = $pdo->prepare('SELECT id, points_balance FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $createWallet = $pdo->prepare(
            'INSERT INTO wallets (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id'
        );
        $createWallet->execute([$userId]);

        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('Wallet could not be created.');
        }

        $amount = abs($balanceChange);
        if ($balanceChange < 0
            && ((int) $user['points_balance'] < $amount || (int) $wallet['balance'] < $amount)) {
            throw new RuntimeException('Insufficient points balance.');
        }

        $earnedChange = $lifetimeMetric === 'earned' ? $amount : 0;
        $redeemedChange = $lifetimeMetric === 'redeemed' ? $amount : 0;
        $userUpdate = $pdo->prepare(
            'UPDATE users
                SET points_balance = points_balance + ?,
                    total_points = total_points + ?
              WHERE id = ?'
        );
        $userUpdate->execute([$balanceChange, $earnedChange, $userId]);

        $walletUpdate = $pdo->prepare(
            'UPDATE wallets
                SET balance = balance + ?,
                    lifetime_earned = lifetime_earned + ?,
                    lifetime_redeemed = lifetime_redeemed + ?
              WHERE id = ?'
        );
        $walletUpdate->execute([$balanceChange, $earnedChange, $redeemedChange, $wallet['id']]);
        $newBalance = (int) $wallet['balance'] + $balanceChange;

        $referencePrefix = match ($type) {
            'deposit' => 'DEP-',
            'redemption' => 'RED-',
            'adjustment' => 'ADJ-',
        };
        $referenceId = $referencePrefix . strtoupper(bin2hex(random_bytes(6)));
        $receipt = array_merge($receiptDetails, [
            'type' => $type,
            'amount' => $amount,
            'balance_change' => $balanceChange,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        $transactionStmt = $pdo->prepare(
            'INSERT INTO transactions
                (wallet_id, type, amount, source_description, status, reference_id, balance_after, receipt_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $transactionStmt->execute([
            $wallet['id'],
            $type,
            $amount,
            $source,
            'completed',
            $referenceId,
            $newBalance,
            json_encode($receipt, JSON_THROW_ON_ERROR),
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'id' => $transactionId,
            'wallet_id' => (int) $wallet['id'],
            'type' => $type,
            'amount' => $amount,
            'reference_id' => $referenceId,
            'status' => 'completed',
            'balance_after' => $newBalance,
        ];
    } catch (Throwable $ex) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Deposit points into a user's wallet (triggered on verified recycling log).
 * Returns transaction row with receipt data.
 */
function depositPointsToWallet(int $userId, int $points, string $source): array
{
    if ($points <= 0) {
        throw new InvalidArgumentException('Deposit points must be positive.');
    }
    $transaction = adjustPoints(
        $userId,
        $points,
        'deposit',
        $source,
        ['source' => $source],
        'earned'
    );
    sendWalletNotification($userId, 'deposit', [
        'amount' => $points,
        'source' => $source,
        'new_balance' => $transaction['balance_after'],
        'reference_id' => $transaction['reference_id'],
    ]);
    return $transaction;
}

function applyWelcomeBonus(int $userId): array
{
    return depositPointsToWallet($userId, 20, 'Welcome bonus');
}

/**
 * Verify a PIN attempt. Returns true if correct, false if incorrect.
 * Also handles failed attempt tracking and lockout.
 */
function verifyPin(int $userId, string $pinAttempt): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();

        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }
        if ($wallet['locked_until'] && strtotime($wallet['locked_until']) > time()) {
            $pdo->commit();
            return false;
        }
        if ($wallet['locked_until'] && strtotime($wallet['locked_until']) <= time()) {
            $wallet['failed_pin_attempts'] = 0;
        }

        $correct = $wallet['pin_hash'] && password_verify($pinAttempt, $wallet['pin_hash']);
        if (!$correct) {
            $attempts = (int) $wallet['failed_pin_attempts'] + 1;
            $lockedUntil = $attempts >= 3 ? date('Y-m-d H:i:s', time() + 900) : null;
            $upd = $pdo->prepare('UPDATE wallets SET failed_pin_attempts = ?, locked_until = ? WHERE id = ?');
            $upd->execute([$attempts, $lockedUntil, $wallet['id']]);
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE wallets SET failed_pin_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$wallet['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function getWithdrawalIdempotencyToken(string $scope): string
{
    $sessionKey = match ($scope) {
        'checkout' => 'withdrawal_checkout_idempotency',
        'legacy' => 'wallet_withdrawal_idempotency',
        default => throw new InvalidArgumentException('Invalid withdrawal idempotency scope.'),
    };
    if (!isset($_SESSION[$sessionKey]) || !preg_match('/^[a-f0-9]{64}$/', (string) $_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION[$sessionKey];
}

function isWithdrawalIdempotencyTokenValid(string $scope, string $candidate): bool
{
    $sessionKey = match ($scope) {
        'checkout' => 'withdrawal_checkout_idempotency',
        'legacy' => 'wallet_withdrawal_idempotency',
        default => throw new InvalidArgumentException('Invalid withdrawal idempotency scope.'),
    };
    return preg_match('/^[a-f0-9]{64}$/', $candidate) === 1
        && isset($_SESSION[$sessionKey])
        && hash_equals((string) $_SESSION[$sessionKey], $candidate);
}

function deriveWithdrawalAuthorizationToken(int $userId, string $referenceId, string $checkoutToken): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $checkoutToken)) {
        throw new InvalidArgumentException('Withdrawal checkout token is invalid.');
    }
    return hash_hmac(
        'sha256',
        'withdrawal-authorization:' . $userId . ':' . $referenceId,
        $checkoutToken
    );
}

/**
 * Check if a wallet is currently locked due to failed PIN attempts.
 */
function isWalletLocked(int $userId): bool
{
    $wallet = getUserWallet($userId);
    if (!$wallet || !$wallet['locked_until']) {
        return false;
    }
    return strtotime($wallet['locked_until']) > time();
}

/**
 * Get remaining lockout time in seconds, or 0 if not locked.
 */
function getWalletLockoutRemaining(int $userId): int
{
    $wallet = getUserWallet($userId);
    if (!$wallet || !$wallet['locked_until']) {
        return 0;
    }
    $remaining = strtotime($wallet['locked_until']) - time();
    return max(0, $remaining);
}

/**
 * Set a new PIN for the wallet. PIN must be 4-6 digits.
 */
function setWalletPin(int $userId, string $pin): void
{
    if (!preg_match('/^\d{4,6}$/', $pin)) {
        throw new InvalidArgumentException('PIN must be 4-6 digits.');
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        'UPDATE wallets SET pin_hash = ?, pin_created_at = CURRENT_TIMESTAMP, failed_pin_attempts = 0, locked_until = NULL
         WHERE user_id = ?'
    );
    $stmt->execute([$hash, $userId]);
}

function getWalletPinResetCooldownUntil(int $userId): ?string
{
    if (PIN_RESET_COOLDOWN_HOURS < 1) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT CASE
            WHEN pin_reset_at IS NOT NULL
             AND TIMESTAMPADD(HOUR, ?, pin_reset_at) > CURRENT_TIMESTAMP
            THEN TIMESTAMPADD(HOUR, ?, pin_reset_at)
            ELSE NULL
         END
           FROM wallets
          WHERE user_id = ?'
    );
    $stmt->execute([PIN_RESET_COOLDOWN_HOURS, PIN_RESET_COOLDOWN_HOURS, $userId]);
    $until = $stmt->fetchColumn();
    return is_string($until) ? $until : null;
}

function assertWalletWithdrawalCooldownExpired(array $wallet): void
{
    if (PIN_RESET_COOLDOWN_HOURS < 1 || empty($wallet['pin_reset_at'])) {
        return;
    }

    $stmt = db()->prepare('SELECT TIMESTAMPADD(HOUR, ?, ?) > CURRENT_TIMESTAMP');
    $stmt->execute([PIN_RESET_COOLDOWN_HOURS, $wallet['pin_reset_at']]);
    if ((int) $stmt->fetchColumn() === 1) {
        throw new RuntimeException('Withdrawals are temporarily on hold after a wallet PIN reset.');
    }
}

function isTrivialWalletPin(string $pin): bool
{
    if (!preg_match('/^[0-9]{4,6}$/D', $pin)) {
        return true;
    }
    if (preg_match('/^([0-9])\1+$/D', $pin)) {
        return true;
    }

    $ascending = true;
    $descending = true;
    for ($index = 1, $length = strlen($pin); $index < $length; $index++) {
        $previous = (int) $pin[$index - 1];
        $current = (int) $pin[$index];
        $ascending = $ascending && $current === ($previous + 1) % 10;
        $descending = $descending && $current === ($previous + 9) % 10;
    }
    return $ascending || $descending;
}

/**
 * Replace or clear a wallet PIN, cancel open requests, and release reservations atomically.
 */
function resetWalletPin(int $userId, ?string $newPin, ?int $adminUserId = null): void
{
    if ($newPin !== null && !preg_match('/^\d{4,6}$/', $newPin)) {
        throw new InvalidArgumentException('Invalid wallet PIN reset request.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('Wallet reset could not be completed.');
        }
        if ($newPin !== null && $wallet['pin_hash'] !== null && password_verify($newPin, $wallet['pin_hash'])) {
            throw new RuntimeException('Wallet reset could not be completed.');
        }

        $requestsStmt = $pdo->prepare(
            "SELECT id, points_reserved
               FROM withdrawal_requests
              WHERE user_id = ? AND status IN ('pending', 'authorized')
              ORDER BY id
              FOR UPDATE"
        );
        $requestsStmt->execute([$userId]);
        $requests = $requestsStmt->fetchAll();
        $released = 0;
        $cancelStmt = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'cancelled', points_reserved = 0, cancelled_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status IN ('pending', 'authorized')"
        );
        foreach ($requests as $request) {
            $cancelStmt->execute([$request['id']]);
            if ($cancelStmt->rowCount() === 1) {
                $released += (int) $request['points_reserved'];
            }
        }

        $pinHash = $newPin === null ? null : password_hash($newPin, PASSWORD_DEFAULT);
        if ($newPin !== null && $pinHash === false) {
            throw new RuntimeException('Wallet reset could not be completed.');
        }
        $updateWallet = $pdo->prepare(
            'UPDATE wallets
                SET pin_hash = ?, pin_created_at = ?, pin_reset_at = CURRENT_TIMESTAMP,
                    failed_pin_attempts = 0, locked_until = NULL, balance = balance + ?
              WHERE id = ?'
        );
        $updateWallet->execute([
            $pinHash,
            $newPin === null ? null : date('Y-m-d H:i:s'),
            $released,
            $wallet['id'],
        ]);
        if ($released > 0) {
            $pdo->prepare('UPDATE users SET points_balance = points_balance + ? WHERE id = ?')
                ->execute([$released, $userId]);
        }

        logWithdrawalAudit(
            $userId,
            $adminUserId === null ? 'wallet_pin_reset' : 'wallet_pin_reset_admin',
            null,
            null,
            null,
            array_filter([
                'admin_user_id' => $adminUserId,
                'cancelled_requests' => count($requests),
                'released_points' => $released,
            ], static fn ($value): bool => $value !== null)
        );
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function sendWalletPinChangedNotice(int $userId): void
{
    if (!EMAIL_FEATURES_ENABLED) {
        return;
    }

    $stmt = db()->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException('Could not load account details for the wallet PIN notice.');
    }
    sendAppEmail(
        (string) $user['email'],
        'Your EcoWallet PIN was changed',
        sprintf(
            "Hi %s,\n\nYour EcoWallet PIN was changed. If you did not make this change, please contact an administrator.\n\n— EcoCycle Team\n",
            $user['name']
        )
    );
}

/**
 * Check if a user has already set up a PIN.
 */
function hasPin(int $userId): bool
{
    $wallet = getUserWallet($userId);
    return $wallet && $wallet['pin_hash'] !== null;
}

function getSavedPayoutMethods(int $userId): array
{
    $stmt = db()->prepare('SELECT id, method, provider, account_label, account_last4 FROM saved_payout_methods WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function validateWithdrawalDetails(string $method, array $details): array
{
    $allowed = ['voucher', 'ewallet', 'bank_transfer'];
    if (!in_array($method, $allowed, true)) {
        return ['Payout method is invalid.'];
    }
    if ($method === 'voucher') {
        return [];
    }

    $provider = trim((string) ($details['provider'] ?? ''));
    $account = trim((string) ($details['account'] ?? ''));
    $errors = [];
    if ($provider === '' || !preg_match('/^[A-Za-z0-9 ()&.-]{2,40}$/', $provider)) {
        $errors[] = 'Enter a valid payout provider or bank name.';
    }
    if ($method === 'bank_transfer' && !preg_match('/^[0-9]{6,24}$/', $account)) {
        $errors[] = 'Bank account number must contain 6-24 digits.';
    }
    if ($method === 'ewallet' && !preg_match('/^(?:\+?63|0)9[0-9]{9}$|^[^@\s]+@[^@\s]+\.[^@\s]+$/', $account)) {
        $errors[] = 'Enter a valid mobile number or email for the e-wallet.';
    }
    return $errors;
}

function getDailyWithdrawalTotal(int $userId): int
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE((
                SELECT SUM(t.amount)
                  FROM transactions t
                  JOIN wallets w ON w.id = t.wallet_id
                 WHERE w.user_id = ?
                   AND t.type = 'withdrawal'
                   AND t.created_at >= CURDATE()
                   AND t.status IN ('pending', 'completed')
            ), 0)
            +
            COALESCE((
                SELECT SUM(r.points_reserved)
                  FROM withdrawal_requests r
                 WHERE r.user_id = ?
                   AND r.status IN ('pending', 'authorized')
                   AND r.expires_at > CURRENT_TIMESTAMP
            ), 0)"
    );
    $stmt->execute([$userId, $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Expire this user's overdue checkout requests and release each reservation once.
 */
function sweepExpiredWithdrawalRequests(int $userId): int
{
    $candidateStmt = db()->prepare(
        "SELECT 1 FROM withdrawal_requests
          WHERE user_id = ?
            AND status IN ('pending', 'authorized')
            AND expires_at <= CURRENT_TIMESTAMP
          LIMIT 1"
    );
    $candidateStmt->execute([$userId]);
    if (!$candidateStmt->fetchColumn()) {
        return 0;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT id FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }

        $requestsStmt = $pdo->prepare(
            "SELECT id, points_reserved
               FROM withdrawal_requests
              WHERE user_id = ?
                AND status IN ('pending', 'authorized')
                AND expires_at <= CURRENT_TIMESTAMP
              ORDER BY id
              FOR UPDATE"
        );
        $requestsStmt->execute([$userId]);
        $requests = $requestsStmt->fetchAll();
        $released = 0;

        $expireStmt = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'expired', points_reserved = 0, expired_at = CURRENT_TIMESTAMP
              WHERE id = ?
                AND status IN ('pending', 'authorized')
                AND expires_at <= CURRENT_TIMESTAMP"
        );
        foreach ($requests as $request) {
            $expireStmt->execute([$request['id']]);
            if ($expireStmt->rowCount() === 1) {
                $released += (int) $request['points_reserved'];
            }
        }

        if ($released > 0) {
            $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
                ->execute([$released, $wallet['id']]);
            $pdo->prepare('UPDATE users SET points_balance = points_balance + ? WHERE id = ?')
                ->execute([$released, $userId]);
        }

        $pdo->commit();
        return $released;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Place a checkout request and reserve its points until completion or expiry.
 */
function createWithdrawalRequest(int $userId, array $requestData): array
{
    sweepExpiredWithdrawalRequests($userId);

    $method = (string) ($requestData['method'] ?? '');
    if (!in_array($method, ['voucher', 'ewallet', 'bank_transfer'], true)) {
        throw new InvalidArgumentException('Payout method is invalid.');
    }

    $points = (int) ($requestData['points'] ?? 0);
    $provider = trim((string) ($requestData['provider'] ?? ''));
    $accountHolder = trim((string) ($requestData['account_holder'] ?? ''));
    $account = trim((string) ($requestData['account'] ?? ''));
    $accountLast4 = (string) ($requestData['account_last4'] ?? substr($account, -4));
    $label = trim((string) ($requestData['label'] ?? ''));
    $savedMethodId = (int) ($requestData['saved_method_id'] ?? 0);
    $saveMethod = !empty($requestData['save_method']);
    $idempotencyToken = (string) ($requestData['idempotency_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyToken)) {
        throw new InvalidArgumentException('Withdrawal idempotency token is invalid.');
    }
    $idempotencyHash = hash('sha256', $idempotencyToken);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }
        assertWalletWithdrawalCooldownExpired($wallet);
        $duplicateStmt = $pdo->prepare(
            'SELECT id FROM withdrawal_requests
              WHERE user_id = ? AND idempotency_key_hash = ?
              LIMIT 1'
        );
        $duplicateStmt->execute([$userId, $idempotencyHash]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('This withdrawal form has already been submitted.');
        }
        if ($points < WALLET_MIN_WITHDRAWAL) {
            throw new RuntimeException('Minimum withdrawal is ' . num(WALLET_MIN_WITHDRAWAL) . ' points.');
        }
        if ((int) $wallet['balance'] < $points) {
            throw new RuntimeException('Amount exceeds your available balance.');
        }
        if (getDailyWithdrawalTotal($userId) + $points > WALLET_DAILY_WITHDRAWAL_CAP) {
            throw new RuntimeException('Amount exceeds your remaining daily withdrawal quota.');
        }

        $reference = 'WDR-' . strtoupper(bin2hex(random_bytes(6)));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $authorizationToken = deriveWithdrawalAuthorizationToken($userId, $reference, $token);
        $authorizationTokenHash = hash('sha256', $authorizationToken);
        $grossAmount = number_format($points / WALLET_POINTS_PER_PESO, 2, '.', '');
        $fee = '0.00';
        $netAmount = $grossAmount;
        $voucherCode = $method === 'voucher'
            ? 'ECO-' . strtoupper(bin2hex(random_bytes(5)))
            : null;

        $newBalance = (int) $wallet['balance'] - $points;
        $pdo->prepare('UPDATE wallets SET balance = ? WHERE id = ?')
            ->execute([$newBalance, $wallet['id']]);
        $pdo->prepare('UPDATE users SET points_balance = points_balance - ? WHERE id = ?')
            ->execute([$points, $userId]);

        $insertStmt = $pdo->prepare(
            "INSERT INTO withdrawal_requests
                (user_id, wallet_id, reference_id, token_hash, idempotency_key_hash,
                 authorization_token_hash, status, points_amount,
                 points_reserved, gross_amount, fee, net_amount, payout_method, provider,
                 payout_label, account_holder, account_last4, voucher_code, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     TIMESTAMPADD(MINUTE, ?, CURRENT_TIMESTAMP))"
        );
        $insertStmt->execute([
            $userId,
            $wallet['id'],
            $reference,
            $tokenHash,
            $idempotencyHash,
            $authorizationTokenHash,
            $points,
            $points,
            $grossAmount,
            $fee,
            $netAmount,
            $method,
            $provider,
            $label,
            $accountHolder,
            $accountLast4,
            $voucherCode,
            WALLET_WITHDRAWAL_EXPIRY_MINUTES,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        if ($saveMethod && $savedMethodId === 0 && $method !== 'voucher' && $account !== '') {
            $methodLabel = $label !== '' ? $label : trim($provider . ' · ' . $accountHolder);
            $pdo->prepare(
                'INSERT INTO saved_payout_methods
                    (user_id, method, provider, account_label, account_last4, account_hash)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                $method,
                $provider,
                substr($methodLabel, 0, 100),
                substr($account, -4),
                password_hash($account, PASSWORD_DEFAULT),
            ]);
        }

        $pdo->commit();
        return [
            'id' => $requestId,
            'reference_id' => $reference,
            'token' => $token,
            'status' => 'pending',
            'points_amount' => $points,
            'points_reserved' => $points,
            'gross_amount' => $grossAmount,
            'fee' => $fee,
            'net_amount' => $netAmount,
            'payout_method' => $method,
            'provider' => $provider,
            'payout_label' => $label,
            'account_holder' => $accountHolder,
            'account_last4' => $accountLast4,
            'voucher_code' => $voucherCode,
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function markWithdrawalPinVerified(int $userId, int $requestId, string $token): void
{
    $stmt = db()->prepare(
        "UPDATE withdrawal_requests
            SET pin_verified_at = CURRENT_TIMESTAMP
          WHERE id = ? AND user_id = ? AND token_hash = ?
            AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP"
    );
    $stmt->execute([$requestId, $userId, hash('sha256', $token)]);
    if ($stmt->rowCount() === 1) {
        return;
    }

    $verifiedStmt = db()->prepare(
        "SELECT 1 FROM withdrawal_requests
          WHERE id = ? AND user_id = ? AND token_hash = ?
            AND status = 'pending' AND pin_verified_at IS NOT NULL
            AND expires_at > CURRENT_TIMESTAMP"
    );
    $verifiedStmt->execute([$requestId, $userId, hash('sha256', $token)]);
    if (!$verifiedStmt->fetchColumn()) {
        throw new RuntimeException('This withdrawal request has expired or is no longer pending.');
    }
}

function getWithdrawalRequest(int $userId, int $requestId, string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT *,
                GREATEST(0, TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, expires_at)) AS seconds_remaining
           FROM withdrawal_requests
          WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();
    if (!$request || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
        return null;
    }
    return $request;
}

/**
 * Consume the QR authorization token once after PIN verification.
 */
function consumeWithdrawalAuthorizationToken(int $userId, int $requestId, string $authorizationToken): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT id FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        if (!$walletStmt->fetch()) {
            throw new RuntimeException('User has no wallet.');
        }

        $requestStmt = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND user_id = ? FOR UPDATE');
        $requestStmt->execute([$requestId, $userId]);
        $request = $requestStmt->fetch();
        if (!$request || !hash_equals((string) $request['authorization_token_hash'], hash('sha256', $authorizationToken))) {
            throw new RuntimeException('Authorization QR code could not be verified.');
        }
        if ($request['authorization_token_used_at'] !== null) {
            throw new RuntimeException('This authorization QR code has already been used.');
        }
        if ($request['status'] !== 'pending' || $request['pin_verified_at'] === null
            || strtotime($request['expires_at']) <= time()) {
            throw new RuntimeException('This authorization request has expired or is not ready.');
        }
        if (!in_array($request['payout_method'], ['ewallet', 'bank_transfer'], true)) {
            throw new RuntimeException('This payout method does not require separate authorization.');
        }

        $authorizeStmt = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'authorized', authorized_at = CURRENT_TIMESTAMP,
                    authorization_token_used_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status = 'pending' AND authorization_token_used_at IS NULL
                AND pin_verified_at IS NOT NULL AND expires_at > CURRENT_TIMESTAMP"
        );
        $authorizeStmt->execute([$requestId]);
        if ($authorizeStmt->rowCount() !== 1) {
            throw new RuntimeException('This withdrawal request has expired. Please start again.');
        }
        $request['status'] = 'authorized';
        $request['authorized_at'] = date('Y-m-d H:i:s');
        $request['authorization_token_used_at'] = $request['authorized_at'];
        $pdo->commit();
        return $request;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function consumeWithdrawalAuthorizationTokenById(int $requestId, string $authorizationToken): array
{
    $stmt = db()->prepare(
        'SELECT user_id FROM withdrawal_requests
          WHERE id = ? AND authorization_token_hash = ?'
    );
    $stmt->execute([$requestId, hash('sha256', $authorizationToken)]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        throw new RuntimeException('Authorization QR code could not be verified.');
    }
    return consumeWithdrawalAuthorizationToken((int) $userId, $requestId, $authorizationToken);
}

function completeAuthorizedWithdrawalRequest(int $userId, int $requestId, string $token): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }

        $requestStmt = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND user_id = ? FOR UPDATE');
        $requestStmt->execute([$requestId, $userId]);
        $request = $requestStmt->fetch();
        if (!$request || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
            throw new RuntimeException('Withdrawal request could not be verified.');
        }
        if ($request['status'] !== 'authorized' || $request['authorization_token_used_at'] === null
            || strtotime($request['expires_at']) <= time()) {
            throw new RuntimeException('This authorized payout is no longer available.');
        }
        if (!in_array($request['payout_method'], ['ewallet', 'bank_transfer'], true)) {
            throw new RuntimeException('This payout method is not eligible for simulated authorization.');
        }

        $methodLabel = $request['payout_method'] === 'ewallet' ? 'E-Wallet' : 'Bank Transfer';
        $description = $methodLabel . ' Withdrawal';
        $receipt = json_encode([
            'type' => 'withdrawal',
            'amount' => (int) $request['points_amount'],
            'payout_method' => $request['payout_method'],
            'provider' => $request['provider'],
            'account_holder' => $request['account_holder'],
            'account_last4' => $request['account_last4'],
            'fee' => (string) $request['fee'],
            'net_amount' => (string) $request['net_amount'],
            'description' => 'Simulated ' . $description,
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR);

        $transactionStmt = $pdo->prepare(
            "INSERT INTO transactions
                (wallet_id, type, amount, source_description, status, reference_id, balance_after, receipt_data)
             VALUES (?, 'withdrawal', ?, ?, 'completed', ?, ?, ?)"
        );
        $transactionStmt->execute([
            $wallet['id'],
            $request['points_amount'],
            $description,
            $request['reference_id'],
            $wallet['balance'],
            $receipt,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE wallets SET lifetime_redeemed = lifetime_redeemed + ? WHERE id = ?')
            ->execute([$request['points_amount'], $wallet['id']]);

        $completeStmt = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'completed', points_reserved = 0, transaction_id = ?,
                    completed_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status = 'authorized'
                AND authorization_token_used_at IS NOT NULL
                AND expires_at > CURRENT_TIMESTAMP"
        );
        $completeStmt->execute([$transactionId, $requestId]);
        if ($completeStmt->rowCount() !== 1) {
            throw new RuntimeException('This payout expired before it could be completed.');
        }

        $pdo->commit();
        return [
            'id' => $transactionId,
            'reference_id' => $request['reference_id'],
            'amount' => (int) $request['points_amount'],
            'balance_after' => (int) $wallet['balance'],
            'payout_method' => $request['payout_method'],
            'provider' => $request['provider'],
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function cancelWithdrawalRequest(int $userId, int $requestId, string $token): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT id FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }

        $requestStmt = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND user_id = ? FOR UPDATE');
        $requestStmt->execute([$requestId, $userId]);
        $request = $requestStmt->fetch();
        if (!$request || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
            throw new RuntimeException('Withdrawal request could not be verified.');
        }
        if (!in_array($request['status'], ['pending', 'authorized'], true)) {
            throw new RuntimeException('This withdrawal request can no longer be changed.');
        }

        $reserved = (int) $request['points_reserved'];
        $update = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'cancelled', points_reserved = 0, cancelled_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status IN ('pending', 'authorized')"
        );
        $update->execute([$requestId]);
        if ($update->rowCount() === 1 && $reserved > 0) {
            $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
                ->execute([$reserved, $wallet['id']]);
            $pdo->prepare('UPDATE users SET points_balance = points_balance + ? WHERE id = ?')
                ->execute([$reserved, $userId]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Complete a simulated voucher payout and create its EcoWallet ledger receipt.
 */
function completeVoucherWithdrawalRequest(int $userId, int $requestId, string $token): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }

        $requestStmt = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND user_id = ? FOR UPDATE');
        $requestStmt->execute([$requestId, $userId]);
        $request = $requestStmt->fetch();
        if (!$request || !hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
            throw new RuntimeException('Withdrawal request could not be verified.');
        }
        if ($request['payout_method'] !== 'voucher' || $request['status'] !== 'pending'
            || strtotime($request['expires_at']) <= time()) {
            throw new RuntimeException('This voucher request is no longer available. Please start again.');
        }

        $receipt = json_encode([
            'type' => 'withdrawal',
            'amount' => (int) $request['points_amount'],
            'payout_method' => 'voucher',
            'provider' => $request['provider'],
            'voucher_code' => $request['voucher_code'],
            'fee' => (string) $request['fee'],
            'net_amount' => (string) $request['net_amount'],
            'description' => 'Simulated Gift Card / Voucher Withdrawal',
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR);
        $description = 'Gift Card / Voucher Withdrawal';
        $txnStmt = $pdo->prepare(
            "INSERT INTO transactions
                (wallet_id, type, amount, source_description, status, reference_id, balance_after, receipt_data)
             VALUES (?, 'withdrawal', ?, ?, 'completed', ?, ?, ?)"
        );
        $txnStmt->execute([
            $wallet['id'],
            $request['points_amount'],
            $description,
            $request['reference_id'],
            $wallet['balance'],
            $receipt,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE wallets SET lifetime_redeemed = lifetime_redeemed + ? WHERE id = ?')
            ->execute([$request['points_amount'], $wallet['id']]);
        $completeStmt = $pdo->prepare(
            "UPDATE withdrawal_requests
                SET status = 'completed', points_reserved = 0, transaction_id = ?,
                    completed_at = CURRENT_TIMESTAMP
              WHERE id = ? AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP"
        );
        $completeStmt->execute([$transactionId, $requestId]);
        if ($completeStmt->rowCount() !== 1) {
            throw new RuntimeException('This voucher request has expired. Please start again.');
        }

        $pdo->commit();
        return [
            'id' => $transactionId,
            'reference_id' => $request['reference_id'],
            'amount' => (int) $request['points_amount'],
            'balance_after' => (int) $wallet['balance'],
            'voucher_code' => (string) $request['voucher_code'],
            'net_amount' => (string) $request['net_amount'],
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

function logWithdrawalAudit(int $userId, string $action, ?int $amount = null, ?string $method = null, ?int $transactionId = null, array $details = []): void
{
    $stmt = db()->prepare('INSERT INTO withdrawal_audit_log (user_id, transaction_id, action, amount, payout_method, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $transactionId,
        $action,
        $amount,
        $method,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        json_encode($details),
    ]);
}

/**
 * Create a withdrawal (cash-out) transaction.
 */
function createWithdrawal(int $userId, int $points, string $payoutMethod, string $description, array $details = [], bool $saveMethod = false): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the wallet row so two simultaneous requests cannot spend the same points.
        $walletStmt = $pdo->prepare('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$userId]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            throw new RuntimeException('User has no wallet.');
        }
        assertWalletWithdrawalCooldownExpired($wallet);
        if ($points < WALLET_MIN_WITHDRAWAL) {
            throw new RuntimeException('Minimum withdrawal is ' . num(WALLET_MIN_WITHDRAWAL) . ' points.');
        }
        if ($wallet['balance'] < $points) {
            throw new RuntimeException('Insufficient balance.');
        }
        if (getDailyWithdrawalTotal($userId) + $points > WALLET_DAILY_WITHDRAWAL_CAP) {
            throw new RuntimeException('Daily withdrawal limit reached.');
        }

        $newBalance = $wallet['balance'] - $points;
        $newRedeemed = $wallet['lifetime_redeemed'] + $points;

        $stmt = $pdo->prepare(
            'UPDATE wallets
             SET balance = ?, lifetime_redeemed = ?
             WHERE id = ?'
        );
        $stmt->execute([$newBalance, $newRedeemed, $wallet['id']]);
        
        // Also deduct from rewards points balance to keep them synchronized
        $pdo->prepare('UPDATE users SET points_balance = points_balance - ? WHERE id = ?')
            ->execute([$points, $userId]);

        $refId = 'WIT-' . strtoupper(bin2hex(random_bytes(6)));
        $txnStmt = $pdo->prepare(
            'INSERT INTO transactions (wallet_id, type, amount, source_description, status, reference_id, balance_after, receipt_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $receipt = json_encode([
            'type' => 'withdrawal',
            'amount' => $points,
            'payout_method' => $payoutMethod,
            'provider' => $details['provider'] ?? '',
            'account_last4' => substr((string) ($details['account'] ?? ''), -4),
            'description' => $description,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $txnStmt->execute([
            $wallet['id'],
            'withdrawal',
            $points,
            $description,
            'completed',
            $refId,
            $newBalance,
            $receipt
        ]);

        $txnId = (int) $pdo->lastInsertId();
        if ($saveMethod && $payoutMethod !== 'voucher') {
            $account = trim((string) ($details['account'] ?? ''));
            $pdo->prepare('INSERT INTO saved_payout_methods (user_id, method, provider, account_label, account_last4, account_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $payoutMethod, trim((string) $details['provider']), trim((string) $details['label']), substr($account, -4), password_hash($account, PASSWORD_DEFAULT)]);
        }
        $pdo->commit();

        return [
            'id' => $txnId,
            'reference_id' => $refId,
            'type' => 'withdrawal',
            'amount' => $points,
            'status' => 'completed',
            'balance_after' => $newBalance
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

/**
 * Create a redemption transaction (item/reward redeemed).
 */
function createRedemptionTransaction(int $userId, int $points, string $rewardTitle): array
{
    if ($points <= 0) {
        throw new InvalidArgumentException('Redemption points must be positive.');
    }
    return adjustPoints(
        $userId,
        -$points,
        'redemption',
        $rewardTitle,
        ['reward' => $rewardTitle],
        'redeemed'
    );
}

function walletTransactionLabel(string $type): string
{
    return match ($type) {
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'redemption' => 'Redemption',
        'adjustment' => 'Adjustment',
        default => 'Transaction',
    };
}

function walletTransactionBalanceChange(array $transaction): int
{
    $type = (string) ($transaction['type'] ?? '');
    if ($type === 'adjustment') {
        $receipt = json_decode($transaction['receipt_data'] ?? '{}', true);
        if (is_array($receipt) && isset($receipt['balance_change']) && is_numeric($receipt['balance_change'])) {
            return (int) $receipt['balance_change'];
        }
    }

    $amount = (int) ($transaction['amount'] ?? 0);
    return $type === 'deposit' ? $amount : -$amount;
}

/**
 * Get transaction history for a wallet with optional filtering.
 */
function getWalletTransactions(int $userId, ?string $type = null, ?string $search = null, int $limit = 50, int $offset = 0): array
{
    $wallet = getUserWallet($userId);
    if (!$wallet) {
        return [];
    }

    $query = 'SELECT * FROM transactions WHERE wallet_id = ?';
    $params = [$wallet['id']];

    if ($type) {
        $query .= ' AND type = ?';
        $params[] = $type;
    }

    if ($search) {
        $query .= ' AND source_description LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $query .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Generate a formatted digital receipt for a transaction.
 */
function generateReceipt(array $transaction, int $userId): string
{
    $stmt = db()->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $receipt = json_decode($transaction['receipt_data'] ?? '{}', true);
    
    $html = '<div style="font-family:monospace; max-width:400px; margin:20px auto; border:1px solid #d1d5db; padding:20px; border-radius:8px; background:#f9fafb; color:#1f2937;">';
    $html .= '<div style="text-align:center; margin-bottom:20px;">';
    $html .= '<h2 style="margin:0; font-size:24px; color:#166534;">🌱 EcoCycle</h2>';
    $html .= '<p style="margin:5px 0; color:#475569;">Digital Transaction Receipt</p>';
    $html .= '</div>';
    
    $html .= '<div style="border-top:1px solid #ddd; border-bottom:1px solid #ddd; padding:15px 0; margin:15px 0;">';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Account:</span><strong>' . e($user['name']) . '</strong></div>';
    $balanceChange = walletTransactionBalanceChange($transaction);
    $amountPrefix = $balanceChange >= 0 ? '+' : '−';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Type:</span><strong>' . e(walletTransactionLabel((string) $transaction['type'])) . '</strong></div>';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Amount:</span><strong>' . $amountPrefix . num(abs($balanceChange)) . ' pts</strong></div>';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Status:</span><span style="color:' . (strpos($transaction['status'], 'completed') !== false ? 'green' : 'orange') . '; font-weight:bold;">' . e(ucfirst($transaction['status'])) . '</span></div>';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Reference:</span><strong style="font-size:12px;">' . e($transaction['reference_id']) . '</strong></div>';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Balance After:</span><strong>' . num($transaction['balance_after']) . ' pts</strong></div>';
    $html .= '<div style="display:flex; justify-content:space-between; margin:8px 0;"><span>Date/Time:</span><small>' . e(date('M d, Y H:i:s', strtotime($transaction['created_at']))) . '</small></div>';
    $html .= '</div>';
    
    if (!empty($receipt)) {
        $html .= '<div style="font-size:12px; color:#475569; line-height:1.6;">';
        $html .= '<p style="margin:10px 0 5px 0;"><strong>Details:</strong></p>';
        if (!empty($receipt['source'])) {
            $html .= '<p style="margin:3px 0;">📌 ' . e($receipt['source']) . '</p>';
        }
        if (!empty($receipt['reward'])) {
            $html .= '<p style="margin:3px 0;">🎁 Reward: ' . e($receipt['reward']) . '</p>';
        }
        if (!empty($receipt['description'])) {
            $html .= '<p style="margin:3px 0;">📝 ' . e($receipt['description']) . '</p>';
        }
        if (!empty($receipt['provider'])) {
            $html .= '<p style="margin:3px 0;">💳 Provider: ' . e($receipt['provider']) . '</p>';
        }
        if (!empty($receipt['account_last4'])) {
            $html .= '<p style="margin:3px 0;">🔒 Destination: ••••' . e($receipt['account_last4']) . '</p>';
        }
        $html .= '</div>';
    }
    
    $html .= '<div style="text-align:center; margin-top:20px; padding-top:15px; border-top:1px solid #d1d5db; font-size:11px; color:#64748b;">';
    $html .= '<p>This is a digital receipt for your records.<br>EcoCycle • Advancing UN SDG 12</p>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Export wallet transactions as CSV.
 */
function exportTransactionsCSV(int $userId): string
{
    $transactions = getWalletTransactions($userId, null, null, 9999, 0);
    
    $csv = "Transaction ID,Type,Amount (pts),Description,Status,Reference ID,Balance After,Date/Time\n";
    foreach ($transactions as $txn) {
        $csv .= sprintf(
            "%d,%s,%d,\"%s\",%s,%s,%d,\"%s\"\n",
            $txn['id'],
            $txn['type'],
            $txn['amount'],
            str_replace('"', '""', $txn['source_description']),
            $txn['status'],
            $txn['reference_id'],
            $txn['balance_after'],
            date('Y-m-d H:i:s', strtotime($txn['created_at']))
        );
    }
    
    return $csv;
}

/* -------------------------------------------------------------------------
 * Wallet Notifications & Emails
 * ---------------------------------------------------------------------- */

/**
 * Send email notification for wallet events (deposits, withdrawals, redemptions).
 * For development, logs to a file; in production, configure with a mail service.
 */
function sendWalletNotification(int $userId, string $type, array $data): void
{
    $stmt = db()->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $subject = '';
    $body = '';

    switch ($type) {
        case 'deposit':
            $subject = '💰 ' . num($data['amount']) . ' points deposited to your EcoWallet';
            $body = sprintf(
                "Hi %s,\n\n" .
                "Great news! You've earned %d points from %s.\n\n" .
                "Your new wallet balance: %d points\n" .
                "Transaction ID: %s\n\n" .
                "Keep recycling to earn more rewards!\n\n" .
                "— EcoCycle Team 🌱\n",
                $user['name'],
                $data['amount'],
                $data['source'] ?? 'verified recycling',
                $data['new_balance'] ?? 0,
                $data['reference_id'] ?? 'N/A'
            );
            break;

        case 'withdrawal':
            $subject = '🏦 Withdrawal of ' . num($data['amount']) . ' points processed';
            $body = sprintf(
                "Hi %s,\n\n" .
                "Your withdrawal request has been processed.\n\n" .
                "Amount: %d points\n" .
                "Payout Method: %s\n" .
                "Transaction ID: %s\n" .
                "Status: %s\n\n" .
                "Your remaining wallet balance: %d points\n\n" .
                "— EcoCycle Team 🌱\n",
                $user['name'],
                $data['amount'],
                $data['method'] ?? 'Voucher',
                $data['reference_id'] ?? 'N/A',
                $data['status'] ?? 'Processing',
                $data['new_balance'] ?? 0
            );
            break;

        case 'redemption':
            $subject = '🎁 Reward redeemed: ' . ($data['reward'] ?? 'Your gift');
            $body = sprintf(
                "Hi %s,\n\n" .
                "You've successfully redeemed a reward!\n\n" .
                "Reward: %s\n" .
                "Points Used: %d\n" .
                "Redemption Code: %s\n\n" .
                "Your remaining wallet balance: %d points\n\n" .
                "— EcoCycle Team 🌱\n",
                $user['name'],
                $data['reward'] ?? 'Unknown',
                $data['amount'] ?? 0,
                $data['code'] ?? 'N/A',
                $data['new_balance'] ?? 0
            );
            break;

        case 'pin_setup':
            $subject = '🔐 Your EcoWallet PIN has been set';
            $body = sprintf(
                "Hi %s,\n\n" .
                "Your EcoWallet is now secured with a PIN.\n\n" .
                "You'll need to enter your PIN when:\n" .
                "• Withdrawing points to cash/vouchers\n" .
                "• Redeeming rewards\n\n" .
                "If you didn't set this PIN, please contact us immediately.\n\n" .
                "— EcoCycle Team 🌱\n",
                $user['name']
            );
            break;

        case 'failed_pin_attempts':
            $subject = '⚠ Multiple failed PIN attempts on your EcoWallet';
            $body = sprintf(
                "Hi %s,\n\n" .
                "We've detected %d failed PIN attempts on your wallet.\n\n" .
                "Your wallet has been temporarily locked for 15 minutes as a security measure.\n\n" .
                "If this wasn't you, please reset your PIN:\n" .
                "Go to EcoWallet → Set PIN → Use password recovery\n\n" .
                "— EcoCycle Team 🌱\n",
                $user['name'],
                $data['attempts'] ?? 3
            );
            break;
    }

    if ($subject && $body) {
        // Log to file for development (in production, integrate with mail service like SendGrid/Mailgun)
        $logFile = __DIR__ . '/../logs/wallet-emails.log';
        if (!is_dir(dirname($logFile))) {
            @mkdir(dirname($logFile), 0755, true);
        }
        $logEntry = sprintf(
            "[%s] TO: %s | SUBJECT: %s\n%s\n---\n",
            date('Y-m-d H:i:s'),
            $user['email'],
            $subject,
            $body
        );
        @file_put_contents($logFile, $logEntry, FILE_APPEND);

        // In production, use a mail service:
        // mail($user['email'], $subject, $body, "From: noreply@ecocycle.local");
    }
}

/**
 * Send in-app notification (flash message) for wallet events.
 */
function notifyWalletEvent(string $type, array $data): void
{
    $message = '';
    switch ($type) {
        case 'deposit':
            $message = sprintf('✨ +%d points deposited from %s', $data['amount'] ?? 0, $data['source'] ?? 'recycling');
            break;
        case 'withdrawal':
            $message = sprintf('💸 Withdrawal of %d points processed (%s)', $data['amount'] ?? 0, $data['reference_id'] ?? '');
            break;
        case 'redemption':
            $message = sprintf('🎁 Redeemed "%s" for %d points', $data['reward'] ?? '', $data['amount'] ?? 0);
            break;
    }
    if ($message) {
        setFlash('success', $message);
    }
}

enforcePasswordChangeRequirement();
