<?php
/**
 * Splits the API's questionnaire steps into one-page-at-a-time screens (projects/<project>/index.json → "screens").
 */
final class Screens
{
    const FIELDS_PER_SCREEN = 4;

    /**
     * @return array list of [badge, badge_style, heading, text, button, doctor, keys[]]
     */
    public static function build(array $funnel, array $plan): array
    {
        $questions = [];   // key => [question, step title]
        foreach ($funnel['steps'] as $step) {
            foreach ($step['questions'] as $q) {
                if ($q['type'] !== 'hidden') {
                    $questions[$q['key']] = ['q' => $q, 'step' => $step['title']];
                }
            }
        }

        $used    = [];
        $screens = [];

        foreach ($plan as $screen) {
            $keys = [];
            foreach ($screen['keys'] ?? [] as $key) {
                if (isset($questions[$key]) && !isset($used[$key])) {
                    $keys[] = $key;
                    $used[$key] = true;
                }
            }
            if ($keys) {
                $screens[] = self::screen($screen, $keys, $questions);
            }
        }

        // Anything the plan doesn't mention, in API order.
        $current = [];
        $flush = static function () use (&$current, &$screens, $questions) {
            if ($current) {
                $screens[] = self::screen([], $current, $questions);
                $current = [];
            }
        };
        foreach ($questions as $key => $item) {
            if (isset($used[$key])) continue;
            $q = $item['q'];

            if ($current && self::dependsOnly($q, $current, $used)) {   // follow-up stays with its question
                $used[$key] = true;
                $current[] = $key;
                continue;
            }
            if (self::isChoiceScreen($q)) {
                $flush();
                $current = [$key];
                $used[$key] = true;
                continue;
            }
            if (count($current) >= self::FIELDS_PER_SCREEN || ($current && self::isChoiceScreen($questions[$current[0]]['q']))) {
                $flush();
            }
            $current[] = $key;
            $used[$key] = true;
        }
        $flush();

        return $screens;
    }

    private static function screen(array $plan, array $keys, array $questions): array
    {
        return [
            'badge'       => $plan['badge'] ?? ($questions[$keys[0]]['step'] ?: null),
            'badge_style' => $plan['badge_style'] ?? 'info',
            'heading'     => $plan['heading'] ?? null,
            'text'        => $plan['text'] ?? null,
            'button'      => $plan['button'] ?? null,
            'doctor'      => !empty($plan['doctor']),
            'keys'        => $keys,
        ];
    }

    /** Multi-option checkbox / radio questions get a page of their own. */
    private static function isChoiceScreen(array $q): bool
    {
        return ($q['type'] === 'checkbox' && count($q['options']) > 1)
            || ($q['type'] === 'radio' && count($q['options']) > 2);
    }

    /**
     * True when $q is a follow-up of the current screen: at least one condition refers to a question
     * on it, and the rest refer to questions already placed on earlier screens.
     */
    private static function dependsOnly(array $q, array $keys, array $placed): bool
    {
        $onScreen = false;
        foreach ($q['conditions'] as $c) {
            if (in_array($c['key'], $keys, true)) {
                $onScreen = true;
            } elseif (!isset($placed[$c['key']]) && !in_array($c['key'], ['age', 'bmi', 'bmi_rating'], true)) {
                return false;
            }
        }
        return $onScreen;
    }
}
