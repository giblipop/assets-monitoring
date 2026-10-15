/**
 * Improved table.js
 * - Ensures each <tr> has data-id and a hidden id input
 * - Guarantees ref_no is present for display (fetch.php should mirror id->ref_no)
 * - Debounced search, stable sorting, safe rendering (escapeHTML)
 * - Clear separation of responsibilities and helpful console debug
 */

(() => {
  // ======= State caches =======
  let allAssets = [];
  let tableHeaders = [];
  let currentSearchTerm = '';
  let sortState = { column: '', direction: 1 };
  let debounceTimer = null;
  let moduleTable = window.INVENTORY_MODULE_TABLE || new URLSearchParams(window.location.search).get('table') || '';
  const workbookTabs = Array.isArray(window.INVENTORY_WORKBOOK_TABS) ? window.INVENTORY_WORKBOOK_TABS : [];
  let headerLabels = {};
  let hiddenColumns = new Set();
  let attachmentColumns = new Set();
  let filterColumns = new Set();
  let activeColumnFilters = new Map();
  let filterMenuSequence = 0;
  let hiddenRows = new Set();
  let editorColumns = [];
  let editorPrimaryKey = '';
  let canEditTable = false;

  function moduleEndpoint(file) {
    const url = new URL(file, window.location.href);
    if (moduleTable) url.searchParams.set('table', moduleTable);
    return url.href;
  }

  // ======= DOM refs =======
  const tableHead = document.getElementById('tableHead');
  const tableBody = document.getElementById('tableBody');
  const searchInput = document.getElementById('searchInput');
  const resultCount = document.getElementById('resultCount');
  const clearSearchBtn = document.getElementById('clearSearchBtn');
  const refreshBtn = document.getElementById('refreshBtn');

  const editModal = document.getElementById('editModal');
  const editAssetForm = document.getElementById('editAssetForm');
  const modalFormFields = document.getElementById('modalFormFields');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const cancelModalBtn = document.getElementById('cancelModalBtn');
  const editStepStatus = document.getElementById('editStepStatus');
  const editStepControls = document.getElementById('editStepControls');
  const editBackBtn = document.getElementById('editBackBtn');
  const editNextBtn = document.getElementById('editNextBtn');
  const editProgress = document.getElementById('editProgress');
  const editStepPercent = document.getElementById('editStepPercent');
  const editStepProgress = document.getElementById('editStepProgress');
  const saveEditBtn = document.getElementById('saveEditBtn');
  let currentEditStep = 0;
  let editStepCount = 0;

  // ======= Utilities =======
  function escapeHTML(value = '') {
    return String(value).replace(/[&<>"']/g, char =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char] || char)
    );
  }

  function normalizeHeaderName(header) {
    return String(header).trim().toLowerCase();
  }

  function getHeaderDisplayLabel(header) {
    const lower = normalizeHeaderName(header);
    if (headerLabels[header]) return headerLabels[header];
    if (['id', 'ref_no', 'refno', 'ref', 'reference'].includes(lower)) return 'REF NO.';
    return String(header).replace(/_/g, ' ').toUpperCase();
  }

  function dedupeTableHeaders(headers) {
    const cleaned = [];
    const seen = new Set();
    const hasRefNoAlias = headers.some(h => {
      const lower = normalizeHeaderName(h);
      return lower === 'ref_no' || lower === 'refno' || lower === 'ref' || lower === 'reference';
    });

    for (const header of headers) {
      const lower = normalizeHeaderName(header);

      // If ref_no exists, hide raw id column from visible headers
      if (lower === 'id' && hasRefNoAlias) continue;

      // Prefer canonical ref_no over other aliases
      if ((lower === 'ref' || lower === 'refno') && headers.some(h => normalizeHeaderName(h) === 'ref_no')) {
        continue;
      }

      if (seen.has(lower)) continue;
      seen.add(lower);
      cleaned.push(header);
    }

    return cleaned;
  }

  // ======= Data loading =======
  async function loadAssets() {
    try {
      renderLoading();
      const res = await fetch(moduleEndpoint('./fetch.php'), { cache: 'no-store' });
      if (!res.ok) throw new Error(`Server returned ${res.status}`);
      const text = await res.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch (err) {
        console.error('Invalid JSON from fetch.php:', text);
        throw new Error('Invalid server response format.');
      }

      if (json.success === false) {
        throw new Error(json.message || 'Server returned an error.');
      }

      const editorResponse = await fetch(moduleEndpoint('./table_editor_api.php'), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const editorData = await editorResponse.json();
      if (!editorResponse.ok || !editorData.success) {
        throw new Error(editorData.message || 'Could not load table settings.');
      }
      editorColumns = Array.isArray(editorData.columns) ? editorData.columns : [];
      hiddenColumns = new Set(Array.isArray(editorData.hidden_columns) ? editorData.hidden_columns : []);
      attachmentColumns = new Set(Array.isArray(editorData.attachment_columns) ? editorData.attachment_columns : []);
      filterColumns = new Set(Array.isArray(editorData.filter_columns) ? editorData.filter_columns : []);
      activeColumnFilters.clear();
      hiddenRows = new Set((Array.isArray(editorData.hidden_rows) ? editorData.hidden_rows : []).map(String));
      editorPrimaryKey = String(editorData.primary_key || '');
      canEditTable = editorData.can_edit === true;
      const editTableButton = document.getElementById('editTableBtn');
      if (editTableButton) {
        editTableButton.hidden = !canEditTable;
        editTableButton.style.display = canEditTable ? '' : 'none';
      }
      allAssets = Array.isArray(json.data) ? json.data : [];
      tableHeaders = dedupeTableHeaders(Array.isArray(json.headers) && json.headers.length
        ? json.headers
        : Object.keys(allAssets[0] || {}));
      headerLabels = json.header_labels && typeof json.header_labels === 'object' ? json.header_labels : {};
      renderWorkbookTabs();
      if (allAssets.length === 0) {
        renderTableHead();
        renderEmptyTable();
        updateResultCount(0);
        return;
      }

      // Normalize each row: ensure id and ref_no exist as canonical keys
      allAssets = allAssets.map(row => {
        const normalized = Object.assign({}, row);

        // Normalize id
        if (!('id' in normalized)) {
          if ('ID' in normalized) normalized.id = normalized.ID;
          else if ('Id' in normalized) normalized.id = normalized.Id;
        }
        if (normalized.id !== undefined && normalized.id !== null) normalized.id = String(normalized.id);

        // Preserve a real reference column when the schema provides one.
        const existingRef = normalized.ref_no ?? normalized.refNo ?? normalized.refno ?? normalized.ref ?? '';
        normalized.ref_no = existingRef !== '' ? String(existingRef) : (normalized.id ?? '');

        return normalized;
      });

      renderTableHead();
      attachSortingListeners();
      renderTableRows(allAssets);
    } catch (err) {
      console.error(err);
      renderError(err.message || 'Failed to load assets.');
    }
  }

  // ======= Rendering helpers =======
  function renderLoading() {
    if (tableHead) tableHead.innerHTML = '';
    if (tableBody) {
      tableBody.innerHTML = `
        <tr><td colspan="100%" class="loading" style="text-align:center;padding:20px;">Loading records...</td></tr>
      `;
    }
  }

  function renderError(message) {
    if (tableHead) tableHead.innerHTML = '';
    if (tableBody) {
      tableBody.innerHTML = `<tr><td colspan="100%" class="error" style="text-align:center;padding:20px;color:#b91c1c;">${escapeHTML(message)}</td></tr>`;
    }
  }

  function renderEmptyTable() {
    if (tableBody) tableBody.innerHTML = `<tr><td colspan="100%" style="text-align:center;padding:20px;">No inventory records found.</td></tr>`;
  }

  function renderTableHead() {
    if (!tableHead) return;
    const visibleHeaders = getVisibleHeaders();
    const row = document.createElement('tr');
    visibleHeaders.forEach(header => {
      const lower = header.toLowerCase();
      const display = getHeaderDisplayLabel(header);
      const cell = document.createElement('th');
      cell.className = 'sortable';
      cell.dataset.key = header;
      cell.setAttribute('aria-sort', sortState.column === header
        ? (sortState.direction === 1 ? 'ascending' : 'descending')
        : 'none');
      const label = document.createElement('span');
      label.className = 'header-label';
      label.textContent = display;
      if (!['id', 'last_user'].includes(lower)) {
        label.title = 'Double-click to rename this column heading';
        label.addEventListener('dblclick', event => {
          event.preventDefault();
          event.stopPropagation();
          beginHeaderRename(cell, header, display);
        });
      }
      cell.appendChild(label);
      if (filterColumns.has(header)) {
        const filterButton = document.createElement('button');
        filterButton.type = 'button';
        filterButton.className = 'column-filter-button';
        filterButton.setAttribute('aria-label', `Filter ${display}`);
        filterButton.setAttribute('aria-haspopup', 'true');
        filterButton.setAttribute('aria-expanded', 'false');
        filterButton.title = `Filter ${display}`;
        filterButton.innerHTML = '<i class="fa-solid fa-filter" aria-hidden="true"></i>';
        filterButton.addEventListener('click', event => {
          event.stopPropagation();
          toggleColumnFilterMenu(cell, header, filterButton);
        });
        cell.appendChild(filterButton);
      }

      row.appendChild(cell);
    });
    tableHead.replaceChildren(row);
  }

  function getVisibleHeaders() {
    return tableHeaders.filter(header => !hiddenColumns.has(header));
  }

  function closeColumnFilterMenus(except) {
    document.querySelectorAll('.column-filter-menu').forEach(menu => {
      if (menu !== except) menu.remove();
    });
    tableHead?.querySelectorAll('.column-filter-button[aria-expanded="true"]').forEach(button => {
      if (!except || button.dataset.menuId !== except.id) {
        button.setAttribute('aria-expanded', 'false');
        button.classList.remove('is-open');
        delete button.dataset.menuId;
      }
    });
  }

  function toggleColumnFilterMenu(headerCell, column, button) {
    const existing = document.getElementById(button.dataset.menuId);
    if (existing) {
      existing.remove();
      button.setAttribute('aria-expanded', 'false');
      button.classList.remove('is-open');
      delete button.dataset.menuId;
      return;
    }
    closeColumnFilterMenus();

    const values = [...new Set(allAssets
      .map(asset => String(asset[column] ?? '').trim())
      .filter(Boolean))]
      .sort((left, right) => left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' }));
    const menu = document.createElement('div');
    menu.className = 'column-filter-menu';
    menu.id = `column-filter-menu-${++filterMenuSequence}`;
    menu.setAttribute('role', 'group');
    menu.setAttribute('aria-label', `Filter values for ${getHeaderDisplayLabel(column)}`);
    const heading = document.createElement('div');
    heading.className = 'column-filter-menu-heading';
    heading.textContent = 'Show values';
    const actions = document.createElement('div');
    actions.className = 'column-filter-menu-actions';
    const selectAll = document.createElement('button');
    selectAll.type = 'button';
    selectAll.textContent = 'All';
    const selectNone = document.createElement('button');
    selectNone.type = 'button';
    selectNone.textContent = 'None';
    actions.append(selectAll, selectNone);
    const options = document.createElement('div');
    options.className = 'column-filter-options';
    const selected = activeColumnFilters.get(column);

    if (!values.length) {
      const empty = document.createElement('div');
      empty.className = 'column-filter-empty';
      empty.textContent = 'No values to filter';
      options.appendChild(empty);
    }

    values.forEach(value => {
      const optionLabel = document.createElement('label');
      optionLabel.className = 'column-filter-option';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = value;
      checkbox.checked = !selected || selected.has(value);
      const valueText = document.createElement('span');
      valueText.textContent = value;
      optionLabel.append(checkbox, valueText);
      checkbox.addEventListener('change', () => {
        const current = activeColumnFilters.get(column) || new Set(values);
        if (checkbox.checked) current.add(value);
        else current.delete(value);
        if (current.size === values.length) activeColumnFilters.delete(column);
        else activeColumnFilters.set(column, current);
        applyFilters();
      });
      options.appendChild(optionLabel);
    });

    selectAll.addEventListener('click', event => {
      event.stopPropagation();
      activeColumnFilters.delete(column);
      options.querySelectorAll('input[type="checkbox"]').forEach(checkbox => { checkbox.checked = true; });
      applyFilters();
    });
    selectNone.addEventListener('click', event => {
      event.stopPropagation();
      activeColumnFilters.set(column, new Set());
      options.querySelectorAll('input[type="checkbox"]').forEach(checkbox => { checkbox.checked = false; });
      applyFilters();
    });
    menu.append(heading, actions, options);
    menu.addEventListener('click', event => event.stopPropagation());
    document.body.appendChild(menu);
    const buttonBounds = button.getBoundingClientRect();
    const menuBounds = menu.getBoundingClientRect();
    const left = Math.max(8, Math.min(buttonBounds.right - menuBounds.width, window.innerWidth - menuBounds.width - 8));
    const below = buttonBounds.bottom + 5;
    const top = below + menuBounds.height <= window.innerHeight - 8
      ? below
      : Math.max(8, buttonBounds.top - menuBounds.height - 5);
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
    button.setAttribute('aria-expanded', 'true');
    button.classList.add('is-open');
    button.dataset.menuId = menu.id;
  }

  function getRecordKey(row) {
    return String((editorPrimaryKey && row[editorPrimaryKey]) ?? row.id ?? row.ID ?? row.Id ?? '');
  }

  async function requestCsrfToken() {
    const response = await fetch(moduleEndpoint('./attachment_api.php?action=csrf'), {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await response.json();
    if (!response.ok || !result.success || !result.csrf) {
      throw new Error(result.message || 'Refresh the page and sign in again.');
    }
    return result.csrf;
  }

  function beginHeaderRename(headerCell, column, currentLabel) {
    const label = headerCell.querySelector('.header-label');
    if (!label || label.isContentEditable) return;

    let finished = false;
    let saving = false;
    label.contentEditable = 'true';
    label.classList.add('is-editing');
    label.setAttribute('role', 'textbox');
    label.setAttribute('aria-label', 'Edit column heading');
    label.setAttribute('aria-multiline', 'false');
    label.spellcheck = false;
    label.textContent = currentLabel;

    const removeEditor = restoreLabel => {
      if (finished) return;
      finished = true;
      label.contentEditable = 'false';
      label.classList.remove('is-editing');
      label.removeAttribute('role');
      label.removeAttribute('aria-label');
      label.removeAttribute('aria-multiline');
      label.textContent = restoreLabel;
    };

    const saveLabel = async () => {
      if (finished || saving) return;
      const newLabel = label.textContent.trim();
      if (!newLabel || newLabel.length > 100) {
        void window.appDialogs?.alert('Enter a heading name between 1 and 100 characters.');
        label.focus();
        return;
      }
      if (newLabel === currentLabel) {
        removeEditor(currentLabel);
        return;
      }

      saving = true;
      try {
        const csrf = await requestCsrfToken();
        const response = await fetch('./column_label_api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ table: moduleTable, column: column, label: newLabel })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save this heading.');
        headerLabels[column] = newLabel;
        removeEditor(newLabel);
        renderTableHead();
        attachSortingListeners();
        if (result.warning) void window.appDialogs?.alert(result.warning);
      } catch (error) {
        void window.appDialogs?.alert(error.message || 'Could not save this heading.');
        saving = false;
        label.focus();
      }
    };
    label.addEventListener('click', event => event.stopPropagation());
    label.addEventListener('dblclick', event => event.stopPropagation());
    label.addEventListener('blur', saveLabel);
    label.addEventListener('keydown', event => {
      event.stopPropagation();
      if (event.key === 'Enter') {
        event.preventDefault();
        saveLabel();
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        removeEditor(currentLabel);
      }
    });
    label.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(label);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function renderWorkbookTabs() {
    const tablist = document.getElementById('workbookSheetTabs');
    if (!tablist) return;
    tablist.replaceChildren();
    if (workbookTabs.length < 2) {
      tablist.hidden = true;
      return;
    }
    tablist.hidden = false;
    tablist.setAttribute('role', 'tablist');
    workbookTabs.forEach(tab => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'workbook-sheet-tab';
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', String(tab.table === moduleTable));
      button.textContent = tab.name;
      button.addEventListener('click', () => {
        if (tab.table === moduleTable) return;
        moduleTable = tab.table;
        allAssets = [];
        tableHeaders = [];
        headerLabels = {};
        currentSearchTerm = '';
        if (searchInput) searchInput.value = '';
        sortState = { column: '', direction: 1 };
        renderWorkbookTabs();
        loadAssets();
      });
      tablist.appendChild(button);
    });
  }

// Build a fast lookup map from id -> index in allAssets
function buildIdIndexMap() {
  const map = new Map();
  allAssets.forEach((item, i) => {
    const idVal = getRecordKey(item);
    if (idVal !== '') map.set(idVal, i);
  });
  return map;
}

function renderTableRows(assetList) {
  if (!tableBody) return;
  if (!Array.isArray(assetList) || assetList.length === 0) {
    renderEmptyTable();
    updateResultCount(0);
    return;
  }
  const visibleAssets = assetList.filter(row => {
    const rowId = getRecordKey(row);
    return rowId === '' || !hiddenRows.has(rowId);
  });
  if (!visibleAssets.length) {
    renderEmptyTable();
    updateResultCount(0);
    return;
  }

  // Build id->index map once for reliable originalIndex resolution
  const idIndexMap = buildIdIndexMap();

  tableBody.innerHTML = visibleAssets.map((row) => {
    // Ensure id exists and is a string
    const rowId = getRecordKey(row);

    // If id is missing, try to synthesize a stable fallback using ref_no
    // but prefer id for mapping; if both missing, use a unique placeholder
    const safeRowId = rowId !== '' ? rowId : (`__noid__${(row.ref_no ?? row.refNo ?? '')}`);

    // Determine originalIndex using the idIndexMap; fallback to -1 if not found
    const originalIndex = idIndexMap.has(rowId) ? idIndexMap.get(rowId) : -1;

    // Build cells in the exact database-header order.
    const cells = getVisibleHeaders().map(header => {
      const value = (row[header] !== undefined && row[header] !== null) ? row[header] : '';
      return `<td data-key="${escapeHTML(header)}">${renderCellValue(value)}</td>`;
    }).join('');

    // Hidden id input and data-id attribute
    const hiddenInput = `<input type="hidden" name="id" value="${escapeHTML(rowId)}">`;

    // If originalIndex is -1, we still render the row but mark index as -1
    // The dblclick handler will handle -1 safely by looking up by data-id
    return `
      <tr data-index="${originalIndex}" data-id="${escapeHTML(safeRowId)}">
        ${cells}
        ${hiddenInput}
      </tr>
    `;
  }).join('');

  updateResultCount(visibleAssets.length);
}

function renderCellValue(value) {
  const text = String(value ?? '');
  const lines = text.split(/\r?\n/);
  if (lines.length === 1) {
    const localAttachment = /^attachment_api\.php\?id=[a-f0-9]{32}(?:&inline=image)?$/i.test(text);
    let href = '';
    if (localAttachment) {
      href = text;
    } else {
      try {
        const parsed = new URL(text);
        if (parsed.protocol === 'http:' || parsed.protocol === 'https:') href = parsed.href;
      } catch (_) {
        href = '';
      }
    }
    if (!href) return escapeHTML(text);
    const isImage = localAttachment
      ? /&inline=image$/i.test(text)
      : /\.(?:png|jpe?g|gif|webp)(?:[?#].*)?$/i.test(href);
    const content = isImage
      ? `<img class="inventory-attachment-preview" src="${escapeHTML(href)}" alt="Attached image" loading="lazy">`
      : escapeHTML(text);
    return `<a href="${escapeHTML(href)}" target="_blank" rel="noopener noreferrer">${content}</a>`;
  }
  return lines.map(line => {
    if (/^attachment_api\.php\?id=[a-f0-9]{32}(?:&inline=image)?$/i.test(line)) {
      const image = /&inline=image$/i.test(line);
      const escaped = escapeHTML(line);
      const content = image
        ? `<img class="inventory-attachment-preview" src="${escaped}" alt="Attached image" loading="lazy">`
        : escaped;
      return `<a href="${escaped}" target="_blank" rel="noopener noreferrer">${content}</a>`;
    }
    try {
      const parsed = new URL(line);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        const href = escapeHTML(parsed.href);
        const isImage = /\.(?:png|jpe?g|gif|webp)(?:[?#].*)?$/i.test(parsed.href);
        const content = isImage
          ? `<img class="inventory-attachment-preview" src="${href}" alt="Attached image" loading="lazy">`
          : escapeHTML(line);
        return `<a href="${href}" target="_blank" rel="noopener noreferrer">${content}</a>`;
      }
    } catch (_) {
      return escapeHTML(line);
    }
    return escapeHTML(line);
  }).join('<br>');
}

  function updateResultCount(count) {
    if (!resultCount) return;
    resultCount.textContent = `${count} record${count === 1 ? '' : 's'}`;
  }

  // ======= Sorting & Filtering =======
  function attachSortingListeners() {
    if (!tableHead) return;
    tableHead.querySelectorAll('th.sortable').forEach(th => {
      th.addEventListener('click', () => {
        const key = th.dataset.key;
        if (!key) return;
        if (sortState.column === key) sortState.direction *= -1;
        else { sortState.column = key; sortState.direction = 1; }

        tableHead.querySelectorAll('th.sortable').forEach(headerCell => {
          headerCell.setAttribute('aria-sort', 'none');
        });
        th.setAttribute('aria-sort', sortState.direction === 1 ? 'ascending' : 'descending');

        applyFilters();
      });
    });
  }

  function sortAssets(list, column, direction) {
    return [...list].sort((a, b) => {
      const valueA = String(a[column] ?? '').toLowerCase();
      const valueB = String(b[column] ?? '').toLowerCase();
      if (valueA < valueB) return -1 * direction;
      if (valueA > valueB) return 1 * direction;
      return 0;
    });
  }

  function applyFilters() {
    const term = currentSearchTerm.trim().toLowerCase();
    let filtered = allAssets.filter(asset => {
      const matchesSearch = Object.values(asset).some(val => val !== null && String(val).toLowerCase().includes(term));
      if (!matchesSearch) return false;
      return [...activeColumnFilters].every(([column, values]) => values.has(String(asset[column] ?? '').trim()));
    });

    if (sortState.column) {
      filtered = sortAssets(filtered, sortState.column, sortState.direction);
    }

    renderTableRows(filtered);
    tableHead?.querySelectorAll('.column-filter-button').forEach(button => {
      const column = button.closest('th')?.dataset.key;
      button.classList.toggle('is-active', Boolean(column && activeColumnFilters.has(column)));
    });
  }

  // ======= Modal / Edit handling =======
  function showEditStep(stepIndex) {
    const steps = Array.from(modalFormFields.querySelectorAll('.edit-form-step'));
    if (!steps.length) return;

    currentEditStep = Math.max(0, Math.min(stepIndex, steps.length - 1));
    steps.forEach((step, index) => {
      step.hidden = index !== currentEditStep;
    });

    editStepStatus.textContent = `Step ${currentEditStep + 1} of ${editStepCount}`;
    const progress = Math.round(((currentEditStep + 1) / editStepCount) * 100);
    editStepPercent.textContent = `${progress}%`;
    editStepProgress.style.width = `${progress}%`;
    editStepProgress.parentElement.setAttribute('aria-valuenow', String(progress));
    editBackBtn.disabled = currentEditStep === 0;
    editNextBtn.hidden = currentEditStep === steps.length - 1;
    saveEditBtn.hidden = currentEditStep !== steps.length - 1;
    editStepControls.hidden = steps.length < 2;
    editProgress.hidden = steps.length < 2;
    modalFormFields.scrollTop = 0;
  }

  function paginateEditFields(fields) {
    const fieldsPerStep = window.innerWidth <= 600
      ? (window.innerHeight <= 560 ? 2 : 3)
      : 8;
    const pages = [];
    for (let start = 0; start < fields.length; start += fieldsPerStep) {
      pages.push(fields.slice(start, start + fieldsPerStep));
    }
    editStepCount = pages.length;
    return pages.map((page, index) => `
      <div class="edit-form-step inventory-form-step" data-step="${index}" ${index === 0 ? '' : 'hidden'}>
        ${page.join('')}
      </div>
    `).join('');
  }

  function openEditModal(index) {
    const assetData = allAssets[index];
    if (!assetData) return;

    // Build fields from the same database headers rendered by the table.
    const idValue = assetData.id ?? '';
    let html = `<input type="hidden" name="id" value="${escapeHTML(idValue)}">`;
    const visibleHeaders = getVisibleHeaders();

    const fieldMarkup = visibleHeaders.map(key => {
      const lowerKey = key.toLowerCase();
      const isProtected = (lowerKey === 'id' || lowerKey === 'asset_code' || lowerKey === 'ref_no' || lowerKey === 'refno' || lowerKey === 'last_user');
      const displayValue = assetData[key] !== undefined && assetData[key] !== null ? assetData[key] : '';
      const labelText = getHeaderDisplayLabel(key);
      const attachmentControl = !isProtected && attachmentColumns.has(key)
        ? `<div class="field-attachment-control"><input type="file" id="attachment_${escapeHTML(key)}" data-attachment-field="${escapeHTML(key)}" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv" hidden><button type="button" class="field-attachment-button" aria-label="Attach image or document to ${escapeHTML(labelText)}" title="Attach image or document"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></button></div>`
        : '';
      return `
        <div class="form-group">
          <label for="input_${escapeHTML(key)}">${escapeHTML(labelText)}</label>
          <div class="field-input-with-action">
            <input type="text" id="input_${escapeHTML(key)}" name="${escapeHTML(key)}" value="${escapeHTML(displayValue)}" ${isProtected ? 'disabled' : ''}>
            ${attachmentControl}
          </div>
          ${isProtected && lowerKey !== 'id' ? `<input type="hidden" name="${escapeHTML(key)}" value="${escapeHTML(displayValue)}">` : ''}
        </div>
      `;
    });

    modalFormFields.innerHTML = html + paginateEditFields(fieldMarkup);
    modalFormFields.querySelectorAll('input[type="file"][data-attachment-field]').forEach(fileInput => {
      const attachButton = fileInput.closest('.field-attachment-control')?.querySelector('.field-attachment-button');
      attachButton?.addEventListener('click', () => fileInput.click());
      fileInput.addEventListener('change', async () => {
        const selectedFile = fileInput.files && fileInput.files[0];
        if (!selectedFile) return;
        const controls = [
          ...modalFormFields.querySelectorAll('input[type="file"]'),
          saveEditBtn,
          editNextBtn,
          editBackBtn,
          cancelModalBtn,
          closeModalBtn
        ].filter(Boolean);
        const previousDisabled = controls.map(control => control.disabled);
        try {
          controls.forEach(control => { control.disabled = true; });
          if (attachButton) {
            attachButton.disabled = true;
            attachButton.title = 'Uploading ' + selectedFile.name;
            attachButton.setAttribute('aria-label', 'Uploading ' + selectedFile.name);
          }
          await uploadFieldAttachment(selectedFile, fileInput.dataset.attachmentField, fileInput);
        } catch (error) {
          await window.appDialogs?.alert(error.message || 'The attachment could not be uploaded.');
        } finally {
          controls.forEach((control, index) => { control.disabled = previousDisabled[index]; });
          if (attachButton) {
            attachButton.disabled = false;
            attachButton.title = 'Attach image or document';
            attachButton.setAttribute('aria-label', 'Attach image or document to ' + fileInput.dataset.attachmentField.replace(/_/g, ' '));
          }
        }
      });
    });
    showEditStep(0);
    editAssetForm.setAttribute('data-current-index', String(index));
    editModal.classList.add('active');
  }

  async function uploadFieldAttachment(file, fieldName, fileInput) {
    const field = Array.from(modalFormFields.querySelectorAll('[name]'))
      .find(element => element.name === fieldName);
    if (!(field instanceof HTMLInputElement)) throw new Error('The selected record field is unavailable.');
    const formData = new FormData();
    formData.append('file', file);
    const csrf = await requestCsrfToken();
    const response = await fetch(moduleEndpoint('./attachment_api.php'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf },
      body: formData
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'The attachment could not be uploaded.');
    }
    field.value = result.url;
    fileInput.value = '';
    await window.appDialogs?.alert(`Attached ${result.name}. Save the record to keep its link.`);
  }

 // dblclick: try to use data-index first; if -1 or missing, resolve by data-id
tableBody.addEventListener("dblclick", (e) => {
  const clickedRow = e.target.closest("tr");
  if (!clickedRow) return;

  let index = parseInt(clickedRow.getAttribute("data-index"), 10);
  if (Number.isNaN(index) || index < 0) {
    // fallback: resolve by data-id (the canonical id we attached)
    const dataId = clickedRow.getAttribute('data-id') || clickedRow.querySelector('input[name="id"]')?.value || '';
    if (!dataId) {
      console.warn('Row has no data-id or hidden id; cannot open editor.');
      return;
    }
    // Find index in allAssets by id (exact match)
    index = allAssets.findIndex(item => {
      const itemId = (item.id ?? item.ID ?? item.Id ?? '').toString();
      return itemId === dataId;
    });
    if (index === -1) {
      console.warn('Could not map data-id to allAssets index:', dataId);
      return;
    }
  }

  const assetData = allAssets[index];
  if (!assetData) {
    console.warn('No asset data found for index', index);
    return;
  }

  openEditModal(index);
});


  // Close modal handlers
  const hideModal = () => editModal?.classList.remove('active');
  closeModalBtn?.addEventListener('click', hideModal);
  cancelModalBtn?.addEventListener('click', hideModal);
  editBackBtn?.addEventListener('click', () => showEditStep(currentEditStep - 1));
  editNextBtn?.addEventListener('click', () => showEditStep(currentEditStep + 1));
  window.addEventListener('click', (e) => { if (e.target === editModal) hideModal(); });

  // Submit edit form
  editAssetForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (currentEditStep < editStepCount - 1) {
      showEditStep(currentEditStep + 1);
      return;
    }
    const index = parseInt(editAssetForm.getAttribute('data-current-index'), 10);
    const formData = new FormData(editAssetForm);
    const submitBtn = editAssetForm.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.innerText : null;
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerText = 'Saving changes...'; }

    try {
      const res = await fetch(moduleEndpoint('./update.php'), { method: 'POST', body: formData });
      if (!res.ok) throw new Error(`Network error ${res.status}`);
      const json = await res.json();
      if (json.success) {
        // Update local cache (only non-protected fields)
        tableHeaders.forEach(key => {
          const lower = key.toLowerCase();
          if (lower !== 'asset_code' && lower !== 'id') {
            allAssets[index][key] = formData.get(key);
          }
        });
        renderTableRows(allAssets);
        hideModal();
        window.appUndo?.show(json.undo_token, 'Record updated.');
      } else {
        alert(`Failed to save: ${json.message || 'Unknown error'}`);
      }
    } catch (err) {
      console.error('Update failed:', err);
      alert('An error occurred while saving. Check console for details.');
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = originalText; }
    }
  });

  // ======= Search, refresh, clear =======
  function debounce(fn, wait = 250) {
    return (...args) => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => fn(...args), wait);
    };
  }

  searchInput?.addEventListener('input', debounce((e) => {
    currentSearchTerm = e.target.value.toLowerCase();
    applyFilters();
  }, 200));

  clearSearchBtn?.addEventListener('click', () => {
    if (!searchInput) return;
    searchInput.value = '';
    currentSearchTerm = '';
    applyFilters();
  });

  refreshBtn?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    currentSearchTerm = '';
    sortState = { column: '', direction: 1 };
    loadAssets();
  });

  window.addEventListener('click', event => {
    if (!event.target.closest?.('.column-filter-menu, .column-filter-button')) {
      closeColumnFilterMenus();
    }
  });
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeColumnFilterMenus();
  });
  window.addEventListener('scroll', () => closeColumnFilterMenus(), { passive: true });
  window.addEventListener('resize', () => closeColumnFilterMenus());

  // ======= Initialization =======
  document.addEventListener('DOMContentLoaded', () => {
    loadAssets();
  });

  // Expose for debugging if needed
  window.__inventory = {
    reload: loadAssets,
    getAll: () => allAssets,
    getEditorState: () => ({
      table: moduleTable,
      headers: tableHeaders.slice(),
      columns: editorColumns.map(column => Object.assign({}, column)),
      hiddenColumns: Array.from(hiddenColumns),
      attachmentColumns: Array.from(attachmentColumns),
      filterColumns: Array.from(filterColumns),
      hiddenRows: new Set(hiddenRows),
      headerLabels: Object.assign({}, headerLabels),
      primaryKey: editorPrimaryKey,
      canEdit: canEditTable,
      assets: allAssets.slice()
    })
  };
})();
