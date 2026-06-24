async function campEditor(action, campId=null, name=null) {
    let body = `action=${action}`;
    if (campId)
        body += `&campId=${campId}`;
    if (name)
        body += `&name=${name}`;

    let url = new URL(window.location.href);
    let curPath = url.origin + url.pathname;
    if (curPath.endsWith(".php"))
        curPath = curPath.split('/').slice(0, -1).join('/');
    if (!curPath.endsWith("/"))
        curPath += "/";
    curPath+="campeditor.php";

    let res = await fetch(curPath, {
        method: "POST",
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body
    });
    let js = await res.json();
    if (js.error)
        alert(`An error occured: ${js.result}`);
    else
        window.location.reload();
}

let campMenuDropdown = null;
let _campMenuId = null;
let _campMenuName = null;
let _campMenuData = null;

function closeCampMenu() {
    if (campMenuDropdown) campMenuDropdown.classList.remove('show');
    _campMenuId = null;
    _campMenuName = null;
    _campMenuData = null;
}

function buildCampaignUrl(data) {
    const settings = data?.settings || {};
    const domains = Array.isArray(settings.domains) ? settings.domains : [];
    const preferredDomain = domains.find((domain) => {
        const value = String(domain || '').trim();
        return value && !value.includes('*');
    });
    const host = String(preferredDomain || window.location.host).replace(/^https?:\/\//, '').split('/')[0];
    const identifier = String(settings.identifier || '').trim().replace(/^\/+|\/+$/g, '');
    return `${window.location.protocol}//${host}/${encodeURIComponent(identifier)}`;
}

async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
}

document.addEventListener('DOMContentLoaded', function() {
    campMenuDropdown = document.createElement('div');
    campMenuDropdown.className = 'camp-menu-dropdown';
    campMenuDropdown.innerHTML = `
        <div class="camp-menu-item btn-rename"><i class="bi bi-pencil-fill"></i> Rename</div>
        <div class="camp-menu-item btn-clone"><i class="bi bi-copy"></i> Clone</div>
        <div class="camp-menu-item btn-copy-url"><i class="bi bi-link-45deg"></i> Copy URL</div>
        <div class="camp-menu-item btn-stats"><i class="bi bi-bar-chart-fill"></i> Statistics</div>
        <div class="camp-menu-item btn-allowed"><i class="bi bi-person-circle"></i> Allowed</div>
        <div class="camp-menu-item btn-blocked"><i class="bi bi-ban"></i> Blocked</div>
        <div class="camp-menu-item btn-leads"><i class="bi bi-coin"></i> Leads</div>
        <div class="camp-menu-divider"></div>
        <div class="camp-menu-item btn-delete camp-menu-danger"><i class="bi bi-trash-fill"></i> Delete</div>
    `;
    document.body.appendChild(campMenuDropdown);

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.camp-menu-btn') && !e.target.closest('.camp-menu-dropdown')) {
            closeCampMenu();
        }
    });

    document.addEventListener('scroll', closeCampMenu, true);

    campMenuDropdown.addEventListener('click', async function(e) {
        const menuItem = e.target.closest('.camp-menu-item');
        if (!menuItem || !_campMenuId) return;
        e.preventDefault();
        e.stopPropagation();
        const campaignId = _campMenuId;
        const campaignName = _campMenuName;
        const campaignData = _campMenuData;
        closeCampMenu();

        if (menuItem.classList.contains('btn-rename')) {
            const currentName = campaignName ?? '';
            const newName = prompt("Enter new campaign name:", currentName);
            if (newName == null) return;
            const trimmedName = newName.trim();
            if (trimmedName) {
                await campEditor('ren', campaignId, trimmedName);
            } else {
                alert('Campaign name can not be empty!');
            }
            return;
        }

        if (menuItem.classList.contains('btn-delete')) {
            if (confirm(`Are you sure? Going to delete campaign ${campaignName}.`)) {
                await campEditor('del', campaignId);
            }
            return;
        }

        if (menuItem.classList.contains('btn-clone')) {
            const defaultCloneName = `${campaignName ?? ''} (Clone)`;
            const newName = prompt("Enter cloned campaign name:", defaultCloneName);
            if (newName == null) return;
            const trimmedName = newName.trim();
            if (!trimmedName) {
                alert('Campaign name can not be empty!');
                return;
            }
            await campEditor('dup', campaignId, trimmedName);
            return;
        }

        if (menuItem.classList.contains('btn-copy-url')) {
            const url = buildCampaignUrl(campaignData);
            await copyText(url);
            alert(`Campaign URL copied:\n${url}`);
            return;
        }

        let startDateEndDateParams = getStartDateEndDateParams();
        if (menuItem.classList.contains('btn-stats')) {
            window.location.href = `statistics.php?campId=${campaignId}${startDateEndDateParams}`;
            return;
        }

        if (menuItem.classList.contains('btn-allowed')) {
            window.location.href = `clicks.php?campId=${campaignId}&view=allowed${startDateEndDateParams}`;
            return;
        }

        if (menuItem.classList.contains('btn-blocked')) {
            window.location.href = `clicks.php?campId=${campaignId}&view=blocked${startDateEndDateParams}`;
            return;
        }

        if (menuItem.classList.contains('btn-leads')) {
            window.location.href = `clicks.php?campId=${campaignId}&view=leads${startDateEndDateParams}`;
            return;
        }
    });
});

function campNameCellClick(e, cell) {
    const target = e.target;
    const row = cell.getRow();
    const campaignId = row.getData().id;
    if (!campaignId) return;

    const menuBtn = target.closest('.camp-menu-btn');
    if (menuBtn) {
        e.preventDefault();
        e.stopPropagation();
        const wasOpen = campMenuDropdown.classList.contains('show') && _campMenuId === campaignId;
        closeCampMenu();
        if (!wasOpen) {
            _campMenuId = campaignId;
            _campMenuName = row.getData().name;
            _campMenuData = row.getData();
            const btnRect = menuBtn.getBoundingClientRect();
            campMenuDropdown.classList.add('show');
            const menuH = campMenuDropdown.offsetHeight;
            const spaceBelow = window.innerHeight - btnRect.bottom;
            if (spaceBelow >= menuH) {
                campMenuDropdown.style.top = btnRect.bottom + 'px';
                campMenuDropdown.style.bottom = '';
            } else {
                campMenuDropdown.style.top = (btnRect.top - menuH) + 'px';
                campMenuDropdown.style.bottom = '';
            }
            campMenuDropdown.style.left = (btnRect.right - campMenuDropdown.offsetWidth) + 'px';
        }
        return;
    }
}

function getDateSuffix() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('startdate');
    const endDate = urlParams.get('enddate');
    const toDDMM = (d) => d.substring(0, 2) + d.substring(3, 5);
    if (startDate && endDate) {
        const s = toDDMM(startDate);
        const e = toDDMM(endDate);
        return s === e ? `_${s}` : `_${s}-${e}`;
    }
    const today = new Date();
    const dd = String(today.getDate()).padStart(2, '0');
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    return `_${dd}${mm}`;
}

function getStartDateEndDateParams() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('startdate');
    const endDate = urlParams.get('enddate');
    if (startDate && endDate) {
        return `&startdate=${startDate}&enddate=${endDate}`;
    }
    return '';
}
