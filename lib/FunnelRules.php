<?php
/**
 * Server-side copy of the funnel rules in assets/funnel.js:
 * derived values (age, BMI, BMI rating), condition-based visibility,
 * field validation and disqualification.
 *
 * Answer shapes: checkbox => string[], height => ['feet' => int, 'inches' => int], everything else => string.
 */
final class FunnelRules
{
    /** Question types that the visitor never fills in directly. */
    const COMPUTED_TYPES = ['bmi', 'hidden'];

    /**
     * Adds age, bmi and bmi_rating to the answers (overwriting anything the browser sent).
     */
    public static function derive(array $answers): array
    {
        unset($answers['age'], $answers['bmi'], $answers['bmi_rating']);

        $age = self::ageFromDob($answers['dob'] ?? null);
        if ($age !== null) {
            $answers['age'] = $age;
        }

        $bmi = self::bmi($answers['height'] ?? null, $answers['weight'] ?? null);
        if ($bmi !== null) {
            $answers['bmi']        = $bmi;
            $answers['bmi_rating'] = self::bmiRating($bmi);
        }

        return $answers;
    }

    public static function ageFromDob($dob): ?int
    {
        if (!is_string($dob) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $birth = new DateTimeImmutable($dob);
        $today = new DateTimeImmutable('today');
        if ($birth > $today) {
            return null;
        }
        return $birth->diff($today)->y;
    }

    public static function bmi($height, $weight): ?float
    {
        if (!is_array($height) || !is_numeric($height['feet'] ?? null) || !is_numeric($weight)) {
            return null;
        }
        $inches = (int) $height['feet'] * 12 + (int) ($height['inches'] ?? 0);
        $lbs    = (float) $weight;
        if ($inches <= 0 || $lbs <= 0) {
            return null;
        }
        return round(703 * $lbs / ($inches * $inches), 1);
    }

    public static function bmiRating(float $bmi): string
    {
        if ($bmi < 18.5) return 'Underweight';
        if ($bmi < 25)   return 'Normal';
        if ($bmi < 30)   return 'Overweight';
        return 'Obese';
    }

    /**
     * A question is visible when all of its "show" conditions match (or it has none)
     * and not all of its "hide" conditions match.
     */
    public static function isVisible(array $question, array $answers): bool
    {
        $show = $hide = [];
        foreach ($question['conditions'] as $condition) {
            if ($condition['visibility'] === 'hide') {
                $hide[] = $condition;
            } else {
                $show[] = $condition;
            }
        }
        foreach ($show as $condition) {
            if (!self::matches($condition, $answers)) {
                return false;
            }
        }
        if ($hide) {
            foreach ($hide as $condition) {
                if (!self::matches($condition, $answers)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    public static function matches(array $condition, array $answers): bool
    {
        $actual   = $answers[$condition['key']] ?? null;
        $expected = $condition['value'];

        if ($actual === null || $actual === '' || $actual === []) {
            return $condition['operator'] === 'not_equal_to';
        }

        if (is_array($actual)) {   // checkbox: "equal_to" means "includes"
            $list = array_map('strval', array_values($actual));
            switch ($condition['operator']) {
                case 'equal_to':
                case 'contains':
                    return in_array($expected, $list, true);
                case 'not_equal_to':
                    return !in_array($expected, $list, true);
                default:
                    return false;
            }
        }

        $numeric = is_numeric($actual) && is_numeric($expected);
        $a = $numeric ? (float) $actual : (string) $actual;
        $b = $numeric ? (float) $expected : (string) $expected;

        switch ($condition['operator']) {
            case 'equal_to':              return $a == $b;
            case 'not_equal_to':          return $a != $b;
            case 'contains':              return stripos((string) $actual, (string) $expected) !== false;
            case 'greater_than':          return $numeric && $a > $b;
            case 'equal_to_greater_than':
            case 'greater_than_or_equal_to': return $numeric && $a >= $b;
            case 'less_than':             return $numeric && $a < $b;
            case 'equal_to_less_than':
            case 'less_than_or_equal_to': return $numeric && $a <= $b;
            default:
                error_log('Funnel: unknown condition operator "' . $condition['operator'] . '"');
                return false;
        }
    }

    /**
     * Validate every visible question in the funnel.
     *
     * @return array [
     *   'errors'       => [key => message],
     *   'disqualified' => [[key, label, value], ...],
     *   'answers'      => visible answers + derived values, keyed by question key,
     *   'params'       => the same answers keyed by default_params (for CRM / intake payloads),
     * ]
     */
    public static function validate(array $funnel, array $answers, array $config): array
    {
        $answers = self::derive(self::sanitize($answers));
        $errors = $disqualified = $clean = $params = [];

        foreach ($funnel['steps'] as $step) {
            foreach ($step['questions'] as $q) {
                $key = $q['key'];

                if (in_array($q['type'], self::COMPUTED_TYPES, true)) {
                    if (isset($answers[$key])) {
                        $clean[$key] = $params[$q['param']] = $answers[$key];
                    } elseif ($q['type'] === 'bmi' && $q['required']) {
                        $errors[$key] = 'Enter your height and weight to calculate your BMI.';
                    }
                    continue;
                }

                if (!self::isVisible($q, $answers)) {
                    continue;
                }

                $value = $answers[$key] ?? null;
                $error = self::validateValue($q, $value, $config);
                if ($error === null && $key === 'target_weight' && !self::isEmpty($value)) {
                    $error = self::targetWeightError($value, $answers);
                }
                if ($error !== null) {
                    $errors[$key] = $error;
                    continue;
                }
                if (self::isEmpty($value)) {
                    continue;
                }

                foreach ($q['options'] as $option) {
                    $picked = is_array($value) ? in_array($option['value'], $value, true) : $option['value'] === $value;
                    if ($picked && $option['disqualify']) {
                        $disqualified[] = ['key' => $key, 'label' => $q['label'], 'value' => $option['value']];
                    }
                }

                $clean[$key] = $params[$q['param']] = $value;
            }
        }

        return ['errors' => $errors, 'disqualified' => $disqualified, 'answers' => $clean, 'params' => $params];
    }

    /** Goal weight: below the current weight and not under a healthy BMI of 18.5 (same rules as assets/funnel.js). */
    private static function targetWeightError($goal, array $answers): ?string
    {
        $current = $answers['weight'] ?? null;
        if (is_numeric($current) && (float) $goal >= (float) $current) {
            return 'Goal weight must be less than your current weight (' . round((float) $current) . ' lbs).';
        }
        $bmi = self::bmi($answers['height'] ?? null, $goal);
        if ($bmi !== null && $bmi < 18.5) {
            return 'Goal weight must result in a healthy BMI of at least 18.5.';
        }
        return null;
    }

    private static function validateValue(array $q, $value, array $config): ?string
    {
        if (self::isEmpty($value)) {
            return $q['required'] ? 'This field is required.' : null;
        }

        $options = array_column($q['options'], 'value');

        switch ($q['type']) {
            case 'checkbox':
                if (!is_array($value)) return 'Invalid answer.';
                foreach ($value as $v) {
                    if (!in_array($v, $options, true)) return 'Invalid choice.';
                }
                return null;

            case 'radio':
            case 'select':
                return in_array($value, $options, true) ? null : 'Invalid choice.';

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.';

            case 'phone':
                $digits = preg_replace('/\D/', '', (string) $value);
                if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
                return strlen($digits) === 10 ? null : 'Enter a valid 10-digit US phone number.';

            case 'number':
                if ($q['key'] === 'zip') {
                    return preg_match('/^\d{5}$/', (string) $value) ? null : 'Enter a 5-digit ZIP code.';
                }
                return is_numeric($value) ? null : 'Enter a number.';

            case 'date':
                $age = self::ageFromDob($value);
                if ($age === null) return 'Enter a valid date.';
                if ($q['key'] === 'dob' && $age < (int) $config['min_age']) {
                    return 'You must be at least ' . (int) $config['min_age'] . ' years old.';
                }
                return null;

            case 'height':
                $feet   = $value['feet'] ?? null;
                $inches = $value['inches'] ?? null;
                if (!is_numeric($feet) || !is_numeric($inches) || $feet < 3 || $feet > 8 || $inches < 0 || $inches > 11) {
                    return 'Select your height.';
                }
                return null;

            case 'weight':
                if (!is_numeric($value) || $value < $config['weight_min'] || $value > $config['weight_max']) {
                    return 'Enter a weight between ' . $config['weight_min'] . ' and ' . $config['weight_max'] . ' lbs.';
                }
                return null;

            default:   // input, textarea
                return mb_strlen((string) $value) > 2000 ? 'Please keep this under 2000 characters.' : null;
        }
    }

    private static function isEmpty($value): bool
    {
        if (is_array($value)) {
            return $value === [];   // sanitize() turns a height without feet into []
        }
        return $value === null || trim((string) $value) === '';
    }

    /** Keep only scalar strings, string lists and the height pair. */
    private static function sanitize(array $answers): array
    {
        $out = [];
        foreach ($answers as $key => $value) {
            if (!is_string($key)) continue;
            if (is_array($value)) {
                if (array_key_exists('feet', $value)) {
                    $out[$key] = [
                        'feet'   => is_numeric($value['feet'] ?? null) ? (int) $value['feet'] : null,
                        'inches' => is_numeric($value['inches'] ?? null) ? (int) $value['inches'] : null,
                    ];
                    if ($out[$key]['feet'] === null) $out[$key] = [];
                } else {
                    $out[$key] = array_values(array_map('strval', array_filter($value, 'is_scalar')));
                }
            } elseif (is_scalar($value)) {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }
}
