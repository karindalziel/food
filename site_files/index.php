<?php
// index.php — Today dashboard: daily nutrition totals vs goals, and meal list for today.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

$db = get_db();
$person = get_active_person();

if (!$person) {
    header('Location: settings.php');
    exit;
}

$today_param = $_GET['today'] ?? '';
$today = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today_param) && strtotime($today_param)) ? $today_param : date('Y-m-d');
$totals = daily_totals((int)$person['id'], $today);
$has_empty = has_empty_meals((int)$person['id'], $today, $today);

$stmt = $db->prepare("
    SELECT m.id, m.eaten_at, m.notes, m.is_planned,
           COALESCE(SUM(f.grams_fiber * mi.portion_multiplier), 0) AS fiber,
           COALESCE(SUM(f.grams_protein * mi.portion_multiplier), 0) AS protein,
           COALESCE(SUM(f.servings_produce * mi.portion_multiplier), 0) AS produce,
           COUNT(mi.id) AS item_count
    FROM meals m
    LEFT JOIN meal_items mi ON mi.meal_id = m.id
    LEFT JOIN foods f ON f.id = mi.food_id
    WHERE m.person_id = ? AND DATE(m.eaten_at) = ?
    GROUP BY m.id
    ORDER BY m.eaten_at DESC
");
$stmt->execute([$person['id'], $today]);
$meals = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT mi.meal_id, f.name, mi.portion_multiplier
    FROM meal_items mi JOIN foods f ON f.id = mi.food_id
    JOIN meals m ON m.id = mi.meal_id
    WHERE m.person_id = ? AND DATE(m.eaten_at) = ?
    ORDER BY mi.id
");
$stmt->execute([$person['id'], $today]);
$meal_foods = [];
foreach ($stmt->fetchAll() as $row) {
    $meal_foods[$row['meal_id']][] = $row;
}

page_header('Today', 'today');
?><script>ensureLocalDate();</script>

<div class="card">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <h2 style="margin:0">Today — <?= date('F j, Y', strtotime($today)) ?></h2>
        <?php if ($has_empty): ?>
            <span role="img" aria-label="Warning: calculations do not include missing meal data" style="cursor:help;font-size:1.1rem">⚠️</span>
        <?php endif; ?>
    </div>
    <?php
    $goals_missing  = !goal_bar('Fiber',   (float)$totals['fiber'],   (float)$person['goal_fiber_g'],         'g',   'fiber');
    $goals_missing |= !goal_bar('Protein', (float)$totals['protein'], (float)$person['goal_protein_g'],       'g',   'protein');
    $goals_missing |= !goal_bar('Produce', (float)$totals['produce'], (float)$person['goal_produce_servings'], 'srv', 'produce');
    if ($goals_missing): ?>
        <p class="goals-missing-note">Some goals are not set. <a href="settings.php">Configure in Settings</a>.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Meals Today</h2>
    <?php if (empty($meals)): ?>
        <p class="empty">No meals logged yet today.</p>
    <?php else: ?>
        <?php foreach ($meals as $meal): include __DIR__ . '/_meal_row.php'; endforeach; ?>
    <?php endif; ?>
</div>

<a href="log.php<?= u_qs() ?>" class="fab" title="Log a meal">+</a>

<?php page_footer('today'); ?>
