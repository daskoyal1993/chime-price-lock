<?php
/**
 * Order confirmation. Layout and text: projects/<project>/thank-you.json.
 * Clears the funnel session and the answers saved in the browser.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);

if (!empty($record['order'])) {
    $_SESSION['last_order'] = ['order' => $record['order'], 'product' => $record['product'], 'first_name' => $record['answers']['first_name'] ?? ''];
    $record = [];
}
$last = $_SESSION['last_order'] ?? null;
if (!$last) {
    redirect_to('index.php');
}

$page = Page::load('thank-you');
$ctx  = [
    'last' => $last,
    'look' => product_look(['id' => $last['product']['product_id'], 'name' => $last['product']['product_name']]),
];

page_open($page);
echo '<main class="' . e($page['main_class']) . '">' . "\n";
Page::sections($page['sections'], $ctx);
echo "</main>\n";
?>
<script>
try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('funnel:') === 0) localStorage.removeItem(k); }); } catch (e) {}
</script>
<?php
page_close($page, $ctx);
