<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Scrolling member reviews.
 * Settings: kicker, title_html, rating_label, verified_label, date_label, items[{title, text, name, date, image}].
 */
$rating = $site['brand']['rating'];
?>
<div class="<?= cls('reviews', $s) ?>">
    <p class="reviews-kicker"><?= icon('star') ?> <?= e($s['kicker'] ?? '') ?></p>
    <h2><?= $s['title_html'] ?? '' ?></h2>
    <p class="reviews-rating"><?= trustpilot_stars((float) $rating, 18) ?> <?= e($s['rating_label'] ?? '') ?> <?= e($rating) ?></p>
    <div class="review-track" tabindex="0" aria-label="Member reviews">
        <?php foreach ($s['items'] ?? [] as $r): ?>
            <article class="review">
                <?= trustpilot_stars(5, 16) ?>
                <h3><?= e($r['title']) ?></h3>
                <p><?= e($r['text']) ?></p>
                <p class="review-by"><img src="<?= asset($r['image']) ?>" alt="" loading="lazy"><span><strong><?= e($r['name']) ?></strong><small><?= icon('check-circle') ?><?= e($s['verified_label'] ?? '') ?></small></span></p>
                <p class="review-date"><strong><?= e($s['date_label'] ?? '') ?></strong> <?= e($r['date']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</div>
