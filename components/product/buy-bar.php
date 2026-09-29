<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Sticky "Buy Now" bar, filled in by product.js. Settings: button.
 * Standard classes: .buy-bar  .buy-bar-image  .buy-bar-name  .buy-bar-plan  .buy-bar-cta
 */
?>
<div class="<?= cls('buy-bar', $s) ?>" hidden>
    <div class="buy-bar-inner">
        <img src="" alt="" class="buy-bar-image">
        <p><strong class="buy-bar-name"></strong><span class="buy-bar-plan"></span></p>
        <button type="button" class="btn-dark buy-bar-cta"><?= e($s['button'] ?? '') ?></button>
    </div>
</div>
