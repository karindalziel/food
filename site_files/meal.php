<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

$db = get_db();
$person = get_active_person();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM meals WHERE id = ? AND person_id = ?');
$stmt->execute([$id, $person['id'] ?? 0]);
$meal = $stmt->fetch();

if (!$meal) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("
    SELECT mi.id, mi.portion_multiplier,
           f.name, f.quantity_description, f.grams, f.grams_fiber, f.grams_protein, f.servings_produce
    FROM meal_items mi JOIN foods f ON f.id = mi.food_id
    WHERE mi.meal_id = ?
    ORDER BY mi.id
");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$total_fiber = $total_protein = $total_produce = 0;
foreach ($items as $item) {
    $m = $item['portion_multiplier'];
    $total_fiber   += $item['grams_fiber']      * $m;
    $total_protein += $item['grams_protein']    * $m;
    $total_produce += $item['servings_produce'] * $m;
}

page_header('Meal Detail', '');
?>

<div class="card">
    <h2><?= date('l, F j · g:i a', strtotime($meal['eaten_at'])) ?></h2>
    <?php if ($meal['notes']): ?>
        <p style="color:var(--muted);margin-bottom:12px"><?= htmlspecialchars($meal['notes']) ?></p>
    <?php endif; ?>
    <?php if (empty($items)): ?>
        <p style="color:var(--warn);font-size:.9rem"><span role="img" aria-label="Warning">⚠️</span> No foods logged for this meal.</p>
    <?php else: ?>
    <div class="nutrition-pills">
        <span class="pill pill-fiber"><?= round($total_fiber, 2) ?>g fiber</span>
        <span class="pill pill-protein"><?= round($total_protein, 2) ?>g protein</span>
        <span class="pill pill-produce"><?= round($total_produce, 2) ?> produce</span>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($items)): ?>
<div class="card">
    <h2>Foods</h2>
    <?php foreach ($items as $item): ?>
        <?php $m = $item['portion_multiplier']; ?>
        <div class="meal-item">
            <div style="flex:1">
                <div class="food-name"><?= htmlspecialchars($item['name']) ?></div>
                <div class="food-meta">
                    <?= htmlspecialchars($item['quantity_description'] ?? '') ?>
                    <?php if ($m != 1): ?> × <?= $m ?><?php endif; ?>
                    <?php if ($item['grams'] > 0): ?>
                        (<?= round($item['grams'] * $m, 2) ?>g)
                    <?php endif; ?>
                </div>
                <div class="nutrition-pills">
                    <span class="pill pill-fiber"><?= round($item['grams_fiber'] * $m, 2) ?>g fiber</span>
                    <span class="pill pill-protein"><?= round($item['grams_protein'] * $m, 2) ?>g protein</span>
                    <span class="pill pill-produce"><?= round($item['servings_produce'] * $m, 2) ?> produce</span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<a href="log.php?edit=<?= $id ?>" class="btn btn-primary btn-block">Edit this meal</a>

<?php page_footer(''); ?>
