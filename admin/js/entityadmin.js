/**
 * Generic entity admin (Phase 1).
 *
 * Renders the list and the create/edit form for any entity type purely from the
 * declarative schema embedded by entitypage.php, talking to entityapi.php. No
 * entity-specific JavaScript is required.
 */
(function () {
    const schema = JSON.parse(document.getElementById('entitySchema').textContent);
    const type = window.ENTITY_TYPE;
    const api = 'entityapi.php';
    const refCache = {};

    function apiCall(action, opts) {
        opts = opts || {};
        let url = api + '?action=' + encodeURIComponent(action) + '&type=' + encodeURIComponent(opts.type || type);
        if (opts.id != null) url += '&id=' + encodeURIComponent(opts.id);
        const init = { method: opts.method || 'GET', headers: { 'Accept': 'application/json' } };
        if (opts.body) {
            init.method = 'POST';
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        return fetch(url, init).then(r => r.json());
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    }

    function fmtDate(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        return d.toISOString().slice(0, 16).replace('T', ' ');
    }

    function loadList() {
        apiCall('list').then(res => {
            const tbody = $('#entityTable tbody').empty();
            if (!res.ok) { $('#entityListMsg').text(res.error || 'Failed to load'); return; }
            if (!res.items.length) {
                $('#entityListMsg').text('No ' + schema.title.toLowerCase() + ' yet. Click "New" to create one.');
                return;
            }
            $('#entityListMsg').text('');
            res.items.forEach(item => {
                const tr = $('<tr>');
                tr.append($('<td>').text(item.name));
                tr.append($('<td>').text(item.group || ''));
                tr.append($('<td>').text(fmtDate(item.updated_at)));
                const actions = $('<td class="entity-actions" style="text-align:right;">');
                $('<a title="Edit"><i class="bi bi-pencil-square"></i></a>').on('click', () => openForm(item.id)).appendTo(actions);
                $('<a title="Delete" style="color:#e57373;"><i class="bi bi-trash"></i></a>').on('click', () => del(item)).appendTo(actions);
                tr.append(actions);
                tbody.append(tr);
            });
        });
    }

    function refOptions(entityType) {
        if (refCache[entityType]) return Promise.resolve(refCache[entityType]);
        return apiCall('list', { type: entityType }).then(res => {
            refCache[entityType] = res.ok ? res.items : [];
            return refCache[entityType];
        });
    }

    function fieldInput(f, value) {
        const name = esc(f.key);
        const v = value;
        switch (f.type) {
            case 'textarea':
                return `<textarea class="form-control" name="${name}" rows="3">${esc(v || '')}</textarea>`;
            case 'json':
                return `<textarea class="form-control" name="${name}" rows="4">${esc(v ? JSON.stringify(v, null, 2) : '')}</textarea>`;
            case 'kvlines': {
                let lines = '';
                if (v && typeof v === 'object') lines = Object.keys(v).map(k => k + '=' + v[k]).join('\n');
                return `<textarea class="form-control" name="${name}" rows="3">${esc(lines)}</textarea>`;
            }
            case 'csv': {
                const s = Array.isArray(v) ? v.join(',') : (v || '');
                return `<input class="form-control" name="${name}" value="${esc(s)}" />`;
            }
            case 'checkbox':
                return `<input type="checkbox" name="${name}" ${v ? 'checked' : ''} />`;
            case 'number':
                return `<input type="number" step="any" class="form-control" name="${name}" value="${esc(v == null ? (f.default != null ? f.default : '') : v)}" />`;
            case 'select': {
                let opts = '';
                Object.keys(f.options || {}).forEach(k => {
                    const sel = String(v != null ? v : f.default) === k ? 'selected' : '';
                    opts += `<option value="${esc(k)}" ${sel}>${esc(f.options[k])}</option>`;
                });
                return `<select class="form-control" name="${name}">${opts}</select>`;
            }
            case 'entityref':
                return `<select class="form-control" name="${name}" data-ref="${esc(f.entity)}" data-val="${esc(v == null ? '' : v)}"><option value="">— none —</option></select>`;
            default:
                return `<input class="form-control" name="${name}" value="${esc(v == null ? (f.default != null ? f.default : '') : v)}" />`;
        }
    }

    function buildForm(item) {
        const wrap = $('#entityFormFields').empty();
        const settings = (item && item.settings) || {};
        schema.fields.forEach(f => {
            let value;
            if (f.key === 'name') value = item ? item.name : '';
            else if (f.key === 'group') value = item ? item.group : '';
            else value = settings[f.key];
            const grp = $('<div class="form-group">');
            grp.append(`<label>${esc(f.label)}${f.required ? ' *' : ''}</label>`);
            grp.append(fieldInput(f, value));
            if (f.help) grp.append(`<div class="field-help">${esc(f.help)}</div>`);
            wrap.append(grp);
        });
        // populate entityref selects asynchronously
        wrap.find('select[data-ref]').each(function () {
            const sel = $(this);
            const cur = sel.attr('data-val');
            refOptions(sel.attr('data-ref')).then(items => {
                items.forEach(it => {
                    sel.append(`<option value="${it.id}" ${String(it.id) === String(cur) ? 'selected' : ''}>${esc(it.name)}</option>`);
                });
            });
        });
    }

    function addTemplatePicker() {
        apiCall('templates').then(res => {
            if (!res.ok || !res.items.length) return;
            const grp = $('<div class="form-group">');
            grp.append('<label>Start from template</label>');
            const sel = $('<select class="form-control"><option value="">— blank —</option></select>');
            res.items.forEach((t, i) => sel.append(`<option value="${i}">${esc(t.name)}</option>`));
            sel.on('change', function () {
                const idx = $(this).val();
                if (idx === '') return;
                const t = res.items[idx];
                buildForm({ name: t.name, group: '', settings: t.settings });
                addTemplatePicker();
            });
            grp.append(sel);
            $('#entityFormFields').prepend(grp);
        });
    }

    function openForm(id) {
        $('#entityFormError').text('');
        if (id == null) {
            $('#entityFormTitle').text('New ' + schema.singular);
            $('#entityForm')[0].reset();
            $('#entityForm input[name=id]').val('');
            buildForm(null);
            addTemplatePicker();
            $('#entityFormModal').modal();
        } else {
            apiCall('get', { id }).then(res => {
                if (!res.ok) { alert(res.error || 'Failed'); return; }
                $('#entityFormTitle').text('Edit ' + schema.singular);
                $('#entityForm input[name=id]').val(res.item.id);
                buildForm(res.item);
                $('#entityFormModal').modal();
            });
        }
    }

    function collect() {
        const body = { id: $('#entityForm input[name=id]').val() };
        schema.fields.forEach(f => {
            const el = $('#entityForm [name="' + f.key + '"]');
            if (!el.length) return;
            body[f.key] = f.type === 'checkbox' ? el.is(':checked') : el.val();
        });
        return body;
    }

    function del(item) {
        if (!confirm('Delete "' + item.name + '"?')) return;
        apiCall('delete', { body: { id: item.id } }).then(res => {
            if (!res.ok) { alert(res.error || 'Delete failed'); return; }
            loadList();
        });
    }

    $(function () {
        $('#entityNew').on('click', () => openForm(null));
        $('#entityForm').on('submit', function (e) {
            e.preventDefault();
            apiCall('save', { body: collect() }).then(res => {
                if (!res.ok) { $('#entityFormError').text(res.error || 'Save failed'); return; }
                $.modal.close();
                loadList();
            });
        });
        loadList();
    });
})();
