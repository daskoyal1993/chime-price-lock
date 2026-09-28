<?php
/**
 * Legal / info pages: legal.php?page=privacy-policy.
 * Page list and layout: projects/<project>/legal.json → "pages". Content: projects/<project>/legal/<page>.html
 * (plain HTML, edited by hand; {brand}, {email}, {phone} are filled in). Linked from site.json → footer and checkout.json.
 * No funnel or session data is needed, so these pages open from anywhere (new tab, email link).
 */
require __DIR__ . '/bootstrap.php';

$page  = Page::load('legal');
$pages = $page['pages'] ?? [];
$slug  = (string) ($_GET['page'] ?? '');
$info  = preg_match('/^[a-z0-9-]{1,64}$/', $slug) && isset($pages[$slug]) ? $pages[$slug] : null;
$file  = $info ? __DIR__ . '/projects/' . basename((string) $config['project']) . '/legal/' . $slug . '.html' : '';

if (!$info || !is_file($file)) {
    http_response_code(404);
    $info = ['title' => $page['text']['not_found_title'] ?? 'Page not found'];
    $html = '<p>' . e($page['text']['not_found'] ?? 'This page does not exist.') . '</p><ul>';
    foreach ($pages as $s => $p) {
        $html .= '<li><a href="legal.php?page=' . e($s) . '">' . e($p['title']) . '</a></li>';
    }
    $html .= '</ul>';
} else {
    $html = fill(preg_replace('/<!--.*?-->\s*/s', '', (string) file_get_contents($file)), [   // editor notes stay out of the page
        'brand' => site('brand.name', ''),
        'email' => site('brand.email', ''),
        'phone' => site('brand.phone', ''),
    ], true);
}

page_open($page, $info['title']);
?>
<main class="<?= e($page['main_class']) ?>">
    <article class="legal wrap narrow">
        <h1 class="legal-title"><?= e($info['title']) ?></h1>
        <?php if (!empty($info['updated'])): ?>
            <p class="legal-updated"><?= e(fill($page['text']['updated'] ?? 'Last updated: {date}', ['date' => $info['updated']])) ?></p>
        <?php endif; ?>
        <div class="legal-body"><?= $html ?></div>
    </article>
</main>
<?php
page_close($page);
