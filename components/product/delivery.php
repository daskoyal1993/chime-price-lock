<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Delivery banner + estimate (carrier and estimate from site.json → delivery) + disclaimer.
 * Settings: title_html, image, image_alt, disclaimer_html.
 */
?>
<section class="<?= cls('pp-delivery', $s) ?>">
    <div class="wrap narrow">
        <h2 class="section-title"><?= $s['title_html'] ?? '' ?></h2>
        <img class="delivery-img" src="<?= asset($s['image'] ?? '') ?>" alt="<?= e($s['image_alt'] ?? '') ?>" loading="lazy">
        <?php Page::component(['component' => 'common/delivery-estimate'], $ctx); ?>
        <?php if (!empty($s['disclaimer_html'])): ?><p class="footnote left"><?= $s['disclaimer_html'] ?></p><?php endif; ?>
    </div>
</section>
