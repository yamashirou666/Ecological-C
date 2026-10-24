<?php
/**
 * EcoCycle — central configuration & PDO database connection.
 *
 * Adjust DB credentials here if your XAMPP setup differs from the defaults.
 */

declare(strict_types=1);

$secretConfigPath = __DIR__ . '/secrets.php';
$secretConfig = is_file($secretConfigPath) ? require $secretConfigPath : [];
if (!is_array($secretConfig)) {
    throw new RuntimeException('config/secrets.php must return an array.');
}

$configValue = static function (array $secrets, string $key, string $environment, string $default): string {
    if (isset($secrets[$key]) && is_string($secrets[$key])) {
        return $secrets[$key];
    }
    $value = getenv($environment);
    return $value !== false ? $value : $default;
};

// Local XAMPP defaults remain available; deployment values belong in ignored secrets config.
defined('DB_HOST') || define('DB_HOST', $configValue($secretConfig, 'db_host', 'ECOCYCLE_DB_HOST', '127.0.0.1'));
defined('DB_PORT') || define('DB_PORT', $configValue($secretConfig, 'db_port', 'ECOCYCLE_DB_PORT', '3306'));
defined('DB_NAME') || define('DB_NAME', $configValue($secretConfig, 'db_name', 'ECOCYCLE_DB_NAME', 'ecocycle'));
defined('DB_USER') || define('DB_USER', $configValue($secretConfig, 'db_user', 'ECOCYCLE_DB_USER', 'root'));
defined('DB_PASS') || define('DB_PASS', $configValue($secretConfig, 'db_pass', 'ECOCYCLE_DB_PASS', ''));
defined('ECOCYCLE_APP_KEY') || define(
    'ECOCYCLE_APP_KEY',
    $configValue($secretConfig, 'app_key', 'ECOCYCLE_APP_KEY', '')
);
defined('EMAIL_FEATURES_ENABLED') || define('EMAIL_FEATURES_ENABLED', true);
defined('APP_BASE_URL') || define(
    'APP_BASE_URL',
    rtrim($configValue($secretConfig, 'app_base_url', 'ECOCYCLE_APP_BASE_URL', 'http://localhost/Project%20EchoCycle'), '/')
);
defined('MAIL_DRIVER') || define('MAIL_DRIVER', $configValue($secretConfig, 'mail_driver', 'ECOCYCLE_MAIL_DRIVER', 'log'));
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', $configValue($secretConfig, 'mail_from_address', 'ECOCYCLE_MAIL_FROM_ADDRESS', 'no-reply@ecocycle.local'));
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', $configValue($secretConfig, 'mail_from_name', 'ECOCYCLE_MAIL_FROM_NAME', 'EcoCycle'));
defined('MAIL_SMTP_HOST') || define('MAIL_SMTP_HOST', $configValue($secretConfig, 'mail_smtp_host', 'ECOCYCLE_MAIL_SMTP_HOST', ''));
defined('MAIL_SMTP_PORT') || define('MAIL_SMTP_PORT', $configValue($secretConfig, 'mail_smtp_port', 'ECOCYCLE_MAIL_SMTP_PORT', '587'));
defined('MAIL_SMTP_USERNAME') || define('MAIL_SMTP_USERNAME', $configValue($secretConfig, 'mail_smtp_username', 'ECOCYCLE_MAIL_SMTP_USERNAME', ''));
defined('MAIL_SMTP_PASSWORD') || define('MAIL_SMTP_PASSWORD', $configValue($secretConfig, 'mail_smtp_password', 'ECOCYCLE_MAIL_SMTP_PASSWORD', ''));
defined('MAIL_SMTP_ENCRYPTION') || define('MAIL_SMTP_ENCRYPTION', $configValue($secretConfig, 'mail_smtp_encryption', 'ECOCYCLE_MAIL_SMTP_ENCRYPTION', 'tls'));
defined('MAIL_SMTP_AUTH') || define('MAIL_SMTP_AUTH', $configValue($secretConfig, 'mail_smtp_auth', 'ECOCYCLE_MAIL_SMTP_AUTH', 'true') === 'true');

// ---- App settings ----
const APP_NAME = 'EcoCycle';
const APP_TAGLINE = 'Turn everyday recycling into a rewarding community habit.';
const APP_TIMEZONE = 'Asia/Manila';
const WALLET_MIN_WITHDRAWAL = 500;
const WALLET_POINTS_PER_PESO = 10;
const WALLET_DAILY_WITHDRAWAL_CAP = 5000;
const WALLET_WITHDRAWAL_EXPIRY_MINUTES = 10;
const PIN_RESET_COOLDOWN_HOURS = 24;
const WALLET_CHECKOUT_ENABLED = true;
const RECYCLING_DAILY_POINT_CAP = 3000;
const RECYCLING_MAX_KG_PER_ENTRY = 100;

date_default_timezone_set(APP_TIMEZONE);

function hashPassword(string $password): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if ($hash === false) {
        throw new RuntimeException('Password hashing failed.');
    }
    return $hash;
}

function passwordPolicyError(string $password): ?string
{
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length < 8 || $length > 64
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)) {
        return 'Use 8–64 characters, including uppercase and lowercase letters and a number.';
    }

    if (!defined('PASSWORD_ARGON2ID') && strlen($password) > 72) {
        return 'This password is too long for the current password-hashing configuration.';
    }

    $commonPasswords = [
        'password123', 'password1', 'password', '12345678', '123456789',
        'qwerty123', 'letmein123', 'welcome123', 'iloveyou1', 'admin123',
        'ecocycle1', 'abc12345', 'qwertyuiop1', 'p@ssw0rd1',
    ];
    if (in_array(strtolower($password), $commonPasswords, true)) {
        return 'Choose a less common password.';
    }

    if (passwordHasBeenPwned($password)) {
        return 'This password has appeared in a data breach. Choose a different password.';
    }
    return null;
}

function passwordHasBeenPwned(string $password): bool
{
    $sha1 = strtoupper(sha1($password));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);
    $url = 'https://api.pwnedpasswords.com/range/' . $prefix;
    $response = false;

    try {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                return false;
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Add-Padding: true'],
                CURLOPT_USERAGENT => 'EcoCycle password safety check',
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if (is_string($body) && $status === 200) {
                $response = $body;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 4,
                    'ignore_errors' => true,
                    'header' => "Add-Padding: true\r\nUser-Agent: EcoCycle password safety check\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            if (is_string($body) && preg_match('/\s200\s/', $statusLine)) {
                $response = $body;
            }
        }
    } catch (Throwable $ex) {
        $response = false;
    }

    if (!is_string($response)) {
        error_log('Have I Been Pwned password check unavailable; allowing password.');
        return false;
    }

    foreach (preg_split('/\r\n|\r|\n/', $response) as $line) {
        $parts = explode(':', trim($line), 2);
        if (count($parts) === 2 && hash_equals(strtoupper($parts[0]), $suffix)) {
            return true;
        }
    }
    return false;
}

/**
 * Return a shared PDO connection.
 *
 * @param bool $withDatabase  When false, connect to the server only (used by setup).
 */
function db(bool $withDatabase = true): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO && $withDatabase) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    if ($withDatabase) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $connection = new PDO($dsn, DB_USER, DB_PASS, $options);
    $connection->exec('SET time_zone = ' . $connection->quote(date('P')));
    if ($withDatabase) {
        $pdo = $connection;
    }
    return $connection;
}
