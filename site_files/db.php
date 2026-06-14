<?php
// db.php — PDO setup, schema init, CSRF helpers, shared query utilities.
// Included by every page. Provides get_db(), get_active_person(), and helper functions.
declare(strict_types=1);

// Session hardening — set before any session_start() call in the including file
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 2592000, // 30 days — prevents stale-token errors on mobile
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
/** Returns (and lazily creates) the session CSRF token. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Renders a hidden CSRF input for use inside HTML forms. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/** Verifies the CSRF token from POST body or X-CSRF-Token header; shows friendly error on failure. */
function csrf_verify(): void {
    $post   = $_POST['csrf_token']          ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token  = $post !== '' ? $post : $header;
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        // JSON callers get a terse error; browser form submissions get a helpful page
        if (($header !== '' && $post === '') || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')) {
            header('Content-Type: application/json');
            exit(json_encode(['error' => 'Session expired. Please refresh and try again.']));
        }
        ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Session Expired</title>
<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa}
.box{background:#fff;border-radius:12px;padding:32px 28px;max-width:360px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.1)}
h1{font-size:1.2rem;margin:0 0 12px}p{color:#555;margin:0 0 20px;line-height:1.5}
a{display:inline-block;padding:10px 24px;background:#2d6a4f;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
</style></head><body>
<div class="box">
    <h1>Session Expired</h1>
    <p>Your session timed out while the page was open. Press back to return to your form — your entries may still be there.</p>
    <a href="javascript:history.back()">Go Back</a>
</div>
</body></html><?php
        exit;
    }
}

/**
 * Returns a PDO singleton connected to data/diet.db.
 * Creates the data/ directory and initializes the schema on first call.
 */
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

/** Runs schema.sql to create tables if they don't exist. Called once per request by get_db(). */
function init_schema(PDO $db): void {
    $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
}

/**
 * Returns the active person row, falling back to the first person in the database.
 * Sets $_SESSION['person_id']; regenerates the session ID when switching users.
 * @return array|null  Person row, or null if no people exist yet.
 */
function get_active_person(): ?array {
    $db = get_db();
    // ?u= in URL sets the active person and is bookmarkable
    if (!empty($_GET['u']) && ctype_digit((string)$_GET['u'])) {
        $id = (int)$_GET['u'];
        if ($id > 0 && $id !== (int)($_SESSION['person_id'] ?? 0)) {
            // Prevents session fixation when switching to a different person via ?u=
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

/** Returns "u=X" string (no leading ?) for use in URLs. */
function u_param(): string {
    $person = get_active_person();
    return $person ? 'u=' . $person['id'] : '';
}

/** Returns "?u=X" or "" for use when building hrefs. */
function u_qs(): string {
    $p = u_param();
    return $p ? '?' . $p : '';
}

/** Returns "&u=X" or "" for appending to existing query strings. */
function u_amp(): string {
    $p = u_param();
    return $p ? '&' . $p : '';
}

/**
 * Computes total fiber, protein, and produce consumed on a given date,
 * across all meals and food items for the person.
 * @return array{fiber: float, protein: float, produce: float}
 */
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

/**
 * Returns per-day nutrition totals for the 7 days starting at $week_start (Monday).
 * @return array[]  Each row: {day: string, fiber: float, protein: float, produce: float}
 */
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

/**
 * Returns true if any meal in the date range has no associated food items.
 * Used to display a warning that day/week totals may be incomplete.
 */
function has_empty_meals(int $person_id, string $date_start, string $date_end): bool {
    $db = get_db();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM meals m
        LEFT JOIN meal_items mi ON mi.meal_id = m.id
        WHERE m.person_id = ?
          AND DATE(m.eaten_at) BETWEEN ? AND ?
          AND mi.id IS NULL  -- LEFT JOIN with IS NULL finds meals that have no items
    ");
    $stmt->execute([$person_id, $date_start, $date_end]);
    return (int)$stmt->fetchColumn() > 0;
}

