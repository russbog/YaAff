// ── Collect a single step's data from a step section element ──
function collectStepData(stepSec) {
    var action = 'folder';
    var actionRadio = stepSec.querySelector('input.flow-step-action:checked');
    if (actionRadio) action = actionRadio.value;

    var folders = [];
    var redirectUrls = [];
    var weights = [];
    var redirectType = 302;
    var folderloadtypes = {};

    if (action === 'folder') {
        stepSec.querySelectorAll('.flow-step-folder').forEach(function (inp) {
            if (inp.value.trim()) {
                var folder = inp.value.trim();
                folders.push(folder);
                var w = inp.closest('.flow-path-item').querySelector('.flow-step-weight');
                weights.push(parseInt(w ? w.value : 0, 10) || 0);
                var modeBtn = inp.closest('.flow-path-item').querySelector('.flow-step-mode');
                if (modeBtn) folderloadtypes[folder] = modeBtn.dataset.mode || 'base';
            }
        });
    } else {
        stepSec.querySelectorAll('.flow-step-redirect').forEach(function (inp) {
            if (inp.value.trim()) {
                var url = inp.value.trim();
                var host = '';
                try { host = new URL(url).hostname.replace(/^www\./, ''); } catch (e) { host = 'redirect'; }
                redirectUrls.push({ url: url, label: host });
                var w = inp.closest('.flow-path-item').querySelector('.flow-step-weight');
                weights.push(parseInt(w ? w.value : 0, 10) || 0);
            }
        });
        var rtSel = stepSec.querySelector('select.flow-step-redirect-type');
        if (rtSel) redirectType = /^\d+$/.test(rtSel.value) ? parseInt(rtSel.value, 10) : rtSel.value;
    }

    return {
        action: action,
        folders: folders,
        redirect: { urls: redirectUrls, type: redirectType },
        weights: weights,
        folderloadtypes: folderloadtypes
    };
}

// ── Collect distribution settings from a flow section ──
function collectDistributionData(sec) {
    var flowDist = 'equal';
    var flowDistSel = sec.querySelector('.flow-dist');
    if (flowDistSel) flowDist = flowDistSel.value;

    var optimizeFor = 'Lead';
    var ofRadio = sec.querySelector('.flow-optimize-for:checked');
    if (ofRadio) optimizeFor = ofRadio.value;

    var optimizeMode = 'funnels';
    var omRadio = sec.querySelector('.flow-optimize-mode:checked');
    if (omRadio) optimizeMode = omRadio.value;

    return {
        distribution: flowDist,
        optimize_for: optimizeFor,
        optimize_mode: optimizeMode
    };
}

// ── Collect all flows data as JSON for form submit ──
export function collectFlowsData() {
    var flows = [];
    var rows = document.querySelectorAll('.flow-list-row');
    rows.forEach(function (row) {
        var fi = row.dataset.flowIndex;
        var sec = document.getElementById('sec-flow-' + fi);
        if (!sec) return;

        var name = row.querySelector('.flow-name-label').value || 'Flow';

        // Flow filters from QueryBuilder
        var fb = $('#flow-filters-' + fi);
        var filters = {};
        try { filters = fb.queryBuilder('getRules') || {}; } catch (e) {}

        var flowType = 'regular';
        var ftSel = sec.querySelector('.flow-type');
        if (ftSel) flowType = ftSel.value;

        var flowWeight = 100;
        var fwInp = sec.querySelector('.flow-weight');
        if (fwInp) flowWeight = parseInt(fwInp.value, 10) || 0;

        var dist = collectDistributionData(sec);

        // Collect steps from separate step sections
        var steps = [];
        document.querySelectorAll('.step-section[data-flow-index="' + fi + '"]').forEach(function (stepSec) {
            steps.push(collectStepData(stepSec));
        });

        flows.push({
            name: name,
            type: flowType,
            weight: flowWeight,
            filters: filters,
            distribution: dist.distribution,
            optimize_for: dist.optimize_for,
            optimize_mode: dist.optimize_mode,
            steps: steps
        });
    });
    return JSON.stringify(flows);
}
