@extends('layouts.app')

@section('title', 'Warehouse Selection — Simpul')

@section('content')

{{-- ── Order Entry Form ─────────────────────────────────────── --}}
<section class="card" aria-labelledby="form-title">
    <h2 class="card-title" id="form-title">Create Order</h2>
    <p style="margin-bottom:1rem;font-size:.9rem;color:#4b5563;">
        Add one or more products, specify quantities, then click
        <strong>Evaluate Warehouses</strong> to receive an allocation recommendation.
        This tool simulates warehouse selection; no inventory is reserved.
    </p>

    <div id="order-rows" role="list" aria-label="Order lines">
        {{-- Rows injected by JS --}}
    </div>

    <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" id="add-row-btn"
                aria-label="Add product line">
            ＋ Add product
        </button>
        <button type="button" class="btn btn-primary" id="submit-btn"
                aria-label="Evaluate warehouse selection">
            Evaluate Warehouses
        </button>
    </div>
</section>

{{-- ── Results ──────────────────────────────────────────────── --}}
<section id="results-section" aria-live="polite" aria-label="Selection results" hidden>
</section>

{{-- ── Hidden product data ──────────────────────────────────── --}}
<script>
    /* Products injected server-side — no extra AJAX round-trip needed. */
    const PRODUCTS = @json($products->map(fn($p) => [
        'code'  => $p->code,
        'label' => $p->code . ' — ' . $p->name,
    ]));

    const API_URL   = '{{ route("api.warehouse-selection") }}';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    // ── State ────────────────────────────────────────────────────
    let rowCounter = 0;

    // ── Helpers ──────────────────────────────────────────────────
    function productOptions(selectedCode = '') {
        return PRODUCTS.map(p =>
            `<option value="${p.code}" ${p.code === selectedCode ? 'selected' : ''}>${escHtml(p.label)}</option>`
        ).join('');
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function addRow(code = '', qty = 1) {
        rowCounter++;
        const id = `row-${rowCounter}`;
        const div = document.createElement('div');
        div.className = 'order-row';
        div.setAttribute('role', 'listitem');
        div.id = id;
        div.innerHTML = `
            <div>
                <label for="product-${id}">Product</label>
                <select id="product-${id}" name="product_id" aria-label="Product for line ${rowCounter}">
                    ${productOptions(code)}
                </select>
            </div>
            <div>
                <label for="qty-${id}">Quantity</label>
                <input type="number" id="qty-${id}" name="quantity"
                       value="${qty}" min="1" max="9999"
                       aria-label="Quantity for line ${rowCounter}" />
            </div>
            <div style="padding-top:1.4rem;">
                <button type="button" class="btn-danger-sm" data-remove="${id}"
                        aria-label="Remove this line">✕</button>
            </div>`;
        document.getElementById('order-rows').appendChild(div);
    }

    function removeRow(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    function collectItems() {
        const rows = document.querySelectorAll('#order-rows .order-row');
        const items = [];
        rows.forEach(row => {
            const productId = row.querySelector('select[name="product_id"]').value;
            const quantity  = parseInt(row.querySelector('input[name="quantity"]').value, 10);
            items.push({ product_id: productId, quantity });
        });
        return items;
    }

    // ── Render helpers ───────────────────────────────────────────
    function badgeClass(status) {
        return { complete: 'badge-complete', partial: 'badge-partial', unfulfillable: 'badge-unfulfillable' }[status] || '';
    }

    function renderAllocations(allocations) {
        if (!allocations.length) return '<p style="color:#6b7280;font-size:.875rem;">No warehouse allocations.</p>';
        return allocations.map(w => `
            <div class="allocation-block">
                <div class="allocation-header">
                    <span>${escHtml(w.warehouse_code)} — ${escHtml(w.warehouse_city)}</span>
                    <span class="dist">${w.distance_km} km &nbsp;·&nbsp; score: ${w.warehouse_distance_score.toLocaleString()}</span>
                </div>
                <table class="allocation-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Requested</th>
                            <th>Allocated</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${w.items.map(i => `
                            <tr>
                                <td>${escHtml(i.product_code)} — ${escHtml(i.product_name)}</td>
                                <td>${i.requested_quantity}</td>
                                <td>${i.allocated_quantity}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>`).join('');
    }

    function renderShortages(items) {
        if (!items.length) return '';
        return `
            <div class="card" aria-labelledby="shortage-title">
                <h3 class="card-title" id="shortage-title">⚠️ Unfulfilled Items</h3>
                <ul class="shortage-list" role="list">
                    ${items.map(u => `
                        <li>
                            <span><strong>${escHtml(u.product_code)}</strong> — ${escHtml(u.product_name)}</span>
                            <span>
                                Requested <strong>${u.requested_quantity}</strong> &nbsp;·&nbsp;
                                Allocated <strong>${u.allocated_quantity}</strong> &nbsp;·&nbsp;
                                <span class="shortage-qty">Short ${u.shortage_quantity}</span>
                            </span>
                        </li>
                        <li style="border:none;padding:.1rem 0;font-size:.8rem;color:#6b7280;">${escHtml(u.reason)}</li>`).join('')}
                </ul>
            </div>`;
    }

    function renderExplanations(explanations) {
        if (!explanations.length) return '';
        return `
            <div class="card" aria-labelledby="exp-title">
                <h3 class="card-title" id="exp-title">💡 Decision Explanations</h3>
                <ul class="explanation-list" role="list">
                    ${explanations.map(e => `<li>${escHtml(e)}</li>`).join('')}
                </ul>
            </div>`;
    }

    function renderResult(data) {
        const section = document.getElementById('results-section');
        section.removeAttribute('hidden');

        section.innerHTML = `
            <div class="card" aria-labelledby="result-title">
                <div class="result-header">
                    <h2 class="card-title" id="result-title" style="margin:0;border:none;padding:0;">Selection Result</h2>
                    <span class="badge ${badgeClass(data.status)}">${data.status}</span>
                </div>
                <p class="result-summary" style="margin-bottom:1rem;">${escHtml(data.summary)}</p>
                <div class="metrics">
                    <div class="metric">Warehouse(s) used: <span>${data.allocations.length}</span></div>
                    <div class="metric">Allocated lines: <span>${data.total_allocated_lines}</span></div>
                    <div class="metric">Distance score: <span>${data.total_distance_score.toLocaleString()}</span></div>
                </div>
                <h3 style="font-size:.95rem;font-weight:600;margin-bottom:.5rem;">Warehouse Allocations</h3>
                ${renderAllocations(data.allocations)}
            </div>
            ${renderShortages(data.unfulfilled_items)}
            ${renderExplanations(data.explanations)}`;

        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderErrors(errors) {
        const section = document.getElementById('results-section');
        section.removeAttribute('hidden');

        let html = '<div class="alert alert-error"><strong>Validation errors:</strong><ul style="margin:.5rem 0 0 1rem;">';
        if (typeof errors === 'object') {
            Object.values(errors).flat().forEach(msg => {
                html += `<li>${escHtml(msg)}</li>`;
            });
        } else {
            html += `<li>${escHtml(String(errors))}</li>`;
        }
        html += '</ul></div>';
        section.innerHTML = html;
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── Event handlers ───────────────────────────────────────────
    document.getElementById('add-row-btn').addEventListener('click', () => addRow());

    document.getElementById('order-rows').addEventListener('click', e => {
        const removeId = e.target.closest('[data-remove]')?.dataset.remove;
        if (removeId) removeRow(removeId);
    });

    document.getElementById('submit-btn').addEventListener('click', async () => {
        const rows = document.querySelectorAll('#order-rows .order-row');
        if (!rows.length) {
            renderErrors({ items: ['Please add at least one product line.'] });
            return;
        }

        const btn = document.getElementById('submit-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner" aria-hidden="true"></span> Evaluating…';

        const items = collectItems();

        try {
            const resp = await fetch(API_URL, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ items }),
            });

            const data = await resp.json();

            if (resp.status === 422) {
                renderErrors(data.errors ?? data.message ?? 'Validation failed.');
            } else if (!resp.ok) {
                renderErrors(data.error ?? `Server error ${resp.status}.`);
            } else {
                renderResult(data.data ?? data);
            }
        } catch (err) {
            renderErrors('Network error — could not reach the server. Please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });

    // ── Init ─────────────────────────────────────────────────────
    // Start with one empty row
    addRow();
</script>
@endsection
