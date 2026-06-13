<?php
// export.php — Streams meal data as a CSV file. Optional ?start= and ?end= date filters.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

$db = get_db();
$person = get_active_person();
if (!$person) { http_response_code(403); exit('No person configured.'); }

$person_id = (int)$person['id'];
$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

// Validate date format to prevent header injection and malformed SQL
if ($start && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = null;
if ($end   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = null;

$where = 'WHERE m.person_id = ?';
$params = [$person_id];

if ($start && $end) {
    $where .= ' AND DATE(m.eaten_at) BETWEEN ? AND ?';
    $params[] = $start;
    $params[] = $end;
}

$stmt = $db->prepare("
    SELECT
        DATE(m.eaten_at) AS date,
        TIME(m.eaten_at) AS time,
        m.notes AS meal_notes,
        f.name AS food,
        f.quantity_description,
        mi.portion_multiplier,
        ROUND(f.grams * mi.portion_multiplier, 1) AS total_grams,
        ROUND(f.grams_fiber * mi.portion_multiplier, 2) AS fiber_g,
        ROUND(f.grams_protein * mi.portion_multiplier, 2) AS protein_g,
        ROUND(f.servings_produce * mi.portion_multiplier, 2) AS produce_servings
    FROM meals m
    JOIN meal_items mi ON mi.meal_id = m.id
    JOIN foods f ON f.id = mi.food_id
    $where
    ORDER BY m.eaten_at, mi.id
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$person_slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($person['name']));
$exported_at = date('Y-m-d_Hi');
$filename = "diet_export_{$person_slug}_{$exported_at}";
if ($start && $end) { $filename .= "__{$start}_{$end}"; }
$filename .= '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Time', 'Meal Notes', 'Food', 'Quantity Description', 'Portion Multiplier', 'Total Grams', 'Fiber (g)', 'Protein (g)', 'Produce Servings']);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
