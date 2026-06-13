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

// Week navigation — ISO week starts Monday
$today_param = $_GET['today'] ?? '';
$today = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today_param) && strtotime($today_param)) ? $today_param : date('Y-m-d');
$week_offset = (int)($_GET['w'] ?? 0);
$monday_this_week = date('N') === '1' ? $today : date('Y-m-d', strtotime('monday this week'));
$week_start = date('Y-m-d', strtotime($monday_this_week . ' ' . ($week_offset * 7) . ' days'));
$week_end   = date('Y-m-d', strtotime($week_start . ' +6 days'));

$view_date = $_GET['date'] ?? $today;

$weekly = weekly_totals((int)$person['id'], $week_start);
$by_day = [];
foreach ($weekly as $row) { $by_day[$row['day']] = $row; }

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime($week_start . " +$i days"));
}

$week_fiber   = array_sum(array_column($weekly, 'fiber'));
$week_protein = array_sum(array_column($weekly, 'protein'));
$week_produce = array_sum(array_column($weekly, 'produce'));

$week_has_empty = has_empty_meals((int)$person['id'], $week_start, $week_end);
$day_has_empty  = has_empty_meals((int)$person['id'], $view_date, $view_date);

// Meals for selected day
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
    ORDER BY m.eaten_at
");
$stmt->execute([$person['id'], $view_date]);
$day_meals = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT mi.meal_id, f.name, mi.portion_multiplier
    FROM meal_items mi JOIN foods f ON f.id = mi.food_id
    JOIN meals m ON m.id = mi.meal_id
    WHERE m.person_id = ? AND DATE(m.eaten_at) = ?
    ORDER BY mi.id
");
$stmt->execute([$person['id'], $view_date]);
$meal_foods = [];
foreach ($stmt->fetchAll() as $row) {
    $meal_foods[$row['meal_id']][] = $row;
}

$day_totals = daily_totals((int)$person['id'], $view_date);

page_header('Reports', 'reports');
?><script>ensureLocalDate();</script>

<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <a href="reports.php?w=<?= $week_offset - 1 ?><?= u_amp() ?>" class="btn btn-secondary btn-sm">← Prev</a>
        <span style="font-weight:600;font-size:.95rem">
            <?= date('M j', strtotime($week_start)) ?> – <?= date('M j, Y', strtotime($week_end)) ?>
        </span>
        <?php if ($week_offset < 0): ?>
            <a href="reports.php?w=<?= $week_offset + 1 ?><?= u_amp() ?>" class="btn btn-secondary btn-sm">Next →</a>
        <?php else: ?>
            <span style="width:64px"></span>
        <?php endif; ?>
    </div>

    <!-- 7-day grid -->
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:16px">
        <?php foreach ($days as $d): ?>
            <?php
            $data = $by_day[$d] ?? ['fiber'=>0,'protein'=>0,'produce'=>0];
            $is_today = $d === $today;
            $is_selected = $d === $view_date;
            $has_data = isset($by_day[$d]);
            $day_empty = has_empty_meals((int)$person['id'], $d, $d);
            ?>
            <a href="reports.php?w=<?= $week_offset ?>&date=<?= $d ?><?= u_amp() ?>"
               style="text-decoration:none;text-align:center;padding:6px 2px;border-radius:8px;
                      border:2px solid <?= $is_selected ? 'var(--primary)' : 'transparent' ?>;
                      background:<?= $is_today ? 'var(--primary-light)' : 'transparent' ?>">
                <div style="font-size:.7rem;color:var(--muted)"><?= date('D', strtotime($d)) ?></div>
                <div style="font-size:.85rem;font-weight:<?= $is_today ? '700' : '500' ?>;color:var(--text)"><?= date('j', strtotime($d)) ?></div>
                <?php if ($day_empty): ?>
                    <div style="font-size:.75rem"><span role="img" aria-label="Warning: missing meal data">⚠️</span></div>
                <?php elseif ($has_data): ?>
                    <div style="width:6px;height:6px;border-radius:50%;background:var(--accent);margin:2px auto 0"></div>
                <?php else: ?>
                    <div style="height:8px"></div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Weekly totals -->
    <div style="border-top:1px solid var(--border);padding-top:12px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <div style="font-size:.8rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em">Week Total</div>
            <?php if ($week_has_empty): ?>
                <span role="img" aria-label="Warning: calculations do not include missing meal data" style="cursor:help;font-size:1rem">⚠️</span>
            <?php endif; ?>
        </div>
        <?php goal_bar('Fiber', $week_fiber, $person['goal_fiber_g'] * 7, 'g', 'fiber'); ?>
        <?php goal_bar('Protein', $week_protein, $person['goal_protein_g'] * 7, 'g', 'protein'); ?>
        <?php goal_bar('Produce', $week_produce, $person['goal_produce_servings'] * 7, 'srv', 'produce'); ?>
    </div>
</div>

<!-- Day detail -->
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:8px">
            <h2 style="margin:0"><?= date('l, F j', strtotime($view_date)) ?></h2>
            <?php if ($day_has_empty): ?>
                <span role="img" aria-label="Warning: calculations do not include missing meal data" style="cursor:help;font-size:1rem">⚠️</span>
            <?php endif; ?>
        </div>
        <?php if ($view_date === $today): ?>
            <a href="log.php<?= u_qs() ?>" class="btn btn-primary btn-sm">+ Meal</a>
        <?php endif; ?>
    </div>

    <?php goal_bar('Fiber', (float)$day_totals['fiber'], (float)$person['goal_fiber_g'], 'g', 'fiber'); ?>
    <?php goal_bar('Protein', (float)$day_totals['protein'], (float)$person['goal_protein_g'], 'g', 'protein'); ?>
    <?php goal_bar('Produce', (float)$day_totals['produce'], (float)$person['goal_produce_servings'], 'srv', 'produce'); ?>

    <?php if (empty($day_meals)): ?>
        <p class="empty" style="margin-top:12px">No meals logged.</p>
    <?php else: ?>
        <div style="border-top:1px solid var(--border);margin-top:8px;padding-top:8px">
        <?php foreach ($day_meals as $meal): ?>
            <div class="meal-item">
                <div style="flex:1">
                    <div class="food-name">
                        <?= date('g:i a', strtotime($meal['eaten_at'])) ?>
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
        </div>
    <?php endif; ?>
</div>

<div style="text-align:center;margin-top:4px">
    <a href="export.php?start=<?= $week_start ?>&end=<?= $week_end ?>&person=<?= $person['id'] ?><?= u_amp() ?>"
       class="btn btn-secondary">Export this week as CSV</a>
    &nbsp;
    <a href="export.php?person=<?= $person['id'] ?><?= u_amp() ?>" class="btn btn-secondary">Export all data</a>
</div>

<?php page_footer('reports'); ?>
