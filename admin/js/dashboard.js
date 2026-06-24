/* Real-time traffic dashboard.
 *
 * Renders KPI cards, a dependency-free dual area chart with hover tooltip,
 * a traffic-quality panel (donut + meters) and Top breakdown bar lists.
 * Polls dashboard.php?action=data on a configurable interval. The data
 * contract (summary / series / top_country / top_flow) is unchanged. */
(function () {
    'use strict';

    // --- number formatting -------------------------------------------------
    const nf0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
    const nf2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const fmt = {
        int: v => nf0.format(Number(v) || 0),
        money: v => '$' + nf2.format(Number(v) || 0),
        pct: v => nf2.format(Number(v) || 0) + '%',
        dec: v => nf2.format(Number(v) || 0),
        compact: v => {
            v = Number(v) || 0;
            const a = Math.abs(v);
            if (a >= 1e6) return (v / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M';
            if (a >= 1e3) return (v / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k';
            return nf0.format(v);
        },
    };

    // Big highlight cards (financial outcome of the traffic).
    const KPI_PRIMARY = [
        { key: 'revenue', label: 'Revenue', icon: 'bi-cash-stack', accent: '#2ecc8f', fmt: 'money' },
        { key: 'cost', label: 'Cost', icon: 'bi-wallet2', accent: '#ffa94d', fmt: 'money' },
        { key: 'profit', label: 'Profit', icon: 'bi-graph-up-arrow', accent: '#2ecc8f', fmt: 'money', signed: true },
        { key: 'roi', label: 'ROI', icon: 'bi-bullseye', accent: '#f7c948', fmt: 'pct', signed: true },
    ];

    // Compact volume / performance tiles.
    const KPI_SECONDARY = [
        { key: 'clicks', label: 'Clicks', icon: 'bi-cursor-fill', accent: '#3b9cff', fmt: 'int' },
        { key: 'uniques', label: 'Uniques', icon: 'bi-person-check', accent: '#22d3ee', fmt: 'int' },
        { key: 'conversions', label: 'Conversions', icon: 'bi-check2-circle', accent: '#a78bfa', fmt: 'int' },
        { key: 'leads', label: 'Leads', icon: 'bi-flag-fill', accent: '#ffa94d', fmt: 'int' },
        { key: 'purchases', label: 'Purchases', icon: 'bi-bag-check-fill', accent: '#2ecc8f', fmt: 'int' },
        { key: 'cr', label: 'CR', icon: 'bi-percent', accent: '#f7c948', fmt: 'pct' },
        { key: 'epc', label: 'EPC', icon: 'bi-coin', accent: '#3b9cff', fmt: 'money' },
        { key: 'bots', label: 'Bots', icon: 'bi-bug-fill', accent: '#ff6b6b', fmt: 'int' },
    ];

    const $ = id => document.getElementById(id);
    let timer = null;
    let curRange = 86400;
    let lastSeries = [];

    function tz() {
        try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; }
        catch (e) { return 'UTC'; }
    }

    function load() {
        const camp = $('dash-camp').value;
        const end = Math.floor(Date.now() / 1000);
        const start = end - curRange;
        const url = `dashboard.php?action=data&campId=${camp}&start=${start}&end=${end}&tz=${encodeURIComponent(tz())}`;
        $('dash').classList.add('is-loading');
        fetch(url)
            .then(r => r.json())
            .then(render)
            .catch(() => { setUpdated(false); })
            .finally(() => $('dash').classList.remove('is-loading'));
    }

    function render(data) {
        renderKpis(data.summary || {});
        renderQuality(data.summary || {});
        renderChart(data.series || []);
        renderTop('dash-top-country', data.top_country || [], true);
        renderTop('dash-top-flow', data.top_flow || [], false);
        setUpdated(true);
    }

    function setUpdated(ok) {
        const dot = $('dash-live');
        const refresh = parseInt($('dash-refresh').value, 10);
        dot.classList.toggle('off', !(ok && refresh > 0));
        $('dash-updated-text').textContent = ok
            ? 'Updated ' + new Date().toLocaleTimeString()
            : 'Connection error';
    }

    // --- KPI cards ---------------------------------------------------------
    function kpiCard(cfg, s) {
        const raw = s[cfg.key] !== undefined ? s[cfg.key] : 0;
        const value = fmt[cfg.fmt](raw);
        let valClass = '';
        if (cfg.signed) valClass = Number(raw) > 0 ? 'pos' : (Number(raw) < 0 ? 'neg' : '');
        const sub = kpiSub(cfg, s);
        return `<div class="kpi" style="--kpi-accent:${cfg.accent}">
            <div class="kpi-top">
                <span class="kpi-label">${cfg.label}</span>
                <span class="kpi-icon"><i class="bi ${cfg.icon}"></i></span>
            </div>
            <div class="kpi-value ${valClass}">${value}</div>
            ${sub ? `<div class="kpi-sub">${sub}</div>` : ''}
        </div>`;
    }

    function kpiSub(cfg, s) {
        switch (cfg.key) {
            case 'profit': return `Rev ${fmt.money(s.revenue || 0)} · Cost ${fmt.money(s.cost || 0)}`;
            case 'roi': return 'Return on investment';
            case 'revenue': return `${fmt.int(s.conversions || 0)} conversions`;
            case 'cost': return `CPC ${fmt.money(s.cpc || 0)}`;
            case 'uniques': return `${fmt.pct(s.uniques_ratio || 0)} of clicks`;
            case 'conversions': return `CR ${fmt.pct(s.cr || 0)}`;
            case 'bots': return `${fmt.pct(s.bot_ratio || 0)} of traffic`;
            case 'cr': return 'Conversion rate';
            case 'epc': return 'Per click';
            default: return '';
        }
    }

    function renderKpis(s) {
        $('dash-kpis').innerHTML =
            `<div class="dash-kpi-row primary">${KPI_PRIMARY.map(c => kpiCard(c, s)).join('')}</div>
             <div class="dash-kpi-row secondary">${KPI_SECONDARY.map(c => kpiCard(c, s)).join('')}</div>`;
    }

    // --- Traffic quality ---------------------------------------------------
    function renderQuality(s) {
        const clicks = Number(s.clicks) || 0;
        const uniques = Number(s.uniques) || 0;
        const ratio = clicks > 0 ? (uniques / clicks) * 100 : 0;
        const returning = Math.max(0, clicks - uniques);

        const R = 50, C = 2 * Math.PI * R;
        const dash = (ratio / 100) * C;

        const meters = [
            { label: 'Conversion rate', val: fmt.pct(s.cr || 0), pct: Math.min(100, Number(s.cr) || 0), color: '#2ecc8f' },
            { label: 'Bot rate', val: fmt.pct(s.bot_ratio || 0), pct: Math.min(100, Number(s.bot_ratio) || 0), color: '#ff6b6b' },
            { label: 'Blocked', val: fmt.int(s.blocked || 0), pct: clicks + Number(s.blocked || 0) > 0 ? (Number(s.blocked || 0) / (clicks + Number(s.blocked || 0))) * 100 : 0, color: '#ffa94d' },
        ];

        $('dash-quality').innerHTML = `
            <div class="dash-donut-wrap">
                <div class="dash-donut">
                    <svg width="116" height="116" viewBox="0 0 116 116">
                        <circle cx="58" cy="58" r="${R}" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="11"/>
                        <circle cx="58" cy="58" r="${R}" fill="none" stroke="#22d3ee" stroke-width="11"
                            stroke-linecap="round" stroke-dasharray="${dash.toFixed(2)} ${C.toFixed(2)}"/>
                    </svg>
                    <div class="dash-donut-center">
                        <span class="dd-val">${fmt.pct(ratio)}</span>
                        <span class="dd-lbl">Unique</span>
                    </div>
                </div>
                <div class="dash-donut-legend">
                    <div class="dash-dl-item">
                        <div class="dash-dl-top"><i style="background:#22d3ee"></i> Unique visitors</div>
                        <div class="dash-dl-val">${fmt.int(uniques)}</div>
                    </div>
                    <div class="dash-dl-item">
                        <div class="dash-dl-top"><i style="background:rgba(255,255,255,0.2)"></i> Returning / repeat</div>
                        <div class="dash-dl-val">${fmt.int(returning)}</div>
                    </div>
                </div>
            </div>
            <div class="dash-meters">
                ${meters.map(m => `
                    <div class="dash-meter">
                        <div class="dash-meter-top"><span>${m.label}</span><b>${m.val}</b></div>
                        <div class="dash-meter-track"><div class="dash-meter-fill" style="width:${m.pct.toFixed(1)}%;background:${m.color}"></div></div>
                    </div>`).join('')}
            </div>`;
    }

    // --- Top breakdown bars ------------------------------------------------
    function flag(code) {
        if (/^[A-Za-z]{2}$/.test(code)) {
            const cc = code.toUpperCase();
            const emoji = String.fromCodePoint(...[...cc].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
            return `<span class="dash-bar-flag">${emoji}</span>`;
        }
        return `<span class="dash-bar-flag code">${escapeHtml(String(code).slice(0, 3).toUpperCase())}</span>`;
    }

    function renderTop(id, rows, withFlag) {
        const el = $(id);
        if (!rows.length) {
            el.innerHTML = `<div class="dash-empty"><i class="bi bi-inbox"></i>No data for this period</div>`;
            return;
        }
        const max = Math.max(1, ...rows.map(r => Number(r.clicks) || 0));
        const totalClicks = rows.reduce((a, r) => a + (Number(r.clicks) || 0), 0) || 1;
        el.innerHTML = `<div class="dash-bars">` + rows.map(r => {
            const clicks = Number(r.clicks) || 0;
            const w = (clicks / max) * 100;
            const share = (clicks / totalClicks) * 100;
            const lead = withFlag ? flag(r.name) : `<span class="dash-bar-flag code">${escapeHtml(String(r.name).slice(0, 2).toUpperCase())}</span>`;
            return `<div class="dash-bar-row">
                ${lead}
                <div class="dash-bar-main">
                    <div class="dash-bar-line">
                        <span class="dash-bar-name" title="${escapeHtml(r.name)}">${escapeHtml(r.name)}</span>
                        <span class="dash-bar-val"><b>${fmt.int(clicks)}</b> clicks · ${fmt.int(r.uniques)} uniq</span>
                    </div>
                    <div class="dash-bar-track"><div class="dash-bar-fill" style="width:${w.toFixed(1)}%"></div></div>
                </div>
                <span class="dash-bar-pct">${share.toFixed(1)}%</span>
            </div>`;
        }).join('') + `</div>`;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // --- Chart: dependency-free dual area chart with hover -----------------
    const chartGeom = { pts: [], series: [] };

    function renderChart(series) {
        lastSeries = series;
        const canvas = $('dash-chart');
        const wrap = canvas.parentElement;
        const dpr = window.devicePixelRatio || 1;
        const cssW = wrap.clientWidth || 600;
        const cssH = 260;
        canvas.width = cssW * dpr;
        canvas.height = cssH * dpr;
        canvas.style.height = cssH + 'px';
        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssW, cssH);

        const padL = 46, padR = 16, padT = 14, padB = 30;
        const w = cssW - padL - padR, h = cssH - padT - padB;

        if (!series.length) {
            ctx.fillStyle = '#61719a';
            ctx.font = '13px Roboto, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data for this period', cssW / 2, cssH / 2);
            chartGeom.pts = [];
            return;
        }

        const clicks = series.map(p => Number(p.clicks) || 0);
        const convs = series.map(p => Number(p.conversions) || 0);
        const maxY = niceMax(Math.max(1, ...clicks, ...convs));
        const n = series.length;
        const x = i => padL + (n === 1 ? w / 2 : (w * i) / (n - 1));
        const y = v => padT + h - (h * v) / maxY;

        // gridlines + y labels
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.fillStyle = '#61719a';
        ctx.font = '11px Roboto, sans-serif';
        ctx.textAlign = 'right';
        ctx.lineWidth = 1;
        for (let g = 0; g <= 4; g++) {
            const gy = padT + (h * g) / 4;
            ctx.beginPath(); ctx.moveTo(padL, gy); ctx.lineTo(padL + w, gy); ctx.stroke();
            ctx.fillText(fmt.compact(maxY * (4 - g) / 4), padL - 8, gy + 4);
        }

        drawArea(ctx, series, x, y, p => Number(p.clicks) || 0, padT + h, '#3b9cff', 'rgba(59,156,255,0.28)');
        drawArea(ctx, series, x, y, p => Number(p.conversions) || 0, padT + h, '#2ecc8f', 'rgba(46,204,143,0.26)');

        // x labels (first / mid / last)
        ctx.fillStyle = '#61719a';
        ctx.textAlign = 'center';
        const idxs = n === 1 ? [0] : [0, Math.floor((n - 1) / 2), n - 1];
        [...new Set(idxs)].forEach(i => {
            const px = Math.max(padL + 14, Math.min(x(i), padL + w - 14));
            ctx.fillText(xLabel(series[i].bucket), px, padT + h + 20);
        });

        // store geometry for hover
        chartGeom.series = series;
        chartGeom.pts = series.map((p, i) => ({
            x: x(i), cy: y(Number(p.clicks) || 0), vy: y(Number(p.conversions) || 0),
            clicks: Number(p.clicks) || 0, conversions: Number(p.conversions) || 0,
            revenue: Number(p.revenue) || 0, bucket: p.bucket,
        }));
        chartGeom.bounds = { padT, h, padL, w };
    }

    function niceMax(v) {
        if (v <= 5) return 5;
        const pow = Math.pow(10, Math.floor(Math.log10(v)));
        const n = v / pow;
        const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
        return step * pow;
    }

    function xLabel(b) {
        const s = String(b);
        return s.length >= 10 ? s.slice(5) : s; // YYYY-MM-DD -> MM-DD
    }

    function drawArea(ctx, series, x, y, pick, baseY, line, fill) {
        const n = series.length;
        // area fill
        ctx.beginPath();
        series.forEach((p, i) => {
            const px = x(i), py = y(pick(p));
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        });
        ctx.lineTo(x(n - 1), baseY);
        ctx.lineTo(x(0), baseY);
        ctx.closePath();
        const grad = ctx.createLinearGradient(0, 0, 0, baseY);
        grad.addColorStop(0, fill);
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.fill();
        // line
        ctx.beginPath();
        series.forEach((p, i) => {
            const px = x(i), py = y(pick(p));
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        });
        ctx.strokeStyle = line;
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';
        ctx.stroke();
        // points (only when few)
        if (n <= 40) {
            ctx.fillStyle = line;
            series.forEach((p, i) => {
                ctx.beginPath();
                ctx.arc(x(i), y(pick(p)), n === 1 ? 4 : 3, 0, Math.PI * 2);
                ctx.fill();
            });
        }
    }

    function onChartMove(e) {
        const tip = $('dash-chart-tip');
        if (!chartGeom.pts.length) { tip.classList.remove('show'); return; }
        const canvas = $('dash-chart');
        const rect = canvas.getBoundingClientRect();
        const mx = e.clientX - rect.left;
        let best = chartGeom.pts[0], bd = Infinity;
        chartGeom.pts.forEach(p => {
            const d = Math.abs(p.x - mx);
            if (d < bd) { bd = d; best = p; }
        });
        redrawWithCursor(best);
        tip.innerHTML = `<div class="tt-date">${escapeHtml(String(best.bucket))}</div>
            <div class="tt-row"><span><span class="tt-dot" style="background:#3b9cff"></span>Clicks</span><b>${fmt.int(best.clicks)}</b></div>
            <div class="tt-row"><span><span class="tt-dot" style="background:#2ecc8f"></span>Conv.</span><b>${fmt.int(best.conversions)}</b></div>
            <div class="tt-row"><span><span class="tt-dot" style="background:#f7c948"></span>Revenue</span><b>${fmt.money(best.revenue)}</b></div>`;
        const wrap = canvas.parentElement;
        let left = best.x;
        const tipW = tip.offsetWidth || 140;
        left = Math.max(tipW / 2 + 4, Math.min(left, wrap.clientWidth - tipW / 2 - 4));
        tip.style.left = left + 'px';
        const tipTop = Math.max(0, Math.min(best.cy, best.vy) - 8);
        tip.style.top = Math.min(tipTop, wrap.clientHeight - (tip.offsetHeight || 80) - 4) + 'px';
        tip.classList.add('show');
    }

    function redrawWithCursor(pt) {
        renderChart(lastSeries);
        if (!chartGeom.bounds) return;
        const ctx = $('dash-chart').getContext('2d');
        const { padT, h } = chartGeom.bounds;
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 4]);
        ctx.beginPath(); ctx.moveTo(pt.x, padT); ctx.lineTo(pt.x, padT + h); ctx.stroke();
        ctx.setLineDash([]);
        [['#3b9cff', pt.cy], ['#2ecc8f', pt.vy]].forEach(([c, cy]) => {
            ctx.beginPath(); ctx.arc(pt.x, cy, 5, 0, Math.PI * 2);
            ctx.fillStyle = '#0f1830'; ctx.fill();
            ctx.lineWidth = 2.5; ctx.strokeStyle = c; ctx.stroke();
        });
    }

    function onChartLeave() {
        $('dash-chart-tip').classList.remove('show');
        renderChart(lastSeries);
    }

    // --- controls ----------------------------------------------------------
    function applyRefresh() {
        if (timer) { clearInterval(timer); timer = null; }
        const ms = parseInt($('dash-refresh').value, 10);
        if (ms > 0) timer = setInterval(load, ms);
        const dot = $('dash-live');
        if (dot) dot.classList.toggle('off', !(ms > 0));
    }

    function spinRefresh() {
        const btn = $('dash-refresh-now');
        btn.classList.add('is-spinning');
        setTimeout(() => btn.classList.remove('is-spinning'), 700);
        load();
    }

    function initRange() {
        $('dash-range').addEventListener('click', e => {
            const btn = e.target.closest('button[data-range]');
            if (!btn) return;
            [...$('dash-range').children].forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            curRange = parseInt(btn.dataset.range, 10);
            load();
        });
    }

    $('dash-camp').addEventListener('change', load);
    $('dash-refresh').addEventListener('change', applyRefresh);
    $('dash-refresh-now').addEventListener('click', spinRefresh);
    initRange();

    const canvas = $('dash-chart');
    canvas.addEventListener('mousemove', onChartMove);
    canvas.addEventListener('mouseleave', onChartLeave);
    let rt = null;
    window.addEventListener('resize', () => {
        clearTimeout(rt);
        rt = setTimeout(() => renderChart(lastSeries), 150);
    });

    load();
    applyRefresh();
})();
