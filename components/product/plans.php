<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Product cards with the plan lengths from the API.
 * Standard classes (product.js uses ONLY these — keep them in any new HTML; the layout around them may change freely):
 *   .product-card[data-product][data-name][data-image]   one product          .product-image    its image
 *   .product-details  .product-name  .product-content (description)           .product-info     extra info (included list)
 *   .product-options  → .product-option (one plan) → input.product-option-input[data-price][data-months][data-label][data-per-day]
 *   .product-pricing (hidden until a plan is picked) → .product-price  .product-price-per-day   (filled by product.js)
 *   .product-action → button.product-cta    .product-error (message)
 * Full list: docs/DESIGNER.md → "Product page: standard classes".
 * Needs $ctx['products'] (sellable_products()) and $ctx['chosen_id']. Product names come from the funnel JSON;
 * the add-on (NAD+) image is only shown when the product name includes it (product_look() → has_bonus).
 * Settings: id, title_plain, title_brand, title_end, text, features[{title, text_html}], empty_text, card{…}, plan{…}, included{…}.
 */
$bonus    = $site['bonus'] ?? null;
$rating   = $site['brand']['rating'];
$c        = $s['card'] ?? [];
$pl       = $s['plan'] ?? [];
$inc      = $s['included'] ?? [];
$features = $s['features'] ?? [];
$free     = $c['free_label'] ?? 'FREE';
$label    = static function (int $months) use ($pl): string {
    return $months === 1 ? ($pl['monthly_name'] ?? '') : fill($pl['kicker'] ?? '', ['months' => $months]);
};
?>
<section class="<?= cls('pp-products', $s) ?>" id="<?= e($s['id'] ?? 'products') ?>">
    <div class="wrap">
        <h2 class="section-title"><?= e($s['title_plain'] ?? '') ?> <span class="brand"><?= e($s['title_brand'] ?? '') ?></span> <?= e($s['title_end'] ?? '') ?></h2>
        <p class="section-sub"><?= e($s['text'] ?? '') ?></p>
        <?php if ($features): ?>
            <div class="feature-row">
                <?php foreach ($features as $i => $f): $last = $i === count($features) - 1; ?>
                    <div class="feature<?= $last ? ' feature-green' : '' ?>">
                        <span class="feature-num"><?= $last ? icon('check') : $i + 1 ?></span>
                        <div><h3><?= e($f['title']) ?></h3><p><?= $f['text_html'] ?></p></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$ctx['products']): ?>
            <div class="alert-box"><?= e($s['empty_text'] ?? '') ?></div>
        <?php endif; ?>

        <div class="product-grid">
            <?php foreach ($ctx['products'] as $item):
                $p = $item['product']; $look = $item['look']; $variants = $item['variants'];
                $featured = featured_variant($variants);
                $monthly  = null;
                foreach ($variants as $v) { if (plan_months($v) === 1) { $monthly = $v; break; } }
                $form = $variants[0]['form'] ?? '';
            ?>
            <article class="product-card" id="product-<?= e($look['keyword']) ?>" data-product="<?= e($look['keyword']) ?>" data-name="<?= e($look['title']) ?>" data-image="<?= asset($look['image']) ?>" data-product-id="<?= e($p['id']) ?>">
                <div class="pc-media">
                    <?php if (!empty($c['award_image'])): ?><img class="pc-award" src="<?= asset($c['award_image']) ?>" alt="<?= e($c['award_alt'] ?? '') ?>"><?php endif; ?>
                    <?php if (!empty($c['stock_label'])): ?><span class="pc-stock"><span class="dot"></span><?= e($c['stock_label']) ?></span><?php endif; ?>
                    <div class="pc-vials">
                        <figure class="product-image"><img src="<?= asset($look['image']) ?>" alt=""><figcaption><?= e($look['short']) ?></figcaption></figure>
                        <?php if ($bonus && $look['has_bonus']): // add-on image only when the product (funnel JSON name) includes it ?>
                            <span class="pc-plus" aria-hidden="true"><?= icon('plus') ?></span>
                            <figure><img src="<?= asset($bonus['image']) ?>" alt=""><figcaption><?= e($bonus['name']) ?> <b><?= e($free) ?></b></figcaption></figure>
                        <?php endif; ?>
                    </div>
                    <div class="pc-chips">
                        <?php if ($look['badge']): ?><span class="chip chip-green"><?= e($look['badge']) ?></span><?php endif; ?>
                        <span class="chip"><?= e(($form ?: 'Injectable') === 'Injectable' ? ($c['injectable_label'] ?? 'Injectable') : $form) ?></span>
                    </div>
                </div>
                <?php if ($bonus && !empty($c['bonus_bar_html'])): ?>
                    <p class="pc-bonus-bar"><?= fill($c['bonus_bar_html'], ['bonus' => $bonus['name'], 'value' => money($bonus['value'], false)], true) ?></p>
                <?php endif; ?>
                <?php if (!empty($c['press'])): ?>
                    <div class="pc-press"><?php foreach ($c['press'] as $logo): ?><img src="<?= asset($logo['image']) ?>" alt="<?= e($logo['alt']) ?>"><?php endforeach; ?></div>
                <?php endif; ?>

                <div class="pc-body product-details">
                    <h3 class="pc-title product-name"><?= e($look['combo']) ?></h3>
                    <p class="pc-sub product-content"><?= e(fill($bonus ? ($c['sub_with_bonus'] ?? '') : ($c['sub'] ?? ''), ['bonus' => $bonus['name'] ?? '', 'form' => $form])) ?></p>
                    <p class="pc-rating"><?= fill($c['rating_html'] ?? '', ['rating' => $rating], true) ?></p>

                    <?php if ($bonus && !empty($c['bonus_box'])): $bb = $c['bonus_box']; ?>
                        <div class="bonus-box">
                            <?php if ($look['has_bonus']): ?><img src="<?= asset($bonus['image']) ?>" alt=""><?php endif; ?>
                            <div><p class="bonus-kicker"><?= e($bb['kicker'] ?? '') ?></p><p class="bonus-name"><?= fill($bb['name_html'] ?? '', ['bonus' => $bonus['name'], 'value' => money($bonus['value'], false)], true) ?></p><p><?= e($bonus['text']) ?></p></div>
                        </div>
                    <?php endif; ?>

                    <fieldset class="plans product-options">
                        <legend><?= e($pl['legend'] ?? '') ?></legend>
                        <?php foreach ($variants as $v):
                            $months     = plan_months($v);
                            $price      = $v['offer']['price'];
                            $perMonth   = $price / $months;
                            $isFeatured = $featured && $featured['id'] === $v['id'];
                            $freeType   = (bool) preg_match('/free/i', $v['name']);
                            $save       = $monthly && $months > 1 ? round((1 - $perMonth / $monthly['offer']['price']) * 100) : 0;
                            $vars       = ['months' => $months, 'months_minus_1' => $months - 1, 'save' => $save, 'price' => money($price, false)];
                            $perDay     = round($price / (30 * $months), 2);   // same rule as the checkout: price ÷ (30 × months)
                            $banner     = $freeType ? ($pl['banner_free_html'] ?? '') : ($save > 0 ? ($pl['banner_save_html'] ?? '') : ($pl['banner_best_html'] ?? ''));
                        ?>
                            <label class="plan product-option<?= $isFeatured ? ' plan-featured' : '' ?>">
                                <?php if ($isFeatured): ?>
                                    <span class="plan-banner"><?= icon('gift') ?>
                                        <span><?= fill($banner, $vars, true) ?></span>
                                    </span>
                                <?php endif; ?>
                                <span class="plan-row">
                                    <input type="radio" class="product-option-input" name="plan-<?= e($p['id']) ?>" value="<?= e($v['id']) ?>" data-price="<?= e($price) ?>" data-months="<?= $months ?>" data-label="<?= e($label($months)) ?>" data-per-day="<?= e($perDay) ?>"<?= $ctx['chosen_id'] === $v['id'] ? ' checked' : '' ?>>
                                    <?php if ($months === 1): ?>
                                        <span class="plan-main"><span class="plan-name"><?= e($pl['monthly_name'] ?? '') ?></span><span class="plan-note"><?= e($pl['monthly_note'] ?? '') ?></span></span>
                                        <span class="plan-price"><?= money_big($price) ?><small><?= e($pl['per_month'] ?? '/mo') ?></small></span>
                                    <?php else: ?>
                                        <span class="plan-main">
                                            <span class="plan-kicker"><?= e($label($months)) ?></span>
                                            <span class="plan-big"><?= money_big(round($perMonth, 2)) ?> <small><?= e($pl['per_month'] ?? '/mo') ?></small></span>
                                            <span class="plan-note"><?= e(fill($freeType ? ($pl['note_free'] ?? '') : ($pl['note_billed'] ?? ''), $vars)) ?></span>
                                            <span class="plan-due"><?= fill($pl['due_html'] ?? '', $vars, true) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="product-action">
                        <p class="product-pricing" hidden><strong class="product-price"></strong> <span class="product-price-per-day"></span></p>
                        <button type="button" class="btn-choose product-cta" disabled><?= e($pl['button'] ?? '') ?></button>
                        <p class="form-error product-error" role="alert" hidden></p>
                    </div>

                    <?php if (($inc['visible'] ?? true) !== false): ?>
                        <div class="included product-info">
                            <p class="included-title"><?= e($inc['title'] ?? '') ?></p>
                            <ul>
                                <?php if ($bonus && !empty($inc['bonus_line'])): ?>
                                    <li class="included-bonus"><?= icon('check-circle') ?><span><?= e(fill($inc['bonus_line'], ['bonus' => $bonus['name']])) ?></span><s><?= money($bonus['value'], false) ?></s><b><?= e($free) ?></b></li>
                                <?php endif; ?>
                                <?php foreach ($site['included'] ?? [] as $row): ?>
                                    <li><?= icon('check-circle') ?><span><?= e($row['text']) ?></span><s><?= money($row['value'], false) ?></s><b><?= e($free) ?></b></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
