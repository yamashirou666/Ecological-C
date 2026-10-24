<?php
/**
 * Shared page header: <head>, Tailwind config, and top navigation.
 * Expects an optional $pageTitle variable to be set before inclusion.
 */
require_once __DIR__ . '/functions.php';

$user   = currentUser();
$title  = isset($pageTitle) ? $pageTitle . ' · ' . APP_NAME : APP_NAME;
$active = basename($_SERVER['PHP_SELF']);
$themePreference = $user && isset($user['theme_preference']) ? sanitizeThemePreference((string) $user['theme_preference']) : 'system';

/** Nav link helper with active-state styling. */
function navLink(string $href, string $label, string $active): string
{
    $isActive = $active === $href;
    $classes  = $isActive
        ? 'nav-link nav-link-active'
        : 'nav-link';
    return '<a href="' . e($href) . '" class="' . e($classes) . '">' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth" data-theme="<?= e($themePreference) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="EcoCycle — a community recycling rewards platform advancing UN SDG 12.">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/tailwind.css') ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>♻️</text></svg>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script>
        window.ECO_THEME_CSRF = <?= json_encode(csrfToken()) ?>;
        (function() {
            const root = document.documentElement;
            const storageKey = 'ecocycle-theme';
            const preferredTheme = localStorage.getItem(storageKey) || 'system';
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const selectedTheme = preferredTheme === 'system' ? (prefersDark ? 'dark' : 'light') : preferredTheme;
            root.setAttribute('data-theme', selectedTheme);
            root.classList.toggle('dark', selectedTheme === 'dark');
        })();
    </script>
</head>
<body class="min-h-screen bg-eco-50 text-slate-800 font-sans antialiased flex flex-col" data-user-logged-in="<?= e($user ? '1' : '0') ?>">

<!-- Skip link for accessibility -->
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-white focus:px-4 focus:py-2 focus:rounded-lg focus:shadow">Skip to content</a>

<header class="sticky top-0 z-40 pt-4">
    <nav class="nav-shell max-w-6xl mx-auto px-3 md:px-4 h-16 flex items-center justify-between" aria-label="Main navigation">
        <a href="index.php" class="nav-brand flex items-center gap-2 font-extrabold text-lg">
            <span class="brand-mark" aria-hidden="true">♻️</span>
            <span><?= e(APP_NAME) ?></span>
        </a>

        <button id="navToggle" class="md:hidden p-2 rounded-lg text-slate-600 hover:bg-white/10" aria-label="Toggle navigation" aria-expanded="false">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <div id="navMenu" class="hidden md:flex items-center gap-1 absolute md:static top-20 left-0 right-0 bg-transparent md:bg-transparent border-b md:border-0 border-eco-100 px-4 md:px-0 py-3 md:py-0 flex-col md:flex-row shadow md:shadow-none">
            <?php if ($user): ?>
                <?= navLink('dashboard.php', 'Dashboard', $active) ?>
                <?= navLink('wallet.php', 'EcoWallet 🏦', $active) ?>
                <?= navLink('log.php', 'Log Recycling', $active) ?>
                <?= navLink('rewards.php', 'Rewards', $active) ?>
                <?= navLink('leaderboard.php', 'Leaderboard', $active) ?>
                <?= navLink('learn.php', 'Learn', $active) ?>
                <?php if (isAdmin()): ?>
                    <?= navLink('admin.php', 'Admin', $active) ?>
                <?php endif; ?>
                <div class="nav-user-area flex items-center gap-3 md:ml-3 mt-2 md:mt-0 md:pl-3">
                    <button id="themeToggle" type="button" class="theme-switch" aria-label="Toggle light and dark mode" title="Toggle theme">
                        <span class="theme-icon" aria-hidden="true">☀️</span>
                    </button>
                    <a href="profile.php" class="nav-profile flex items-center gap-2">
                        <span class="w-8 h-8 rounded-full bg-eco-100 text-eco-700 grid place-items-center font-bold" aria-hidden="true"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                        <span class="font-semibold"><?= num($user['points_balance']) ?> pts</span>
                    </a>
                    <form method="post" action="logout.php" class="nav-logout-form">
                        <?= csrfField() ?>
                        <button type="submit" class="nav-logout">Log out</button>
                    </form>
                </div>
            <?php else: ?>
                <?= navLink('index.php', 'Home', $active) ?>
                <?= navLink('leaderboard.php', 'Leaderboard', $active) ?>
                <?= navLink('learn.php', 'Learn', $active) ?>
                <button id="themeToggle" type="button" class="theme-switch ml-2" aria-label="Toggle light and dark mode" title="Toggle theme">
                    <span class="theme-icon" aria-hidden="true">☀️</span>
                </button>
                <a href="login.php" class="nav-login">Log in</a>
                <a href="register.php" class="nav-signup">Sign up</a>
            <?php endif; ?>
        </div>
    </nav>
</header>
<script>
    (function() {
        const root = document.documentElement;
        const storageKey = 'ecocycle-theme';
        const toggle = document.getElementById('themeToggle');
        const setTheme = (theme) => {
            const next = theme === 'dark' || theme === 'light' ? theme : 'system';
            const resolved = next === 'system'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : next;

            root.setAttribute('data-theme', resolved);
            root.classList.toggle('dark', resolved === 'dark');
            localStorage.setItem(storageKey, next);

            if (toggle) {
                const icon = toggle.querySelector('.theme-icon');
                if (icon) icon.textContent = resolved === 'dark' ? '🌙' : '☀️';
                toggle.setAttribute('aria-pressed', String(resolved === 'dark'));
            }

            if (window.ECO_THEME_CSRF && document.body.dataset.userLoggedIn === '1') {
                fetch('profile.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'theme_only=1&theme_preference=' + encodeURIComponent(next) + '&csrf=' + encodeURIComponent(window.ECO_THEME_CSRF)
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('The server rejected the theme preference update.');
                    }
                    return response.json();
                }).then(function(result) {
                    if (!result.ok) {
                        throw new Error('The server did not save the theme preference.');
                    }
                }).catch(function(error) {
                    console.error('Theme preference could not be saved to the account.', error);
                });
            }
        };

        const current = localStorage.getItem(storageKey) || 'system';
        setTheme(current);

        if (toggle) {
            toggle.addEventListener('click', function() {
                const resolvedTheme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                setTheme(resolvedTheme);
            });
        }
    })();
</script>

<main id="main" class="flex-1 w-full">
<?php foreach (takeFlashes() as $flash):
    $palette = [
        'success' => 'bg-eco-100 text-eco-800 border-eco-300',
        'error'   => 'bg-red-50 text-red-700 border-red-200',
        'info'    => 'bg-sky-50 text-sky-700 border-sky-200',
    ][$flash['type']] ?? 'bg-slate-50 text-slate-700 border-slate-200';
?>
    <div class="max-w-6xl mx-auto px-4 pt-4">
        <div class="border <?= e($palette) ?> rounded-xl px-4 py-3 text-sm font-semibold" role="status"><?= e($flash['message']) ?></div>
    </div>
<?php endforeach; ?>
