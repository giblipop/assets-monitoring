(() => {
    const lookupModal = document.getElementById('employeeIssueModal');
    const issueModal = document.getElementById('assetIssueModal');
    const frame = document.getElementById('contentFrame');
    const nameInput = document.getElementById('employeeIssueName');
    const newEmployeeName = document.getElementById('newEmployeeName');
    const newEmployeeDepartment = document.getElementById('newEmployeeDepartment');
    const newEmployeeWorkArea = document.getElementById('newEmployeeWorkArea');
    const newEmployeeJobTrade = document.getElementById('newEmployeeJobTrade');
    const newEmployeeNationality = document.getElementById('newEmployeeNationality');
    const employeeResults = document.getElementById('employeeIssueResults');
    const startIssueButton = document.getElementById('employeeIssueStart');
    const returnSelectedButton = document.getElementById('returnSelectedAssets');
    const issuedSection = document.getElementById('employeeIssuedSection');
    const issuedSummary = document.getElementById('employeeIssuedSummary');
    const issuedRows = document.getElementById('employeeIssuedRows');
    const lookupNotice = document.getElementById('employeeIssueNotice');
    const modulePicker = document.getElementById('assetModulePicker');
    const modulePanels = document.getElementById('assetModulePanels');
    const selectionCount = document.getElementById('assetSelectionCount');
    const confirmIssueButton = document.getElementById('assetIssueConfirm');
    const issueNotice = document.getElementById('assetIssueNotice');
    const newEmployeeNotice = document.getElementById('newEmployeeNotice');
    const apiUrl = new URL('./employee_issue_api.php', window.location.href);
    const csrfUrl = new URL('../../page/auth_api.php?action=csrf', window.location.href);

    if (!lookupModal || !issueModal || !frame || !nameInput) return;

    let employeeSearchTimer = 0;
    let selectedEmployee = null;
    let modules = [];
    let selectedModules = new Set();
    let selectedAssets = new Map();
    let assetSearchTimers = new Map();
    let assetSearchRequestIds = new Map();
    let assetSearchSequence = 0;
    let csrfToken = '';

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char]));
    }

    function setNotice(element, message = '', isError = false) {
        element.textContent = message;
        element.classList.toggle('error', isError);
    }

    async function requestJson(url, options = {}) {
        const response = await fetch(url, { cache: 'no-store', ...options });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('The server returned an invalid response.');
        }
        if (!response.ok || data.success === false) {
            throw new Error(data.message || `The request failed (${response.status}).`);
        }
        return data;
    }

    function showLookup() {
        issueModal.hidden = true;
        lookupModal.hidden = false;
        selectedEmployee = null;
        nameInput.value = '';
        newEmployeeName.value = '';
        newEmployeeDepartment.value = '';
        newEmployeeWorkArea.value = '';
        newEmployeeJobTrade.value = '';
        newEmployeeNationality.value = '';
        employeeResults.replaceChildren();
        issuedRows.replaceChildren();
        issuedSection.hidden = true;
        startIssueButton.disabled = true;
        updateReturnSelection();
        activateTaskTab('return');
        setNotice(lookupNotice);
        setNotice(newEmployeeNotice);
        window.setTimeout(() => nameInput.focus(), 30);
    }

    function closeIssue() {
        issueModal.hidden = true;
        lookupModal.hidden = false;
        startIssueButton.focus();
    }

    function closeLookup() {
        issueModal.hidden = true;
        lookupModal.hidden = true;
        frame.focus();
    }

    function renderEmployeeChoices(employees, search) {
        employeeResults.replaceChildren();
        for (const employee of employees) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'employee-employee-option';
            button.setAttribute('role', 'option');
            const name = document.createElement('strong');
            name.textContent = employee.name;
            const details = document.createElement('span');
            details.textContent = [employee.department, employee.work_area].filter(Boolean).join(' · ');
            button.append(name, details);
            button.addEventListener('click', () => selectEmployee(employee));
            employeeResults.append(button);
        }
        if (search.trim().length >= 2) {
            const hint = document.createElement('div');
            hint.className = 'employee-issue-notice';
            hint.textContent = employees.length
                ? 'Choose the employee to see their currently assigned assets.'
                : 'No assigned assets found for that name.';
            employeeResults.append(hint);
        }
    }

    async function searchEmployees() {
        const search = nameInput.value.trim();
        selectedEmployee = null;
        issuedSection.hidden = true;
        setNotice(lookupNotice);
        if (search.length < 2) {
            employeeResults.replaceChildren();
            return;
        }
        employeeResults.replaceChildren();
        const url = new URL(apiUrl);
        url.searchParams.set('action', 'employees');
        url.searchParams.set('q', search);
        try {
            const data = await requestJson(url);
            if (nameInput.value.trim() !== search) return;
            renderEmployeeChoices(data.employees || [], search);
        } catch (error) {
            setNotice(lookupNotice, error.message, true);
        }
    }

    async function selectEmployee(employee) {
        selectedEmployee = {
            name: employee.name,
            department: employee.department || '',
            work_area: employee.work_area || ''
        };
        nameInput.value = selectedEmployee.name;
        employeeResults.replaceChildren();
        setNotice(lookupNotice);
        await loadIssuedAssets();
    }

    function displayValue(record, keys) {
        for (const key of keys) {
            const match = Object.keys(record).find(candidate => candidate.toLowerCase() === key);
            if (match && record[match] !== null && String(record[match]).trim() !== '') {
                return String(record[match]);
            }
        }
        return '—';
    }

    function issueDuration(value) {
        const raw = String(value ?? '').trim();
        let year;
        let month;
        let day;
        const numeric = /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/.exec(raw);
        const namedMonth = /^(\d{1,2})[-/\s]([a-z]{3,9})[-/\s](\d{2}|\d{4})$/i.exec(raw);
        if (numeric) {
            year = Number(numeric[1]);
            month = Number(numeric[2]) - 1;
            day = Number(numeric[3]);
        } else if (namedMonth) {
            const monthNames = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
            month = monthNames.indexOf(namedMonth[2].slice(0, 3).toLowerCase());
            year = Number(namedMonth[3]);
            if (year < 100) year += year < 70 ? 2000 : 1900;
            day = Number(namedMonth[1]);
        } else {
            return 'Issue date unavailable';
        }
        if (month < 0) return 'Invalid issue date';
        const start = new Date(year, month, day);
        if (start.getFullYear() !== year || start.getMonth() !== month || start.getDate() !== day) return 'Invalid issue date';
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (start > today) return 'Not issued yet';
        const todayUTC = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
        const totalDays = Math.floor((todayUTC - Date.UTC(start.getFullYear(), start.getMonth(), start.getDate())) / 86400000);
        let totalMonths = (today.getFullYear() - start.getFullYear()) * 12 + today.getMonth() - start.getMonth();
        const currentMonthLastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
        if (today.getDate() < Math.min(start.getDate(), currentMonthLastDay)) totalMonths--;
        const years = Math.floor(totalMonths / 12);
        const months = totalMonths % 12;
        const monthIndex = start.getMonth() + totalMonths;
        const anniversaryYear = start.getFullYear() + Math.floor(monthIndex / 12);
        const anniversaryMonth = ((monthIndex % 12) + 12) % 12;
        const anniversaryDay = Math.min(start.getDate(), new Date(anniversaryYear, anniversaryMonth + 1, 0).getDate());
        const anniversary = new Date(anniversaryYear, anniversaryMonth, anniversaryDay);
        const remainingDays = Math.floor((todayUTC - Date.UTC(anniversary.getFullYear(), anniversary.getMonth(), anniversary.getDate())) / 86400000);
        const parts = [];
        if (years) parts.push(`${years} year${years === 1 ? '' : 's'}`);
        if (months) parts.push(`${months} month${months === 1 ? '' : 's'}`);
        if (remainingDays || !parts.length) parts.push(`${remainingDays} day${remainingDays === 1 ? '' : 's'}`);
        return parts.join(', ');
    }

    async function loadIssuedAssets() {
        if (!selectedEmployee) return;
        const employee = selectedEmployee.name;
        issuedSection.hidden = false;
        issuedSummary.textContent = 'Loading…';
        issuedRows.innerHTML = '<tr><td class="employee-issued-empty" colspan="9">Loading assigned assets…</td></tr>';
        const url = new URL(apiUrl);
        url.searchParams.set('action', 'assigned');
        url.searchParams.set('name', employee);
        try {
            const data = await requestJson(url);
            if (!selectedEmployee || selectedEmployee.name !== employee) return;
            const assets = data.assets || [];
            issuedSummary.textContent = `${assets.length} asset(s) currently recorded with this employee name.`;
            document.getElementById('employeeIssuedName').textContent = employee;
            if (!assets.length) {
                issuedRows.innerHTML = '<tr><td class="employee-issued-empty" colspan="9">No assets are currently recorded with this employee name.</td></tr>';
                updateReturnSelection();
                return;
            }
            issuedRows.innerHTML = assets.map(record => `<tr>
                <td><input type="checkbox" class="return-asset-checkbox" data-return-table="${escapeHTML(record.table)}" data-return-id="${escapeHTML(record.id)}" aria-label="Select ${escapeHTML(record.module)} asset ${escapeHTML(displayValue(record, ['asset_code', 'ref_no', 'serial_number', 'serial_no']))}"></td>
                <td>${escapeHTML(record.module || '—')}</td>
                <td>${escapeHTML(displayValue(record, ['asset_code', 'ref_no', 'serial_number', 'serial_no']))}</td>
                <td>${escapeHTML(displayValue(record, ['model']))}</td>
                <td>${escapeHTML(displayValue(record, ['serial_number', 'serial_no']))}</td>
                <td>${escapeHTML(displayValue(record, ['status']))}</td>
                <td>${escapeHTML(displayValue(record, ['issued_date', 'date_issued', 'issue_date', 'dateissued']))}</td>
                <td>${escapeHTML(issueDuration(displayValue(record, ['issued_date', 'date_issued', 'issue_date', 'dateissued'])))}</td>
                <td>${escapeHTML(record.last_user || '—')}</td>
            </tr>`).join('');
            updateReturnSelection();
        } catch (error) {
            issuedSummary.textContent = 'Could not load this employee’s assets.';
            issuedRows.innerHTML = `<tr><td class="employee-issued-empty" colspan="9">${escapeHTML(error.message)}</td></tr>`;
        }
    }

    function updateReturnSelection() {
        const selected = issuedRows.querySelectorAll('.return-asset-checkbox:checked').length;
        const total = issuedRows.querySelectorAll('.return-asset-checkbox').length;
        document.getElementById('returnSelectionCount').textContent = `${selected} of ${total} selected`;
        if (returnSelectedButton) returnSelectedButton.disabled = selected === 0;
    }

    function issueEmployeeDetails() {
        return {
            name: newEmployeeName.value.trim(),
            department: newEmployeeDepartment.value.trim(),
            work_area: newEmployeeWorkArea.value.trim(),
            job_trade: newEmployeeJobTrade.value.trim(),
            nationality: newEmployeeNationality.value.trim()
        };
    }

    function selectionKey(table, id) {
        return `${table}\u0000${id}`;
    }

    function updateSelectionCount() {
        const count = selectedAssets.size;
        selectionCount.textContent = `${count} asset${count === 1 ? '' : 's'} selected`;
        confirmIssueButton.disabled = count === 0;
        modulePanels.querySelectorAll('.asset-module-panel').forEach(panel => {
            const module = modules.find(item => item.name === panel.dataset.module);
            if (module) updateModulePanelSummary(panel, module);
        });
    }

    function updateModulePanelSummary(panel, module) {
        const count = Array.from(selectedAssets.values())
            .filter(asset => asset.table === module.name).length;
        const summary = panel.querySelector('.asset-module-summary');
        summary.textContent = `${count} selected`;
        summary.hidden = count === 0;
    }

    function activateModulePanel(moduleName) {
        modulePanels.querySelectorAll('.asset-module-panel').forEach(panel => {
            const expanded = panel.dataset.module === moduleName;
            panel.classList.toggle('expanded', expanded);
            panel.querySelector('.asset-module-toggle').setAttribute('aria-expanded', String(expanded));
            panel.querySelector('.asset-module-body').hidden = !expanded;
        });
    }

    function renderAssets(module, assets, search, append = false) {
        const panel = modulePanels.querySelector(`[data-module="${CSS.escape(module.name)}"]`);
        if (!panel) return;
        const container = panel.querySelector('.asset-module-rows');
        if (!assets.length && !append) {
            container.innerHTML = '<div class="asset-list-note">No available assets match this search.</div>';
            return;
        }
        const rows = assets.map(asset => {
            const key = selectionKey(module.name, asset.id);
            const checked = selectedAssets.has(key) ? ' checked' : '';
            const subtitle = [asset.model, asset.serial_number || asset.serial_no, asset.status].filter(Boolean).join(' · ');
            return `<label class="asset-option">
                <input type="checkbox" data-asset-module="${escapeHTML(module.name)}" data-asset-id="${escapeHTML(asset.id)}"${checked}>
                <strong>${escapeHTML(asset.asset_number || asset.id)}</strong>
                <span>${escapeHTML(subtitle || 'Available')}</span>
            </label>`;
        }).join('');
        if (append) container.insertAdjacentHTML('beforeend', rows);
        else container.innerHTML = rows;
    }

    function moduleFieldDefault(fieldName) {
        const normalized = fieldName.toLowerCase();
        const employee = issueEmployeeDetails();
        if (normalized === 'name' || normalized === 'assigned_to' || normalized === 'issued_to' || normalized === 'employee_name') return employee.name;
        if (normalized === 'department') return employee.department;
        if (normalized === 'work_area' || normalized === 'workarea') return employee.work_area;
        if (normalized === 'jobtrade' || normalized === 'job_trade') return employee.job_trade;
        if (normalized === 'nationality') return employee.nationality;
        return '';
    }

    function renderSelectedAssetForms(module) {
        const panel = modulePanels.querySelector(`[data-module="${CSS.escape(module.name)}"]`);
        if (!panel) return;
        const container = panel.querySelector('.asset-module-selected-fields');
        const selections = Array.from(selectedAssets.values()).filter(asset => asset.table === module.name);
        container.replaceChildren();

        for (const asset of selections) {
            const card = document.createElement('section');
            card.className = 'asset-selected-form';
            const heading = document.createElement('h4');
            heading.textContent = `${asset.assetNumber} — complete blank fields for this module`;
            card.append(heading);
            if (!asset.fieldsLoaded) {
                const loading = document.createElement('p');
                loading.className = 'asset-selected-form-note';
                loading.textContent = 'Loading empty fields…';
                card.append(loading);
            } else if (asset.fieldsError) {
                const error = document.createElement('p');
                error.className = 'asset-selected-form-note error';
                error.textContent = asset.fieldsError;
                card.append(error);
            } else if (!asset.fields.length) {
                const empty = document.createElement('p');
                empty.className = 'asset-selected-form-note';
                empty.textContent = 'This asset has no other blank fields to fill. Employee, status, and issue date are handled automatically.';
                card.append(empty);
            } else {
                const grid = document.createElement('div');
                grid.className = 'asset-selected-fields-grid';
                for (const field of asset.fields) {
                    const label = document.createElement('label');
                    label.className = 'asset-selected-field';
                    const title = document.createElement('span');
                    title.textContent = `${field.label}${field.required ? ' *' : ''}`;
                    let input;
                    if (field.type === 'textarea') {
                        input = document.createElement('textarea');
                        input.rows = 2;
                    } else if (field.type === 'select') {
                        input = document.createElement('select');
                        const placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = 'Select…';
                        input.append(placeholder);
                        for (const choice of field.choices || []) {
                            const option = document.createElement('option');
                            option.value = choice;
                            option.textContent = choice;
                            input.append(option);
                        }
                    } else {
                        input = document.createElement('input');
                        input.type = field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text';
                        if (input.type === 'number') input.step = 'any';
                    }
                    input.className = 'employee-issue-input asset-dynamic-field';
                    input.dataset.fieldName = field.name;
                    input.value = asset.fieldsValues[field.name] ?? moduleFieldDefault(field.name);
                    input.required = Boolean(field.required);
                    if (field.type !== 'number' && field.type !== 'date' && field.type !== 'select') {
                        input.maxLength = Number(field.max_length || 4000);
                    }
                    input.addEventListener('input', () => {
                        asset.fieldsValues[field.name] = input.value;
                    });
                    input.addEventListener('change', () => {
                        asset.fieldsValues[field.name] = input.value;
                    });
                    label.append(title, input);
                    grid.append(label);
                }
                card.append(grid);
            }
            container.append(card);
        }
    }

    async function loadSelectedAssetFields(module, asset) {
        const url = new URL(apiUrl);
        url.searchParams.set('action', 'asset_fields');
        url.searchParams.set('table', module.name);
        url.searchParams.set('id', asset.id);
        try {
            const data = await requestJson(url);
            const current = selectedAssets.get(selectionKey(module.name, asset.id));
            if (!current) return;
            current.fields = data.fields || [];
            current.fieldsLoaded = true;
            current.fieldsError = '';
            for (const field of current.fields) {
                current.fieldsValues[field.name] ??= moduleFieldDefault(field.name);
            }
        } catch (error) {
            const current = selectedAssets.get(selectionKey(module.name, asset.id));
            if (!current) return;
            current.fieldsLoaded = true;
            current.fieldsError = error.message;
        }
        renderSelectedAssetForms(module);
    }

    async function loadModuleAssets(module, search = '', offset = 0, append = false) {
        const panel = modulePanels.querySelector(`[data-module="${CSS.escape(module.name)}"]`);
        if (!panel) return;
        const container = panel.querySelector('.asset-module-rows');
        const moreButton = panel.querySelector('.asset-module-load-more');
        const requestId = ++assetSearchSequence;
        assetSearchRequestIds.set(module.name, requestId);
        moreButton.disabled = true;
        moreButton.textContent = append ? 'Loading more assets…' : 'Loading assets…';
        if (!append) {
            moreButton.hidden = true;
            container.innerHTML = '<div class="asset-list-note">Loading available assets…</div>';
        }
        const url = new URL(apiUrl);
        url.searchParams.set('action', 'assets');
        url.searchParams.set('table', module.name);
        if (search) url.searchParams.set('q', search);
        if (offset) url.searchParams.set('offset', String(offset));
        try {
            const data = await requestJson(url);
            if (!selectedModules.has(module.name) || assetSearchRequestIds.get(module.name) !== requestId) return;
            const assets = data.assets || [];
            renderAssets(module, assets, search, append);
            moreButton.dataset.nextOffset = String(offset + assets.length);
            moreButton.hidden = !data.has_more;
            moreButton.textContent = 'Load 100 more assets';
            moreButton.disabled = false;
        } catch (error) {
            if (assetSearchRequestIds.get(module.name) !== requestId) return;
            container.textContent = error.message;
            moreButton.hidden = true;
            moreButton.textContent = 'Load 100 more assets';
            moreButton.disabled = false;
            setNotice(issueNotice, error.message, true);
        }
    }

    function addModulePanel(module) {
        const panel = document.createElement('section');
        panel.className = 'asset-module-panel';
        panel.dataset.module = module.name;
        const header = document.createElement('h3');
        const toggle = document.createElement('button');
        toggle.className = 'asset-module-toggle';
        toggle.type = 'button';
        toggle.setAttribute('aria-expanded', 'false');
        const title = document.createElement('span');
        title.className = 'asset-module-title';
        title.textContent = module.label;
        const summary = document.createElement('span');
        summary.className = 'asset-module-summary';
        summary.hidden = true;
        const chevron = document.createElement('span');
        chevron.className = 'asset-module-chevron';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '▾';
        toggle.append(title, summary, chevron);
        header.append(toggle);
        const body = document.createElement('div');
        body.className = 'asset-module-body';
        body.hidden = true;
        const search = document.createElement('input');
        search.className = 'employee-issue-input';
        search.type = 'search';
        search.placeholder = `Search ${module.label} asset number, ID, model, or serial`;
        search.setAttribute('aria-label', `Search ${module.label} assets`);
        const rows = document.createElement('div');
        rows.className = 'asset-module-rows';
        const moreButton = document.createElement('button');
        moreButton.className = 'employee-issue-button secondary asset-module-load-more';
        moreButton.type = 'button';
        moreButton.textContent = 'Load 100 more assets';
        moreButton.hidden = true;
        const selectedFields = document.createElement('div');
        selectedFields.className = 'asset-module-selected-fields';
        body.append(search, rows, moreButton, selectedFields);
        panel.append(header, body);
        modulePanels.append(panel);
        toggle.addEventListener('click', () => {
            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            activateModulePanel(isExpanded ? '' : module.name);
        });
        search.addEventListener('input', () => {
            const oldTimer = assetSearchTimers.get(module.name);
            window.clearTimeout(oldTimer);
            const query = search.value.trim();
            assetSearchRequestIds.set(module.name, ++assetSearchSequence);
            moreButton.hidden = true;
            moreButton.disabled = true;
            rows.innerHTML = '<div class="asset-list-note">Searching available assets…</div>';
            assetSearchTimers.set(module.name, window.setTimeout(() => loadModuleAssets(module, query), 250));
        });
        moreButton.addEventListener('click', () => {
            loadModuleAssets(module, search.value.trim(), Number(moreButton.dataset.nextOffset || 0), true);
        });
        updateModulePanelSummary(panel, module);
        activateModulePanel(module.name);
        loadModuleAssets(module);
    }

    function openIssuePicker() {
        const employee = issueEmployeeDetails();
        if (!employee.name) {
            setNotice(newEmployeeNotice, 'Enter the employee name before choosing assets.', true);
            newEmployeeName.focus();
            return;
        }
        setNotice(newEmployeeNotice);
        issueModal.hidden = false;
        document.getElementById('assetIssueEmployee').textContent = employee.name;
        modulePicker.replaceChildren();
        modulePanels.replaceChildren();
        selectedModules = new Set();
        selectedAssets = new Map();
        assetSearchTimers = new Map();
        assetSearchRequestIds = new Map();
        setNotice(issueNotice);
        updateSelectionCount();
        requestJson(new URL('?action=modules', apiUrl)).then(data => {
            modules = data.modules || [];
            const issueable = modules.filter(module => module.issueable);
            if (!issueable.length) {
                setNotice(issueNotice, 'There are no visible modules with the asset identifier, status, and employee fields needed for issuance.', true);
                return;
            }
            for (const module of issueable) {
                const label = document.createElement('label');
                label.className = 'asset-module-choice';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = module.name;
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        selectedModules.add(module.name);
                        addModulePanel(module);
                    } else {
                        selectedModules.delete(module.name);
                        modulePanels.querySelector(`[data-module="${CSS.escape(module.name)}"]`)?.remove();
                        for (const [key, asset] of selectedAssets) {
                            if (asset.table === module.name) selectedAssets.delete(key);
                        }
                        if (!modulePanels.querySelector('.asset-module-panel.expanded')) {
                            const nextModule = Array.from(selectedModules).pop();
                            if (nextModule) activateModulePanel(nextModule);
                        }
                        updateSelectionCount();
                    }
                });
                const text = document.createElement('span');
                text.textContent = module.label;
                label.append(checkbox, text);
                modulePicker.append(label);
            }
        }).catch(error => setNotice(issueNotice, error.message, true));
    }

    async function confirmIssuance() {
        if (!issueEmployeeDetails().name || selectedAssets.size === 0) return;
        const invalidField = issueModal.querySelector('.asset-dynamic-field:invalid');
        if (invalidField) {
            invalidField.focus();
            invalidField.reportValidity();
            return;
        }
        if (Array.from(selectedAssets.values()).some(asset => !asset.fieldsLoaded || asset.fieldsError)) {
            setNotice(issueNotice, 'Wait for the selected asset fields to load before confirming issuance.', true);
            return;
        }
        confirmIssueButton.disabled = true;
        setNotice(issueNotice, 'Issuing selected assets…');
        try {
            if (!csrfToken) {
                const csrfData = await requestJson(csrfUrl);
                csrfToken = csrfData.csrf;
            }
            const data = await requestJson(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'issue',
                    employee: issueEmployeeDetails(),
                    assets: Array.from(selectedAssets.values()).map(asset => ({
                        table: asset.table,
                        id: asset.id,
                        fields: asset.fieldsValues
                    }))
                })
            });
            issueModal.hidden = true;
            lookupModal.hidden = false;
            activateTaskTab('issue');
            setNotice(newEmployeeNotice, data.message);
            window.appUndo?.show(data.undo_token, data.message);
        } catch (error) {
            setNotice(issueNotice, error.message, true);
        } finally {
            updateSelectionCount();
        }
    }

    async function returnSelectedAssets() {
        if (!selectedEmployee) return;
        const assets = Array.from(issuedRows.querySelectorAll('.return-asset-checkbox:checked'), checkbox => ({
            table: checkbox.dataset.returnTable,
            id: checkbox.dataset.returnId
        }));
        if (!assets.length) return;
        if (!await window.appDialogs.confirm(
            `Return ${assets.length} selected asset(s) from ${selectedEmployee.name}? Employee assignment details will be cleared; asset information will be kept.`,
            { title: 'Return selected assets', confirmText: 'Return assets' }
        )) return;
        returnSelectedButton.disabled = true;
        setNotice(lookupNotice, 'Returning selected assets…');
        try {
            if (!csrfToken) {
                const csrfData = await requestJson(csrfUrl);
                csrfToken = csrfData.csrf;
            }
            const data = await requestJson(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'return',
                    employee: { name: selectedEmployee.name },
                    assets
                })
            });
            await loadIssuedAssets();
            setNotice(lookupNotice, data.message);
            window.appUndo?.show(data.undo_token, data.message);
        } catch (error) {
            setNotice(lookupNotice, error.message, true);
        } finally {
            updateReturnSelection();
        }
    }

    document.getElementById('employeeIssueClose').addEventListener('click', closeLookup);
    document.getElementById('assetIssueClose').addEventListener('click', closeIssue);
    document.getElementById('assetIssueCancel').addEventListener('click', closeIssue);
    startIssueButton.addEventListener('click', openIssuePicker);
    returnSelectedButton.addEventListener('click', returnSelectedAssets);
    document.getElementById('selectAllReturns').addEventListener('click', () => {
        issuedRows.querySelectorAll('.return-asset-checkbox').forEach(checkbox => { checkbox.checked = true; });
        updateReturnSelection();
    });
    document.getElementById('clearReturnSelection').addEventListener('click', () => {
        issuedRows.querySelectorAll('.return-asset-checkbox:checked').forEach(checkbox => { checkbox.checked = false; });
        updateReturnSelection();
    });
    issuedRows.addEventListener('change', event => {
        if (event.target.matches('.return-asset-checkbox')) updateReturnSelection();
    });
    document.getElementById('returnAssetsTab').addEventListener('click', () => activateTaskTab('return'));
    document.getElementById('issueAssetsTab').addEventListener('click', () => activateTaskTab('issue'));
    function activateTaskTab(task) {
        const returning = task === 'return';
        document.getElementById('returnAssetsTab').classList.toggle('active', returning);
        document.getElementById('issueAssetsTab').classList.toggle('active', !returning);
        document.getElementById('returnAssetsTab').setAttribute('aria-selected', String(returning));
        document.getElementById('issueAssetsTab').setAttribute('aria-selected', String(!returning));
        document.getElementById('returnAssetsPane').hidden = !returning;
        document.getElementById('issueAssetsPane').hidden = returning;
    }
    confirmIssueButton.addEventListener('click', confirmIssuance);
    nameInput.addEventListener('input', () => {
        window.clearTimeout(employeeSearchTimer);
        employeeSearchTimer = window.setTimeout(searchEmployees, 220);
    });
    [newEmployeeName, newEmployeeDepartment, newEmployeeWorkArea, newEmployeeJobTrade, newEmployeeNationality].forEach(input => input.addEventListener('input', () => {
        startIssueButton.disabled = !newEmployeeName.value.trim();
        for (const moduleName of selectedModules) {
            const module = modules.find(item => item.name === moduleName);
            if (!module) continue;
            for (const asset of selectedAssets.values()) {
                if (asset.table === moduleName && asset.fieldsLoaded) {
                    for (const field of asset.fields) {
                        if (!asset.fieldsValues[field.name]) {
                            asset.fieldsValues[field.name] = moduleFieldDefault(field.name);
                        }
                    }
                    renderSelectedAssetForms(module);
                }
            }
        }
    }));
    modulePanels.addEventListener('change', event => {
        const checkbox = event.target.closest('input[data-asset-module]');
        if (!checkbox) return;
        const table = checkbox.dataset.assetModule;
        const id = checkbox.dataset.assetId;
        const key = selectionKey(table, id);
        const module = modules.find(item => item.name === table);
        if (checkbox.checked) {
            if (selectedAssets.size >= 100) {
                checkbox.checked = false;
                setNotice(issueNotice, 'You can issue up to 100 assets at a time. Confirm this batch before starting another.', true);
                return;
            }
            const modulePanel = modulePanels.querySelector(`[data-module="${CSS.escape(table)}"]`);
            const label = checkbox.closest('.asset-option')?.querySelector('strong')?.textContent || id;
            const asset = {
                table,
                id,
                assetNumber: label,
                fields: [],
                fieldsValues: {},
                fieldsLoaded: false,
                fieldsError: ''
            };
            selectedAssets.set(key, asset);
            if (module) {
                if (modulePanel) updateModulePanelSummary(modulePanel, module);
                renderSelectedAssetForms(module);
                loadSelectedAssetFields(module, asset);
            }
        } else {
            selectedAssets.delete(key);
            if (module) {
                const modulePanel = modulePanels.querySelector(`[data-module="${CSS.escape(table)}"]`);
                if (modulePanel) updateModulePanelSummary(modulePanel, module);
                renderSelectedAssetForms(module);
            }
        }
        updateSelectionCount();
    });
    lookupModal.addEventListener('click', event => {
        if (event.target === lookupModal) closeLookup();
    });
    issueModal.addEventListener('click', event => {
        if (event.target === issueModal) closeIssue();
    });

    function handleShortcut(event) {
        if (event.ctrlKey && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'f') {
            event.preventDefault();
            event.stopPropagation();
            showLookup();
        }
        if (event.key === 'Escape') {
            if (!issueModal.hidden) closeIssue();
            else if (!lookupModal.hidden) closeLookup();
        }
    }

    document.addEventListener('keydown', handleShortcut, true);
    function bindFrameShortcut() {
        try {
            frame.contentDocument?.addEventListener('keydown', handleShortcut, true);
        } catch (error) {
            console.error('Could not bind the employee lookup shortcut in the inventory frame.', error);
        }
    }
    frame.addEventListener('load', bindFrameShortcut);
    bindFrameShortcut();
})();
