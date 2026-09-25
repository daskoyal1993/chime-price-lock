<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Product / checkout header: logo, optional phone, secure-payment badge.
 * Settings: show_phone (bool), phone_label_html, secure_html (defaults in site.json → header).
 */
$b = $site['brand'];
?>
<header class="<?= cls('shop-header', $s) ?>">
    <div class="shop-header-inner">
        <a href="<?= e(with_query('index.php')) ?>" class="shop-logo"><img src="<?= asset($b['logo_alt']) ?>" alt="<?= e($b['name']) ?>"></a>
        <div class="shop-header-right">
            <?php if (!empty($s['show_phone'])): ?>
                <p class="shop-phone"><?= $s['phone_label_html'] ?? '' ?><br><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $b['phone'])) ?>"><?= e($b['phone']) ?></a></p>
            <?php endif; ?>
            <span class="secure-badge"><?= icon('lock') ?><span><?= $s['secure_html'] ?? '' ?></span></span>
        </div>
    </div>
</header>
