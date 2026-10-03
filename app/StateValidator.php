<?php
declare(strict_types=1);
namespace FitBot;

/** A versioned, allow-listed snapshot. Unknown fields (including credentials) are discarded. */
final class StateValidator
{
    private static function number(mixed $value, float $min, float $max, float $fallback = 0): float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? max($min, min($max, (float) $value)) : $fallback;
    }
    private static function date(mixed $date): bool
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < '2000-01-01' || $date > gmdate('Y-m-d', time() + 86400)) return false;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
    private static function rows(mixed $rows, int $max): array { return is_array($rows) ? array_slice(array_values($rows), -$max) : []; }
    private static function id(mixed $id): string
    {
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id) ? $id : bin2hex(random_bytes(8));
    }
    public static function action(mixed $a): ?array
    {
        $allowed = ['planner', 'calculator', 'nutrition', 'progress', 'recovery', 'log_water', 'start_timer', 'update_goal'];
        if (!is_array($a) || !in_array($a['type'] ?? null, $allowed, true)) return null;
        $args = [];
        $input = is_array($a['args'] ?? null) ? $a['args'] : [];
        if ($a['type'] === 'log_water') $args['glasses'] = (int) self::number($input['glasses'] ?? null, 1, 2, 1);
        if ($a['type'] === 'start_timer') $args['seconds'] = (int) self::number($input['seconds'] ?? null, 30, 180, 60);
        if ($a['type'] === 'update_goal') {
            if (!in_array($input['goal'] ?? null, ['lose', 'maintain', 'gain'], true)) return null;
            $args['goal'] = $input['goal'];
        }
        return ['id' => self::id($a['id'] ?? null), 'type' => $a['type'], 'args' => (object) $args, 'done' => ($a['done'] ?? false) === true];
    }
    public static function clean(array $raw): array
    {
        if (($raw['version'] ?? null) !== 2) throw new ApiException(422, 'نسخه ساختار داده پشتیبانی نمی‌شود.', 'invalid_state');
        $now = Http::now();
        $ex = FitnessService::exercises();
        $s = ['version' => 2, 'profile' => null, 'water' => [], 'weights' => [], 'sessions' => [], 'favorites' => [], 'meals' => [], 'mealChoices' => [0, 0, 0, 0], 'diet' => 'regular', 'events' => [], 'seenEventsAt' => 0, 'chat' => [], 'settings' => ['theme' => 'dark', 'reduced' => false, 'waterGoal' => 8], 'activeSession' => null];
        if (is_array($raw['profile'] ?? null)) $s['profile'] = FitnessService::profile($raw['profile']);
        foreach (array_slice(is_array($raw['water'] ?? null) ? $raw['water'] : [], -365, null, true) as $date => $n) {
            if (self::date($date)) $s['water'][$date] = (int) self::number($n, 0, 20);
        }
        $weights = [];
        foreach (self::rows($raw['weights'] ?? null, 1000) as $w) {
            if (!is_array($w) || !self::date($w['date'] ?? null) || !is_numeric($w['value'] ?? null)) continue;
            $value = (float) $w['value'];
            if (!is_finite($value) || $value < 35 || $value > 250) continue;
            $weights[$w['date']] = ['date' => $w['date'], 'value' => round($value, 1), 'note' => Http::text($w['note'] ?? '', 120)];
        }
        ksort($weights);
        $s['weights'] = array_values($weights);
        foreach (self::rows($raw['sessions'] ?? null, 1000) as $r) {
            if (!is_array($r) || !is_numeric($r['endedAt'] ?? null)) continue;
            $s['sessions'][] = ['id' => self::id($r['id'] ?? null), 'name' => Http::text($r['name'] ?? '', 80), 'startedAt' => self::number($r['startedAt'] ?? null, 0, $now + 86400000, $now), 'endedAt' => self::number($r['endedAt'], 0, $now + 86400000, $now), 'duration' => (int) self::number($r['duration'] ?? null, 0, 21600), 'count' => (int) self::number($r['count'] ?? null, 1, 10, 4), 'dayIndex' => (int) self::number($r['dayIndex'] ?? null, 0, 6)];
        }
        foreach (self::rows($raw['favorites'] ?? null, 24) as $id) if (is_string($id) && isset($ex[$id]) && !in_array($id, $s['favorites'], true)) $s['favorites'][] = $id;
        foreach (array_slice(is_array($raw['meals'] ?? null) ? $raw['meals'] : [], -365, null, true) as $date => $rows) {
            if (!self::date($date)) continue;
            $day = [];
            foreach (self::rows($rows, 4) as $m) {
                if (!is_array($m) || !is_int($m['slot'] ?? null) || $m['slot'] < 0 || $m['slot'] > 3) continue;
                $ingredients = [];
                foreach (self::rows($m['ingredients'] ?? null, 12) as $pair) {
                    if (is_array($pair) && is_string($pair[0] ?? null) && is_numeric($pair[1] ?? null)) $ingredients[] = [Http::text($pair[0], 70), self::number((float) $pair[1], 0, 3000)];
                }
                $day[$m['slot']] = ['slot' => $m['slot'], 'id' => Http::text($m['id'] ?? '', 40), 'name' => Http::text($m['name'] ?? '', 80), 'kcal' => self::number($m['kcal'] ?? null, 0, 4000), 'p' => self::number($m['p'] ?? null, 0, 300), 'c' => self::number($m['c'] ?? null, 0, 600), 'f' => self::number($m['f'] ?? null, 0, 200), 'ingredients' => $ingredients];
            }
            $s['meals'][$date] = array_values($day);
        }
        for ($i = 0; $i < 4; $i++) $s['mealChoices'][$i] = ($raw['mealChoices'][$i] ?? 0) === 1 ? 1 : 0;
        $s['diet'] = ($raw['diet'] ?? '') === 'vegetarian' ? 'vegetarian' : 'regular';
        foreach (self::rows($raw['events'] ?? null, 40) as $e) {
            if (!is_array($e) || !is_string($e['message'] ?? null)) continue;
            $s['events'][] = ['message' => Http::text($e['message'], 200), 'at' => self::number($e['at'] ?? null, 0, $now + 86400000, $now), 'type' => in_array($e['type'] ?? null, ['check', 'drop', 'dumbbell', 'scale', 'settings', 'spark', 'leaf'], true) ? $e['type'] : 'check'];
        }
        $s['seenEventsAt'] = self::number($raw['seenEventsAt'] ?? null, 0, $now + 86400000);
        foreach (['coordinator', 'trainer', 'nutrition', 'recovery'] as $role) {
            $s['chat'][$role] = [];
            foreach (self::rows($raw['chat'][$role] ?? null, 40) as $m) {
                if (!is_array($m) || !in_array($m['role'] ?? null, ['user', 'assistant'], true) || !is_string($m['content'] ?? null)) continue;
                $actions = array_values(array_filter(array_map([self::class, 'action'], self::rows($m['actions'] ?? null, 3))));
                $s['chat'][$role][] = ['id' => self::id($m['id'] ?? null), 'role' => $m['role'], 'content' => Http::text($m['content'], 6000), 'at' => self::number($m['at'] ?? null, 0, $now + 86400000, $now), 'mode' => ($m['mode'] ?? '') === 'cloud' ? 'cloud' : 'local', 'actions' => $actions];
            }
        }
        $settings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        $s['settings'] = ['theme' => ($settings['theme'] ?? '') === 'light' ? 'light' : 'dark', 'reduced' => ($settings['reduced'] ?? false) === true, 'waterGoal' => (int) self::number($settings['waterGoal'] ?? null, 4, 16, 8)];
        $a = $raw['activeSession'] ?? null;
        if (is_array($a) && is_numeric($a['startedAt'] ?? null) && $now - (float) $a['startedAt'] < 86400000 && (float) $a['startedAt'] <= $now + 60000) {
            $items = [];
            $checked = [];
            foreach (self::rows($a['items'] ?? null, 8) as $i => $it) {
                if (!is_array($it) || !is_string($it['id'] ?? null) || !isset($ex[$it['id']])) continue;
                $items[] = ['id' => $it['id'], 'sets' => (int) self::number($it['sets'] ?? null, 1, 4, 2), 'reps' => Http::text($it['reps'] ?? '', 40)];
                $checked[] = ($a['checked'][$i] ?? false) === true;
            }
            if ($items) $s['activeSession'] = ['id' => self::id($a['id'] ?? null), 'name' => Http::text($a['name'] ?? '', 80), 'dayIndex' => (int) self::number($a['dayIndex'] ?? null, 0, 6), 'items' => $items, 'checked' => $checked, 'startedAt' => (float) $a['startedAt'], 'pausedMs' => self::number($a['pausedMs'] ?? null, 0, 86400000), 'pausedAt' => isset($a['pausedAt']) ? self::number($a['pausedAt'], 0, $now + 60000, $now) : null];
        }
        // Empty day maps must serialize as objects, not lists.
        $s['water'] = (object) $s['water'];
        $s['meals'] = (object) $s['meals'];
        return $s;
    }
}
