<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Image card with an "Includes" check list.
 * Settings: title_html, image, image_alt, list_title, points[], stat, footnote.
 */
?>
<section class="<?= cls('pp-allin', $s) ?>">
    <div class="wrap narrow">
        <h2 class="section-title"><?= $s['title_html'] ?? '' ?></h2>
        <div class="allin-card">
            <img src="<?= asset($s['image'] ?? '') ?>" alt="<?= e($s['image_alt'] ?? '') ?>" loading="lazy">
            <p class="allin-head"><?= e($s['list_title'] ?? '') ?></p>
            <ul><?php foreach ($s['points'] ?? [] as $pt): ?><li><?= icon('check-circle') ?><?= e($pt) ?></li><?php endforeach; ?></ul>
            <?php if (!empty($s['stat'])): ?><p class="allin-stat"><?= icon('check-circle') ?><?= e($s['stat']) ?></p><?php endif; ?>
            <?php if (!empty($s['footnote'])): ?><p class="footnote"><?= e($s['footnote']) ?></p><?php endif; ?>
        </div>
    </div>
</section>
