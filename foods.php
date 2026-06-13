<?php
// foods.php — Food library: browse, create, and edit foods with optional USDA lookup.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

$db = get_db();
$errors = [];
$success = '';

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$new = isset($_GET['new']);

$food = null;
if ($edit_id) {
    $stmt = $db->prepare('SELECT * FROM foods WHERE id = ?');
    $stmt->execute([$edit_id]);
    $food = $stmt->fetch();
    if (!$food) { $edit_id = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $edit_id) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM meal_items WHERE food_id = ?');
        $stmt->execute([$edit_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Cannot delete: this food is used in logged meals.';
        } else {
            $db->prepare('DELETE FROM foods WHERE id = ?')->execute([$edit_id]);
            header('Location: foods.php?deleted=1' . u_amp());
            exit;
        }
    } elseif ($action === 'save') {
        $name     = trim($_POST['name'] ?? '');
        $qty_desc = trim($_POST['quantity_description'] ?? '');
        $grams    = $_POST['grams'] !== '' ? (float)$_POST['grams'] : null;
        $fiber    = (float)($_POST['grams_fiber'] ?? 0);
        $protein  = (float)($_POST['grams_protein'] ?? 0);
        $produce  = (float)($_POST['servings_produce'] ?? 0);

        if (!$name) { $errors[] = 'Name is required.'; }

        if (empty($errors)) {
            if ($edit_id) {
                $db->prepare("
                    UPDATE foods SET name=?, quantity_description=?, grams=?,
                           grams_fiber=?, grams_protein=?, servings_produce=?
                    WHERE id=?
                ")->execute([$name, $qty_desc ?: null, $grams, $fiber, $protein, $produce, $edit_id]);
                $success = 'Food updated.';
                $stmt = $db->prepare('SELECT * FROM foods WHERE id = ?');
                $stmt->execute([$edit_id]);
                $food = $stmt->fetch();
            } else {
                $db->prepare("
                    INSERT INTO foods (name, quantity_description, grams, grams_fiber, grams_protein, servings_produce)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$name, $qty_desc ?: null, $grams, $fiber, $protein, $produce]);
                header('Location: foods.php?saved=1' . u_amp());
                exit;
            }
        }
    }
}

$search = trim($_GET['s'] ?? '');
if ($search) {
    $stmt = $db->prepare("SELECT * FROM foods WHERE name LIKE ? ORDER BY name");
    $stmt->execute(['%' . $search . '%']);
} else {
    $stmt = $db->query("SELECT * FROM foods ORDER BY name");
}
$foods = $stmt->fetchAll();

$editing  = $edit_id || $new;
$form_food = $food ?? [];

page_header('Foods', 'foods');
?>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Food saved.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Food deleted.</div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<?php if ($editing): ?>

<?php if (!$edit_id && defined('USDA_API_KEY') && USDA_API_KEY !== ''): ?>
<div class="card">
    <h2>Search USDA Database</h2>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:10px">Search to pre-fill the form below. Fiber and protein scale automatically when you adjust grams.</p>
    <div style="position:relative">
        <label for="usda-search" class="sr-only">Search USDA food database</label>
        <input type="text" id="usda-search" placeholder="e.g. banana, chicken breast, oats…" autocomplete="off"
               aria-autocomplete="list" aria-controls="usda-results"
               style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
        <div id="usda-results" class="search-results" role="listbox" aria-live="polite"></div>
    </div>
    <div id="usda-status" style="font-size:.8rem;color:var(--muted);margin-top:6px;min-height:1.2em" aria-live="polite"></div>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= $edit_id ? 'Edit Food' : 'New Food' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <?= csrf_field() ?>
        <?php $prefix = ''; $autofocus = !$edit_id; include __DIR__ . '/_food_form_fields.php'; ?>
        <div style="display:flex;gap:10px;margin-top:4px">
            <button type="submit" class="btn btn-primary" style="flex:1">Save Food</button>
            <a href="foods.php" class="btn btn-secondary">Cancel</a>
            <?php if ($edit_id): ?>
                <button type="submit" name="action" value="delete" class="btn btn-danger"
                        onclick="return confirm('Delete this food?')">Delete</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (defined('USDA_API_KEY') && USDA_API_KEY !== ''): ?>
<script>
initUsdaSearch('usda-search', 'usda-results', 'usda-status', '');
</script>
<?php endif; ?>

<?php else: ?>
<div class="card">
    <div class="foods-toolbar">
        <form method="get">
            <label for="foods-search" class="sr-only">Search foods</label>
            <input type="text" id="foods-search" name="s" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search foods…"
                   style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
        </form>
        <a href="foods.php?new=1" class="btn btn-primary btn-sm">+ New</a>
    </div>

    <?php if (empty($foods)): ?>
        <p class="empty">No foods yet. <a href="foods.php?new=1">Add one</a>.</p>
    <?php else: ?>

        <!-- Table: wider screens -->
        <table class="foods-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="text-align:right">Grams</th>
                    <th style="text-align:right">Fiber</th>
                    <th style="text-align:right">Protein</th>
                    <th style="text-align:right">Produce</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($foods as $f): ?>
                <tr>
                    <td>
                        <a href="foods.php?edit=<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></a>
                        <?php if ($f['quantity_description']): ?>
                            <div style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($f['quantity_description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right"><?= $f['grams'] !== null ? round($f['grams'], 1).'g' : '—' ?></td>
                    <td style="text-align:right"><?= round($f['grams_fiber'], 1) ?>g</td>
                    <td style="text-align:right"><?= round($f['grams_protein'], 1) ?>g</td>
                    <td style="text-align:right"><?= round($f['servings_produce'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Cards: narrow screens -->
        <div class="foods-cards">
            <?php foreach ($foods as $f): ?>
            <div class="food-card">
                <div class="food-card-name">
                    <a href="foods.php?edit=<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></a>
                    <?php if ($f['quantity_description']): ?>
                        <span class="food-card-qty"><?= htmlspecialchars($f['quantity_description']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="food-card-stats">
                    <?php if ($f['grams'] !== null): ?><span><?= round($f['grams'], 1) ?>g</span><?php endif; ?>
                    · <span><?= round($f['grams_fiber'], 1) ?>g</span> fib
                    · <span><?= round($f['grams_protein'], 1) ?>g</span> pro
                    <?php if ($f['servings_produce'] > 0): ?>
                        · <span><?= round($f['servings_produce'], 1) ?></span> prod
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer('foods'); ?>
