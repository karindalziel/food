<?php
// api.php — Internal JSON API: food/template search, USDA proxy, food save, food list.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'search_foods') {
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $db = get_db();
    $stmt = $db->prepare("
        SELECT id, name, quantity_description, grams, grams_fiber, grams_protein, servings_produce
        FROM foods WHERE name LIKE ? ORDER BY name LIMIT 20
    ");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'usda_search') {
    $q = trim($_GET['q'] ?? '');
    if (!$q || !defined('USDA_API_KEY')) { echo json_encode([]); exit; }

    $url = 'https://api.nal.usda.gov/fdc/v1/foods/search?' . http_build_query([
        'query'    => $q,
        'dataType' => 'Foundation,SR Legacy',
        'pageSize' => 12,
        'api_key'  => USDA_API_KEY,
    ]);
    $ctx    = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $result = @file_get_contents($url, false, $ctx);
    if (!$result) { echo json_encode(['error' => 'USDA request failed']); exit; }

    $data  = json_decode($result, true);
    $foods = $data['foods'] ?? [];
    $out   = [];
    foreach ($foods as $f) {
        $fiber = 0; $protein = 0;
        foreach ($f['foodNutrients'] ?? [] as $n) {
            // USDA nutrient IDs: 1079 = dietary fiber, 1003 = protein
            if (($n['nutrientId'] ?? 0) == 1079) $fiber   = (float)($n['value'] ?? 0);
            if (($n['nutrientId'] ?? 0) == 1003) $protein = (float)($n['value'] ?? 0);
        }
        $serving_g = (float)($f['servingSize'] ?? 100);
        if (strtolower($f['servingSizeUnit'] ?? 'g') !== 'g') $serving_g = 100;
        if ($serving_g <= 0) $serving_g = 100;
        $out[] = [
            'fdc_id'           => $f['fdcId'],
            'name'             => $f['description'],
            'grams'            => round($serving_g, 1),
            'qty_desc'         => $f['householdServingFullText'] ?? '',
            'fiber_per_100g'   => round($fiber,   4),
            'protein_per_100g' => round($protein, 4),
            'fiber'            => round($fiber   * $serving_g / 100, 1),
            'protein'          => round($protein * $serving_g / 100, 1),
        ];
    }
    echo json_encode($out);
    exit;
}

if ($action === 'save_food' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name     = trim($_POST['name'] ?? '');
    $qty_desc = trim($_POST['quantity_description'] ?? '');
    $grams    = (isset($_POST['grams']) && $_POST['grams'] !== '') ? (float)$_POST['grams'] : null;
    $fiber    = (float)($_POST['grams_fiber'] ?? 0);
    $protein  = (float)($_POST['grams_protein'] ?? 0);
    $produce  = (float)($_POST['servings_produce'] ?? 0);

    $errors = [];
    if (!$name) $errors[] = 'Name is required.';
    if ($errors) { echo json_encode(['errors' => $errors]); exit; }

    $db = get_db();
    $db->prepare("INSERT INTO foods (name, quantity_description, grams, grams_fiber, grams_protein, servings_produce) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$name, $qty_desc ?: null, $grams, $fiber, $protein, $produce]);
    $id = (int)$db->lastInsertId();

    echo json_encode([
        'id' => $id, 'name' => $name, 'quantity_description' => $qty_desc,
        'grams' => $grams, 'grams_fiber' => $fiber, 'grams_protein' => $protein,
        'servings_produce' => $produce,
    ]);
    exit;
}

if ($action === 'list_foods') {
    $db = get_db();
    $stmt = $db->query("SELECT id, name, quantity_description, grams, grams_fiber, grams_protein, servings_produce FROM foods ORDER BY name");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'search_templates') {
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $db = get_db();
    $stmt = $db->prepare("SELECT id, name FROM meal_templates WHERE name LIKE ? ORDER BY name LIMIT 20");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'get_template') {
    $id = (int)($_GET['id'] ?? 0);
    $db = get_db();
    $stmt = $db->prepare("
        SELECT mti.portion_multiplier,
               f.id AS food_id, f.name, f.quantity_description,
               f.grams, f.grams_fiber, f.grams_protein, f.servings_produce
        FROM meal_template_items mti
        JOIN foods f ON f.id = mti.food_id
        WHERE mti.template_id = ?
        ORDER BY mti.id
    ");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

echo json_encode(['error' => 'Unknown action']);
