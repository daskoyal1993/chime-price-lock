<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Checkout form: shipping (prefilled from the quiz), billing toggle, card fields, legal text, warranty.
 * Field ids / data-field names are used by checkout.js and submit.php — only the labels are configurable.
 * Needs $ctx['answers'] and $ctx['states'].
 * Settings: headings{}, labels{field_id: label}, billing{}, card{}, button, legal_html, warranty{}.
 */
$a      = $ctx['answers'];
$states = $ctx['states'];
$h      = $s['headings'] ?? [];
$lbl    = $s['labels'] ?? [];
$bill   = $s['billing'] ?? [];
$card   = $s['card'] ?? [];
$w      = $s['warranty'] ?? null;

$v = static function (string $key) use ($a): string {
    return e($a[$key] ?? '');
};
$field = static function (string $id, string $value = '', array $attrs = []) use ($lbl): void {
    $label = $lbl[$id] ?? $id;
    $extra = '';
    foreach ($attrs as $k => $val) {
        $extra .= ' ' . $k . '="' . e($val) . '"';
    }
    echo '<div class="co-field" data-field="' . e($id) . '"><label for="' . e($id) . '">' . e($label) . '</label>'
        . '<input id="' . e($id) . '" class="co-input" value="' . $value . '" placeholder="' . e($label) . '"' . $extra . '>'
        . '<p class="fq-error" hidden></p></div>';
};
$state = static function (string $id, string $selected) use ($states, $lbl): void {
    echo '<div class="co-field" data-field="' . e($id) . '"><label for="' . e($id) . '">' . e($lbl['state'] ?? 'State') . '</label><div class="select-wrap">'
        . '<select id="' . e($id) . '" class="co-input" autocomplete="address-level1"><option value="">' . e($lbl['state_placeholder'] ?? '') . '</option>';
    foreach ($states as $st) {
        echo '<option value="' . e($st) . '"' . ($st === $selected ? ' selected' : '') . '>' . e($st) . '</option>';
    }
    echo '</select>' . select_arrow() . '</div><p class="fq-error" hidden></p></div>';
};
?>
<div class="<?= cls('co-main', $s) ?>">
    <form id="checkout-form" class="co-form" novalidate>
        <h1 class="co-h"><?= e($h['shipping'] ?? '') ?></h1>
        <div class="co-grid">
            <?php $field('shipping_first_name', $v('first_name'), ['autocomplete' => 'given-name']); ?>
            <?php $field('shipping_last_name', $v('last_name'), ['autocomplete' => 'family-name']); ?>
            <?php $field('shipping_address1', $v('address_line1'), ['autocomplete' => 'address-line1']); ?>
            <?php $field('shipping_address2', $v('address_line2'), ['autocomplete' => 'address-line2']); ?>
            <div class="co-field"><label for="shipping_country"><?= e($lbl['country'] ?? '') ?></label><input id="shipping_country" class="co-input is-valid" value="<?= e($lbl['country_value'] ?? '') ?>" readonly></div>
            <?php $state('shipping_state', (string) ($a['shipping_state'] ?? '')); ?>
            <?php $field('shipping_city', $v('city'), ['autocomplete' => 'address-level2']); ?>
            <?php $field('shipping_zip', $v('zip'), ['autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'maxlength' => '5']); ?>
            <?php $field('shipping_phone', $v('phone'), ['autocomplete' => 'tel-national', 'type' => 'tel']); ?>
            <?php $field('shipping_email', $v('email'), ['autocomplete' => 'email', 'type' => 'email']); ?>
        </div>

        <h2 class="co-h"><?= e($h['billing'] ?? '') ?></h2>
        <fieldset class="co-toggle">
            <legend><?= e($bill['same_label'] ?? '') ?></legend>
            <label><input type="radio" name="billing_same" value="1" checked> <?= e($bill['yes'] ?? 'Yes') ?></label>
            <label><input type="radio" name="billing_same" value="0"> <?= e($bill['no'] ?? 'No') ?></label>
        </fieldset>
        <div class="co-grid" id="billing-fields" hidden>
            <?php $field('billing_first_name', '', ['autocomplete' => 'billing given-name']); ?>
            <?php $field('billing_last_name', '', ['autocomplete' => 'billing family-name']); ?>
            <?php $field('billing_address1', '', ['autocomplete' => 'billing address-line1']); ?>
            <?php $field('billing_city', '', ['autocomplete' => 'billing address-level2']); ?>
            <?php $state('billing_state', ''); ?>
            <?php $field('billing_zip', '', ['autocomplete' => 'billing postal-code', 'inputmode' => 'numeric', 'maxlength' => '5']); ?>
        </div>

        <h2 class="co-h"><?= e($h['payment'] ?? '') ?></h2>
        <p class="co-muted"><?= e($card['note'] ?? '') ?></p>
        <div class="co-field" data-field="card_number">
            <label for="card_number"><?= e($card['number_label'] ?? '') ?></label>
            <input id="card_number" class="co-input" inputmode="numeric" autocomplete="cc-number" placeholder="<?= e($card['number_placeholder'] ?? '') ?>" maxlength="23">
            <p class="fq-error" hidden></p>
        </div>
        <div class="co-grid co-grid-3">
            <div class="co-field" data-field="card_month"><label for="card_month"><?= e($card['expiry_label'] ?? '') ?></label><div class="select-wrap">
                <select id="card_month" class="co-input" autocomplete="cc-exp-month"><option value=""><?= e($card['month_placeholder'] ?? '') ?></option>
                    <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>">(<?= sprintf('%02d', $m) ?>) <?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?>
                </select><?= select_arrow() ?></div><p class="fq-error" hidden></p></div>
            <div class="co-field" data-field="card_year"><label for="card_year" class="sr-only"><?= e($card['year_label'] ?? '') ?></label><span class="co-label-spacer" aria-hidden="true">&nbsp;</span><div class="select-wrap">
                <select id="card_year" class="co-input" autocomplete="cc-exp-year"><option value=""><?= e($card['year_placeholder'] ?? '') ?></option>
                    <?php for ($y = (int) date('Y'); $y <= (int) date('Y') + 19; $y++): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
                </select><?= select_arrow() ?></div><p class="fq-error" hidden></p></div>
            <div class="co-field" data-field="card_cvv"><label for="card_cvv"><?= e($card['cvv_label'] ?? '') ?></label>
                <input id="card_cvv" class="co-input co-cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="<?= e($card['cvv_placeholder'] ?? '') ?>" maxlength="4" style="background-image:url('<?= asset($card['cvv_image'] ?? '') ?>')">
                <p class="fq-error" hidden></p></div>
        </div>

        <p class="form-error" role="alert" hidden></p>
        <button type="submit" class="btn-complete" id="complete-btn"><?= icon('shield') ?><span><?= e($s['button'] ?? '') ?></span></button>
        <?php if (!empty($s['legal_html'])): ?><p class="co-legal"><?= $s['legal_html'] ?></p><?php endif; ?>

        <?php if ($w && ($w['visible'] ?? true) !== false): ?>
            <div class="warranty">
                <div><p class="warranty-title"><?= e($w['title'] ?? '') ?></p><p><?= e($w['text'] ?? '') ?></p></div>
                <img src="<?= asset($w['image'] ?? '') ?>" alt="<?= e($w['image_alt'] ?? '') ?>">
            </div>
        <?php endif; ?>
    </form>
</div>
