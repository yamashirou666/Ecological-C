</main>

<footer class="mt-16 bg-eco-900 text-eco-100">
    <div class="max-w-6xl mx-auto px-4 py-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-2 font-extrabold text-lg text-white">
                <span class="text-2xl" aria-hidden="true">♻️</span> <?= e(APP_NAME) ?>
            </div>
            <p class="mt-3 text-sm text-eco-200"><?= e(APP_TAGLINE) ?></p>
        </div>
        <div>
            <h3 class="font-bold text-white mb-3 text-sm uppercase tracking-wide">Explore</h3>
            <ul class="space-y-2 text-sm text-eco-200">
                <li><a href="index.php" class="hover:text-white">Home</a></li>
                <li><a href="leaderboard.php" class="hover:text-white">Leaderboard</a></li>
                <li><a href="learn.php" class="hover:text-white">Learn to recycle</a></li>
                <li><a href="rewards.php" class="hover:text-white">Rewards</a></li>
            </ul>
        </div>
        <div>
            <h3 class="font-bold text-white mb-3 text-sm uppercase tracking-wide">Our Mission</h3>
            <p class="text-sm text-eco-200">Advancing UN Sustainable Development Goal 12 — Responsible Consumption &amp; Production — one recycled item at a time.</p>
        </div>
        <div>
            <h3 class="font-bold text-white mb-3 text-sm uppercase tracking-wide">SDG 12 Targets</h3>
            <ul class="space-y-2 text-sm text-eco-200">
                <li><span class="font-semibold text-white">12.5</span> — Reduce waste through recycling &amp; reuse</li>
                <li><span class="font-semibold text-white">12.8</span> — Awareness for sustainable lifestyles</li>
            </ul>
        </div>
    </div>
    <div class="border-t border-eco-800">
        <div class="max-w-6xl mx-auto px-4 py-4 text-xs text-eco-300 flex flex-col sm:flex-row justify-between gap-2">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Built for a greener community.</span>
            <span>Impact figures are educational estimates.</span>
        </div>
    </div>
</footer>

<script>
    // Mobile nav toggle
    (function () {
        const toggle = document.getElementById('navToggle');
        const menu = document.getElementById('navMenu');
        if (toggle && menu) {
            toggle.addEventListener('click', function () {
                const open = menu.classList.toggle('hidden');
                toggle.setAttribute('aria-expanded', String(!open));
            });
        }
    })();
</script>
</body>
</html>
