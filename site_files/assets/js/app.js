function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toTitleCase(str) {
    return str.toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
}

function scaleNutrients(prefix) {
    prefix = prefix || '';
    const g       = parseFloat(document.getElementById(prefix + 'field-grams').value) || 0;
    const fPer100 = parseFloat(document.getElementById(prefix + 'fiber_per_100g').value) || 0;
    const pPer100 = parseFloat(document.getElementById(prefix + 'protein_per_100g').value) || 0;
    if (!parseInt(document.getElementById(prefix + 'usda_loaded').value)) return;
    document.getElementById(prefix + 'field-fiber').value   = Math.round(fPer100 * g / 10) / 10;
    document.getElementById(prefix + 'field-protein').value = Math.round(pPer100 * g / 10) / 10;
}

function applyUsda(idx, resultsEl, statusEl, inputEl, prefix) {
    prefix = prefix || '';
    const f = resultsEl._data[idx];
    document.getElementById(prefix + 'field-name').value    = toTitleCase(f.name);
    document.getElementById(prefix + 'field-qty').value     = f.qty_desc || '';
    document.getElementById(prefix + 'field-grams').value   = f.grams;
    document.getElementById(prefix + 'field-fiber').value   = f.fiber;
    document.getElementById(prefix + 'field-protein').value = f.protein;
    document.getElementById(prefix + 'fiber_per_100g').value   = f.fiber_per_100g;
    document.getElementById(prefix + 'protein_per_100g').value = f.protein_per_100g;
    document.getElementById(prefix + 'usda_loaded').value = '1';
    resultsEl.style.display = 'none';
    inputEl.value = '';
    statusEl.textContent = 'Filled from USDA — adjust grams or values as needed.';
    document.getElementById(prefix + 'field-grams').focus();
}

function initUsdaSearch(inputId, resultsId, statusId, prefix) {
    const inputEl   = document.getElementById(inputId);
    const resultsEl = document.getElementById(resultsId);
    const statusEl  = document.getElementById(statusId);
    if (!inputEl) return;
    let debounce;
    inputEl.addEventListener('input', () => {
        clearTimeout(debounce);
        const q = inputEl.value.trim();
        if (q.length < 2) { resultsEl.style.display = 'none'; statusEl.textContent = ''; return; }
        statusEl.textContent = 'Searching…';
        debounce = setTimeout(async () => {
            try {
                const res   = await fetch('api.php?action=usda_search&q=' + encodeURIComponent(q));
                const foods = await res.json();
                if (foods.error) { statusEl.textContent = 'Error: ' + foods.error; return; }
                statusEl.textContent = foods.length ? '' : 'No results found.';
                if (!foods.length) { resultsEl.style.display = 'none'; return; }
                resultsEl.innerHTML = foods.map((f, idx) =>
                    `<div class="search-result-item" tabindex="0"
                          onclick="applyUsda(${idx},document.getElementById('${resultsId}'),document.getElementById('${statusId}'),document.getElementById('${inputId}'),'${prefix}')"
                          onkeydown="if(event.key==='Enter')applyUsda(${idx},document.getElementById('${resultsId}'),document.getElementById('${statusId}'),document.getElementById('${inputId}'),'${prefix}')">
                        <div>${escHtml(f.name)}</div>
                        <div class="sr-meta">${f.grams}g${f.qty_desc ? ' · ' + escHtml(f.qty_desc) : ''} &nbsp;·&nbsp; ${f.fiber}g fiber &nbsp;·&nbsp; ${f.protein}g protein</div>
                    </div>`
                ).join('');
                resultsEl._data = foods;
                resultsEl.style.display = 'block';
            } catch(e) { statusEl.textContent = 'Request failed.'; }
        }, 350);
    });
    inputEl.addEventListener('blur', () => setTimeout(() => { resultsEl.style.display = 'none'; }, 180));
}

function ensureLocalDate() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const localDate = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`;
    const url = new URL(window.location.href);
    if (url.searchParams.get('today') !== localDate) {
        url.searchParams.set('today', localDate);
        window.location.replace(url.toString());
    }
}
