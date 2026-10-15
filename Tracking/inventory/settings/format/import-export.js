(function () {
    const importExportScriptUrl = document.currentScript?.src || new URL('./import-export.js', document.baseURI).href;
    const runWhenReady = callback => {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, { once: true });
        else callback();
    };
    runWhenReady(() => {
    const PATH_SEGMENTS = window.location.pathname.split('/').filter(Boolean);
    const LAST_SEGMENT = PATH_SEGMENTS[PATH_SEGMENTS.length - 1] || '';
    let rawAssetContext = LAST_SEGMENT.replace(/\.(html|php)$/i, '');

    if (!rawAssetContext || rawAssetContext.toLowerCase() === 'index') {
        rawAssetContext = PATH_SEGMENTS[PATH_SEGMENTS.length - 2] || PATH_SEGMENTS[0] || 'asset';
    }

    const assetContextClean = rawAssetContext.replace(/s$/i, '');
    const assetDisplaySingular = `${assetContextClean.charAt(0).toUpperCase()}${assetContextClean.slice(1)}`;
    const moduleTable = window.INVENTORY_MODULE_TABLE || new URLSearchParams(window.location.search).get('table') || '';
    const backendUrl = new URL('./manual_add.php', window.location);
    if (moduleTable) backendUrl.searchParams.set('table', moduleTable);
    const BACKEND_URL = backendUrl.href;

    const ui = {
        importBtn: document.getElementById('importBtn'),
        importDropdown: document.getElementById('importDropdown'),
        importManualBtn: document.getElementById('importManualBtn'),
        importFileBtn: document.getElementById('importFileBtn'),
        exportBtn: document.getElementById('exportBtn'),
        tableHead: document.getElementById('tableHead'),
        tableBody: document.getElementById('tableBody'),
        deleteDropdown: document.getElementById('deleteDropdown'),
        addManualModal: document.getElementById('addManualModal'),
        closeAddModalBtn: document.getElementById('closeAddModalBtn'),
        cancelAddModalBtn: document.getElementById('cancelAddModalBtn'),
        addAssetForm: document.getElementById('addAssetForm') || document.querySelector('[data-form="manual-add"]') || document.getElementById('addLaptopForm'),
        addModalFormFields: document.getElementById('addModalFormFields'),
        addProgress: document.getElementById('addProgress'),
        addStepStatus: document.getElementById('addStepStatus'),
        addStepPercent: document.getElementById('addStepPercent'),
        addStepProgress: document.getElementById('addStepProgress'),
        addStepControls: document.getElementById('addStepControls'),
        addBackBtn: document.getElementById('addBackBtn'),
        addNextBtn: document.getElementById('addNextBtn'),
        saveAddBtn: document.getElementById('saveAddBtn')
    };
    let currentAddStep = 0;
    let addStepCount = 0;

    window.isSelectMode = typeof window.isSelectMode === 'boolean' ? window.isSelectMode : false;

    const SCHEMA = Object.freeze({
        refNo: ['refno', 'ref_no', 'ref', 'item_no', 'refno.'],
        jobNumber: ['jobnumber', 'job_no', 'jobno'],
        assetCode: ['assetcode', 'asset_code', 'assetid', 'assettag'],
        model: ['model', 'brand', 'type'],
        specs: ['specs', 'specifications', 'details'],
        company: ['company', 'comp'],
        name: ['name', 'username', 'employeename'],
        department: ['department', 'dept'],
        jobTrade: ['jobtrade', 'job_trade', 'position', 'role'],
        nationality: ['nationality', 'nat'],
        workArea: ['workarea', 'work_area', 'location'],
        serialNo: ['serialnumber', 'serial_number', 'sn', 's/n'],
        issuedDate: ['issueddate', 'issued_date', 'date'],
        status: ['status', 'state'],
        remarks: ['remarks', 'notes', 'comments']
    });

    const CANONICAL_FIELDS = Object.keys(SCHEMA);

    const HUMAN_READABLE_NAMES = {
        refNo: 'Ref No.',
        jobNumber: 'Job Number',
        assetCode: 'Asset Code',
        model: 'Model',
        specs: 'Specs',
        company: 'Company',
        name: 'Name',
        department: 'Department',
        jobTrade: 'Job Trade',
        nationality: 'Nationality',
        workArea: 'Work Area',
        serialNo: 'Serial Number',
        issuedDate: 'Issued Date',
        status: 'Status',
        remarks: 'Remarks'
    };

    const TARGET_HEADER_KEYS = CANONICAL_FIELDS.map(field => field.toLowerCase());
    const WORD_SEPARATOR_REGEX = /[\s\-_./]+/g;

    function normalizeString(value = '') {
        return String(value).trim().toLowerCase().replace(WORD_SEPARATOR_REGEX, '');
    }

    function formatLabel(fieldKey) {
        return HUMAN_READABLE_NAMES[fieldKey] || fieldKey.replace(/([A-Z])/g, ' $1').replace(/^./, first => first.toUpperCase());
    }

    function escapeHTML(value = '') {
        return String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char] || char));
    }

    function mapHeaderToFieldKey(text) {
        const normalizedHeader = normalizeString(text);

        for (const [fieldKey, aliases] of Object.entries(SCHEMA)) {
            if (aliases.includes(normalizedHeader) || normalizedHeader === fieldKey.toLowerCase()) {
                return fieldKey;
            }
        }

        return text
            .toLowerCase()
            .replace(/[.\-_]/g, '')
            .split(' ')
            .map((word, idx) => idx === 0 ? word : word.charAt(0).toUpperCase() + word.slice(1))
            .join('');
    }

    function createFormField(fieldKey, labelText) {
        const wrapper = document.createElement('div');
        wrapper.className = 'form-group';

        const label = document.createElement('label');
        label.htmlFor = `add_${fieldKey}`;
        label.textContent = labelText;

        const input = document.createElement('input');
        input.type = /date/i.test(labelText) || /date/i.test(fieldKey) ? 'date' : 'text';
        input.id = `add_${fieldKey}`;
        input.dataset.key = fieldKey;
        input.placeholder = `Enter ${labelText.toLowerCase()}...`;

        wrapper.append(label, input);
        return wrapper;
    }

    function showAddStep(stepIndex) {
        const steps = Array.from(ui.addModalFormFields?.querySelectorAll('.inventory-form-step') || []);
        if (!steps.length) {
            addStepCount = 0;
            ui.addProgress.hidden = true;
            ui.addStepControls.hidden = true;
            ui.saveAddBtn.hidden = false;
            return;
        }

        addStepCount = steps.length;
        currentAddStep = Math.max(0, Math.min(stepIndex, addStepCount - 1));
        steps.forEach((step, index) => {
            step.hidden = index !== currentAddStep;
        });

        const progress = Math.round(((currentAddStep + 1) / addStepCount) * 100);
        ui.addStepStatus.textContent = `Step ${currentAddStep + 1} of ${addStepCount}`;
        ui.addStepPercent.textContent = `${progress}%`;
        ui.addStepProgress.style.width = `${progress}%`;
        ui.addStepProgress.parentElement.setAttribute('aria-valuenow', String(progress));
        ui.addBackBtn.disabled = currentAddStep === 0;
        ui.addNextBtn.hidden = currentAddStep === addStepCount - 1;
        ui.saveAddBtn.hidden = currentAddStep !== addStepCount - 1;
        ui.addStepControls.hidden = addStepCount < 2;
        ui.addProgress.hidden = addStepCount < 2;
        ui.addModalFormFields.scrollTop = 0;
    }

    function buildDynamicFormFields() {
        if (!ui.addModalFormFields || !ui.tableHead) return false;

        const headers = Array.from(ui.tableHead.querySelectorAll('th[data-key]'));
        if (!headers.length) return false;

        ui.addModalFormFields.innerHTML = '';
        const seenFields = new Set();
        const fields = [];

        headers.forEach(th => {
            const fieldKey = String(th.dataset.key || '').trim();
            const normalizedKey = normalizeString(fieldKey);

            // Primary keys and generated reference values are managed by the database.
            if (!fieldKey || normalizedKey === 'id' || normalizedKey === 'refno' || normalizedKey === 'lastuser') return;
            if (seenFields.has(normalizedKey)) return;
            seenFields.add(normalizedKey);

            const labelNode = th.cloneNode(true);
            labelNode.querySelector('.sort-indicator')?.remove();
            const labelText = labelNode.textContent.trim() || formatLabel(fieldKey);
            fields.push(createFormField(fieldKey, labelText));
        });

        const fieldsPerStep = window.innerWidth <= 600
            ? (window.innerHeight <= 560 ? 2 : 3)
            : 8;
        for (let start = 0; start < fields.length; start += fieldsPerStep) {
            const step = document.createElement('div');
            step.className = 'edit-form-step inventory-form-step';
            step.hidden = start > 0;
            fields.slice(start, start + fieldsPerStep).forEach(field => step.appendChild(field));
            ui.addModalFormFields.appendChild(step);
        }
        showAddStep(0);
        return true;
    }

    function waitForTableHeaders(timeoutMs = 10000) {
        if (ui.tableHead?.querySelector('th[data-key]')) return Promise.resolve(true);

        return new Promise(resolve => {
            if (!ui.tableHead || typeof MutationObserver === 'undefined') {
                resolve(false);
                return;
            }

            let finished = false;
            const observer = new MutationObserver(() => {
                if (!ui.tableHead.querySelector('th[data-key]')) return;
                finished = true;
                observer.disconnect();
                clearTimeout(timeout);
                resolve(true);
            });

            const timeout = setTimeout(() => {
                if (finished) return;
                observer.disconnect();
                resolve(false);
            }, timeoutMs);

            observer.observe(ui.tableHead, { childList: true, subtree: true });
        });
    }

    function collectFormData() {
        const data = {};
        const inputs = ui.addModalFormFields ? ui.addModalFormFields.querySelectorAll('input[data-key]') : [];

        inputs.forEach(input => {
            const key = input.dataset.key;
            if (key) data[key] = input.value.trim();
        });

        return data;
    }

    function showToast(message, level = 'info') {
        if (typeof alert !== 'undefined') {
            alert(message);
            return;
        }

        console[level === 'error' ? 'error' : 'log'](message);
    }

    async function submitManualRecord() {
        const payload = collectFormData();
        console.info(`Submitting ${assetDisplaySingular} payload`, payload);

        try {
            const response = await fetch(BACKEND_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.status !== 'success') {
                showToast(`Application Error: ${data.message}`, 'error');
                return;
            }

            if (data.id && !payload.refNo) {
                payload.refNo = data.id;
            }

            renderRecordsToTable([payload]);
            ui.addManualModal?.classList.remove('show');
            ui.addAssetForm?.reset();
            showToast(`New ${assetDisplaySingular.toLowerCase()} record added successfully!`);
            window.appUndo?.show(data.undo_token, `New ${assetDisplaySingular.toLowerCase()} record added.`);
        } catch (error) {
            console.error('Manual submission failed', error);
            showToast('Unable to save record. Check your connection and try again.', 'error');
        }
    }

    function renderRecordsToTable() {
        if (window.__inventory?.reload) window.__inventory.reload();
    }
    function toggleImportDropdown(event) {
        event.stopPropagation();
        ui.importDropdown?.classList.toggle('show');
        ui.deleteDropdown?.classList.remove('show');
    }

    function closeOverlays(event) {
        ui.importDropdown?.classList.remove('show');
        ui.deleteDropdown?.classList.remove('show');

        if (event.target === ui.addManualModal) {
            ui.addManualModal?.classList.remove('show');
        }
    }

    ui.importManualBtn?.addEventListener('click', async () => {
        ui.importDropdown?.classList.remove('show');

        let fieldsReady = buildDynamicFormFields();
        if (!fieldsReady) {
            fieldsReady = await waitForTableHeaders();
            if (fieldsReady) fieldsReady = buildDynamicFormFields();
        }

        if (!fieldsReady) {
            showToast('Could not load this table\'s database columns. Refresh the page and try again.', 'error');
            return;
        }

        ui.addAssetForm?.reset();
        showAddStep(0);
        ui.addManualModal?.classList.add('show');
    });

    ui.closeAddModalBtn?.addEventListener('click', () => ui.addManualModal?.classList.remove('show'));
    ui.cancelAddModalBtn?.addEventListener('click', () => ui.addManualModal?.classList.remove('show'));
    ui.addBackBtn?.addEventListener('click', () => showAddStep(currentAddStep - 1));
    ui.addNextBtn?.addEventListener('click', () => showAddStep(currentAddStep + 1));
    ui.addAssetForm?.addEventListener('submit', event => {
        event.preventDefault();
        if (currentAddStep < addStepCount - 1) {
            showAddStep(currentAddStep + 1);
            return;
        }
        submitManualRecord();
    });

    ui.importBtn?.addEventListener('click', toggleImportDropdown);
    ui.importDropdown?.addEventListener('click', event => event.stopPropagation());
    window.addEventListener('click', closeOverlays);

    function parseCSVLine(line, delimiter = ',') {
        const values = [];
        let current = '';
        let inQuotes = false;

        for (let i = 0; i < line.length; i += 1) {
            const char = line[i];

            if (char === '"') {
                const nextChar = line[i + 1];
                if (inQuotes && nextChar === '"') {
                    current += '"';
                    i += 1;
                } else {
                    inQuotes = !inQuotes;
                }
                continue;
            }

            if (char === delimiter && !inQuotes) {
                values.push(current);
                current = '';
                continue;
            }

            current += char;
        }

        values.push(current);
        return values.map(value => value.trim().replace(/^"|"$/g, ''));
    }

    function matrixFromCSV(text) {
        const lines = String(text).replace(/^\uFEFF/, '').split(/\r?\n/).filter(line => line.trim());
        const delimiter = [',', '\t', ';'].map(candidate => ({
            candidate,
            score: lines.slice(0, 10).reduce((sum, line) => sum + parseCSVLine(line, candidate).length, 0)
        })).sort((left, right) => right.score - left.score)[0]?.candidate || ',';
        return lines.map(line => parseCSVLine(line, delimiter));
    }

    function matrixFromHtmlTable(htmlText) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlText, 'text/html');
        const rows = Array.from(doc.querySelectorAll('table tr'));

        return rows.map(row => Array.from(row.querySelectorAll('th, td')).map(cell => cell.textContent.trim()));
    }

    function getImportableHeaders() {
        return ui.tableHead ? Array.from(ui.tableHead.querySelectorAll('th[data-key]'))
            .map(th => String(th.dataset.key || '').trim())
            .filter(key => key && !['id', 'ref_no', 'refno', 'ref', 'reference', 'last_user', 'lastuser'].includes(normalizeString(key))) : [];
    }

    function canonicalField(header) {
        const normalized = normalizeString(header);
        for (const [fieldKey, aliases] of Object.entries(SCHEMA)) {
            if (normalized === normalizeString(fieldKey) || aliases.some(alias => normalizeString(alias) === normalized)) return fieldKey;
        }
        return '';
    }

    function createColumnMap(sourceHeaders, targetHeaders) {
        const direct = new Map(targetHeaders.map(header => [normalizeString(header), header]));
        const canonicalTargets = new Map();
        targetHeaders.forEach(header => {
            const canonical = canonicalField(header);
            if (canonical && !canonicalTargets.has(canonical)) canonicalTargets.set(canonical, header);
        });
        const map = {};
        sourceHeaders.forEach((source, index) => {
            let target = direct.get(normalizeString(source));
            if (!target) target = canonicalTargets.get(canonicalField(source));
            if (target && !Object.prototype.hasOwnProperty.call(map, target)) map[target] = index;
        });
        return map;
    }

    function detectHeaderRow(matrix, targetHeaders) {
        let best = null;
        for (let index = 0; index < Math.min(matrix.length, 100); index += 1) {
            const row = matrix[index] || [];
            const columnMap = createColumnMap(row, targetHeaders);
            const score = Object.keys(columnMap).length;
            if (score && (!best || score > best.score)) best = { index, row, columnMap, score };
        }
        return best;
    }

    function buildImportedRecords(matrix, header) {
        const records = [];
        let skipped = 0;
        matrix.slice(header.index + 1).forEach(row => {
            const values = row.map(value => String(value ?? '').trim());
            const nonEmpty = values.filter(Boolean);
            const first = normalizeString(nonEmpty[0] || '');
            const summaryRow = /^(grand)?total\b/.test(first) || /^subtotal\b/.test(first) || /^page\d+(of\d+)?$/.test(first);
            let repeatedHeader = true;
            Object.entries(header.columnMap).forEach(([target, column]) => {
                const cell = normalizeString(values[column] || '');
                if (cell !== normalizeString(header.row[column] || '') && cell !== normalizeString(target)) repeatedHeader = false;
            });
            if (!nonEmpty.length || summaryRow || repeatedHeader) { skipped += 1; return; }

            const record = {};
            Object.entries(header.columnMap).forEach(([target, column]) => {
                if (values[column]) record[target] = values[column];
            });
            if (Object.keys(record).length) records.push(record);
            else skipped += 1;
        });
        return { records, skipped };
    }

    let spreadsheetParserPromise;
    function loadSpreadsheetParser() {
        if (window.XLSX) return Promise.resolve(window.XLSX);
        if (!spreadsheetParserPromise) {
            spreadsheetParserPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = new URL('./vendor/xlsx.full.min.js', importExportScriptUrl).href;
                script.onload = () => window.XLSX ? resolve(window.XLSX) : reject(new Error('Excel reader did not initialize.'));
                script.onerror = () => reject(new Error('Could not load Excel reader. Check your internet connection and try again.'));
                document.head.appendChild(script);
            }).catch(error => { spreadsheetParserPromise = null; throw error; });
        }
        return spreadsheetParserPromise;
    }

    function decodeImportText(bytes) {
        if (bytes.length >= 2 && bytes[0] === 0xff && bytes[1] === 0xfe) return new TextDecoder('utf-16le').decode(bytes);
        if (bytes.length >= 2 && bytes[0] === 0xfe && bytes[1] === 0xff) return new TextDecoder('utf-16be').decode(bytes);
        return new TextDecoder('utf-8').decode(bytes).replace(/\0/g, '');
    }
    async function readImportFile(file) {
        const extension = file.name.split('.').pop().toLowerCase();
        const bytes = new Uint8Array(await file.arrayBuffer());
        const prefix = decodeImportText(bytes.slice(0, 2048)).trimStart();
        if (['csv', 'tsv', 'txt'].includes(extension)) {
            const text = decodeImportText(bytes);
            return [{ name: file.name, matrix: matrixFromCSV(text) }];
        }
        if (/^(<!doctype html|<html|<table)/i.test(prefix) && /<table/i.test(prefix)) {
            return [{ name: file.name, matrix: matrixFromHtmlTable(decodeImportText(bytes)) }];
        }
        const XLSX = await loadSpreadsheetParser();
        const workbook = XLSX.read(bytes, { type: 'array', cellDates: true });
        return workbook.SheetNames.map(name => ({
            name,
            matrix: XLSX.utils.sheet_to_json(workbook.Sheets[name], { header: 1, defval: '', raw: false, blankrows: true })
        }));
    }

    function showImportReview(records, sheetCount, skipped) {
        const columns = getImportableHeaders().filter(header => records.some(record => Object.prototype.hasOwnProperty.call(record, header)));
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay show';
        overlay.style.zIndex = '1200';
        const rowsHtml = records.map(record => `<tr>${columns.map(header => `<td>${escapeHTML(record[header] || '')}</td>`).join('')}</tr>`).join('');
        overlay.innerHTML = `<div class="modal-content" style="width:min(1100px,96vw);max-width:1100px;max-height:90vh;display:flex;flex-direction:column;">
            <div class="modal-header"><h2>Review Imported Records</h2><button type="button" class="close-modal-btn" data-import-cancel>&times;</button></div>
            <p>Found ${records.length} records across ${sheetCount} sheet${sheetCount === 1 ? '' : 's'}. All worksheet rows, including rows past print page breaks, are scanned. ${skipped} blank, repeated-header, summary, or unmatched rows are skipped.</p>
            <div style="overflow:auto;max-height:60vh"><table style="width:100%;border-collapse:collapse"><thead><tr>${columns.map(column => `<th>${escapeHTML(column.replace(/_/g, ' '))}</th>`).join('')}</tr></thead><tbody>${rowsHtml}</tbody></table></div>
            <p data-import-status></p><div class="modal-actions"><button type="button" class="btn-secondary" data-import-cancel>Cancel</button><button type="button" class="btn-primary" data-import-save>Save ${records.length} Records</button></div>
            </div>`;
        document.body.appendChild(overlay);
        const cancels = overlay.querySelectorAll('[data-import-cancel]');
        const close = () => overlay.remove();
        cancels.forEach(button => button.addEventListener('click', close));
        const saveButton = overlay.querySelector('[data-import-save]');
        const status = overlay.querySelector('[data-import-status]');
        const saved = new Set();
        const undoGroup = Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2, '0')).join('');
        let undoToken = null;
        saveButton.addEventListener('click', async () => {
            saveButton.disabled = true;
            cancels.forEach(button => { button.disabled = true; });
            let failures = 0;
            for (let index = 0; index < records.length; index += 1) {
                if (saved.has(index)) continue;
                status.textContent = `Saving record ${index + 1} of ${records.length}...`;
                try {
                    const response = await fetch(BACKEND_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ...records[index], undo_group: undoGroup })
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== 'success') throw new Error(result.message || `HTTP ${response.status}`);
                    saved.add(index);
                    undoToken = result.undo_token || undoToken;
                } catch (error) { failures += 1; console.error(`Import record ${index + 1} failed`, error); }
            }
            if (saved.size === records.length) {
                close();
                if (window.__inventory?.reload) await window.__inventory.reload();
                showToast(`Successfully saved ${records.length} imported records.`);
                window.appUndo?.show(undoToken, `Successfully saved ${records.length} imported records.`);
            } else {
                status.textContent = `Saved ${saved.size} of ${records.length}; ${failures} failed. Retry sends only unsaved records.`;
                if (saved.size && undoToken) {
                    window.appUndo?.show(undoToken, `Saved ${saved.size} imported records.`);
                }
                saveButton.textContent = `Retry ${records.length - saved.size} Unsaved Records`;
                saveButton.disabled = false;
                cancels.forEach(button => { button.disabled = false; });
            }
        });
    }

    function showSheetSelection(sheets) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay show';
        overlay.style.zIndex = '1200';
        const firstImportable = sheets.findIndex(sheet => sheet.records.length > 0);
        const options = sheets.map((sheet, index) => {
            const available = sheet.records.length > 0;
            const checked = available && index === firstImportable ? 'checked' : '';
            const disabled = available ? '' : 'disabled';
            const details = available ? `${sheet.records.length} records detected` : 'No matching table headers or data rows';
            return `<label style="display:flex;align-items:center;gap:10px;padding:12px;border-bottom:1px solid #e2e8f0;${available ? '' : 'opacity:.55;'}">
                <input type="checkbox" data-sheet-index="${index}" ${checked} ${disabled}>
                <span><strong>${escapeHTML(sheet.name)}</strong><br><small>${details}</small></span>
            </label>`;
        }).join('');
        overlay.innerHTML = `<div class="modal-content" style="width:min(650px,94vw);max-width:650px;">
            <div class="modal-header"><h2>Choose Worksheet(s)</h2><button type="button" class="close-modal-btn" data-sheet-cancel>&times;</button></div>
            <p>Select the worksheet or worksheets to include in the import. Sheets with no matching database headers are unavailable.</p>
            <div style="max-height:50vh;overflow:auto;border:1px solid #cbd5e1;border-radius:8px;">${options}</div>
            <p data-sheet-error style="color:#b91c1c;min-height:1.2em;"></p>
            <div class="modal-actions"><button type="button" class="btn-secondary" data-sheet-cancel>Cancel</button><button type="button" class="btn-primary" data-sheet-review>Review Selected Sheets</button></div>
            </div>`;
        document.body.appendChild(overlay);
        const closeButtons = overlay.querySelectorAll('[data-sheet-cancel]');
        closeButtons.forEach(button => button.addEventListener('click', () => overlay.remove()));
        overlay.querySelector('[data-sheet-review]').addEventListener('click', () => {
            const selected = Array.from(overlay.querySelectorAll('[data-sheet-index]:checked'))
                .map(input => sheets[Number(input.dataset.sheetIndex)])
                .filter(Boolean);
            const records = selected.flatMap(sheet => sheet.records);
            if (!records.length) {
                overlay.querySelector('[data-sheet-error]').textContent = 'Select at least one worksheet with detected records.';
                return;
            }
            const skipped = selected.reduce((total, sheet) => total + sheet.skipped, 0);
            overlay.remove();
            showImportReview(records, selected.length, skipped);
        });
    }

    function mapHeadersAndBuildTable(sheets) {
        const targetHeaders = getImportableHeaders();
        if (!targetHeaders.length) { showToast('No importable columns found in the current table.', 'error'); return; }
        const prepared = sheets.map(sheet => {
            const header = detectHeaderRow(sheet.matrix, targetHeaders);
            if (!header) return { name: sheet.name, records: [], skipped: 0 };
            const result = buildImportedRecords(sheet.matrix, header);
            return { name: sheet.name, records: result.records, skipped: result.skipped };
        });
        if (!prepared.some(sheet => sheet.records.length)) {
            showToast('No matching data rows were found in any worksheet.', 'error');
            return;
        }
        showSheetSelection(prepared);
    }
    function triggerFileInput() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.csv,.tsv,.txt,.xls,.xlsx,.xlsm,.xlsb,.xlt,.xltx,.xltm,.ods,.fods,.xml';
        input.addEventListener('change', async event => {
            const file = event.target.files?.[0];
            if (!file) return;
            try {
                const sheets = await readImportFile(file);
                mapHeadersAndBuildTable(sheets);
            } catch (error) {
                console.error('Workbook import failed', error);
                showToast(error.message || 'Unable to read this workbook.', 'error');
            }
        });
        input.click();
    }

    ui.importFileBtn?.addEventListener('click', triggerFileInput);

    function buildExportHtml(headers, rows) {
        const totalCols = headers.length;
        const thickBorder = '3px solid #0F172A';
        const thinBorder = '1px solid #CBD5E1';
        const rowStyle = 'height: 35pt; mso-height-source: userscripts;';

        const headerCells = headers.map((header, index) => {
            const borderLeft = index === 0 ? thickBorder : thinBorder;
            const borderRight = index === totalCols - 1 ? thickBorder : thinBorder;
            const style = `background-color: #1E293B; color: #FFFFFF; font-weight: bold; text-align: center; border-top: ${thickBorder}; border-bottom: ${thickBorder}; border-left: ${borderLeft}; border-right: ${borderRight};`;
            return `<th style="${style}">${escapeHTML(header)}</th>`;
        }).join('');

        const rowHtml = rows.map((row, rowIndex) => {
            const cells = Array.from(row.querySelectorAll('td')).filter(cell => !cell.classList.contains('checkbox-col') && !cell.querySelector('input[type="checkbox"]'));
            if (!cells.length) return '';

            const cellsHtml = cells.map((cell, cellIndex) => {
                const borderLeft = cellIndex === 0 ? thickBorder : thinBorder;
                const borderRight = cellIndex === totalCols - 1 ? thickBorder : thinBorder;
                const borderBottom = rowIndex === rows.length - 1 ? thickBorder : thinBorder;
                const style = `text-align: left; padding-left: 6px; border-top: ${thinBorder}; border-bottom: ${borderBottom}; border-left: ${borderLeft}; border-right: ${borderRight};`;
                return `<td style="${style}">${escapeHTML(cell.textContent.trim())}</td>`;
            }).join('');

            return `<tr style="${rowStyle}">${cellsHtml}</tr>`;
        }).join('');

        const colsMarkup = headers.map(() => '<col width="320" style="width: 53.14pt;" />').join('');

        return `
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta charset="utf-8" />
                <style>
                    table { border-collapse: collapse; table-layout: fixed; font-family: 'Segoe UI', Arial, sans-serif; }
                    tr { height: 35pt; mso-height-source: userscripts; }
                    th, td { font-size: 10.5pt; vertical-align: middle; white-space: normal; word-wrap: break-word; }
                </style>
            </head>
            <body>
                <table>
                    <colgroup>${colsMarkup}</colgroup>
                    <thead><tr style="${rowStyle}">${headerCells}</tr></thead>
                    <tbody>${rowHtml}</tbody>
                </table>
            </body>
            </html>
        `;
    }

    function executeExcelExport() {
        if (!ui.tableHead || !ui.tableBody) return;

        const headers = Array.from(ui.tableHead.querySelectorAll('th'))
            .map(cell => cell.textContent.trim())
            .filter(text => text && !text.includes('Select Rows') && text !== 'âœ”');

        const rows = Array.from(ui.tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('.loading') && !row.querySelector('.error'));

        if (!rows.length) {
            showToast('No records found to export.', 'error');
            return;
        }

        const html = buildExportHtml(headers, rows);
        const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        const link = document.createElement('a');
        const filename = `${rawAssetContext}_registry_${new Date().toISOString().slice(0, 10)}.xls`;

        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    ui.exportBtn?.addEventListener('click', executeExcelExport);
});
})();







