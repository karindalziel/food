<?php
// Shared food form fields partial.
// Variables (set before including):
//   $form_food  — associative array of current values, or []
//   $prefix     — string prepended to all element IDs (e.g. 'nf-'), default ''
//   $autofocus  — bool, whether to autofocus the name field, default false
$form_food = $form_food ?? [];
$prefix    = $prefix    ?? '';
$autofocus = $autofocus ?? false;
?>
<input type="hidden" id="<?= $prefix ?>fiber_per_100g"   value="0">
<input type="hidden" id="<?= $prefix ?>protein_per_100g" value="0">
<input type="hidden" id="<?= $prefix ?>usda_loaded"      value="0">

<div class="form-group">
    <label for="<?= $prefix ?>field-name">Name</label>
    <input type="text" id="<?= $prefix ?>field-name" name="name"
           value="<?= htmlspecialchars($form_food['name'] ?? '') ?>"
           required <?= $autofocus ? 'autofocus' : '' ?>>
</div>
<div class="form-group">
    <label for="<?= $prefix ?>field-qty">Quantity description <span style="color:var(--muted);font-weight:400">(e.g. "1 cup cooked")</span></label>
    <input type="text" id="<?= $prefix ?>field-qty" name="quantity_description"
           value="<?= htmlspecialchars($form_food['quantity_description'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="<?= $prefix ?>field-grams">Total grams <span style="color:var(--muted);font-weight:400">(for the above quantity)</span></label>
    <input type="number" id="<?= $prefix ?>field-grams" name="grams"
           value="<?= isset($form_food['grams']) ? (float)$form_food['grams'] : '' ?>"
           step="1" min="1" required placeholder="e.g. 245"
           oninput="scaleNutrients('<?= $prefix ?>')">
</div>
<div class="form-row">
    <div class="form-group">
        <label for="<?= $prefix ?>field-fiber">Grams fiber</label>
        <input type="number" id="<?= $prefix ?>field-fiber" name="grams_fiber"
               value="<?= (float)($form_food['grams_fiber'] ?? 0) ?>" step="0.1" min="0">
    </div>
    <div class="form-group">
        <label for="<?= $prefix ?>field-protein">Grams protein</label>
        <input type="number" id="<?= $prefix ?>field-protein" name="grams_protein"
               value="<?= (float)($form_food['grams_protein'] ?? 0) ?>" step="0.1" min="0">
    </div>
    <div class="form-group">
        <label for="<?= $prefix ?>field-produce">Produce servings</label>
        <input type="number" id="<?= $prefix ?>field-produce" name="servings_produce"
               value="<?= (float)($form_food['servings_produce'] ?? 0) ?>" step="0.25" min="0">
    </div>
</div>
