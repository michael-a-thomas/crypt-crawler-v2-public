<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crypt Crawler</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #111; color: #eee; }
        .top { position: sticky; top: 0; background: #1c1c1c; border-bottom: 1px solid #333; padding: 12px; z-index: 50; }
        .controls { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        button, select { padding: 8px 10px; border-radius: 6px; border: 1px solid #555; background: #222; color: #fff; }
        button:hover { cursor: pointer; background: #2a2a2a; }
        .progress-wrap { border: 1px solid #333; border-radius: 8px; padding: 10px; background: #151515; }
        #stateLine { margin-bottom: 8px; color: #9ecbff; font-size: 14px; }
        #progressTable { width: 100%; border-collapse: collapse; font-size: 13px; }
        #progressTable th, #progressTable td { border: 1px solid #333; padding: 6px; text-align: left; }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); padding: 14px; }
        .card { background: #1a1a1a; border: 1px solid #333; border-radius: 8px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
        .thumb { width: 100%; height: 220px; object-fit: cover; border-radius: 6px; background: #2a2a2a; }
        .title { font-weight: bold; line-height: 1.3; }
        .meta { color: #bdbdbd; font-size: 13px; }
        .price { color: #89e089; font-weight: bold; }
        .size { display: inline-block; font-size: 12px; padding: 2px 6px; border: 1px solid #555; border-radius: 20px; width: fit-content; }
        a { color: #9ecbff; }
    </style>
</head>
<body>
<div class="top">
    <div class="controls">
        <button id="startBtn">Start Crawl</button>
        <button id="stopBtn">Stop Crawl</button>
        <button id="clearBtn">Clear All Listings</button>
        <select id="sourceFilter"><option value="">Source: All</option></select>
        <select id="categoryFilter"><option value="All">Category: All</option></select>
        <select id="sizeFilter"><option value="">Size: All</option></select>
        <button id="priceSortBtn">Price: Low -> High</button>
        <button id="abcSortBtn">ABC: A -> Z</button>
    </div>
    <div class="progress-wrap">
        <div id="stateLine">Current Progress: idle</div>
        <table id="progressTable">
            <thead>
            <tr>
                <th>Source</th>
                <th>Search Term</th>
                <th>Method</th>
                <th>Accepted/Target</th>
                <th>Duplicates Skipped</th>
                <th>Rejected/Filtered</th>
            </tr>
            </thead>
            <tbody id="progressBody"></tbody>
        </table>
    </div>
</div>

<div id="cards" class="grid"></div>

<script>
    const state = {
        source: '',
        category: 'All',
        size: '',
        sort: 'price',
        dir: 'ASC'
    };

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
    }

    async function callApi(action, params = {}) {
        const query = new URLSearchParams({action, ...params});
        const res = await fetch('?' + query.toString(), {cache: 'no-store'});
        return res.json();
    }

    function resetSort() {
        state.sort = 'price';
        state.dir = 'ASC';
        document.getElementById('priceSortBtn').textContent = 'Price: Low -> High';
        document.getElementById('abcSortBtn').textContent = 'ABC: A -> Z';
    }

    async function loadFilters() {
        const data = await callApi('filters');
        if (!data.ok) return;
        const sourceSel = document.getElementById('sourceFilter');
        const categorySel = document.getElementById('categoryFilter');
        const sizeSel = document.getElementById('sizeFilter');

        sourceSel.innerHTML = '<option value="">Source: All</option>';
        (data.sources || []).forEach(v => sourceSel.insertAdjacentHTML('beforeend', `<option value="${esc(v)}">${esc(v)}</option>`));
        categorySel.innerHTML = '';
        (data.categories || []).forEach(v => categorySel.insertAdjacentHTML('beforeend', `<option value="${esc(v)}">Category: ${esc(v)}</option>`));
        sizeSel.innerHTML = '<option value="">Size: All</option>';
        (data.sizes || []).forEach(v => sizeSel.insertAdjacentHTML('beforeend', `<option value="${esc(v)}">${esc(v)}</option>`));

        categorySel.value = state.category;
    }

    async function loadListings() {
        const data = await callApi('listings', {
            source: state.source,
            category: state.category,
            size: state.size,
            sort: state.sort,
            dir: state.dir
        });
        const cards = document.getElementById('cards');
        cards.innerHTML = '';
        (data.items || []).forEach(item => {
            const img = item.image_url ? `<img class="thumb" src="${esc(item.image_url)}" alt="thumb">` : '<div class="thumb"></div>';
            const size = item.size ? `<span class="size">Size: ${esc(item.size)}</span>` : '';
            const desc = item.description ? `<div class="meta">${esc(item.description)}</div>` : '';
            cards.insertAdjacentHTML('beforeend', `
                <article class="card">
                    ${img}
                    <div class="title">${esc(item.title)}</div>
                    <div class="meta">${esc(item.source)} - <span class="price">$${Number(item.price || 0).toFixed(2)}</span></div>
                    ${size}
                    ${desc}
                    <a href="${esc(item.url)}" target="_blank" rel="noopener">Open Listing</a>
                </article>
            `);
        });
    }

    async function loadProgress() {
        const data = await callApi('progress');
        if (!data.ok) return;
        const st = data.state || {};
        document.getElementById('stateLine').textContent =
            `Current Progress: ${st.status || 'idle'} | source: ${st.current_source || '-'} | search term: ${st.current_search_term || '-'} | method: ${st.current_method || '-'} | updated: ${st.updated_at || '-'}`;

        const body = document.getElementById('progressBody');
        body.innerHTML = '';
        (data.progress || []).forEach(row => {
            body.insertAdjacentHTML('beforeend', `
                <tr>
                    <td>${esc(row.source)}</td>
                    <td>${esc(row.search_term)}</td>
                    <td>${esc(row.method)}</td>
                    <td>${esc(row.accepted)}/${esc(row.target)}</td>
                    <td>${esc(row.duplicates_skipped)}</td>
                    <td>${esc(row.rejected_filtered)}</td>
                </tr>
            `);
        });
    }

    document.getElementById('startBtn').addEventListener('click', async () => {
        await callApi('start');
        await loadProgress();
    });
    document.getElementById('stopBtn').addEventListener('click', async () => {
        await callApi('stop');
        await loadProgress();
    });
    document.getElementById('clearBtn').addEventListener('click', async () => {
        await callApi('clear');
        resetSort();
        await loadFilters();
        await loadListings();
        await loadProgress();
    });

    document.getElementById('sourceFilter').addEventListener('change', async (e) => {
        state.source = e.target.value;
        resetSort();
        await loadListings();
    });
    document.getElementById('categoryFilter').addEventListener('change', async (e) => {
        state.category = e.target.value;
        resetSort();
        await loadListings();
    });
    document.getElementById('sizeFilter').addEventListener('change', async (e) => {
        state.size = e.target.value;
        resetSort();
        await loadListings();
    });

    document.getElementById('priceSortBtn').addEventListener('click', async () => {
        state.sort = 'price';
        state.dir = state.dir === 'ASC' ? 'DESC' : 'ASC';
        document.getElementById('priceSortBtn').textContent = state.dir === 'ASC' ? 'Price: Low -> High' : 'Price: High -> Low';
        await loadListings();
    });

    document.getElementById('abcSortBtn').addEventListener('click', async () => {
        state.sort = 'alpha';
        state.dir = state.dir === 'ASC' ? 'DESC' : 'ASC';
        document.getElementById('abcSortBtn').textContent = state.dir === 'ASC' ? 'ABC: A -> Z' : 'ABC: Z -> A';
        await loadListings();
    });

    async function init() {
        await loadFilters();
        await loadListings();
        await loadProgress();
        setInterval(async () => {
            await loadProgress();
            await loadListings();
            await loadFilters();
        }, 1200);
    }

    init();
</script>
</body>
</html>
