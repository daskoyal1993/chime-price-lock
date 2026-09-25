<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Order confirmation card. Needs $ctx['last'] (order, product, first_name) and $ctx['look'].
 * Settings: badge, title ({name_part}), name_part (", {name}"), text_html ({email}), product_name, order_label, steps_html[].
 */
$last = $ctx['last'];
$look = $ctx['look'];
$name = $last['first_name'] !== '' ? fill($s['name_part'] ?? '', ['name' => $last['first_name']]) : '';
?>
<div class="<?= cls('ty-card', $s) ?>">
    <span class="quiz-badge quiz-badge-success"><?= icon('check') ?><?= e($s['badge'] ?? '') ?></span>
    <h1 class="quiz-title"><?= e(fill($s['title'] ?? '', ['name_part' => $name])) ?></h1>
    <p class="quiz-helper"><?= fill($s['text_html'] ?? '', ['email' => $last['order']['email']], true) ?></p>
    <div class="summary-product ty-product">
        <img src="<?= asset($look['image']) ?>" alt="">
        <div><p class="summary-name"><?= e(fill($s['product_name'] ?? '{product}', ['product' => $look['title'], 'months' => $last['product']['months']])) ?></p><p class="co-muted small"><?= e(fill($s['order_label'] ?? '', ['order_id' => $last['order']['order_id']])) ?></p></div>
        <p class="summary-day"><strong><?= money($last['order']['total'], false) ?></strong></p>
    </div>
    <?php if (!empty($s['steps_html'])): ?>
        <ol class="ty-steps">
            <?php foreach ($s['steps_html'] as $step): ?><li><?= $step ?></li><?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
