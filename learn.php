<?php
/**
 * EcoCycle — educational hub: sorting guides & contamination-reduction tips.
 */
require_once __DIR__ . '/includes/functions.php';

$catalogue = materials();

// Sorting guidance per material: what belongs, and what to avoid.
$guides = [
    'plastic' => [
        'yes' => ['Drink bottles &amp; caps', 'Detergent &amp; shampoo bottles', 'Clean food tubs'],
        'no'  => ['Plastic bags &amp; film', 'Polystyrene foam', 'Greasy takeaway containers'],
        'tip' => 'Rinse and squash bottles to save space. Leave caps on unless your local program asks otherwise.',
    ],
    'glass' => [
        'yes' => ['Bottles (any colour)', 'Food jars', 'Sauce &amp; condiment jars'],
        'no'  => ['Drinking glasses', 'Window &amp; mirror glass', 'Ceramics &amp; Pyrex'],
        'tip' => 'Rinse jars and remove lids. Broken window glass is a different material — never mix it in.',
    ],
    'paper' => [
        'yes' => ['Newspaper &amp; magazines', 'Cardboard boxes (flattened)', 'Office paper &amp; envelopes'],
        'no'  => ['Greasy pizza boxes', 'Waxed or laminated paper', 'Used tissues &amp; napkins'],
        'tip' => 'Keep paper dry and clean. Flatten cardboard so more fits in the bin.',
    ],
    'metal' => [
        'yes' => ['Aluminium drink cans', 'Steel food tins', 'Clean foil &amp; trays'],
        'no'  => ['Aerosol cans (if pressurised)', 'Paint or chemical tins', 'Electronics'],
        'tip' => 'Give cans a quick rinse. Scrunch foil into a ball at least the size of a golf ball so sorters catch it.',
    ],
    'ewaste' => [
        'yes' => ['Phones &amp; chargers', 'Cables &amp; small gadgets', 'Batteries (at drop-off points)'],
        'no'  => ['General household waste', 'Items with leaking batteries', 'Large appliances (book a pickup)'],
        'tip' => 'Never put e-waste in the kerbside bin. Wipe personal data and use a certified drop-off point.',
    ],
    'organic' => [
        'yes' => ['Fruit &amp; vegetable scraps', 'Coffee grounds &amp; tea', 'Garden trimmings'],
        'no'  => ['Meat &amp; dairy (unless allowed)', 'Plastic-lined "compostable" cups', 'Cooking oil'],
        'tip' => 'Composting organics keeps them out of landfill, where they would release methane.',
    ],
];

$myths = [
    ['All plastics are recyclable', 'Only certain resin types are accepted locally. Check the number inside the ♻️ symbol against your council\'s list.'],
    ['Items must be spotless', 'A quick rinse is enough — you don\'t need to scrub. But greasy or food-caked items can contaminate a whole batch.'],
    ['Shredded paper is fine loose', 'Loose shreds jam sorting machines. Bag them or add to home compost instead.'],
    ['Bottle caps must come off', 'For most modern programs you can leave plastic caps on. When in doubt, follow local rules.'],
];

$pageTitle = 'Learn to recycle';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-5xl mx-auto px-4 py-10">
    <div class="text-center max-w-2xl mx-auto">
        <span class="inline-block bg-eco-100 text-eco-800 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">SDG Target 12.8 · Awareness</span>
        <h1 class="mt-3 text-3xl font-extrabold text-slate-900">Recycle right, every time</h1>
        <p class="mt-3 text-slate-600">Correct sorting keeps materials in the loop and prevents contamination that sends whole batches to landfill. Here's a quick guide by material.</p>
    </div>

    <!-- Sorting guides -->
    <div class="mt-10 grid gap-6 md:grid-cols-2">
        <?php foreach ($guides as $key => $guide): $meta = $catalogue[$key]; ?>
            <article class="bg-white rounded-2xl shadow-sm border border-eco-100 p-6">
                <div class="flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-eco-50 grid place-items-center text-2xl" aria-hidden="true"><?= e($meta['icon']) ?></span>
                    <div>
                        <h2 class="font-extrabold text-slate-900"><?= e($meta['label']) ?></h2>
                        <p class="text-xs text-slate-400"><?= e($meta['points_per_kg']) ?> pts/kg · saves <?= num($meta['co2_per_kg'], 1) ?> kg CO₂/kg</p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="font-bold text-eco-700 mb-1">✅ Recycle</div>
                        <ul class="space-y-1 text-slate-600">
                            <?php foreach ($guide['yes'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <div>
                        <div class="font-bold text-red-500 mb-1">🚫 Keep out</div>
                        <ul class="space-y-1 text-slate-600">
                            <?php foreach ($guide['no'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <p class="mt-4 text-xs bg-eco-50 text-eco-800 rounded-lg px-3 py-2"><strong>Tip:</strong> <?= e($guide['tip']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Contamination myths -->
    <div class="mt-12">
        <h2 class="text-2xl font-extrabold text-slate-900 text-center">Bust the contamination myths</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <?php foreach ($myths as [$myth, $truth]): ?>
                <div class="bg-white rounded-2xl border border-eco-100 p-5">
                    <div class="text-sm font-bold text-slate-900">❌ Myth: <?= e($myth) ?></div>
                    <div class="mt-2 text-sm text-slate-600">✅ <?= e($truth) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Seasonal challenge callout -->
    <div class="mt-12 bg-gradient-to-br from-eco-600 to-eco-800 rounded-3xl p-8 text-white text-center">
        <div class="text-4xl" aria-hidden="true">🗓️</div>
        <h2 class="mt-3 text-2xl font-extrabold">This season: Plastic-Free July</h2>
        <p class="mt-2 text-eco-50 max-w-xl mx-auto">Challenge yourself to cut single-use plastics and log every plastic item you divert. Small swaps add up to a big community win.</p>
        <a href="<?= isLoggedIn() ? 'log.php' : 'register.php' ?>" class="mt-5 inline-block bg-white text-eco-700 font-bold px-6 py-3 rounded-xl hover:bg-eco-50 transition">
            <?= isLoggedIn() ? 'Log a plastic item' : 'Join the challenge' ?>
        </a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
