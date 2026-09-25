<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * "FedEx Est. Delivery: Within 48 Hours" line. Values from site.json → delivery (a section can override them).
 */
$d = array_merge($site['delivery'] ?? [], $s);
?>
<p class="<?= cls('delivery-est', $s) ?>"><img src="<?= asset($d['carrier'] ?? '') ?>" alt="<?= e($d['carrier_alt'] ?? '') ?>"> <?= e($d['label'] ?? '') ?> <b><?= e($d['estimate'] ?? '') ?></b></p>
