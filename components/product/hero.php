<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Product hero: "Today / With …" figures from the visitor's answers, stats, and the treatment picker.
 * Needs $ctx['answers'] and $ctx['products'] (sellable_products()).
 * Treatment picker: .product-picker-item[data-product] (= the product card's data-product) — used by product.js.
 * Settings: title_plain, title_brand, subtitle_html, bonus_pill_html, figures{female,male}{start,end}, labels{…},
 *           projected_loss_pct, stats[], treatments_title, price_from_html, footnote.
 */
$a        = $ctx['answers'];
$bonus    = $site['bonus'] ?? null;
$labels   = $s['labels'] ?? [];
$sex      = strtolower((string) ($a['gender'] ?? '')) === 'male' ? 'male' : 'female';
$figures  = $s['figures'][$sex] ?? [];
$weight   = isset($a['weight']) ? (float) $a['weight'] : null;
$bmi      = $a['bmi'] ?? null;
$ratio    = 1 - ((float) ($s['projected_loss_pct'] ?? 0) / 100);
$unit     = $labels['weight_unit'] ?? ' Lbs';
$treats   = $ctx['products'];
usort($treats, static function ($x, $y) {
    return (int) $y['look']['featured'] <=> (int) $x['look']['featured'];
});
?>
<section class="<?= cls('pp-hero', $s) ?>">
    <div class="wrap">
        <h1 class="pp-hero-title"><?= e($s['title_plain'] ?? '') ?> <span class="brand"><?= e($s['title_brand'] ?? '') ?></span></h1>
        <p class="pp-hero-sub"><?= $s['subtitle_html'] ?? '' ?></p>
        <?php if ($bonus && !empty($s['bonus_pill_html'])): ?>
            <p class="bonus-pill"><?= fill($s['bonus_pill_html'], ['bonus' => $bonus['name'], 'value' => money($bonus['value'], false), 'line' => $bonus['line']], true) ?></p>
        <?php endif; ?>

        <div class="pp-hero-grid">
            <div class="report">
                <div class="report-figures" aria-hidden="true">
                    <figure><img src="<?= asset($figures['start'] ?? '') ?>" alt=""><figcaption class="tag tag-gray"><?= e($labels['today'] ?? '') ?></figcaption></figure>
                    <span class="report-arrows"></span>
                    <figure><img src="<?= asset($figures['end'] ?? '') ?>" alt=""><figcaption class="tag tag-brand"><?= e($labels['after'] ?? '') ?></figcaption></figure>
                </div>
                <dl class="report-table">
                    <div><dt><?= e($labels['weight'] ?? '') ?></dt><dd class="red"><?= $weight ? e(round($weight)) . e($unit) : '—' ?></dd></div>
                    <div><dt><?= e($labels['weight'] ?? '') ?></dt><dd class="blue"><?= $weight ? e(round($weight * $ratio)) . e($unit) : '—' ?></dd></div>
                    <div><dt><?= e($labels['bmi'] ?? '') ?></dt><dd class="orange"><?= $bmi ? e(number_format($bmi, 1)) : '—' ?></dd></div>
                    <div><dt><?= e($labels['bmi'] ?? '') ?></dt><dd class="blue"><?= $bmi ? e(number_format($bmi * $ratio, 1)) : '—' ?></dd></div>
                    <div><dt><?= e($labels['cravings'] ?? '') ?></dt><dd class="red small"><?= e($labels['cravings_before'] ?? '') ?></dd></div>
                    <div><dt><?= e($labels['cravings'] ?? '') ?></dt><dd class="blue small"><?= e($labels['cravings_after'] ?? '') ?></dd></div>
                </dl>
            </div>

            <div class="pp-hero-side">
                <?php if (!empty($s['stats'])): ?>
                    <div class="stats-card">
                        <?php foreach ($s['stats'] as $st): ?>
                            <div class="stat"><p class="stat-value"><?= e($st['value']) ?><small><?= e($st['unit']) ?></small></p><p><?= e($st['text']) ?></p></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h2 class="treat-title"><?= e($s['treatments_title'] ?? '') ?></h2>
                <div class="treat-list">
                    <?php foreach ($treats as $item): $look = $item['look']; ?>
                        <button type="button" class="treat-card product-picker-item" data-product="<?= e($look['keyword']) ?>" aria-pressed="false">
                            <img src="<?= asset($look['image']) ?>" alt="">
                            <span class="treat-body">
                                <span class="treat-name"><?= e($look['title']) ?></span>
                                <span class="treat-tagline"><?= e($look['tagline']) ?></span>
                                <span class="treat-meta">
                                    <?php if ($look['badge']): ?><span class="chip chip-green"><?= e($look['badge']) ?></span><?php endif; ?>
                                    <span><?= fill($s['price_from_html'] ?? '{price}', ['price' => money(lowest_monthly($item['variants']), false)], true) ?></span>
                                </span>
                            </span>
                            <span class="radio-dot" aria-hidden="true"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php if (!empty($s['footnote'])): ?><p class="footnote"><?= e($s['footnote']) ?></p><?php endif; ?>
    </div>
</section>
