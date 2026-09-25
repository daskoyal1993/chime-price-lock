<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Sticky "Buy Now" bar, filled in by product.js. Settings: button.
 */
?>
<div class="<?= cls('buy-bar', $s) ?>" id="buy-bar" hidden>
    <div class="buy-bar-inner">
        <img src="" alt="" id="buy-bar-img">
        <p><strong id="buy-bar-name"></strong><span id="buy-bar-plan"></span></p>
        <button type="button" class="btn-dark" id="buy-bar-btn"><?= e($s['button'] ?? '') ?></button>
    </div>
</div>
