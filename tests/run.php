<?php
declare(strict_types=1);
// Pure PHP tests; no Node.js, Composer or web server needed.
$storage = __DIR__ . '/.runtime-' . bin2hex(random_bytes(5));
putenv('STORAGE_PATH=' . $storage);
putenv('DB_DRIVER=sqlite'); // tests always run on a throwaway SQLite database
require dirname(__DIR__) . '/app/bootstrap.php';
use FitBot\{ApiException, AgentService, Database, FitnessService, StateValidator};
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $checks++;
}
function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) cleanup($p); else unlink($p);
    }
    rmdir($dir);
}
try {
    $p = FitnessService::profile([]);
    $e = FitnessService::estimates($p);
    check($e['bmr'] === 1724 && $e['target'] === 2670, 'Mifflin–St Jeor and rounded target');
    check(abs($e['protein'] * 4 + $e['carbs'] * 4 + $e['fat'] * 9 - $e['target']) <= 4, 'macro rounding');
    check(count(FitnessService::exercises()) === 24, 'exercise catalog');
    foreach (['home', 'dumbbell', 'gym'] as $equipment) foreach ([2, 3, 4, 5] as $days) foreach ([20, 35, 50, 60] as $duration) foreach (['beginner', 'intermediate'] as $level) foreach (['lose', 'maintain', 'gain'] as $goal) {
        $profile = [...$p, 'equipment' => $equipment, 'days' => $days, 'duration' => $duration, 'level' => $level, 'goal' => $goal];
        $plan = FitnessService::plan($profile);
        check(count($plan) === $days, 'weekly day count');
        foreach ($plan as $day) {
            check(count($day['items']) >= 3, 'minimum exercise count');
            foreach ($day['items'] as $item) {
                $ex = FitnessService::exercises()[$item['id']] ?? null;
                check($ex !== null, 'known exercise');
                check($equipment === 'gym' || $equipment === 'dumbbell' && in_array($ex['equip'], ['home', 'dumbbell'], true) || $equipment === 'home' && $ex['equip'] === 'home', 'equipment constraint');
            }
        }
    }
    foreach ([['age' => 17], ['height' => 0], ['weight' => -20], ['goal' => '__proto__'], ['days' => 10]] as $invalid) {
        $rejected = false;
        try { FitnessService::profile($invalid); } catch (ApiException $x) { $rejected = $x->status === 422; }
        check($rejected, 'invalid profile rejected');
    }
    $low = FitnessService::estimates([...$p, 'weight' => 45, 'height' => 180, 'goal' => 'lose']);
    check($low['lowBMI'] && $low['effectiveGoal'] === 'maintain', 'no underweight deficit');
    $state = StateValidator::clean(['version' => 2, 'profile' => $p, 'apiKey' => 'DO_NOT_STORE', 'favorites' => ['squat', '__proto__'], 'weights' => [['date' => '2025-01-03', 'value' => 75.3, 'note' => '<img src=x>'], ['date' => '2025-13-32', 'value' => 50]], 'chat' => ['trainer' => [['id' => 'test-1', 'role' => 'assistant', 'content' => '<script>alert(1)</script>', 'actions' => [['type' => 'delete_all_data'], ['type' => 'log_water', 'args' => ['glasses' => 100]]]]]]]);
    check(!array_key_exists('apiKey', $state), 'unknown credentials discarded');
    check(count($state['weights']) === 1 && $state['favorites'] === ['squat'], 'dates and IDs validated');
    check(count($state['chat']['trainer'][0]['actions']) === 1, 'stored actions allowlisted');
    check($state['chat']['trainer'][0]['actions'][0]['args']->glasses === 2, 'stored action bounds');
    check(AgentService::proposedAction(['function' => ['name' => 'start_timer', 'arguments' => '{"seconds":-1}']]) === null, 'invalid model tool rejected');
    check(AgentService::proposedAction(['function' => ['name' => 'delete_all_data', 'arguments' => '{}']]) === null, 'unknown model tool rejected');
    check(AgentService::proposedAction(['function' => ['name' => 'log_water', 'arguments' => '{"glasses":1}']])['type'] === 'log_water', 'valid model proposal accepted');
    $pdo = Database::connection();
    $hash = password_hash('Example-Test-Password', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(email,name,password_hash,created_at) VALUES(?,?,?,?)')->execute(['test@example.invalid', 'Test', $hash, 1]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_states(user_id,data,updated_at) VALUES(?,?,?)')->execute([$uid, 'null', 1]);
    check(Database::userState($uid)['state'] === null, 'new user has no fabricated records');
    $saved = Database::saveState($uid, json_decode(json_encode($state), true), 0);
    check($saved['revision'] === 1 && Database::userState($uid)['state']['profile']['weight'] === 75, 'SQLite state round trip');
    $conflict = false;
    try { Database::saveState($uid, ['version' => 2], 0); } catch (ApiException $x) { $conflict = $x->status === 409; }
    check($conflict && Database::userState($uid)['revision'] === 1, 'optimistic concurrency rejects stale writes');
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    check((int) $pdo->query('SELECT COUNT(*) FROM user_states')->fetchColumn() === 0, 'account deletion cascades state');
    $pdo = null; Database::close();
    cleanup($storage);
    echo "PASS: $checks PHP checks; all 288 planner combinations verified.\n";
} catch (Throwable $error) {
    $pdo = null; Database::close();
    cleanup($storage);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
