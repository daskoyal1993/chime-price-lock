<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * FAQ accordion (opened/closed by product.js — any page using it must load that script or add its own).
 * Settings: title, text, items[{q, a}].
 */
?>
<section class="<?= cls('pp-faq', $s) ?>">
    <div class="wrap narrow">
        <h2><?= e($s['title'] ?? '') ?></h2>
        <?php if (!empty($s['text'])): ?><p class="faq-intro"><?= e($s['text']) ?></p><?php endif; ?>
        <div class="faq-list">
            <?php foreach ($s['items'] ?? [] as $i => $item): ?>
                <div class="faq-item">
                    <h3><button type="button" aria-expanded="false" aria-controls="faq-<?= $i ?>"><?= e($item['q']) ?><?= icon('plus') ?></button></h3>
                    <div class="faq-answer" id="faq-<?= $i ?>" hidden><p><?= e($item['a']) ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
