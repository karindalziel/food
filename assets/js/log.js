// ── Template search ──────────────────────────────────────────────
const tSearch  = document.getElementById('template-search');
const tResults = document.getElementById('template-results');
let tDebounce;

tSearch.addEventListener('input', () => {
    clearTimeout(tDebounce);
    const q = tSearch.value.trim();
    if (q.length < 1) { tResults.style.display = 'none'; return; }
    tDebounce = setTimeout(() => doTemplateSearch(q), 200);
});
tSearch.addEventListener('blur', () => setTimeout(() => { tResults.style.display = 'none'; }, 150));

async function doTemplateSearch(q) {
    const res = await fetch('api.php?action=search_templates&q=' + encodeURIComponent(q));
    const templates = await res.json();
    if (!templates.length) { tResults.style.display = 'none'; return; }
    tResults.innerHTML = templates.map(t =>
        `<div class="search-result-item" tabindex="0"
              onclick="loadTemplate(${t.id},'${escHtml(t.name)}')"
              onkeydown="if(event.key==='Enter')loadTemplate(${t.id},'${escHtml(t.name)}')">
            ${escHtml(t.name)}
         </div>`).join('');
    tResults.style.display = 'block';
}

async function loadTemplate(id, name) {
    const res = await fetch('api.php?action=get_template&id=' + id);
    const foods = await res.json();
    foods.forEach(f => addFoodRow(f, f.portion_multiplier));
    tSearch.value = '';
    tResults.style.display = 'none';
}

// ── Food search ──────────────────────────────────────────────────
const foodSearch    = document.getElementById('food-search');
const searchResults = document.getElementById('search-results');
let fDebounce;

foodSearch.addEventListener('input', () => {
    clearTimeout(fDebounce);
    const q = foodSearch.value.trim();
    if (q.length < 2) { searchResults.style.display = 'none'; return; }
    fDebounce = setTimeout(() => doFoodSearch(q), 200);
});
foodSearch.addEventListener('blur', () => setTimeout(() => { searchResults.style.display = 'none'; }, 150));

async function doFoodSearch(q) {
    const res = await fetch('api.php?action=search_foods&q=' + encodeURIComponent(q));
    const foods = await res.json();
    if (!foods.length) { searchResults.style.display = 'none'; return; }
    searchResults.innerHTML = foods.map(f =>
        `<div class="search-result-item" tabindex="0"
              onclick="addFoodRow(${JSON.stringify(f).replace(/"/g,'&quot;')}, 1)"
              onkeydown="if(event.key==='Enter')addFoodRow(${JSON.stringify(f).replace(/"/g,'&quot;')}, 1)">
            <div>${escHtml(f.name)}</div>
            <div class="sr-meta">${escHtml(f.quantity_description||'')}
                &nbsp;·&nbsp;${f.grams_fiber}g fiber
                &nbsp;·&nbsp;${f.grams_protein}g protein
                &nbsp;·&nbsp;${f.servings_produce} produce
                ${f.grams ? '&nbsp;·&nbsp;' + f.grams + 'g total' : ''}
            </div>
        </div>`).join('');
    searchResults.style.display = 'block';
}

function addFoodRow(f, portion) {
    const i = rowIndex++;
    const baseG = parseFloat(f.grams) || 0;
    const m = parseFloat(portion) || 1;
    const itemG = baseG > 0 ? (baseG * m).toFixed(1) : '';

    const row = document.createElement('div');
    row.className = 'food-row';
    row.dataset.index = i;
    row.innerHTML = `
        <input type="hidden" name="food_id[]" value="${f.id}">
        <div class="meal-item" style="align-items:flex-start">
            <div style="flex:1">
                <div class="food-name">${escHtml(f.name)}</div>
                <div class="food-meta">${escHtml(f.quantity_description||'')}</div>
                <div class="nutrition-pills" id="pills-${i}">
                    <span class="pill pill-fiber">${(f.grams_fiber*m).toFixed(1)}g fiber</span>
                    <span class="pill pill-protein">${(f.grams_protein*m).toFixed(1)}g protein</span>
                    <span class="pill pill-produce">${(f.servings_produce*m).toFixed(1)} produce</span>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;min-width:155px">
                <div style="display:flex;gap:4px;align-items:center">
                    <input type="number" name="portion[]" value="${m}"
                           step="0.1" min="0.1"
                           style="width:72px;padding:8px 6px;border:1px solid var(--border);border-radius:6px 0 0 6px;font-size:.9rem;text-align:center;border-right:none"
                           data-fiber="${f.grams_fiber}" data-protein="${f.grams_protein}"
                           data-produce="${f.servings_produce}" data-base-grams="${baseG}"
                           data-row="${i}" oninput="portionChanged(this)"
                           aria-label="Portion multiplier for ${escHtml(f.name)}">
                    <span aria-hidden="true" style="padding:6px 4px;border:1px solid var(--border);border-left:none;border-right:none;font-size:.8rem;color:var(--muted);background:#f8f9fa">×</span>
                    <input type="number" id="grams-${i}" value="${itemG}"
                           step="0.1" min="0"
                           style="width:72px;padding:8px 6px;border:1px solid var(--border);border-radius:0 6px 6px 0;font-size:.9rem;text-align:center"
                           data-row="${i}" oninput="gramsChanged(this)"
                           placeholder="g" aria-label="Grams for ${escHtml(f.name)}">
                </div>
                <div style="font-size:.7rem;color:var(--muted);text-align:right">portion × &nbsp;|&nbsp; grams</div>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">Remove</button>
            </div>
        </div>`;
    document.getElementById('items-list').appendChild(row);
    foodSearch.value = '';
    searchResults.style.display = 'none';
}

// ── Portion / grams sync ─────────────────────────────────────────
function portionChanged(input) {
    const i = input.dataset.row;
    const m = parseFloat(input.value) || 0;
    const baseG = parseFloat(input.dataset.baseGrams) || 0;
    const gramsInput = document.getElementById('grams-' + i);
    if (gramsInput && baseG > 0) {
        gramsInput.value = (baseG * m).toFixed(1);
    }
    refreshPills(i, m, input.dataset.fiber, input.dataset.protein, input.dataset.produce);
}

function gramsChanged(input) {
    const i = input.dataset.row;
    const g = parseFloat(input.value) || 0;
    const row = input.closest('.food-row');
    const portionInput = row.querySelector('input[name="portion[]"]');
    const baseG = parseFloat(portionInput.dataset.baseGrams) || 0;
    if (baseG > 0) {
        const newPortion = g / baseG;
        portionInput.value = newPortion.toFixed(1);
        refreshPills(i, newPortion, portionInput.dataset.fiber, portionInput.dataset.protein, portionInput.dataset.produce);
    }
}

function refreshPills(i, m, fiber, protein, produce) {
    const pills = document.getElementById('pills-' + i);
    if (!pills) return;
    pills.innerHTML = `
        <span class="pill pill-fiber">${(fiber*m).toFixed(1)}g fiber</span>
        <span class="pill pill-protein">${(protein*m).toFixed(1)}g protein</span>
        <span class="pill pill-produce">${(produce*m).toFixed(1)} produce</span>`;
}

function removeRow(btn) { btn.closest('.food-row').remove(); }

function toggleTemplateName() {
    const wrap = document.getElementById('template-name-wrap');
    const cb   = document.getElementById('save-as-template');
    wrap.style.display = cb.checked ? 'block' : 'none';
}

// ── Modals ───────────────────────────────────────────────────────
let _foodsModalTrigger   = null;
let _newFoodModalTrigger = null;

async function openFoodsModal() {
    _foodsModalTrigger = document.activeElement;
    const modal = document.getElementById('foods-modal');
    const body  = document.getElementById('foods-modal-body');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    modal.querySelector('button[aria-label]').focus();
    if (body.dataset.loaded) return;
    try {
        const res = await fetch('api.php?action=list_foods');
        const foods = await res.json();
        if (!foods.length) {
            body.innerHTML = '<p style="color:var(--muted);text-align:center;padding:24px 0">No foods yet.</p>';
        } else {
            body.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead><tr>
                    <th style="text-align:left;padding:6px 4px;border-bottom:2px solid var(--border);color:var(--muted);font-size:.78rem">Name</th>
                    <th style="text-align:right;padding:6px 4px;border-bottom:2px solid var(--border);color:var(--muted);font-size:.78rem">g</th>
                    <th style="text-align:right;padding:6px 4px;border-bottom:2px solid var(--border);color:var(--muted);font-size:.78rem">Fiber</th>
                    <th style="text-align:right;padding:6px 4px;border-bottom:2px solid var(--border);color:var(--muted);font-size:.78rem">Protein</th>
                    <th style="text-align:right;padding:6px 4px;border-bottom:2px solid var(--border);color:var(--muted);font-size:.78rem">Produce</th>
                </tr></thead>
                <tbody>${foods.map(f => `<tr>
                    <td style="padding:8px 4px;border-bottom:1px solid var(--border)">
                        <div style="font-weight:500">${escHtml(f.name)}</div>
                        ${f.quantity_description ? `<div style="font-size:.75rem;color:var(--muted)">${escHtml(f.quantity_description)}</div>` : ''}
                    </td>
                    <td style="text-align:right;padding:8px 4px;border-bottom:1px solid var(--border);color:var(--muted)">${f.grams > 0 ? (+f.grams).toFixed(1)+'g' : '—'}</td>
                    <td style="text-align:right;padding:8px 4px;border-bottom:1px solid var(--border)">${(+f.grams_fiber).toFixed(2)}g</td>
                    <td style="text-align:right;padding:8px 4px;border-bottom:1px solid var(--border)">${(+f.grams_protein).toFixed(2)}g</td>
                    <td style="text-align:right;padding:8px 4px;border-bottom:1px solid var(--border)">${(+f.servings_produce).toFixed(2)}</td>
                </tr>`).join('')}</tbody>
            </table>`;
            body.dataset.loaded = '1';
        }
    } catch(e) {
        body.innerHTML = '<p style="color:var(--danger);text-align:center;padding:24px 0">Failed to load foods.</p>';
    }
}

function closeFoodsModal() {
    document.getElementById('foods-modal').style.display = 'none';
    document.body.style.overflow = '';
    if (_foodsModalTrigger) { _foodsModalTrigger.focus(); _foodsModalTrigger = null; }
}

function openNewFoodModal() {
    _newFoodModalTrigger = document.activeElement;
    document.getElementById('new-food-modal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.getElementById('nf-form').reset();
    ['nf-fiber_per_100g','nf-protein_per_100g','nf-usda_loaded'].forEach(id => {
        document.getElementById(id).value = '0';
    });
    const statusEl = document.getElementById('nf-usda-status');
    if (statusEl) statusEl.textContent = '';
    document.getElementById('nf-errors').style.display = 'none';
    const firstFocus = document.getElementById('nf-usda-search') || document.getElementById('nf-field-name');
    if (firstFocus) setTimeout(() => firstFocus.focus(), 50);
}

function closeNewFoodModal() {
    document.getElementById('new-food-modal').style.display = 'none';
    document.body.style.overflow = '';
    if (_newFoodModalTrigger) { _newFoodModalTrigger.focus(); _newFoodModalTrigger = null; }
}

document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('foods-modal').style.display !== 'none') closeFoodsModal();
    if (document.getElementById('new-food-modal').style.display !== 'none') closeNewFoodModal();
});

// ── New food form submit ─────────────────────────────────────────
async function submitNewFood() {
    const form   = document.getElementById('nf-form');
    const errBox = document.getElementById('nf-errors');
    const data   = new FormData(form);
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const res  = await fetch('api.php?action=save_food', { method: 'POST', body: data, headers: { 'X-CSRF-Token': csrfToken } });
        const json = await res.json();
        if (json.errors) {
            errBox.textContent = json.errors.join(' ');
            errBox.style.display = 'block';
            return;
        }
        addFoodRow(json, 1);
        closeNewFoodModal();
    } catch(e) {
        errBox.textContent = 'Save failed — please try again.';
        errBox.style.display = 'block';
    }
}
