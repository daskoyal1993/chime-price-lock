<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * "Your plan, all in" list with icons (items from site.json → included).
 * Settings: title, free_label.
 */
?>
<div class="<?= cls('allin-list', $s) ?>">
    <p class="allin-list-head"><span><?= e($s['title'] ?? '') ?></span><?= icon('gift') ?></p>
    <ul>
        <?php foreach ($site['included'] ?? [] as $inc): ?>
            <li><img src="<?= asset($inc['icon']) ?>" alt="" loading="lazy"><span><?= e($inc['text']) ?></span><span class="price"><s><?= money($inc['value'], false) ?></s><b><?= e($s['free_label'] ?? 'FREE') ?></b></span></li>
        <?php endforeach; ?>
    </ul>
</div>
