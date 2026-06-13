<?php
// log.php — Meal entry and edit form with food search, template loader, and planned-meal detection.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

$db = get_db();
$person = get_active_person();

if (!$person) {
    header('Location: settings.php' . u_qs());
    exit;
}

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$meal = null;
$items = [];

if ($edit_id) {
    $stmt = $db->prepare('SELECT * FROM meals WHERE id = ? AND person_id = ?');
    $stmt->execute([$edit_id, $person['id']]);
    $meal = $stmt->fetch();
    if (!$meal) { $edit_id = null; }

    if ($meal) {
        $stmt = $db->prepare("
            SELECT mi.id, mi.portion_multiplier,
                   f.id AS food_id, f.name, f.quantity_description,
                   f.grams, f.grams_fiber, f.grams_protein, f.servings_produce
            FROM meal_items mi JOIN foods f ON f.id = mi.food_id
            WHERE mi.meal_id = ?
            ORDER BY mi.id
        ");
        $stmt->execute([$edit_id]);
        $items = $stmt->fetchAll();
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_meal' && $edit_id) {
        $db->prepare('DELETE FROM meals WHERE id = ? AND person_id = ?')
           ->execute([$edit_id, $person['id']]);
        header('Location: index.php' . u_qs());
        exit;
    }

    if ($action === 'unplan' && $edit_id) {
        $db->prepare('UPDATE meals SET is_planned = 0 WHERE id = ? AND person_id = ?')
           ->execute([$edit_id, $person['id']]);
        header('Location: log.php?edit=' . $edit_id . u_amp());
        exit;
    }

    if ($action === 'save') {
        $eaten_at_raw   = trim($_POST['eaten_at'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $food_ids       = $_POST['food_id'] ?? [];
        $portions       = $_POST['portion'] ?? [];
        $save_template  = !empty($_POST['save_as_template']);
        $template_name  = trim($_POST['template_name'] ?? '');
        $browser_now_raw = trim($_POST['browser_now'] ?? '');

        $dt = $eaten_at_raw ? DateTime::createFromFormat('Y-m-d\TH:i', $eaten_at_raw) : false;
        if (!$dt) {
            $errors[] = 'Date and time are required.';
        }
        $eaten_at = $dt ? $dt->format('Y-m-d H:i:s') : '';

        // Determine planned status by comparing to browser's current time
        $is_planned = 0;
        if ($dt) {
            $now_dt = DateTime::createFromFormat('Y-m-d\TH:i', $browser_now_raw) ?: new DateTime();
            $is_planned = $dt > $now_dt ? 1 : 0;
        }

        $valid_items = [];
        foreach ($food_ids as $i => $fid) {
            $fid = (int)$fid;
            $portion = (float)($portions[$i] ?? 1);
            if ($fid > 0 && $portion > 0) {
                $valid_items[] = ['food_id' => $fid, 'portion' => $portion];
            }
        }

        if (empty($errors)) {
            if ($edit_id) {
                $db->prepare('UPDATE meals SET eaten_at = ?, notes = ?, is_planned = ? WHERE id = ? AND person_id = ?')
                   ->execute([$eaten_at, $notes ?: null, $is_planned, $edit_id, $person['id']]);
                // Delete all items then re-insert: simpler than diffing, and items have no identity worth preserving.
                $db->prepare('DELETE FROM meal_items WHERE meal_id = ?')->execute([$edit_id]);
                $meal_id = $edit_id;
            } else {
                $db->prepare('INSERT INTO meals (person_id, eaten_at, notes, is_planned) VALUES (?, ?, ?, ?)')
                   ->execute([$person['id'], $eaten_at, $notes ?: null, $is_planned]);
                $meal_id = (int)$db->lastInsertId();
            }

            $ins = $db->prepare('INSERT INTO meal_items (meal_id, food_id, portion_multiplier) VALUES (?, ?, ?)');
            foreach ($valid_items as $item) {
                $ins->execute([$meal_id, $item['food_id'], $item['portion']]);
            }

            // Save as named meal template
            if ($save_template && $template_name && !empty($valid_items)) {
                $db->prepare('INSERT INTO meal_templates (name) VALUES (?)')->execute([$template_name]);
                $tmpl_id = (int)$db->lastInsertId();
                $tins = $db->prepare('INSERT INTO meal_template_items (template_id, food_id, portion_multiplier) VALUES (?, ?, ?)');
                foreach ($valid_items as $item) {
                    $tins->execute([$tmpl_id, $item['food_id'], $item['portion']]);
                }
            }

            header('Location: index.php' . u_qs());
            exit;
        }
    }
}

$default_dt = $meal ? date('Y-m-d\TH:i', strtotime($meal['eaten_at'])) : '';

page_header($edit_id ? 'Edit Meal' : 'Log Meal', 'log');
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<!-- Named meal loader -->
<div class="card">
    <h2>Load Named Meal</h2>
    <div id="template-search-wrap">
        <div style="position:relative">
            <label for="template-search" class="sr-only">Search saved meals</label>
            <input type="text" id="template-search" placeholder="Search saved meals…" autocomplete="off"
                   aria-autocomplete="list" aria-controls="template-results"
                   style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
            <div class="search-results" id="template-results" role="listbox" aria-live="polite"></div>
        </div>
    </div>
</div>

<?php if ($edit_id && !empty($meal['is_planned'])): ?>
<div class="alert" style="background:var(--planned-bg);color:var(--planned-color);display:flex;align-items:center;justify-content:space-between;gap:12px">
    <span>📅 This is a planned meal.</span>
    <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unplan">
        <button type="submit" class="btn btn-sm" style="background:#1a5276;color:#fff;white-space:nowrap">Mark as eaten</button>
    </form>
</div>
<?php endif; ?>

<form method="post" id="meal-form">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="browser_now" id="browser_now">
    <?php /* browser_now is set by JS and compared to eaten_at in PHP to detect future meals.
             The server runs UTC; browser time is the only reliable source for the user's local timezone. */ ?>
    <?= csrf_field() ?>

    <div class="card">
        <h2><?= $edit_id ? 'Edit Meal' : 'Log a Meal' ?></h2>
        <div id="planned-notice" style="display:none;background:var(--planned-bg);color:var(--planned-color);border-radius:8px;padding:8px 12px;font-size:.85rem;margin-bottom:12px">
            📅 This meal is in the future and will be marked as <strong>planned</strong>.
        </div>
        <div class="form-group">
            <label for="eaten_at">Date &amp; Time</label>
            <input type="datetime-local" id="eaten_at" name="eaten_at"
                   value="<?= htmlspecialchars($default_dt) ?>" required>
            <script>
            (function() {
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                const nowStr = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
                document.getElementById('browser_now').value = nowStr;
                <?php if (!$edit_id): ?>
                document.getElementById('eaten_at').value = nowStr;
                <?php endif; ?>
                initPlannedNotice(nowStr);
            })();
            </script>
        </div>
        <div class="form-group">
            <label for="notes">Notes (optional)</label>
            <input type="text" id="notes" name="notes"
                   value="<?= htmlspecialchars($meal['notes'] ?? '') ?>"
                   placeholder="e.g. breakfast, post-workout snack">
        </div>
    </div>

    <div class="card">
        <h2>Foods</h2>
        <p style="font-size:.8rem;color:var(--muted);margin-bottom:10px">
            You can save a meal without foods and add them later.
        </p>

        <div id="items-list">
            <?php foreach ($items as $i => $item): ?>
            <?php
                $m = (float)$item['portion_multiplier'];
                $base_g = (float)$item['grams'];
                $item_g = $base_g > 0 ? round($base_g * $m, 1) : '';
            ?>
            <div class="food-row" data-index="<?= $i ?>">
                <input type="hidden" name="food_id[]" value="<?= $item['food_id'] ?>">
                <div class="meal-item" style="align-items:flex-start">
                    <div style="flex:1">
                        <div class="food-name"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="food-meta"><?= htmlspecialchars($item['quantity_description'] ?? '') ?></div>
                        <div class="nutrition-pills" id="pills-<?= $i ?>">
                            <span class="pill pill-fiber"><?= round($item['grams_fiber'] * $m, 1) ?>g fiber</span>
                            <span class="pill pill-protein"><?= round($item['grams_protein'] * $m, 1) ?>g protein</span>
                            <span class="pill pill-produce"><?= round($item['servings_produce'] * $m, 1) ?> produce</span>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;min-width:155px">
                        <div style="display:flex;gap:4px;align-items:center">
                            <input type="number" name="portion[]" value="<?= $m ?>"
                                   step="0.1" min="0.1"
                                   style="width:72px;padding:8px 6px;border:1px solid var(--border);border-radius:6px 0 0 6px;font-size:.9rem;text-align:center;border-right:none"
                                   data-fiber="<?= $item['grams_fiber'] ?>"
                                   data-protein="<?= $item['grams_protein'] ?>"
                                   data-produce="<?= $item['servings_produce'] ?>"
                                   data-base-grams="<?= $base_g ?>"
                                   data-row="<?= $i ?>"
                                   oninput="portionChanged(this)"
                                   aria-label="Portion multiplier for <?= htmlspecialchars($item['name']) ?>">
                            <span aria-hidden="true" style="padding:6px 4px;border:1px solid var(--border);border-left:none;border-right:none;font-size:.8rem;color:var(--muted);background:#f8f9fa">×</span>
                            <input type="number" id="grams-<?= $i ?>" value="<?= $item_g ?>"
                                   step="0.1" min="0"
                                   style="width:72px;padding:8px 6px;border:1px solid var(--border);border-radius:0 6px 6px 0;font-size:.9rem;text-align:center"
                                   data-row="<?= $i ?>"
                                   oninput="gramsChanged(this)"
                                   placeholder="g"
                                   aria-label="Grams for <?= htmlspecialchars($item['name']) ?>">
                        </div>
                        <div style="font-size:.7rem;color:var(--muted);text-align:right">portion × &nbsp;|&nbsp; grams</div>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">Remove</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Food search -->
        <div style="margin-top:12px">
            <div style="position:relative">
                <label for="food-search" style="display:block;font-size:.85rem;font-weight:500;margin-bottom:4px;color:var(--muted)">Add Food</label>
                <input type="text" id="food-search" placeholder="Search foods…" autocomplete="off"
                       aria-autocomplete="list" aria-controls="search-results"
                       style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
                <div class="search-results" id="search-results" role="listbox" aria-live="polite"></div>
            </div>
            <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
                <button type="button" class="btn btn-secondary btn-sm" onclick="openFoodsModal()">View Foods</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openNewFoodModal()">+ New Food</button>
            </div>
        </div>
    </div>

    <!-- Save as named meal -->
    <div class="card">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <input type="checkbox" name="save_as_template" id="save-as-template"
                   style="width:18px;height:18px;accent-color:var(--primary)" onchange="toggleTemplateName()">
            <span style="font-weight:500">Save foods as a named meal for future use</span>
        </label>
        <div id="template-name-wrap" style="display:none;margin-top:10px">
            <label for="template-name" class="sr-only">Named meal name</label>
            <input type="text" id="template-name" name="template_name" placeholder="Meal name (e.g. 'Weekday breakfast')"
                   style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
        </div>
    </div>

    <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">
            <?= $edit_id ? 'Save Changes' : 'Log Meal' ?>
        </button>
        <?php if ($edit_id): ?>
            <button type="submit" name="action" value="delete_meal"
                    class="btn btn-danger"
                    onclick="return confirm('Delete this meal?')">Delete</button>
        <?php endif; ?>
    </div>
</form>

<script>let rowIndex = <?= count($items) ?>;</script>
<script src="assets/js/log.js"></script>

<!-- Foods modal -->
<div id="foods-modal" role="dialog" aria-modal="true" aria-labelledby="foods-modal-title"
     style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);padding:16px;overflow-y:auto"
     onclick="if(event.target===this)closeFoodsModal()">
    <div style="background:var(--surface);border-radius:var(--radius);max-width:600px;margin:0 auto;box-shadow:0 8px 32px rgba(0,0,0,.2)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border)">
            <span id="foods-modal-title" style="font-weight:600;font-size:1rem;color:var(--primary)">Foods</span>
            <button type="button" onclick="closeFoodsModal()" aria-label="Close foods list"
                    style="background:none;border:none;font-size:1.4rem;line-height:1;cursor:pointer;color:var(--muted);padding:0 4px">&times;</button>
        </div>
        <div id="foods-modal-body" style="padding:12px 16px;font-size:.9rem">
            <p style="color:var(--muted);text-align:center;padding:24px 0">Loading…</p>
        </div>
    </div>
</div>

<!-- New Food modal -->
<div id="new-food-modal" role="dialog" aria-modal="true" aria-labelledby="new-food-modal-title"
     style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);padding:16px;overflow-y:auto"
     onclick="if(event.target===this)closeNewFoodModal()">
    <div style="background:var(--surface);border-radius:var(--radius);max-width:600px;margin:0 auto;box-shadow:0 8px 32px rgba(0,0,0,.2)">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border)">
            <span id="new-food-modal-title" style="font-weight:600;font-size:1rem;color:var(--primary)">New Food</span>
            <button type="button" onclick="closeNewFoodModal()" aria-label="Close new food form"
                    style="background:none;border:none;font-size:1.4rem;line-height:1;cursor:pointer;color:var(--muted);padding:0 4px">&times;</button>
        </div>
        <div style="padding:16px">
            <?php if (defined('USDA_API_KEY') && USDA_API_KEY !== ''): ?>
            <!-- USDA search -->
            <div style="margin-bottom:16px">
                <p style="font-size:.8rem;color:var(--muted);margin-bottom:8px">Search USDA to pre-fill — or enter values manually below.</p>
                <div style="position:relative">
                    <label for="nf-usda-search" class="sr-only">Search USDA food database</label>
                    <input type="text" id="nf-usda-search" placeholder="e.g. banana, chicken breast, oats…"
                           autocomplete="off" aria-autocomplete="list" aria-controls="nf-usda-results"
                           style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:1rem">
                    <div id="nf-usda-results" class="search-results" role="listbox" aria-live="polite"></div>
                </div>
                <div id="nf-usda-status" style="font-size:.8rem;color:var(--muted);margin-top:4px;min-height:1.2em" aria-live="polite"></div>
            </div>
            <?php endif; ?>
            <!-- Food form -->
            <div id="nf-errors" class="alert alert-error" role="alert" style="display:none"></div>
            <form id="nf-form">
                <?php $form_food = []; $prefix = 'nf-'; $autofocus = false; include __DIR__ . '/_food_form_fields.php'; ?>
                <div style="display:flex;gap:10px;margin-top:4px">
                    <button type="button" onclick="submitNewFood()" class="btn btn-primary" style="flex:1">Save &amp; Add to Meal</button>
                    <button type="button" onclick="closeNewFoodModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (defined('USDA_API_KEY') && USDA_API_KEY !== ''): ?>
<script>initUsdaSearch('nf-usda-search', 'nf-usda-results', 'nf-usda-status', 'nf-');</script>
<?php endif; ?>

<?php page_footer('log'); ?>
