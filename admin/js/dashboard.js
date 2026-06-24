// Real-time dashboard: KPI cards, a dependency-free canvas line chart and top
// breakdown tables. Polls dashboard.php?action=data on a configurable interval.
(function () {
    'use strict';

    const KPI = [
        ['clicks', 'Clicks'],
        ['uniques', 'Uniques'],
        ['conversions', 'Conversions'],
        ['leads', 'Leads'],
        ['purchases', 'Purchases'],
        ['cr', 'CR %'],
        ['revenue', 'Revenue'],
        ['cost', 'Cost'],
        ['profit', 'Profit'],
        ['roi', 'ROI %'],
        ['epc', 'EPC'],
        ['bots', 'Bots'],
    ];

    let timer = null;

    function tz() {
        try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; }
        catch (e) { return 'UTC'; }
    }

    function load() {
        const camp = document.getElementById('dash-camp').value;
        const range = parseInt(document.getElementById('dash-range').value, 10);
        const end = Math.floor(Date.now() / 1000);
        const start = end - range;
        const url = `dashboard.php?action=data&campId=${camp}&start=${start}&end=${end}&tz=${encodeURIComponent(tz())}`;
        fetch(url).then(r => r.json()).then(render).catch(() => {});
    }

    function render(data) {
        renderKpis(data.summary || {});
        renderChart(data.series || []);
        renderTop('dash-top-country', data.top_country || []);
        renderTop('dash-top-flow', data.top_flow || []);
        document.getElementById('dash-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function renderKpis(s) {
        const wrap = document.getElementById('dash-kpis');
        wrap.innerHTML = KPI.map(([key, label]) => {
            const v = s[key] !== undefined ? s[key] : 0;
            return `<div class="col-6 col-md-3 col-lg-2">
                <div class="card h-100"><div class="card-body py-2 px-3">
                    <div class="text-muted small">${label}</div>
                    <div class="fs-5 fw-semibold">${v}</div>
                </div></div>
            </div>`;
        }).join('');
    }

    function renderTop(id, rows) {
        if (!rows.length) {
            document.getElementById(id).innerHTML = '<div class="text-muted small">No data</div>';
            return;
        }
        let html = '<table class="table table-sm mb-0"><thead><tr><th>Name</th><th class="text-end">Clicks</th><th class="text-end">Uniques</th></tr></thead><tbody>';
        rows.forEach(r => {
            html += `<tr><td>${escapeHtml(r.name)}</td><td class="text-end">${r.clicks}</td><td class="text-end">${r.uniques}</td></tr>`;
        });
        html += '</tbody></table>';
        document.getElementById(id).innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Minimal dependency-free dual-line canvas chart.
    function renderChart(series) {
        window.__lastSeries = series;
        const canvas = document.getElementById('dash-chart');
        const dpr = window.devicePixelRatio || 1;
        const cssW = canvas.clientWidth || canvas.parentElement.clientWidth;
        const cssH = canvas.height;
        canvas.width = cssW * dpr;
        canvas.height = cssH * dpr;
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssW, cssH);

        const padL = 40, padR = 10, padT = 10, padB = 22;
        const w = cssW - padL - padR, h = cssH - padT - padB;
        if (!series.length) {
            ctx.fillStyle = '#888';
            ctx.fillText('No data', padL, padT + h / 2);
            return;
        }

        const clicks = series.map(p => p.clicks);
        const convs = series.map(p => p.conversions);
        const maxY = Math.max(1, ...clicks, ...convs);
        const n = series.length;
        const x = i => padL + (n === 1 ? w / 2 : (w * i) / (n - 1));
        const y = v => padT + h - (h * v) / maxY;

        // axes + gridlines
        ctx.strokeStyle = '#e3e3e3';
        ctx.lineWidth = 1;
        ctx.fillStyle = '#888';
        ctx.font = '10px sans-serif';
        for (let g = 0; g <= 4; g++) {
            const gy = padT + (h * g) / 4;
            ctx.beginPath(); ctx.moveTo(padL, gy); ctx.lineTo(padL + w, gy); ctx.stroke();
            ctx.fillText(Math.round(maxY * (4 - g) / 4), 4, gy + 3);
        }

        drawLine(ctx, series, x, y, p => p.clicks, '#0d6efd');
        drawLine(ctx, series, x, y, p => p.conversions, '#198754');

        // x labels (first / mid / last)
        ctx.fillStyle = '#888';
        [0, Math.floor((n - 1) / 2), n - 1].forEach(i => {
            const label = String(series[i].bucket).slice(5);
            ctx.fillText(label, Math.min(x(i), padL + w - 30), padT + h + 14);
        });

        // legend
        legend(ctx, padL, padT, '#0d6efd', 'Clicks');
        legend(ctx, padL + 60, padT, '#198754', 'Conversions');
    }

    function drawLine(ctx, series, x, y, pick, color) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.beginPath();
        series.forEach((p, i) => {
            const px = x(i), py = y(pick(p));
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        });
        ctx.stroke();
    }

    function legend(ctx, lx, ly, color, text) {
        ctx.fillStyle = color;
        ctx.fillRect(lx, ly, 8, 8);
        ctx.fillStyle = '#555';
        ctx.fillText(text, lx + 12, ly + 8);
    }

    function applyRefresh() {
        if (timer) { clearInterval(timer); timer = null; }
        const ms = parseInt(document.getElementById('dash-refresh').value, 10);
        if (ms > 0) timer = setInterval(load, ms);
    }

    document.getElementById('dash-camp').addEventListener('change', load);
    document.getElementById('dash-range').addEventListener('change', load);
    document.getElementById('dash-refresh').addEventListener('change', applyRefresh);
    document.getElementById('dash-refresh-now').addEventListener('click', load);
    window.addEventListener('resize', () => renderChart(window.__lastSeries || []));

    load();
    applyRefresh();
})();
