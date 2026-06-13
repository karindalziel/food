<?php
declare(strict_types=1);

// Session hardening — set before any session_start() call in the including file
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// Load config; redirect to setup if missing (skip redirect on setup.php itself)
define('DIET_APP', true);
$_config_path = __DIR__ . '/data/config.php';
if (file_exists($_config_path)) {
    require_once $_config_path;
} elseif (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'setup.php') {
    header('Location: setup.php');
    exit;
}

// ── CSRF helpers ─────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): void {
    $post   = $_POST['csrf_token']               ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN']      ?? '';
    $token  = $post !== '' ? $post : $header;
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function get_db(): PDO {
    static $db = null;
    if ($db === null) {
        $path = __DIR__ . '/data/diet.db';
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0755, true);
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON;');
        init_schema($db);
    }
    return $db;
}

function init_schema(PDO $db): void {
    $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
    // Migrations for columns added after initial release
    try { $db->exec("ALTER TABLE people ADD COLUMN header_color TEXT DEFAULT '#2d6a4f'"); } catch (PDOException) {}
}

function get_active_person(): ?array {
    $db = get_db();
    // ?u= in URL sets the active person and is bookmarkable
    if (!empty($_GET['u']) && ctype_digit((string)$_GET['u'])) {
        $id = (int)$_GET['u'];
        if ($id > 0 && $id !== (int)($_SESSION['person_id'] ?? 0)) {
            session_regenerate_id(true);
        }
        $_SESSION['person_id'] = $id;
    }
    $person_id = $_SESSION['person_id'] ?? null;
    if ($person_id) {
        $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
        $stmt->execute([$person_id]);
        $person = $stmt->fetch();
        if ($person) return $person;
    }
    // Fall back to first person
    $person = $db->query('SELECT * FROM people ORDER BY id LIMIT 1')->fetch();
    if ($person) {
        $_SESSION['person_id'] = (int)$person['id'];
    }
    return $person ?: null;
}

// Returns "u=X" string (no leading ?) for use in URLs
function u_param(): string {
    $person = get_active_person();
    return $person ? 'u=' . $person['id'] : '';
}

// Returns "?u=X" or "" for use when building hrefs
function u_qs(): string {
    $p = u_param();
    return $p ? '?' . $p : '';
}

// Returns "&u=X" or "" for appending to existing query strings
function u_amp(): string {
    $p = u_param();
    return $p ? '&' . $p : '';
}

function daily_totals(int $person_id, string $date): array {
    $db = get_db();
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(f.grams_fiber * mi.portion_multiplier), 0) AS fiber,
            COALESCE(SUM(f.grams_protein * mi.portion_multiplier), 0) AS protein,
            COALESCE(SUM(f.servings_produce * mi.portion_multiplier), 0) AS produce
        FROM meals m
        JOIN meal_items mi ON mi.meal_id = m.id
        JOIN foods f ON f.id = mi.food_id
        WHERE m.person_id = ?
          AND DATE(m.eaten_at) = ?
    ");
    $stmt->execute([$person_id, $date]);
    return $stmt->fetch();
}

function weekly_totals(int $person_id, string $week_start): array {
    $db = get_db();
    $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
    $stmt = $db->prepare("
        SELECT
            DATE(m.eaten_at) AS day,
            COALESCE(SUM(f.grams_fiber * mi.portion_multiplier), 0) AS fiber,
            COALESCE(SUM(f.grams_protein * mi.portion_multiplier), 0) AS protein,
            COALESCE(SUM(f.servings_produce * mi.portion_multiplier), 0) AS produce
        FROM meals m
        JOIN meal_items mi ON mi.meal_id = m.id
        JOIN foods f ON f.id = mi.food_id
        WHERE m.person_id = ?
          AND DATE(m.eaten_at) BETWEEN ? AND ?
        GROUP BY DATE(m.eaten_at)
        ORDER BY day
    ");
    $stmt->execute([$person_id, $week_start, $week_end]);
    return $stmt->fetchAll();
}

function has_empty_meals(int $person_id, string $date_start, string $date_end): bool {
    $db = get_db();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM meals m
        LEFT JOIN meal_items mi ON mi.meal_id = m.id
        WHERE m.person_id = ?
          AND DATE(m.eaten_at) BETWEEN ? AND ?
          AND mi.id IS NULL
    ");
    $stmt->execute([$person_id, $date_start, $date_end]);
    return (int)$stmt->fetchColumn() > 0;
}

function food_types(): array {
    return ['carb', 'component', 'dairy', 'fiber', 'legume', 'meal', 'meat', 'produce', 'protein', 'snack', 'starch', 'whole grain'];
}
