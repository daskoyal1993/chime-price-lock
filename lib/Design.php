<?php
/**
 * Design layer: extra CSS classes, a stylesheet and component variants from designs/<design>/, on top of the existing HTML.
 * The existing classes and all behaviour stay; a design only ADDS classes and CSS, or swaps a component for a variant.
 *
 *   designs/<design>/config.json   { "extends": "default",
 *                                    "bodyClass": "theme-v2",                                  → <body> on every page
 *                                    "classes":   { "<design-id>": "class …" },                → any page
 *                                    "pages":     { "product": { "bodyClass": "product-v2",
 *                                                                "classes": { "product-hero": "hero-v2" } } },
 *                                    "components": { "product/hero": "product/hero-v2" } }    → level 2/3: another template
 *   designs/<design>/design.css    loaded after the page's own CSS
 *
 * Elements are found by a stable data-design-id="…" attribute (design_attrs() in lib/Page.php), never by position or text.
 * Classes are added by PHP while rendering (no flash of the old design) and again by assets/design-manager.js for HTML
 * that the browser creates later (quiz screens). Active design: site.json → "design", FUNNEL_DESIGN in .env, or
 * ?design=<name> to preview (not on FUNNEL_ENV=live; ?design=off ends the preview).
 */
final class Design
{
    /** @var string */
    private static $name = 'default';
    /** @var array */
    private static $cfg = [];
    /** @var string */
    private static $dir = '';

    /** Pick and load the active design (bootstrap.php, after Page::boot() and the session). */
    public static function boot(array $config): void
    {
        self::$dir = dirname(__DIR__) . '/designs';
        $name = (string) ($config['design'] ?? '') ?: (string) (Page::site()['design'] ?? '') ?: 'default';

        // Preview another design in this browser session: ?design=chime-v2 … ?design=off (never on live).
        if (($config['qa_errors']['env'] ?? 'local') !== 'live' && PHP_SAPI !== 'cli') {
            $preview = (string) ($_GET['design'] ?? '');
            if ($preview === 'off') {
                unset($_SESSION['design_preview']);
            } elseif ($preview !== '' && self::exists($preview)) {
                $_SESSION['design_preview'] = $preview;
            }
            $name = (string) ($_SESSION['design_preview'] ?? $name);
        }

        self::$name = self::exists($name) ? $name : 'default';
        try {
            self::$cfg = self::read(self::$name, 0);
        } catch (Throwable $e) {
            self::$cfg = [];
            if (class_exists('ErrorLogger', false)) ErrorLogger::log($e, 'DESIGN_ERROR');
        }
    }

    public static function name(): string
    {
        return self::$name;
    }

    /** Body classes for a page: the design's bodyClass + pages.<page>.bodyClass. */
    public static function bodyClass(string $page): string
    {
        return self::join([(string) (self::$cfg['bodyClass'] ?? ''), (string) (self::$cfg['pages'][$page]['bodyClass'] ?? '')]);
    }

    /** Extra classes for one data-design-id: "classes" (every page) + pages.<page>.classes. */
    public static function classes(string $id, string $page = ''): string
    {
        return self::join([(string) (self::$cfg['classes'][$id] ?? ''), $page !== '' ? (string) (self::$cfg['pages'][$page]['classes'][$id] ?? '') : '']);
    }

    /** The class map for assets/design-manager.js on this page (id => classes), or [] when there is nothing to add. */
    public static function clientClasses(string $page): array
    {
        $map = [];
        foreach (array_merge(array_keys(self::$cfg['classes'] ?? []), array_keys(self::$cfg['pages'][$page]['classes'] ?? [])) as $id) {
            $classes = self::classes((string) $id, $page);
            if ($classes !== '') $map[(string) $id] = $classes;
        }
        return $map;
    }

    /** The design's stylesheet (path for asset()), when it has one. */
    public static function stylesheet(): ?string
    {
        $file = 'designs/' . self::$name . '/design.css';
        return is_file(dirname(__DIR__) . '/' . $file) ? $file : null;
    }

    /**
     * Level 2/3: the component to render instead of $name ("components": {"product/hero": "product/hero-v2"}).
     * A variant that doesn't exist is reported and the original is used, so a typo never breaks a page.
     */
    public static function component(string $name): string
    {
        $variant = (string) (self::$cfg['components'][$name] ?? '');
        if ($variant === '' || $variant === $name) return $name;
        if (preg_match('~^[a-z0-9-]+(/[a-z0-9-]+)*$~', $variant) && is_file(dirname(__DIR__) . '/components/' . $variant . '.php')) {
            return $variant;
        }
        if (class_exists('ErrorLogger', false)) {
            ErrorLogger::jsonError('Design "' . self::$name . '"', 'component variant "' . $variant . '" for ' . $name . ' not found, using the original');
        }
        return $name;
    }

    /* ---------------------------------------------------------------- internals */

    private static function exists(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9-]{1,40}$/', $name) && is_file(self::$dir . '/' . $name . '/config.json');
    }

    /** config.json, merged over the design it "extends" (up to 5 levels). */
    private static function read(string $name, int $depth): array
    {
        $file = self::$dir . '/' . $name . '/config.json';
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON in designs/' . $name . '/config.json: ' . json_last_error_msg());
        }
        $parent = (string) ($data['extends'] ?? '');
        if ($parent !== '' && $parent !== $name && $depth < 5 && self::exists($parent)) {
            $data = self::merge(self::read($parent, $depth + 1), $data);
        }
        return $data;
    }

    /** Nested objects merge; bodyClass and "classes" entries of both designs are combined; anything else is replaced. */
    private static function merge(array $base, array $over, bool $inClasses = false): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && is_array($base[$k] ?? null)) {
                $base[$k] = self::merge($base[$k], $v, $inClasses || $k === 'classes');
            } elseif (is_string($v) && is_string($base[$k] ?? null) && ($inClasses || $k === 'bodyClass')) {
                $base[$k] = self::join([$base[$k], $v]);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** Space-separated, de-duplicated, only safe class names. */
    private static function join(array $parts): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim(implode(' ', $parts))) ?: [] as $c) {
            if ($c !== '' && preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $c)) $out[$c] = true;
        }
        return implode(' ', array_keys($out));
    }
}
