<?php
/**
 * Loads a .env file (KEY=value per line) into the environment, so config.php's getenv('FUNNEL_…') picks it up.
 *
 *   # comment
 *   FUNNEL_DB_HOST=localhost
 *   FUNNEL_DB_PASS='value with ; % [ ] , characters'
 *
 * Values may be wrapped in '…' or "…" (nothing inside is expanded). KEY= (nothing) is skipped, so config.php's default
 * applies; KEY='' sets an empty value (e.g. XAMPP root without password). A variable that is already set in the real
 * environment (e.g. Apache SetEnv) is not overwritten. A missing .env file is fine.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, 'export ') === 0) {
                $line = substr($line, 7);
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                error_log('.env line ' . ($n + 1) . ' ignored (no "=")');
                continue;
            }
            $key   = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                error_log('.env line ' . ($n + 1) . ' ignored (bad name "' . $key . '")');
                continue;
            }
            $quote = $value !== '' ? $value[0] : '';
            if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote && strlen($value) >= 2) {
                $value = substr($value, 1, -1);
            } else {
                if (($hash = strpos($value, ' #')) !== false) {
                    $value = rtrim(substr($value, 0, $hash));   // unquoted value: "value  # comment"
                }
                if ($value === '') {
                    continue;   // KEY= → keep the default from config.php
                }
            }
            if (getenv($key) !== false) {
                continue;   // the real environment wins
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    /** The variable's value when it is set (even to ""), otherwise $default. */
    public static function get(string $key, $default = '')
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
