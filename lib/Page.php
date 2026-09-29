<?php
/**
 * JSON-driven pages.
 *
 *   projects/<project>/site.json    brand, theme colors, shared styles, header/footer, product looks, shared offer blocks
 *   projects/<project>/<page>.json  one file per page: title, styles, theme overrides, header, sections, script text
 *   components/<group>/<name>.php   the HTML for one section; a page JSON lists them in "sections"
 *
 * Every section is { "component": "product/hero", "visible": true, ...its own settings }.
 * Text is escaped when printed. Keys ending in "_html" are printed as HTML (trusted, edited by developers).
 * "{placeholders}" in text are filled by the component (see fill()).
 */
final class Page
{
    /** @var string */
    private static $dir = '';
    /** @var array */
    private static $site = [];

    /** Point at projects/<project>/ and load site.json. */
    public static function boot(string $projectDir): void
    {
        if (!is_dir($projectDir)) {
            throw new RuntimeException('Project folder not found: ' . $projectDir);
        }
        self::$dir  = rtrim($projectDir, '/\\');
        self::$site = self::read('site');
    }

    public static function site(): array
    {
        return self::$site;
    }

    /** A page's JSON (projects/<project>/<name>.json). */
    public static function load(string $name): array
    {
        return self::read($name) + [
            'title'      => '',
            'body_class' => '',
            'styles'     => [],
            'theme'      => [],
            'header'     => null,
            'main_class' => '',
            'sections'   => [],
            'after_main' => [],
            'footer'     => true,
            'text'       => [],
        ];
    }

    private static function read(string $name): array
    {
        $file = self::$dir . '/' . $name . '.json';
        if (!is_file($file)) {
            throw new RuntimeException('Page config not found: ' . $file);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in ' . $file . ': ' . json_last_error_msg());
        }
        return $data;
    }

    /** Render a list of sections. Hidden ones ("visible": false) are skipped. */
    public static function sections(array $sections, array $ctx): void
    {
        foreach ($sections as $section) {
            self::component($section, $ctx);
        }
    }

    /** Render one section with components/<component>.php. */
    public static function component(array $section, array $ctx): void
    {
        if (($section['visible'] ?? true) === false) {
            return;
        }
        $name = (string) ($section['component'] ?? '');
        $file = __DIR__ . '/../components/' . $name . '.php';
        if (!preg_match('~^[a-z0-9-]+(/[a-z0-9-]+)*$~', $name) || !is_file($file)) {
            throw new RuntimeException('Unknown component "' . $name . '" (expected components/' . $name . '.php)');
        }
        (static function (string $__file, array $s, array $ctx, array $site) {
            require $__file;
        })($file, $section, $ctx, self::$site);
    }
}

/** site.json, or one value from it by dotted path: site('brand.name'). */
function site(?string $path = null, $default = null)
{
    $value = Page::site();
    if ($path === null) {
        return $value;
    }
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

/**
 * Fill "{name}" placeholders. With $html = true the template is trusted HTML and the values are escaped;
 * otherwise the result is plain text (escape it when printing).
 */
function fill(string $template, array $vars, bool $html = false): string
{
    $map = [];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = $html ? e($v) : (string) $v;
    }
    return strtr($template, $map);
}

/** Class attribute value: the component's own classes plus the section's optional "class". */
function cls(string $base, array $s): string
{
    return e(trim($base . ' ' . ($s['class'] ?? '')));
}

/** Page head, opening <body> and the page header. */
function page_open(array $page, string $title = ''): void
{
    $theme  = site('theme', []);
    $colors = array_merge($theme['colors'] ?? [], $page['theme']['colors'] ?? []);
    $font   = $page['theme']['font_url'] ?? $theme['font_url'] ?? '';
    $family = $page['theme']['font_family'] ?? $theme['font_family'] ?? '';
    if ($family !== '') {
        $colors['font'] = $family;
    }
    $styles = array_merge(site('styles', []), $page['styles']);
    $title  = $title !== '' ? $title : $page['title'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(site('brand.name') . ' | ' . $title) ?></title>
<?php if ($qa = ErrorLogger::clientConfig()): // QA error log: JS errors + failed fetch calls → qa-log.php (first, so it sees every error) ?>
    <script id="qa-errors-config" type="application/json"><?= json_encode($qa, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="<?= asset('assets/qa-errors.js') ?>"></script>
<?php endif; ?>
    <link rel="icon" href="<?= asset(site('brand.favicon', '')) ?>">
<?php if ($font !== ''): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($font) ?>">
<?php endif; ?>
<?php foreach ($styles as $css): ?>
    <link rel="stylesheet" href="<?= asset($css) ?>">
<?php endforeach; ?>
<?php if ($colors): ?>
    <style>:root {<?php foreach ($colors as $name => $value): ?> --<?= preg_replace('/[^a-z0-9-]/i', '', $name) ?>: <?= str_replace(['<', '>', '{', '}', ';'], '', (string) $value) ?>;<?php endforeach; ?> }</style>
<?php endif; ?>
</head>
<body class="<?= e($page['body_class']) ?>">
<?php
    if ($page['header']) {
        Page::component(array_merge(site('header', []), $page['header']), []);
    }
}

/** Sections after <main>, the footer, page data for the script and the closing tags. */
function page_close(array $page, array $ctx = [], array $scripts = [], ?array $boot = null): void
{
    Page::sections($page['after_main'], $ctx);
    if ($page['footer'] !== false) {
        Page::component(['component' => 'layout/footer'] + (is_array($page['footer']) ? $page['footer'] : []), $ctx);
    }
    if ($boot !== null) {
        echo '<script id="page-data" type="application/json">' . json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
    foreach ($scripts as $src) {
        echo '<script src="' . asset($src) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** A standard page: head, header, <main> with the JSON sections, footer, scripts. */
function render_page(array $page, array $ctx, array $scripts = [], ?array $boot = null): void
{
    page_open($page);
    echo '<main' . ($page['main_class'] !== '' ? ' class="' . e($page['main_class']) . '"' : '') . ">\n";
    Page::sections($page['sections'], $ctx);
    echo "</main>\n";
    page_close($page, $ctx, $scripts, $boot);
}
