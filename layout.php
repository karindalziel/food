<?php
function page_header(string $title, string $active = ''): void {
    $person = get_active_person();
    $db = get_db();
    $people = $db->query('SELECT id, name FROM people ORDER BY id')->fetchAll();
    $qs = u_qs();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Food+</title>
<meta name="csrf-token" content="<?= csrf_token() ?>">
<?php
$header_color = $person['header_color'] ?? '#2d6a4f';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $header_color)) $header_color = '#2d6a4f';
?>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="icon" type="image/png" sizes="96x96" href="favicon-96x96.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<link rel="stylesheet" href="assets/css/app.css">
<style>
:root { --primary: <?= $header_color ?>; --primary-light: <?= $header_color ?>1a; }
</style>
<script src="assets/js/app.js"></script>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<header>
    <h1>Food+</h1>
    <?php if (count($people) > 1): ?>
        <div class="person-switcher">
            <?php foreach ($people as $p): ?>
                <a href="index.php?u=<?= $p['id'] ?>"
                   class="<?= $p['id'] == ($person['id'] ?? 0) ? 'active-person' : '' ?>">
                    <?= htmlspecialchars($p['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php elseif ($person): ?>
        <span style="font-size:.85rem;opacity:.8"><?= htmlspecialchars($person['name']) ?></span>
    <?php endif; ?>
</header>
<main id="main-content">
<?php
}

function page_footer(string $active = ''): void {
    $qs = u_qs();
    $nav = [
        ['href' => 'index.php',   'label' => 'Today',    'key' => 'today',    'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
        ['href' => 'log.php',     'label' => 'Log Meal', 'key' => 'log',      'icon' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'],
        ['href' => 'reports.php', 'label' => 'Reports',  'key' => 'reports',  'icon' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'],
        ['href' => 'foods.php',   'label' => 'Foods',    'key' => 'foods',    'icon' => '<path d="M12 20.94c1.5.05 3.69-.08 4.95-1.52 1.78-2.06 1.54-6.38-.52-8.5C15.11 9.57 13.29 8.82 12 9c-1.29-.18-3.11.57-4.43 1.92-2.06 2.12-2.3 6.44-.52 8.5C8.31 20.86 10.5 20.99 12 20.94z"/><path d="M10 2c1 .5 2 2 2 5"/><path d="M14 2.5c0 3.46-2.02 6.4-5 7.5"/>'],
        ['href' => 'settings.php','label' => 'Settings', 'key' => 'settings', 'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>'],
    ];
    ?>
</main>
<nav>
<?php foreach ($nav as $item): ?>
    <a href="<?= $item['href'] . $qs ?>" class="<?= $active === $item['key'] ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <?= $item['icon'] ?>
        </svg>
        <?= $item['label'] ?>
    </a>
<?php endforeach; ?>
</nav>
</body>
</html>
<?php
}

function goal_bar(string $label, float $value, float $goal, string $unit, string $type): void {
    $pct = $goal > 0 ? min(($value / $goal) * 100, 100) : 0;
    $over = $goal > 0 && $value >= $goal;
    $class = $over ? 'over' : ($pct >= 75 ? '' : 'warn');
    ?>
    <div class="goal-bar">
        <div class="goal-bar-label">
            <span><?= htmlspecialchars($label) ?></span>
            <span class="value"><?= round($value, 2) ?> / <?= round($goal, 2) ?> <?= htmlspecialchars($unit) ?></span>
        </div>
        <div class="bar-track" role="progressbar" aria-valuenow="<?= round($value, 2) ?>" aria-valuemin="0" aria-valuemax="<?= round($goal, 2) ?>" aria-label="<?= htmlspecialchars($label) ?>: <?= round($value, 2) ?> of <?= round($goal, 2) ?> <?= htmlspecialchars($unit) ?>">
            <div class="bar-fill <?= $class ?>" style="width:<?= $pct ?>%"></div>
        </div>
    </div>
    <?php
}
