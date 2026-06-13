<?php
// import.php — CSV import with dry-run preview. Accepts the same format as export.php.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

$db = get_db();
$active = get_active_person();
$people = $db->query('SELECT id, name FROM people ORDER BY id')->fetchAll();

$errors   = [];
$results  = null; // summary after a successful import

// Column index constants matching the export header
define('COL_DATE',     0);
define('COL_TIME',     1);
define('COL_NOTES',    2);
define('COL_FOOD',     3);
define('COL_QTY_DESC', 4);
define('COL_PORTION',  5);
define('COL_GRAMS',    6);
define('COL_FIBER',    7);
define('COL_PROTEIN',  8);
define('COL_PRODUCE',  9);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $person_id       = (int)($_POST['person_id'] ?? 0);
    $on_dup_meal     = $_POST['on_dup_meal']  ?? 'skip';   // skip | replace
    $on_dup_food     = $_POST['on_dup_food']  ?? 'keep';   // keep | update
    $dry_run         = !empty($_POST['dry_run']);

    if (!$person_id) {
        $errors[] = 'Select a person to import meals for.';
    }

    if (empty($_FILES['csv_file']['tmp_name'])) {
        $errors[] = 'Please choose a CSV file.';
    } elseif (($_FILES['csv_file']['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors[] = 'File too large (max 5 MB).';
    }

    if (empty($errors)) {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) { $errors[] = 'Could not read uploaded file.'; }
    }

    if (empty($errors)) {
        // Read and validate header
        $header = fgetcsv($fh);
        $expected = ['Date', 'Time', 'Meal Notes', 'Food', 'Quantity Description',
                     'Portion Multiplier', 'Total Grams', 'Fiber (g)', 'Protein (g)', 'Produce Servings'];
        if ($header !== $expected) {
            $errors[] = 'CSV header does not match the expected export format. '
                      . 'Expected: ' . implode(', ', $expected) . '. '
                      . 'Got: ' . implode(', ', (array)$header);
        }
    }

    if (empty($errors)) {
        // Parse all rows
        $rows = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if (count($row) < 10) {
                $errors[] = "Line $line: expected 10 columns, got " . count($row);
                continue;
            }
            if (empty(trim($row[COL_DATE])) || empty(trim($row[COL_FOOD]))) {
                continue; // skip blank lines
            }
            $rows[] = $row;
        }
        fclose($fh);
    }

    if (empty($errors) && empty($rows)) {
        $errors[] = 'The CSV file contains no data rows.';
    }

    if (empty($errors)) {
        // ── Step 1: Derive base food nutrition values ───────────────────────
        // For each food name, collect per-serving values from every row it appears in
        // (value / portion_multiplier) then average them.
        // Averaging handles cases where the same food was logged with slightly different
        // values across entries (e.g. different brands, rounding variation).
        $food_data = []; // keyed by lowercase food name
        foreach ($rows as $row) {
            $name    = trim($row[COL_FOOD]);
            $key     = mb_strtolower($name);
            $portion = (float)$row[COL_PORTION];
            if ($portion <= 0) continue;

            $base_grams   = (float)$row[COL_GRAMS]   / $portion;
            $base_fiber   = (float)$row[COL_FIBER]   / $portion;
            $base_protein = (float)$row[COL_PROTEIN]  / $portion;
            $base_produce = (float)$row[COL_PRODUCE]  / $portion;
            $qty_desc     = trim($row[COL_QTY_DESC]);

            if (!isset($food_data[$key])) {
                $food_data[$key] = [
                    'name'     => $name,
                    'qty_desc' => $qty_desc,
                    'grams'    => [],
                    'fiber'    => [],
                    'protein'  => [],
                    'produce'  => [],
                ];
            }
            $food_data[$key]['grams'][]   = $base_grams;
            $food_data[$key]['fiber'][]   = $base_fiber;
            $food_data[$key]['protein'][] = $base_protein;
            $food_data[$key]['produce'][] = $base_produce;
        }

        // Average the collected values
        $foods_to_process = [];
        foreach ($food_data as $key => $fd) {
            $avg = fn(array $a) => array_sum($a) / count($a);
            $foods_to_process[$key] = [
                'name'     => $fd['name'],
                'qty_desc' => $fd['qty_desc'],
                'grams'    => $avg($fd['grams']),
                'fiber'    => $avg($fd['fiber']),
                'protein'  => $avg($fd['protein']),
                'produce'  => $avg($fd['produce']),
            ];
        }

        // ── Step 2: Create/update foods ─────────────────────────────────────
        $food_ids        = []; // lowercase name → id
        $foods_created   = 0;
        $foods_updated   = 0;
        $foods_skipped   = 0;

        foreach ($foods_to_process as $key => $fd) {
            // Case-insensitive lookup
            $stmt = $db->prepare("SELECT id FROM foods WHERE LOWER(name) = ?");
            $stmt->execute([$key]);
            $existing = $stmt->fetch();

            if ($existing) {
                $food_ids[$key] = (int)$existing['id'];
                if ($on_dup_food === 'update') {
                    if (!$dry_run) {
                        $db->prepare("UPDATE foods SET quantity_description=?, grams=?,
                                      grams_fiber=?, grams_protein=?, servings_produce=? WHERE id=?")
                           ->execute([$fd['qty_desc'] ?: null, $fd['grams'],
                                      $fd['fiber'], $fd['protein'], $fd['produce'],
                                      $existing['id']]);
                    }
                    $foods_updated++;
                } else {
                    $foods_skipped++;
                }
            } else {
                if (!$dry_run) {
                    $db->prepare("INSERT INTO foods (name, quantity_description, grams, grams_fiber, grams_protein, servings_produce)
                                  VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$fd['name'], $fd['qty_desc'] ?: null,
                                  $fd['grams'], $fd['fiber'], $fd['protein'], $fd['produce']]);
                    $food_ids[$key] = (int)$db->lastInsertId();
                } else {
                    $food_ids[$key] = 0; // placeholder for dry run
                }
                $foods_created++;
            }
        }

        // ── Step 3: Group rows into meals ────────────────────────────────────
        // The key "date|time|notes" reconstructs logical meals from flat CSV rows —
        // rows that share the same date, time, and notes were part of one meal.
        $meal_groups = []; // keyed by "date|time|notes"
        foreach ($rows as $row) {
            $date  = trim($row[COL_DATE]);
            $time  = trim($row[COL_TIME]) ?: '00:00:00';
            $notes = trim($row[COL_NOTES]);
            $key   = "$date|$time|$notes";
            $meal_groups[$key][] = $row;
        }

        // ── Step 4: Create meals ─────────────────────────────────────────────
        $meals_created  = 0;
        $meals_replaced = 0;
        $meals_skipped  = 0;
        $items_created  = 0;

        foreach ($meal_groups as $key => [$first]) {
            [$date, $time, $notes] = explode('|', $key, 3);
            $eaten_at = $date . ' ' . ($time ?: '00:00:00');

            // Check for duplicate
            $stmt = $db->prepare("SELECT id FROM meals WHERE person_id = ? AND eaten_at = ?");
            $stmt->execute([$person_id, $eaten_at]);
            $existing_meal = $stmt->fetch();

            if ($existing_meal) {
                if ($on_dup_meal === 'skip') {
                    $meals_skipped++;
                    continue;
                }
                // replace: delete old items and meal, re-create
                if (!$dry_run) {
                    $db->prepare('DELETE FROM meal_items WHERE meal_id = ?')->execute([$existing_meal['id']]);
                    $db->prepare('DELETE FROM meals WHERE id = ?')->execute([$existing_meal['id']]);
                }
                $meals_replaced++;
            } else {
                $meals_created++;
            }

            if ($dry_run) continue;

            $db->prepare("INSERT INTO meals (person_id, eaten_at, notes) VALUES (?, ?, ?)")
               ->execute([$person_id, $eaten_at, $notes ?: null]);
            $meal_id = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT INTO meal_items (meal_id, food_id, portion_multiplier) VALUES (?, ?, ?)");
            foreach ($meal_groups[$key] as $row) {
                $food_key = mb_strtolower(trim($row[COL_FOOD]));
                $fid      = $food_ids[$food_key] ?? 0;
                $portion  = (float)$row[COL_PORTION];
                if ($fid && $portion > 0) {
                    $ins->execute([$meal_id, $fid, $portion]);
                    $items_created++;
                }
            }
        }

        $results = [
            'dry_run'        => $dry_run,
            'foods_created'  => $foods_created,
            'foods_updated'  => $foods_updated,
            'foods_skipped'  => $foods_skipped,
            'meals_created'  => $meals_created,
            'meals_replaced' => $meals_replaced,
            'meals_skipped'  => $meals_skipped,
            'items_created'  => $items_created,
            'total_rows'     => count($rows),
        ];
    }
}

page_header('Import', 'settings');
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($results): ?>
<div class="card">
    <h2><?= $results['dry_run'] ? '🔍 Preview (dry run — nothing was saved)' : '✅ Import complete' ?></h2>
    <table>
        <tr><td>CSV rows processed</td><td style="text-align:right;font-weight:600"><?= $results['total_rows'] ?></td></tr>
        <tr><td style="padding-top:12px;color:var(--muted);font-size:.8rem;text-transform:uppercase">Foods</td><td></td></tr>
        <tr><td>New foods created</td><td style="text-align:right;font-weight:600"><?= $results['foods_created'] ?></td></tr>
        <tr><td>Existing foods updated</td><td style="text-align:right;font-weight:600"><?= $results['foods_updated'] ?></td></tr>
        <tr><td>Existing foods kept as-is</td><td style="text-align:right;font-weight:600"><?= $results['foods_skipped'] ?></td></tr>
        <tr><td style="padding-top:12px;color:var(--muted);font-size:.8rem;text-transform:uppercase">Meals</td><td></td></tr>
        <tr><td>New meals imported</td><td style="text-align:right;font-weight:600"><?= $results['meals_created'] ?></td></tr>
        <tr><td>Existing meals replaced</td><td style="text-align:right;font-weight:600"><?= $results['meals_replaced'] ?></td></tr>
        <tr><td>Existing meals skipped</td><td style="text-align:right;font-weight:600"><?= $results['meals_skipped'] ?></td></tr>
        <?php if (!$results['dry_run']): ?>
        <tr><td>Food items logged</td><td style="text-align:right;font-weight:600"><?= $results['items_created'] ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if ($results['dry_run'] && empty($errors)): ?>
        <p style="margin-top:12px;font-size:.9rem;color:var(--muted)">
            This was a preview only. Submit again without "Dry run" to actually import.
        </p>
    <?php elseif (!$results['dry_run']): ?>
        <div style="margin-top:16px">
            <a href="index.php<?= u_qs() ?>" class="btn btn-primary">Go to Today</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Import from CSV</h2>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
        Upload a CSV in the same format as the export. Each row is one food within a meal.
        Foods are identified by name (case-insensitive); their per-serving nutrition values
        are calculated by dividing the logged values by the portion multiplier.
    </p>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="csv-file">CSV file</label>
            <input type="file" id="csv-file" name="csv_file" accept=".csv,text/csv" required>
        </div>

        <div class="form-group">
            <label for="person-id">Import meals for</label>
            <select id="person-id" name="person_id">
                <option value="">— select person —</option>
                <?php foreach ($people as $p): ?>
                    <option value="<?= $p['id'] ?>"
                            <?= $p['id'] == ($active['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="font-size:.85rem;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">Options</div>

        <div class="form-group">
            <label for="on-dup-meal">If a meal already exists at the same date &amp; time</label>
            <select id="on-dup-meal" name="on_dup_meal">
                <option value="skip">Skip (keep existing meal)</option>
                <option value="replace">Replace (delete and re-import)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="on-dup-food">If a food name already exists in the library</label>
            <select id="on-dup-food" name="on_dup_food">
                <option value="keep">Keep existing food values</option>
                <option value="update">Update food values from import</option>
            </select>
        </div>

        <div style="margin-bottom:14px">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <input type="checkbox" name="dry_run" value="1"
                       style="width:18px;height:18px;accent-color:var(--primary)">
                <span>
                    <strong>Dry run</strong> — preview what would be imported without saving anything
                </span>
            </label>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Upload &amp; Import</button>
    </form>
</div>

<div class="card">
    <h2>Expected CSV format</h2>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:10px">
        Columns must be in this exact order (same as the export):
    </p>
    <div style="overflow-x:auto">
        <table style="font-size:.75rem;white-space:nowrap">
            <thead>
                <tr>
                    <th>Date</th><th>Time</th><th>Meal Notes</th><th>Food</th>
                    <th>Quantity Description</th><th>Portion Multiplier</th>
                    <th>Total Grams</th><th>Fiber (g)</th><th>Protein (g)</th><th>Produce Servings</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>2024-01-15</td><td>08:30:00</td><td>breakfast</td><td>Oatmeal</td>
                    <td>1 cup cooked</td><td>1</td><td>245</td><td>4</td><td>6</td><td>0</td>
                </tr>
                <tr>
                    <td>2024-01-15</td><td>08:30:00</td><td>breakfast</td><td>Blueberries</td>
                    <td>1 cup</td><td>0.5</td><td>72.5</td><td>1.8</td><td>0.5</td><td>0.5</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p style="font-size:.8rem;color:var(--muted);margin-top:10px">
        Rows with the same Date, Time, and Meal Notes are grouped into one meal.
        Portion Multiplier of 1 = one full serving of the food.
    </p>
</div>

<?php page_footer('settings'); ?>
