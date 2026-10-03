<?php
declare(strict_types=1);
namespace FitBot;

final class FitnessService
{
    public const DEFAULT_PROFILE = ['name' => '', 'gender' => 'male', 'age' => 25, 'height' => 175, 'weight' => 75, 'activity' => 1.55, 'goal' => 'maintain', 'days' => 3, 'equipment' => 'home', 'level' => 'beginner', 'duration' => 35];
    public static function profile(array $raw): array
    {
        $p = self::DEFAULT_PROFILE;
        $p['name'] = trim(Http::text($raw['name'] ?? '', 32));
        foreach (['age' => [18, 85], 'height' => [120, 230], 'weight' => [35, 250]] as $key => [$low, $high]) {
            $v = $raw[$key] ?? $p[$key];
            if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < $low || $v > $high) throw new ApiException(422, 'سن، قد یا وزن در بازه پشتیبانی‌شده نیست؛ این ابزار برای بزرگسالان است.', 'invalid_profile');
            if ($key === 'age' && floor((float) $v) !== (float) $v) throw new ApiException(422, 'سن باید یک عدد صحیح باشد.', 'invalid_profile');
            $p[$key] = $v;
        }
        $options = ['gender' => ['male', 'female'], 'activity' => [1.2, 1.375, 1.55, 1.725, 1.9], 'goal' => ['lose', 'maintain', 'gain'], 'days' => [2, 3, 4, 5], 'equipment' => ['home', 'dumbbell', 'gym'], 'level' => ['beginner', 'intermediate'], 'duration' => [20, 35, 50, 60]];
        foreach ($options as $key => $choices) {
            $value = $raw[$key] ?? $p[$key];
            if (!in_array($value, $choices, true)) throw new ApiException(422, 'یکی از گزینه‌های مشخصات یا برنامه معتبر نیست.', 'invalid_profile');
            $p[$key] = $value;
        }
        return $p;
    }
    public static function estimates(array $p): array
    {
        $bmr = 10 * $p['weight'] + 6.25 * $p['height'] - 5 * $p['age'] + ($p['gender'] === 'male' ? 5 : -161);
        $tdee = $bmr * $p['activity'];
        $bmi = $p['weight'] / ($p['height'] / 100) ** 2;
        $goal = $bmi < 18.5 && $p['goal'] === 'lose' ? 'maintain' : $p['goal'];
        $base = $tdee * ($goal === 'lose' ? .85 : ($goal === 'gain' ? 1.08 : 1));
        $floor = $p['gender'] === 'male' ? 1500 : 1200;
        $target = (int) round(max($base, $floor) / 10) * 10;
        $protein = (int) round(min($p['weight'] * ($goal === 'gain' ? 1.8 : 1.6), $target * .32 / 4));
        $fat = (int) round($target * .27 / 9);
        $carbs = max(0, (int) round(($target - $protein * 4 - $fat * 9) / 4));
        return ['bmr' => (int) round($bmr), 'tdee' => (int) round($tdee), 'target' => $target, 'protein' => $protein, 'carbs' => $carbs, 'fat' => $fat, 'bmi' => $bmi, 'effectiveGoal' => $goal, 'floored' => $base < $floor, 'lowBMI' => $bmi < 18.5];
    }
    public static function exercises(): array
    {
        static $indexed = null;
        if ($indexed === null) {
            $rows = json_decode(file_get_contents(__DIR__ . '/data/exercises.json'), true, 32, JSON_THROW_ON_ERROR);
            $indexed = array_column($rows, null, 'id');
        }
        return $indexed;
    }
    public static function plan(array $p): array
    {
        $beginner = $p['level'] === 'beginner';
        $push = $beginner ? 'incline' : 'pushup';
        if ($p['equipment'] === 'home') {
            $full = [['squat', $push, 'bird', 'bridge', 'deadbug', 'calf'], ['lunge', $push, 'bird', 'calf', 'plank', 'bridge'], ['squat', 'bridge', $push, 'deadbug', 'bird', 'crunch']];
            $upper = [$push, 'bird', 'deadbug', 'plank', 'crunch'];
            $lower = ['squat', 'bridge', 'lunge', 'calf', 'deadbug'];
        } elseif ($p['equipment'] === 'dumbbell') {
            $full = [['squat', 'dbbench', 'dbrow', 'bridge', 'curl', 'deadbug'], ['lunge', 'dbbench', 'dbrow', 'calf', 'lateral', 'plank'], ['squat', 'dbrow', 'dbbench', $beginner ? 'bridge' : 'rdl', 'curl', 'crunch']];
            $upper = ['dbbench', 'dbrow', 'lateral', 'curl', 'plank', 'deadbug'];
            $lower = ['squat', $beginner ? 'bridge' : 'rdl', 'lunge', 'calf', 'deadbug'];
        } else {
            $full = [['legpress', $beginner ? 'dbbench' : 'bench', 'latpull', 'bridge', 'curl', 'plank'], [$beginner ? 'squat' : 'bbsquat', 'fly', 'latpull', 'calf', 'pushdown', 'deadbug'], ['legpress', 'dbbench', $beginner ? 'dbrow' : 'bbrow', 'lateral', 'curl', 'crunch']];
            $upper = [$beginner ? 'dbbench' : 'bench', 'latpull', 'lateral', 'curl', 'pushdown', 'plank'];
            $lower = ['legpress', $beginner ? 'squat' : 'bbsquat', 'bridge', 'calf', 'deadbug'];
        }
        $schedules = [2 => [0, 3], 3 => [0, 2, 4], 4 => [0, 1, 3, 4], 5 => [0, 1, 3, 4, 5]];
        $count = match ($p['duration']) {20 => 3, 35 => 4, 50 => 5, default => 6};
        $sets = $p['duration'] === 20 ? 2 : ($beginner ? ($p['duration'] >= 50 ? 3 : 2) : 3);
        $exercises = self::exercises();
        $result = [];
        foreach ($schedules[$p['days']] as $i => $day) {
            $recovery = $p['days'] === 5 && $i === 4;
            $name = $recovery ? 'تحرک و میان‌تنه' : ($p['days'] <= 3 ? 'فول‌بادی ' . ['A', 'B', 'C'][$i] : ($i % 2 === 0 ? 'بالاتنه' : 'پایین‌تنه') . ' ' . ($i < 2 ? 'A' : 'B'));
            $pool = $recovery ? ['bird', 'deadbug', 'bridge', 'calf'] : ($p['days'] <= 3 ? $full[$i] : ($i % 2 === 0 ? $upper : $lower));
            $items = array_map(static fn(string $id): array => ['id' => $id, 'sets' => $recovery ? 2 : $sets, 'reps' => $exercises[$id]['reps'] ?? ($p['goal'] === 'gain' ? '۸–۱۲' : '۱۰–۱۲')], array_slice($pool, 0, $count));
            $result[] = ['dayIndex' => $day, 'name' => $name, 'duration' => $recovery ? 20 : $p['duration'], 'recovery' => $recovery, 'items' => $items];
        }
        return $result;
    }
    public static function calculate(array $raw): array
    {
        $p = self::profile($raw);
        return ['profile' => $p, 'estimates' => self::estimates($p), 'plan' => self::plan($p), 'engine' => 'php', 'medicalDisclaimer' => 'برآورد عمومی بزرگسالان است، نه ارزیابی یا نسخه پزشکی.'];
    }
}
