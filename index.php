<?php
/**
 * EcoCycle — public landing page.
 * Mission, SDG 12 alignment, live community impact stats, and sign-up funnel.
 */
require_once __DIR__ . '/includes/functions.php';

// Community-wide impact totals (public, aggregated).
$totals = db()->query(
    'SELECT
        COALESCE(SUM(weight_kg), 0)    AS total_kg,
        COALESCE(SUM(co2_saved_kg), 0) AS total_co2,
        COUNT(*)                       AS total_logs
     FROM recycling_logs'
)->fetch();

$memberCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$trees       = treesEquivalent((float) $totals['total_co2']);

$pageTitle = 'Recycle. Earn. Grow your community.';
require __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="eco-hero">
    <div class="max-w-6xl mx-auto px-4 py-16 lg:py-24 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <span class="eco-kicker">
                🌍 Supporting UN SDG 12
            </span>
            <h1 class="mt-4 text-4xl sm:text-5xl font-extrabold leading-tight text-slate-900">
                Turn everyday recycling into a <span class="text-eco-600">rewarding community habit</span>.
            </h1>
            <p class="mt-5 text-lg max-w-xl text-slate-600">
                Log what you recycle, earn points for every kilogram diverted from landfill,
                and redeem them for real local rewards — while your neighborhood watches its
                collective impact grow.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <?php if (isLoggedIn()): ?>
                    <a href="dashboard.php" class="px-6 py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition shadow-lg shadow-eco-600/20">Go to my dashboard</a>
                    <a href="log.php" class="px-6 py-3 rounded-xl bg-white text-eco-700 font-bold border border-eco-200 hover:bg-eco-50 transition">Log recycling</a>
                <?php else: ?>
                    <a href="register.php" class="px-6 py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition shadow-lg shadow-eco-600/20">Start recycling free</a>
                    <a href="#how" class="px-6 py-3 rounded-xl bg-white text-eco-700 font-bold border border-eco-200 hover:bg-eco-50 transition">See how it works</a>
                <?php endif; ?>
            </div>
            <p class="mt-4 text-sm text-slate-500">Free forever · <?= num($memberCount) ?> neighbors already recycling</p>
        </div>

        <!-- Live impact card -->
        <div class="eco-panel p-6 sm:p-8">
            <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-eco-700 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-eco-500 animate-pulse" aria-hidden="true"></span>
                Live community impact
            </h2>
            <div class="mt-6 grid grid-cols-2 gap-4">
                <div class="eco-metric-card p-5 text-center">
                    <div class="text-3xl font-extrabold text-eco-700"><?= num($totals['total_kg'], 1) ?></div>
                    <div class="text-xs font-semibold text-slate-500 mt-1">kg diverted from landfill</div>
                </div>
                <div class="eco-metric-card p-5 text-center">
                    <div class="text-3xl font-extrabold text-eco-700"><?= num($totals['total_co2'], 1) ?></div>
                    <div class="text-xs font-semibold text-slate-500 mt-1">kg CO₂ saved</div>
                </div>
                <div class="eco-metric-card p-5 text-center">
                    <div class="text-3xl font-extrabold text-eco-700"><?= num($trees, 1) ?></div>
                    <div class="text-xs font-semibold text-slate-500 mt-1">tree-years equivalent</div>
                </div>
                <div class="eco-metric-card p-5 text-center">
                    <div class="text-3xl font-extrabold text-eco-700"><?= num($totals['total_logs']) ?></div>
                    <div class="text-xs font-semibold text-slate-500 mt-1">items recycled &amp; logged</div>
                </div>
            </div>
            <p class="mt-5 text-xs text-slate-400 text-center">Updated in real time as neighbors log their recycling.</p>
        </div>
    </div>
</section>

<!-- Problem / why it matters -->
<section class="max-w-6xl mx-auto px-4 py-16">
    <div class="text-center max-w-2xl mx-auto">
        <h2 class="text-3xl font-extrabold text-slate-900">Recycling infrastructure exists. Participation doesn't.</h2>
        <p class="mt-4 text-slate-600">Most people want to recycle, but effort feels invisible, incentives are missing, and sorting rules are confusing. EcoCycle closes that gap.</p>
    </div>
    <div class="mt-10 grid gap-6 md:grid-cols-3">
        <?php
        $problems = [
            ['👀', 'Effort feels invisible', 'People never see the impact of what they recycle, so motivation fades.'],
            ['🎁', 'No immediate incentive', 'Beyond civic duty, there is little reason to keep the habit going.'],
            ['❓', 'Sorting is confusing', 'Households are unsure what can be recycled and how to do it correctly.'],
        ];
        foreach ($problems as [$icon, $title, $body]): ?>
            <div class="eco-card bg-white rounded-2xl border border-slate-100 p-6">
                <div class="text-3xl" aria-hidden="true"><?= e($icon) ?></div>
                <h3 class="mt-3 font-bold text-slate-900"><?= e($title) ?></h3>
                <p class="mt-2 text-sm text-slate-600"><?= e($body) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- How it works -->
<section id="how" class="bg-[#0b1f1a] border-y border-emerald-500/20">
    <div class="max-w-6xl mx-auto px-4 py-16">
        <div class="text-center max-w-3xl mx-auto">
            <h2 class="text-4xl sm:text-5xl font-extrabold text-white tracking-tight">How EcoCycle works</h2>
            <p class="mt-4 text-lg text-emerald-100/90">Four simple steps from recycling to real rewards.</p>
        </div>

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4 items-stretch">
            <?php
            $steps = [
                ['1', '📝', 'Log your recycling', 'Pick a material, estimate the weight, and add an optional note.'],
                ['2', '🏦', 'EcoWallet', 'Earned points are stored in your personal EcoWallet for secure tracking, withdrawals, and spending.'],
                ['3', '🎁', 'Redeem rewards', 'Spend points on local discounts, eco-products, or green donations.'],
                ['4', '📈', 'Watch impact grow', 'Track CO₂ saved and climb the community leaderboard.'],
            ];
            foreach ($steps as [$n, $icon, $title, $body]): ?>
                <div class="relative rounded-2xl border border-emerald-400/30 bg-[#123128] p-6 shadow-[0_14px_28px_rgba(7,23,18,0.38)]">
                    <span class="absolute -top-4 left-1/2 -translate-x-1/2 w-12 h-12 rounded-full bg-[#7be7b4] text-[#0b1f1a] font-extrabold text-2xl grid place-items-center shadow-lg shadow-emerald-400/30"><?= e($n) ?></span>
                    <div class="mt-10 flex justify-center text-4xl" aria-hidden="true"><?= e($icon) ?></div>
                    <h3 class="mt-6 text-center text-2xl font-bold text-white"><?= e($title) ?></h3>
                    <p class="mt-3 text-center text-sm leading-relaxed text-emerald-50/80"><?= e($body) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- SDG 12 alignment -->
<section class="max-w-6xl mx-auto px-4 py-16">
    <div class="bg-gradient-to-br from-eco-700 to-eco-900 rounded-3xl p-8 sm:p-12 text-white">
        <div class="grid lg:grid-cols-2 gap-10 items-center">
            <div>
                <span class="inline-block bg-white/15 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">UN Sustainable Development Goal 12</span>
                <h2 class="mt-4 text-3xl font-extrabold">Responsible Consumption &amp; Production</h2>
                <p class="mt-4 text-eco-100">EcoCycle is built in direct support of SDG 12, bridging the gap between individual action and measurable, community-wide environmental impact.</p>
            </div>
            <div class="space-y-4">
                <div class="bg-white/10 rounded-2xl p-5">
                    <div class="font-extrabold text-white">Target 12.5</div>
                    <p class="text-sm text-eco-100 mt-1">Substantially reduce waste generation through prevention, reduction, recycling, and reuse.</p>
                </div>
                <div class="bg-white/10 rounded-2xl p-5">
                    <div class="font-extrabold text-white">Target 12.8</div>
                    <p class="text-sm text-eco-100 mt-1">Ensure people everywhere have relevant information and awareness for sustainable lifestyles in harmony with nature.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<?php if (!isLoggedIn()): ?>
<section class="max-w-3xl mx-auto px-4 pb-8 text-center">
    <h2 class="text-3xl font-extrabold text-slate-900">Ready to make your recycling count?</h2>
    <p class="mt-3 text-slate-600">Join your neighbors and start earning rewards for a habit you already have.</p>
    <a href="register.php" class="mt-6 inline-block px-8 py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition shadow-lg shadow-eco-600/20">Create your free account</a>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
