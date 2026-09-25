<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Site footer. Settings come from site.json → footer; a page can hide it with "footer": false.
 */
$b = $site['brand'];
$f = array_merge($site['footer'] ?? [], $s);
?>
<footer class="<?= cls('site-footer', $f) ?>">
    <div class="footer-inner">
        <div class="footer-top">
            <div class="footer-brand">
                <img class="footer-logo" src="<?= asset($b['logo_alt']) ?>" alt="<?= e($b['name']) ?>">
                <p class="footer-rating"><?= e($f['rating_label'] ?? '') ?> <?= e($b['rating']) ?></p>
                <?= trustpilot_stars((float) $b['rating'], 18) ?>
                <p class="footer-members"><strong><?= e($b['members']) ?></strong> <?= e($f['members_label'] ?? '') ?></p>
                <p class="footer-email"><?= icon('mail') ?><span><?= e($f['email_label'] ?? '') ?><br><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></span></p>
                <?php if (!empty($f['badge'])): ?>
                    <p class="footer-badge"><span class="flag" aria-hidden="true"></span><?= e($f['badge']) ?></p>
                <?php endif; ?>
            </div>
            <?php foreach ($f['columns'] ?? [] as $col): ?>
                <nav class="footer-col" aria-label="<?= e($col['title']) ?>">
                    <h2><?= e($col['title']) ?></h2>
                    <?php foreach ($col['links'] as $link): ?>
                        <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"><?= e($link['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>
        </div>
        <img class="footer-wordmark" src="<?= asset($b['logo_alt']) ?>" alt="" aria-hidden="true">
        <p class="footer-copy"><?= e(fill($f['copyright'] ?? '', ['year' => date('Y'), 'brand' => $b['name']])) ?></p>
    </div>
</footer>
