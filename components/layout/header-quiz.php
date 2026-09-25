<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Quiz header: back arrow, logo, rating.
 * Settings: rating_label (site.json → header).
 */
$b = $site['brand'];
?>
<header class="<?= cls('quiz-header', $s) ?>">
    <button type="button" class="quiz-back" id="funnel-back" aria-label="Go back" hidden><?= icon('arrow-left') ?></button>
    <img class="quiz-logo" src="<?= asset($b['logo']) ?>" alt="<?= e($b['name']) ?>">
    <div class="quiz-rating">
        <span><span class="light"><?= e($s['rating_label'] ?? 'Excellent') ?></span> <strong><?= e($b['rating']) ?></strong></span>
        <?= trustpilot_stars((float) $b['rating']) ?>
    </div>
</header>
