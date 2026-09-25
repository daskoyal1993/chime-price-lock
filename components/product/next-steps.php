<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Numbered steps with an image and check points.
 * Settings: title_html, steps[{title, image, points[]}].
 */
?>
<section class="<?= cls('pp-steps', $s) ?>">
    <div class="wrap narrow">
        <h2 class="section-title"><?= $s['title_html'] ?? '' ?></h2>
        <ol class="steps-list">
            <?php foreach ($s['steps'] ?? [] as $i => $step): ?>
                <li><span class="step-num"><?= $i + 1 ?></span><img src="<?= asset($step['image']) ?>" alt="" loading="lazy">
                    <div><h3><?= e($step['title']) ?></h3><?php foreach ($step['points'] as $pt): ?><p><?= icon('check-circle') ?><?= e($pt) ?></p><?php endforeach; ?></div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
