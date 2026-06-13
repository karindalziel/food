<?php
// _meal_row.php — Partial: single meal row with time, planned badge, foods, nutrition pills, and Edit button.
// Requires $meal and $meal_foods in scope.
?>
<div class="meal-item">
    <div style="flex:1">
        <div class="food-name">
            <?= date('g:i a', strtotime($meal['eaten_at'])) ?>
            <?php if ($meal['is_planned']): ?>
                <span style="display:inline-block;font-size:.7rem;font-weight:600;color:var(--planned-color);background:var(--planned-bg);border-radius:99px;padding:1px 7px;margin-left:4px;vertical-align:middle">planned</span>
            <?php endif; ?>
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
