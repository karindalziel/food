<?php
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
    SELECT m.id, m.eaten_at, m.notes,
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
    <?php goal_bar('Fiber', (float)$totals['fiber'], (float)$person['goal_fiber_g'], 'g', 'fiber'); ?>
    <?php goal_bar('Protein', (float)$totals['protein'], (float)$person['goal_protein_g'], 'g', 'protein'); ?>
    <?php goal_bar('Produce', (float)$totals['produce'], (float)$person['goal_produce_servings'], 'srv', 'produce'); ?>
</div>

<div class="card">
    <h2>Meals Today</h2>
    <?php if (empty($meals)): ?>
        <p class="empty">No meals logged yet today.</p>
    <?php else: ?>
        <?php foreach ($meals as $meal): ?>
            <div class="meal-item">
                <div>
                    <div class="food-name">
                        <a href="meal.php?id=<?= $meal['id'] ?><?= u_amp() ?>">
                            <?= date('g:i a', strtotime($meal['eaten_at'])) ?>
                        </a>
                        <?php if ($meal['notes']): ?>
                            — <span style="color:var(--muted);font-size:.9rem"><?= htmlspecialchars($meal['notes']) ?></span>
                        <?php endif; ?>
                        <?php if ($meal['item_count'] == 0): ?>
                            <span role="img" aria-label="Warning: no foods logged for this meal, calculations do not include this meal"
                                  style="cursor:help;font-size:.9rem;margin-left:4px">⚠️</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($meal_foods[$meal['id']])): ?>
                    <div style="font-size:.75rem;color:var(--muted);margin:3px 0 5px;line-height:1.5">
                        <?php $parts = []; foreach ($meal_foods[$meal['id']] as $fi):
                            $label = htmlspecialchars($fi['name']);
                            if ((float)$fi['portion_multiplier'] != 1.0) $label .= ' ×' . rtrim(rtrim(number_format((float)$fi['portion_multiplier'], 1), '0'), '.');
                            $parts[] = $label;
                        endforeach; echo implode(', ', $parts); ?>
                    </div>
                    <?php endif; ?>
                    <div class="nutrition-pills">
                        <span class="pill pill-fiber"><?= round($meal['fiber'], 2) ?>g fiber</span>
                        <span class="pill pill-protein"><?= round($meal['protein'], 2) ?>g protein</span>
                        <span class="pill pill-produce"><?= round($meal['produce'], 2) ?> produce</span>
                    </div>
                </div>
                <a href="log.php?edit=<?= $meal['id'] ?><?= u_amp() ?>" class="btn btn-secondary btn-sm">Edit</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<a href="log.php<?= u_qs() ?>" class="fab" title="Log a meal">+</a>

<?php page_footer('today'); ?>
