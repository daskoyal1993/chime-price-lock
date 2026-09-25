<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Checkout sidebar: breadcrumbs, heading, then its own "sections" list (summary, delivery, included, reviews…).
 * Settings: crumbs{back, current}, title, sections[].
 */
$cr = $s['crumbs'] ?? [];
?>
<aside class="<?= cls('co-side', $s) ?>">
    <nav class="co-crumbs" aria-label="Checkout steps"><a href="<?= e(with_query($ctx['product_url'])) ?>"><?= e($cr['back'] ?? '') ?></a> <span aria-hidden="true">›</span> <strong><?= e($cr['current'] ?? '') ?></strong></nav>
    <h2 class="co-h"><?= e($s['title'] ?? '') ?></h2>
    <?php Page::sections($s['sections'] ?? [], $ctx); ?>
</aside>
