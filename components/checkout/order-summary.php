<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Order summary card for the chosen plan. Needs $ctx['order'], $ctx['look'], $ctx['per_day'].
 * Settings: name, best_value, compounded, as_low_as, per_day, perks[], perks_image, plan_line, plan_line_single,
 *           bonus_line, fees[{label, value}], warranty_line, warranty_status, total_label, total_per_day, free_label.
 */
$order  = $ctx['order'];
$look   = $ctx['look'];
$perDay = money($ctx['per_day']);
$bonus  = $site['bonus'] ?? null;
$free   = $s['free_label'] ?? 'FREE';
$vars   = ['product' => $look['title'], 'months' => $order['months']];
?>
<div class="<?= cls('summary-card', $s) ?>">
    <div class="summary-product">
        <img src="<?= asset($look['image']) ?>" alt="">
        <div>
            <p class="summary-name"><?= e(fill($s['name'] ?? '{product}', $vars)) ?></p>
            <?php if ($order['months'] > 1 && !empty($s['best_value'])): ?><span class="chip chip-green"><?= e($s['best_value']) ?></span><?php endif; ?>
            <p class="co-muted small"><?= e($s['compounded'] ?? '') ?></p>
        </div>
        <p class="summary-day"><small><?= e($s['as_low_as'] ?? '') ?></small><strong><?= $perDay ?></strong><?= e($s['per_day'] ?? '') ?></p>
    </div>
    <?php if (!empty($s['perks'])): ?>
        <div class="summary-perks">
            <ul><?php foreach ($s['perks'] as $perk): ?><li><?= icon('check-circle') ?><?= e($perk) ?></li><?php endforeach; ?></ul>
            <?php if (!empty($s['perks_image'])): ?><img src="<?= asset($s['perks_image']) ?>" alt="" aria-hidden="true"><?php endif; ?>
        </div>
    <?php endif; ?>
    <dl class="summary-lines">
        <div><dt><?= e($order['months'] > 1 ? fill($s['plan_line'] ?? '', $vars) : ($s['plan_line_single'] ?? '')) ?></dt><dd><?= money($order['price'], false) ?></dd></div>
        <?php if ($bonus && !empty($s['bonus_line'])): ?><div><dt><?= e(fill($s['bonus_line'], ['bonus' => $bonus['name']])) ?></dt><dd><s><?= money($bonus['value'], false) ?></s> <b><?= e($free) ?></b></dd></div><?php endif; ?>
        <?php foreach ($s['fees'] ?? [] as $fee): ?><div><dt><?= e($fee['label']) ?></dt><dd><s><?= money($fee['value'], false) ?></s> <b><?= e($free) ?></b></dd></div><?php endforeach; ?>
        <?php if (!empty($s['warranty_line'])): ?><div><dt><?= e($s['warranty_line']) ?></dt><dd><b><?= e($s['warranty_status'] ?? '') ?></b></dd></div><?php endif; ?>
    </dl>
    <div class="summary-total"><p><?= e($s['total_label'] ?? '') ?></p><p><strong><?= money($order['price'], false) ?></strong><small><?= e(fill($s['total_per_day'] ?? '', ['price' => $perDay])) ?></small></p></div>
</div>
