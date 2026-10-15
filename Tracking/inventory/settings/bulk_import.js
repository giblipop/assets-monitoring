(function () {
  'use strict';

  const apiUrl = './bulk_import_api.php';
  const backupApiUrl = '../../connector/database_backup_api.php';
  const fileInput = document.getElementById('workbookFile');
  const loadStatus = document.getElementById('loadStatus');
  const mappingsNode = document.getElementById('worksheetMappings');
  const previewDialog = document.getElementById('workbookPreviewDialog');
  const sheetListNode = document.getElementById('workbookSheetList');
  const selectionCount = document.getElementById('sheetSelectionCount');
  const importButton = document.getElementById('startImport');
  const progress = document.getElementById('importProgress');
  const resultSection = document.getElementById('resultSection');
  const resultsNode = document.getElementById('results');
  const downloadButton = document.getElementById('downloadTemplate');
  const workbookSidebarName = document.getElementById('workbookSidebarName');
  let modules = [];
  let canCreateModule = false;
  let sheets = [];
  let activeSheet = null;
  let csrfToken = '';
  let importing = false;

  const templateSheets = [
    {
      name: 'Asset Register',
      headers: ['Asset ID', 'Photo', 'Description', 'Category', 'Pkg', 'Dept', 'Location', 'Name', 'MRS No.', 'WBS No.', 'PO No.', 'Amount', 'Date2', 'By', 'To', 'Form', 'Asset Status']
    },
    { name: 'Employees', headers: ['Employee ID', 'Name', 'Department', 'Email', 'Location'] },
    { name: 'Departments', headers: ['Department', 'Location', 'Manager'] }
  ];

  function setStatus(message, type) {
    loadStatus.textContent = message;
    loadStatus.className = 'status' + (type ? ' notice ' + type : ' muted');
  }

  function slugify(value) {
    return String(value).normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
      .slice(0, 64).replace(/_+$/g, '') || 'imported_module';
  }

  function normalized(value) {
    return String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  function makeTemplate() {
    if (!window.XLSX) {
      setStatus('The Excel workbook library did not load. Refresh the page and try again.', 'error');
      return;
    }
    const workbook = XLSX.utils.book_new();
    const instructions = [
      ['Multi-module inventory import template'],
      ['Use one worksheet for each sheet tab. During import, preview the sheet and select or crop its header rows. Blank, repeated, and numeric headings are made unique and safe for database columns.'],
      ['All worksheets import as one sidebar item with Excel-like tabs inside the opened table.'],
      ['Each worksheet becomes a separate table inside the workbook sidebar entry. Tables use text columns based on the selected worksheet headings.'],
      ['Embedded photos, macros and cell formatting are not imported. Merged and multi-row headings are combined into text column names. Formulas are not preserved (a cached cell value may be imported). After import, attach supported files or paste links in the table editor.'],
      ['Import up to 50,000 data rows per worksheet, 100,000 rows per workbook, and 250 columns per worksheet.']
    ];
    XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(instructions), 'Instructions');
    templateSheets.forEach(function (sheet) {
      XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet([sheet.headers]), sheet.name);
    });
    XLSX.writeFile(workbook, 'inventory-multi-module-template.xlsx');
  }

  function createField(labelText, control) {
    const wrapper = document.createElement('div');
    wrapper.className = 'field';
    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.append(label, control);
    return wrapper;
  }

  function makeSelect(label, options) {
    const select = document.createElement('select');
    select.setAttribute('aria-label', label);
    options.forEach(function (option) {
      const item = document.createElement('option');
      item.value = option.value;
      item.textContent = option.label;
      select.appendChild(item);
    });
    return select;
  }

  function getColumnOptions(sheet, mode) {
    if (mode === 'new') {
      const used = new Set();
      return sheet.headers.map(function (header) {
        let base = slugify(header);
        if (!/^[a-z]/.test(base)) base = 'column_' + base;
        if (['id', 'created_at', 'last_user'].includes(base)) base += '_value';
        let value = base;
        let suffix = 2;
        while (used.has(value)) value = base.slice(0, 60 - String(suffix).length) + '_' + suffix++;
        used.add(value);
        return { value: value, label: value };
      });
    }
    const module = modules.find(function (item) { return item.table === sheet.targetTable; });
    return module ? module.columns
      .filter(function (column) {
        return !column.auto_increment && !column.generated &&
          !['id', 'last_user'].includes(column.name.toLowerCase());
      })
      .map(function (column) { return { value: column.name, label: column.name + ' (' + column.type + ')' }; }) : [];
  }

  function columnIndex(value) {
    const letters = String(value).trim().toUpperCase();
    if (!/^[A-Z]{1,3}$/.test(letters)) return -1;
    let result = 0;
    for (const letter of letters) result = result * 26 + letter.charCodeAt(0) - 64;
    return result - 1;
  }

  function columnName(index) {
    let value = index + 1;
    let result = '';
    while (value > 0) {
      const remainder = (value - 1) % 26;
      result = String.fromCharCode(65 + remainder) + result;
      value = Math.floor((value - 1) / 26);
    }
    return result;
  }

  function matrixValue(sheet, row, column) {
    const key = row + ':' + column;
    const link = sheet.links && sheet.links[key];
    const rowValues = sheet.matrix[row - sheet.bounds.s.r] || [];
    const value = rowValues[column - sheet.bounds.s.c];
    const text = value == null ? '' : String(value);
    const parts = text ? [text] : [];
    if (link && !parts.includes(link)) parts.push(link);
    const imageUrls = sheet.imageUrls && sheet.imageUrls[key];
    if (imageUrls && imageUrls.length) {
      imageUrls.forEach(function (url) {
        if (!parts.includes(url)) parts.push(url);
      });
    } else {
      const images = sheet.imageMap && sheet.imageMap[key];
      (images || []).forEach(function (image) {
        parts.push('[Embedded image: ' + image.name + ']');
      });
    }
    return parts.join('\n');
  }

  function headerValue(sheet, row, column) {
    const directValue = matrixValue(sheet, row, column).trim();
    if (directValue) return directValue;
    const merge = sheet.merges.find(function (range) {
      return range.s.r <= row && range.e.r >= row && range.s.c <= column && range.e.c >= column;
    });
    if (!merge) return '';
    return matrixValue(sheet, merge.s.r, merge.s.c).trim();
  }

  function makeUniqueHeaders(parts, firstColumn) {
    const counts = Object.create(null);
    return parts.map(function (values, index) {
      const uniqueParts = values.filter(function (value, partIndex) {
        return value && values.indexOf(value) === partIndex;
      });
      const base = uniqueParts.join(' / ') || 'Column ' + columnName(firstColumn + index);
      const key = normalized(base);
      counts[key] = (counts[key] || 0) + 1;
      return counts[key] === 1 ? base : base + ' (' + counts[key] + ')';
    });
  }

  function getCrop(sheet, card) {
    const values = {};
    card.querySelectorAll('.layout-controls input').forEach(function (input) {
      values[input.name] = input.value.trim();
    });
    const crop = {
      headerStart: Number(values.headerStart),
      headerEnd: Number(values.headerEnd),
      dataStart: Number(values.dataStart),
      dataEnd: Number(values.dataEnd),
      firstColumn: columnIndex(values.firstColumn),
      lastColumn: columnIndex(values.lastColumn)
    };
    if (!Number.isInteger(crop.headerStart) || !Number.isInteger(crop.headerEnd)
      || !Number.isInteger(crop.dataStart) || !Number.isInteger(crop.dataEnd)
      || crop.headerStart < sheet.bounds.s.r + 1
      || crop.headerEnd < crop.headerStart
      || crop.headerEnd >= crop.dataStart
      || crop.dataEnd < crop.dataStart - 1
      || crop.dataEnd > sheet.bounds.e.r + 1
      || crop.firstColumn < sheet.bounds.s.c || crop.lastColumn < crop.firstColumn
      || crop.lastColumn > sheet.bounds.e.c || crop.lastColumn - crop.firstColumn + 1 > 250) {
      throw new Error('Check the header, data, and column crop for worksheet "' + sheet.name + '".');
    }
    if (crop.dataEnd - crop.dataStart + 1 > 50000) {
      throw new Error('The selected data crop for "' + sheet.name + '" exceeds 50,000 rows.');
    }
    return crop;
  }

  function applyCrop(sheet, card) {
    const crop = getCrop(sheet, card);
    const parts = [];
    for (let column = crop.firstColumn; column <= crop.lastColumn; column++) {
      const values = [];
      for (let row = crop.headerStart - 1; row < crop.headerEnd; row++) {
        const value = headerValue(sheet, row, column);
        if (value) values.push(value);
      }
      parts.push(values);
    }
    sheet.headers = makeUniqueHeaders(parts, crop.firstColumn);
    sheet.rows = [];
    for (let row = crop.dataStart - 1; row < crop.dataEnd; row++) {
      const record = new Array(sheet.headers.length);
      let hasData = false;
      sheet.headers.forEach(function (header, index) {
        const value = matrixValue(sheet, row, crop.firstColumn + index);
        record[index] = value || null;
        hasData = hasData || value.trim() !== '';
      });
      if (hasData) sheet.rows.push(record);
    }
    if (sheet.rows.length > 50000) {
      throw new Error('The selected data crop for "' + sheet.name + '" exceeds 50,000 non-empty rows.');
    }
    sheet.crop = crop;
  }

  function createLayoutInput(label, name, value, type) {
    const input = document.createElement('input');
    input.type = type || 'number';
    input.name = name;
    input.value = String(value);
    input.setAttribute('aria-label', label);
    if (input.type === 'number') {
      input.min = '1';
      input.max = '50000';
      input.step = '1';
    }
    return createField(label, input);
  }

  function renderPreview(sheet, container) {
    container.replaceChildren();
    const crop = sheet.crop;
    const preview = document.createElement('div');
    preview.className = 'preview layout-preview';
    const table = document.createElement('table');
    const thead = document.createElement('thead');
    const headingRow = document.createElement('tr');
    const indexHeading = document.createElement('th');
    indexHeading.textContent = 'Row';
    headingRow.appendChild(indexHeading);
    for (let column = sheet.bounds.s.c; column <= sheet.bounds.e.c; column++) {
      const th = document.createElement('th');
      th.textContent = columnName(column);
      if (column < crop.firstColumn || column > crop.lastColumn) th.className = 'outside-crop';
      headingRow.appendChild(th);
    }
    thead.appendChild(headingRow);
    table.appendChild(thead);
    const body = document.createElement('tbody');
    const previewStartInput = container.closest('.worksheet')?.querySelector('[name="previewRow"]');
    const requestedStart = Number(previewStartInput?.value) || sheet.bounds.s.r + 1;
    const firstPreviewRow = Math.max(sheet.bounds.s.r, Math.min(sheet.bounds.e.r, requestedStart - 1));
    const lastPreviewRow = Math.min(sheet.bounds.e.r, firstPreviewRow + 24);
    const merges = sheet.merges.filter(function (merge) {
      return merge.s.r <= lastPreviewRow && merge.e.r >= firstPreviewRow
        && merge.s.c <= sheet.bounds.e.c && merge.e.c >= sheet.bounds.s.c;
    });
    const mergeAt = new Map();
    merges.forEach(function (merge) {
      for (let row = Math.max(merge.s.r, firstPreviewRow); row <= Math.min(merge.e.r, lastPreviewRow); row++) {
        for (let column = Math.max(merge.s.c, sheet.bounds.s.c); column <= Math.min(merge.e.c, sheet.bounds.e.c); column++) {
          mergeAt.set(row + ':' + column, merge);
        }
      }
    });
    for (let row = firstPreviewRow; row <= lastPreviewRow; row++) {
      const tr = document.createElement('tr');
      tr.dataset.row = String(row + 1);
      if (row + 1 >= crop.headerStart && row + 1 <= crop.headerEnd) {
        tr.classList.add('crop-selected-header');
      } else if (row + 1 >= crop.dataStart && row + 1 <= crop.dataEnd) {
        tr.classList.add('crop-selected-data');
      } else {
        tr.classList.add('outside-crop-row');
      }
      const rowNumber = document.createElement('th');
      rowNumber.className = 'row-number';
      const rowButton = document.createElement('button');
      rowButton.type = 'button';
      rowButton.className = 'preview-row-select';
      rowButton.dataset.headerRow = String(row + 1);
      rowButton.textContent = String(row + 1);
      rowButton.setAttribute('aria-label', 'Select row ' + (row + 1) + ' as a header boundary');
      rowNumber.appendChild(rowButton);
      tr.appendChild(rowNumber);
      for (let column = sheet.bounds.s.c; column <= sheet.bounds.e.c; column++) {
        const merge = mergeAt.get(row + ':' + column);
        const clippedMergeStart = merge && merge.s.r < firstPreviewRow
          && row === firstPreviewRow && merge.s.c === column;
        if (merge && !clippedMergeStart && (merge.s.r !== row || merge.s.c !== column)) continue;
        const td = document.createElement('td');
        const value = merge && clippedMergeStart
          ? matrixValue(sheet, merge.s.r, merge.s.c)
          : matrixValue(sheet, row, column);
        td.textContent = value;
        if (merge) {
          td.rowSpan = Math.min(merge.e.r, lastPreviewRow) - row + 1;
          td.colSpan = merge.e.c - merge.s.c + 1;
          td.classList.add('merged-cell');
        }
        if (column < crop.firstColumn || column > crop.lastColumn) td.classList.add('outside-crop');
        tr.appendChild(td);
      }
      body.appendChild(tr);
    }
    table.appendChild(body);
    preview.appendChild(table);
    const caption = document.createElement('p');
    caption.className = 'muted';
    caption.textContent = 'Showing worksheet rows ' + (firstPreviewRow + 1) + '–' + (lastPreviewRow + 1)
      + ' of ' + (sheet.bounds.e.r + 1) + '. ' + sheet.rows.length + ' data row(s) selected inside rows '
      + crop.dataStart + '–' + crop.dataEnd
      + ' and columns ' + columnName(crop.firstColumn) + '–' + columnName(crop.lastColumn)
      + '. Highlighted header rows: ' + crop.headerStart + '–' + crop.headerEnd + '.';
    container.append(preview, caption);
  }

  function updateCropStatus() {
    if (!canCreateModule) {
      setStatus('Only the workspace owner can create tables for an imported workbook.', 'error');
      return;
    }
    const selectedSheets = sheets.filter(function (sheet) { return sheet.selected; });
    const invalidSheet = selectedSheets.find(function (sheet) { return sheet.layoutError; });
    if (invalidSheet) {
      setStatus(invalidSheet.layoutError, 'error');
      return;
    }
    const totalRows = selectedSheets.reduce(function (total, sheet) { return total + sheet.rows.length; }, 0);
    if (totalRows > 100000) {
      setStatus('Current data crops contain ' + totalRows + ' rows; the request limit is 100,000.', 'error');
      return;
    }
    setStatus('Ready: ' + selectedSheets.length + ' worksheet(s) and ' + totalRows + ' data row(s) selected.', 'success');
  }

  function updateSheetSelection() {
    const selected = sheets.filter(function (sheet) { return sheet.selected; });
    selectionCount.textContent = selected.length + ' of ' + sheets.length + ' sheets selected';
    importButton.disabled = !canCreateModule || selected.length === 0;
    selected.forEach(function (sheet, index) {
      sheet.navigationOrder = index;
    });
    updateCropStatus();
  }

  function activateSheet(sheet) {
    sheets.forEach(function (item) {
      if (item.card) item.card.hidden = item !== sheet;
      if (item.pickerButton) item.pickerButton.setAttribute('aria-current', String(item === sheet));
    });
  }

  function refreshColumnMappings(sheet, card) {
    const targetContainer = card.querySelector('.column-mapping');
    targetContainer.replaceChildren();
    const options = getColumnOptions(sheet, sheet.mode);
    sheet.headers.forEach(function (header) {
      const selectOptions = [{ value: '', label: 'Do not import this column' }]
        .concat(options.map(function (option) {
          return { value: option.value, label: option.label };
        }));
      const select = makeSelect('Map ' + header, selectOptions);
      const suggested = sheet.mode === 'new'
        ? options[sheet.headers.indexOf(header)]
        : options.find(function (option) {
          return normalized(option.value) === normalized(header);
        });
      if (suggested) select.value = suggested.value;
      select.dataset.source = header;
      const label = document.createElement('label');
      label.append(document.createTextNode(header), select);
      targetContainer.appendChild(label);
    });
  }

  function renderSheet(sheet) {
    const card = document.createElement('article');
    card.className = 'worksheet';
    sheet.card = card;
    sheet.selected = !sheet.empty && sheet.name.toLowerCase() !== 'instructions';
    sheet.navigationOrder = sheets.indexOf(sheet);
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = sheet.selected;
    checkbox.disabled = sheet.empty;
    sheet.checkbox = checkbox;
    checkbox.setAttribute('aria-label', 'Import worksheet ' + sheet.name);
    const pickerName = document.createElement('span');
    pickerName.textContent = sheet.name;
    pickerName.title = sheet.name;
    pickerName.style.whiteSpace = 'pre';
    const pickerButton = document.createElement('button');
    pickerButton.type = 'button';
    pickerButton.className = 'worksheet-picker-name';
    pickerButton.setAttribute('aria-current', 'false');
    pickerButton.append(pickerName);
    sheet.pickerButton = pickerButton;
    checkbox.addEventListener('click', event => event.stopPropagation());
    checkbox.addEventListener('change', () => {
      sheet.selected = checkbox.checked;
      updateSheetSelection();
      if (!sheet.selected && sheet === activeSheet) {
        const next = sheets.find(item => item.selected);
        if (next) {
          activeSheet = next;
          activateSheet(next);
        }
      }
    });
    pickerButton.addEventListener('click', () => {
      activeSheet = sheet;
      activateSheet(sheet);
    });
    const pickerRow = document.createElement('div');
    pickerRow.className = 'worksheet-picker';
    pickerRow.append(checkbox, pickerButton);
    sheetListNode.appendChild(pickerRow);

    const title = document.createElement('h3');
    title.textContent = sheet.name;
    card.appendChild(title);
    if (sheet.images.length) {
      const imageNotice = document.createElement('p');
      imageNotice.className = 'muted';
      imageNotice.textContent = sheet.images.length + ' embedded image(s) will be attached to their worksheet cells.';
      card.appendChild(imageNotice);
    }
    if (sheet.empty) {
      const emptyNotice = document.createElement('p');
      emptyNotice.className = 'muted';
      emptyNotice.textContent = 'This worksheet is empty and cannot be selected for import.';
      card.appendChild(emptyNotice);
      mappingsNode.appendChild(card);
      return;
    }

    const controls = document.createElement('div');
    controls.className = 'worksheet-head';
    sheet.mode = 'new';
    sheet.targetTable = slugify(sheet.name);

    const tableInput = document.createElement('input');
    tableInput.type = 'text';
    tableInput.maxLength = 64;
    tableInput.pattern = '[a-z][a-z0-9_]{0,63}';
    tableInput.value = sheet.targetTable;
    tableInput.disabled = sheet.mode !== 'new';
    tableInput.setAttribute('aria-label', 'New table name for ' + sheet.name);
    tableInput.addEventListener('input', function () {
      sheet.targetTable = tableInput.value.trim();
    });

    controls.append(createField('Database table name for this sheet', tableInput));
    card.appendChild(controls);

    const layoutTitle = document.createElement('p');
    layoutTitle.className = 'preview-caption';
    layoutTitle.textContent = 'Preview the worksheet and crop its header';
    card.appendChild(layoutTitle);
    const layoutControls = document.createElement('div');
    layoutControls.className = 'layout-controls';
    layoutControls.append(
      createLayoutInput('Header starts at row', 'headerStart', sheet.defaultHeaderRow),
      createLayoutInput('Header ends at row', 'headerEnd', sheet.defaultHeaderRow),
      createLayoutInput('Data starts at row', 'dataStart', sheet.defaultHeaderRow + 1),
      createLayoutInput('Data ends at row', 'dataEnd', sheet.defaultDataEndRow),
      createLayoutInput('First column (A, B, ...)', 'firstColumn', columnName(sheet.bounds.s.c), 'text'),
      createLayoutInput('Last column (A, B, ...)', 'lastColumn', columnName(sheet.bounds.e.c), 'text'),
      createLayoutInput('Preview starting row', 'previewRow', sheet.bounds.s.r + 1)
    );
    const headerCropButton = document.createElement('button');
    headerCropButton.type = 'button';
    headerCropButton.className = 'secondary crop-header-button';
    headerCropButton.textContent = 'Select header rows in preview';
    const headerCropHint = document.createElement('span');
    headerCropHint.className = 'muted crop-header-hint';
    headerCropHint.setAttribute('aria-live', 'polite');
    headerCropHint.textContent = 'Click the button, then click the first and last header row numbers. Click the same row twice for a one-row header.';
    layoutControls.append(headerCropButton, headerCropHint);
    card.appendChild(layoutControls);
    const previewContainer = document.createElement('div');
    previewContainer.className = 'layout-preview-container';
    card.appendChild(previewContainer);

    function refreshCrop() {
      try {
        applyCrop(sheet, card);
        renderPreview(sheet, previewContainer);
        sheet.layoutError = '';
        refreshColumnMappings(sheet, card);
        updateCropStatus();
      } catch (error) {
        sheet.layoutError = error.message;
        previewContainer.textContent = error.message;
        refreshColumnMappings(sheet, card);
        setStatus(error.message, 'error');
      }
    }
    layoutControls.addEventListener('change', function (event) {
      if (event.target.name === 'previewRow') {
        renderPreview(sheet, previewContainer);
        return;
      }
      refreshCrop();
    });
    headerCropButton.addEventListener('click', function () {
      sheet.selectingHeader = !sheet.selectingHeader;
      sheet.headerSelectionStart = null;
      headerCropButton.textContent = sheet.selectingHeader ? 'Cancel header selection' : 'Select header rows in preview';
      headerCropHint.textContent = sheet.selectingHeader
        ? 'Click the first header row, then its last row. Click the same row twice for a one-row header.'
        : 'Click the button, then click the first and last header row numbers. Click the same row twice for a one-row header.';
      if (!sheet.selectingHeader) renderPreview(sheet, previewContainer);
    });
    previewContainer.addEventListener('click', function (event) {
      const rowButton = event.target.closest('[data-header-row]');
      if (!rowButton || !sheet.selectingHeader) return;
      const selectedRow = Number(rowButton.dataset.headerRow);
      if (sheet.headerSelectionStart === null) {
        sheet.headerSelectionStart = selectedRow;
        rowButton.closest('tr').classList.add('crop-header-anchor');
        headerCropHint.textContent = 'First header row: ' + selectedRow + '. Click the last header row (or click it again for a single-row header).';
        return;
      }
      const headerStart = Math.min(sheet.headerSelectionStart, selectedRow);
      const headerEnd = Math.max(sheet.headerSelectionStart, selectedRow);
      const setValue = function (name, value) {
        const input = layoutControls.querySelector('[name="' + name + '"]');
        input.value = String(value);
      };
      setValue('headerStart', headerStart);
      setValue('headerEnd', headerEnd);
      setValue('dataStart', headerEnd + 1);
      setValue('previewRow', Math.max(sheet.bounds.s.r + 1, headerStart - 5));
      sheet.selectingHeader = false;
      sheet.headerSelectionStart = null;
      headerCropButton.textContent = 'Select header rows in preview';
      headerCropHint.textContent = 'Header rows ' + headerStart + '–' + headerEnd + ' selected. Adjust the data range below if needed.';
      refreshCrop();
    });

    const columnOptions = document.createElement('details');
    columnOptions.className = 'column-options';
    const columnSummary = document.createElement('summary');
    columnSummary.textContent = 'Choose which columns to import';
    const mappings = document.createElement('div');
    mappings.className = 'column-mapping';
    columnOptions.append(columnSummary, mappings);
    card.appendChild(columnOptions);
    try {
      applyCrop(sheet, card);
      renderPreview(sheet, previewContainer);
    } catch (error) {
      sheet.layoutError = error.message;
      previewContainer.textContent = error.message;
      setStatus(error.message, 'error');
    }
    refreshColumnMappings(sheet, card);
    mappingsNode.appendChild(card);
  }

  function parseWorksheet(parsed) {
    const sheetName = parsed.name;
    const bounds = parsed.bounds;
    if (bounds.e.r - bounds.s.r + 1 > 50000 || bounds.e.c - bounds.s.c + 1 > 250) {
      throw new Error('Worksheet "' + sheetName + '" exceeds the supported limit of 50,000 rows or 250 columns.');
    }
    const matrix = parsed.matrix;
    const firstContentIndex = matrix.findIndex(function (row) {
      return Array.isArray(row) && row.some(function (value) {
        return value !== null && value !== undefined && String(value).trim() !== '';
      });
    });
    let defaultHeaderIndex = Math.max(firstContentIndex, 0);
    let bestHeadingCount = 0;
    matrix.slice(0, 30).forEach(function (row, index) {
      const filled = Array.isArray(row) ? row.filter(function (value) {
        return value !== null && value !== undefined && String(value).trim() !== '';
      }).length : 0;
      if (filled > bestHeadingCount) {
        bestHeadingCount = filled;
        defaultHeaderIndex = index;
      }
    });
    const defaultHeaderRow = bounds.s.r + defaultHeaderIndex + 1;
    return {
      name: sheetName,
      bounds: bounds,
      matrix: matrix,
      links: Object.create(null),
      merges: parsed.merges || [],
      empty: parsed.empty === true,
      defaultHeaderRow: defaultHeaderRow,
      defaultDataEndRow: bounds.e.r + 1,
      headers: [],
      rows: [],
      images: [],
      imageMap: Object.create(null),
      imageUrls: Object.create(null)
    };
  }

  function applyWorksheetLinks(sheet, parsed) {
    sheet.links = Object.create(null);
    (parsed.links || []).forEach(function (link) {
      if (!Number.isInteger(link.row) || !Number.isInteger(link.column)
        || typeof link.target !== 'string' || !link.target.trim() || link.target.length > 65000) return;
      sheet.links[link.row + ':' + link.column] = link.target;
    });
    sheet.images = Array.isArray(parsed.images) ? parsed.images : [];
    sheet.imageMap = Object.create(null);
    sheet.imageUrls = Object.create(null);
    sheet.images.forEach(function (image) {
      const key = image.row + ':' + image.column;
      if (!sheet.imageMap[key]) sheet.imageMap[key] = [];
      sheet.imageMap[key].push(image);
    });
  }

  async function loadWorkbook(file) {
    if (!window.Worker) throw new Error('Background workbook processing is not supported by this browser. Update to a modern browser and try again.');
    setStatus('Reading workbook in the background. Large files may take a moment; this page will remain responsive.');
    const buffer = await file.arrayBuffer();
    const parsed = await new Promise(function (resolve, reject) {
      const worker = new Worker('./bulk_import_worker.js?v=20261012-worker3');
      worker.onmessage = function (event) {
        worker.terminate();
        if (event.data && event.data.error) reject(new Error(event.data.error));
        else resolve(event.data && event.data.sheets);
      };
      worker.onerror = function (event) {
        worker.terminate();
        reject(new Error(event.message || 'The workbook could not be processed.'));
      };
      worker.postMessage({
        buffer: buffer,
        extractImages: /\.(?:xlsx|xlsm)$/i.test(file.name)
      }, [buffer]);
    });
    if (!Array.isArray(parsed) || !parsed.length) throw new Error('The workbook has no import worksheets.');
    if (parsed.length > 50) throw new Error('Select a workbook with no more than 50 import worksheets.');
    const parsedSheets = parsed.map(function (worksheet) {
      const sheet = parseWorksheet(worksheet);
      applyWorksheetLinks(sheet, worksheet);
      return sheet;
    });
    const response = await fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load available modules.');
    modules = data.modules || [];
    canCreateModule = data.can_create_module === true;
    importButton.disabled = !canCreateModule;
    if (!canCreateModule) {
      setStatus('Only the workspace owner can create tables for an imported workbook.', 'error');
    }
    sheets = parsedSheets;
    mappingsNode.replaceChildren();
    sheetListNode.replaceChildren();
    sheets.forEach(renderSheet);
    activeSheet = sheets.find(function (sheet) { return sheet.selected; }) || null;
    if (activeSheet) activateSheet(activeSheet);
    workbookSidebarName.value = file.name.replace(/\.[^.]+$/, '').trim().slice(0, 100);
    resultSection.hidden = true;
    updateSheetSelection();
    if (previewDialog && !previewDialog.open) previewDialog.showModal();
    updateCropStatus();
  }

  async function fetchCsrf() {
    if (!csrfToken) {
      const response = await fetch(backupApiUrl + '?action=csrf', {
        credentials: 'same-origin', cache: 'no-store'
      });
      const result = await response.json();
      if (!response.ok || !result.success || !result.csrf) {
        throw new Error(result.message || 'Unable to verify the session. Refresh and sign in again.');
      }
      csrfToken = result.csrf;
    }
    return csrfToken;
  }

  function collectModules() {
    if (!canCreateModule) {
      throw new Error('Only the workspace owner can import a workbook and create its worksheet tables.');
    }
    const selectedSheets = sheets.filter(function (sheet) { return sheet.selected; });
    if (!selectedSheets.length) throw new Error('Select at least one worksheet to import.');
    const totalRows = selectedSheets.reduce(function (total, sheet) { return total + sheet.rows.length; }, 0);
    if (totalRows > 100000) throw new Error('The selected data crops exceed the 100,000-row import limit.');
    const usedTargets = new Set();
    return selectedSheets.map(function (sheet) {
      const card = sheet.card;
      const mode = sheet.mode;
      if (sheet.layoutError) throw new Error(sheet.layoutError);
      const table = card.querySelector('input[aria-label^="New table name"]').value.trim();
      if (!table) throw new Error('Provide a table name for "' + sheet.name + '".');
      if (usedTargets.has(table.toLowerCase())) {
        throw new Error('Each worksheet must target a different module. "' + table + '" is selected more than once.');
      }
      usedTargets.add(table.toLowerCase());
      const headerMappings = Array.from(card.querySelectorAll('.column-mapping select')).map(function (select, index) {
        return { source: select.dataset.source, target: select.value, source_index: index };
      }).filter(function (mapping) { return mapping.target; });
      if (!headerMappings.length) throw new Error('Map at least one column for worksheet "' + sheet.name + '".');
      const targetNames = headerMappings.map(function (mapping) { return mapping.target.toLowerCase(); });
      if (new Set(targetNames).size !== targetNames.length) {
        throw new Error('Worksheet column names must be unique in "' + sheet.name + '".');
      }
      return {
        worksheet: sheet.name,
        label: sheet.name,
        sidebar_name: workbookSidebarName.value.trim(),
        table: table,
        mode: mode,
        headers: headerMappings,
        rows: sheet.rows
      };
    });
  }

  function createImportRequests(modules, sidebarName) {
    if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
      throw new Error('Secure workbook import is unavailable in this browser. Use localhost or HTTPS.');
    }
    const idBytes = window.crypto.getRandomValues(new Uint8Array(16));
    const workbookId = Array.from(idBytes, function (value) {
      return value.toString(16).padStart(2, '0');
    }).join('');
    const maxBatchBytes = 8 * 1024 * 1024;
    const encoder = new TextEncoder();
    const requests = [];

    modules.forEach(function (module) {
      const indexedModule = Object.assign({}, module, {
        sheet_order: sheets.findIndex(function (sheet) { return sheet.name === module.worksheet; }),
        mode: 'append',
        rows: []
      });
      const emptyEnvelope = JSON.stringify({
        group_name: sidebarName,
        workbook_id: workbookId,
        modules: [indexedModule]
      });
      const fixedBytes = encoder.encode(emptyEnvelope).length - 2;
      const rowChunks = [];
      let currentRows = [];
      let currentBytes = 0;
      module.rows.forEach(function (row) {
        const rowBytes = encoder.encode(JSON.stringify(row)).length;
        const separatorBytes = currentRows.length ? 1 : 0;
        if (fixedBytes + currentBytes + separatorBytes + rowBytes > maxBatchBytes) {
          if (!currentRows.length) {
            throw new Error('A single row in "' + module.worksheet + '" exceeds the safe server batch size.');
          }
          rowChunks.push(currentRows);
          currentRows = [];
          currentBytes = 0;
        }
        const nextSeparatorBytes = currentRows.length ? 1 : 0;
        if (fixedBytes + currentBytes + nextSeparatorBytes + rowBytes > maxBatchBytes) {
          throw new Error('A single row in "' + module.worksheet + '" exceeds the safe server batch size.');
        }
        currentRows.push(row);
        currentBytes += nextSeparatorBytes + rowBytes;
      });
      if (currentRows.length || rowChunks.length === 0) rowChunks.push(currentRows);

      rowChunks.forEach(function (rows, chunkIndex) {
        const chunkModule = Object.assign({}, module, {
          mode: chunkIndex === 0 ? module.mode : 'append',
          rows: rows,
          sheet_order: indexedModule.sheet_order
        });
        const envelope = {
          group_name: sidebarName,
          workbook_id: workbookId,
          modules: [chunkModule]
        };
        const body = JSON.stringify(envelope);
        if (encoder.encode(body).length > maxBatchBytes) {
          throw new Error('A data batch for "' + module.worksheet + '" is larger than the safe server limit.');
        }
        requests.push({
          worksheet: module.worksheet,
          chunkIndex: chunkIndex,
          chunkCount: rowChunks.length,
          envelope: envelope,
          module: chunkModule,
          body: body
        });
      });
    });
    return requests;
  }

  async function uploadEmbeddedImages(selectedModules, token, statusNode) {
    const uploadUrl = new URL('./format/attachment_api.php', window.location.href).href;
    const queue = [];
    selectedModules.forEach(function (module) {
      const sheet = sheets.find(function (item) { return item.name === module.worksheet; });
      if (!sheet || !sheet.images.length) return;
      const mappedColumns = new Set(module.headers.map(function (header) { return header.source_index; }));
      sheet.images.forEach(function (image) {
        const rowNumber = image.row + 1;
        const columnIndexWithinCrop = image.column - sheet.crop.firstColumn;
        if (rowNumber < sheet.crop.dataStart || rowNumber > sheet.crop.dataEnd
          || image.column < sheet.crop.firstColumn || image.column > sheet.crop.lastColumn
          || !mappedColumns.has(columnIndexWithinCrop)) return;
        queue.push({ module: module, sheet: sheet, image: image });
      });
    });
    if (!queue.length) return;

    let offset = 0;
    while (offset < queue.length) {
      const batch = [];
      let bytes = 0;
      while (offset < queue.length && batch.length < 10) {
        const item = queue[offset];
        if (batch.length && bytes + item.image.data.byteLength > 18 * 1024 * 1024) break;
        batch.push(item);
        bytes += item.image.data.byteLength;
        offset++;
      }
      if (!batch.length) throw new Error('An embedded image exceeds the safe upload request size.');
      const body = new FormData();
      batch.forEach(function (item) {
        const extension = item.image.name.match(/\.([a-z0-9]+)$/i);
        const name = slugify(item.sheet.name) + '_row' + (item.image.row + 1)
          + '_col' + (item.image.column + 1) + '.' + (extension ? extension[1].toLowerCase() : 'png');
        body.append('files[]', new Blob([item.image.data], { type: item.image.mime_type }), name);
      });
      statusNode.textContent = 'Saving embedded image attachments (' + Math.min(offset, queue.length)
        + ' of ' + queue.length + ')…';
      const response = await fetch(uploadUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token },
        body: body
      });
      const result = await response.json();
      if (!response.ok || !result.success || !Array.isArray(result.attachments)
        || result.attachments.length !== batch.length) {
        throw new Error(result.message || 'An embedded workbook image could not be saved.');
      }
      batch.forEach(function (item, index) {
        const key = item.image.row + ':' + item.image.column;
        if (!item.sheet.imageUrls[key]) item.sheet.imageUrls[key] = [];
        item.sheet.imageUrls[key].push(result.attachments[index].url);
      });
    }
    selectedModules.forEach(function (module) {
      const sheet = sheets.find(function (item) { return item.name === module.worksheet; });
      if (!sheet || !sheet.images.length) return;
      applyCrop(sheet, sheet.card);
      module.rows = sheet.rows;
    });
  }

  function renderResults(data) {
    resultsNode.replaceChildren();
    const entries = data.modules || [];
    const succeeded = entries.filter(function (result) { return result.success; }).length;
    const failed = entries.length - succeeded;
    const summary = document.createElement('p');
    summary.className = 'notice ' + (failed && succeeded
      ? 'partial'
      : (failed || !data.success ? 'error' : 'success'));
    summary.textContent = data.message || 'Import finished.';
    resultsNode.appendChild(summary);
    const list = document.createElement('ul');
    list.className = 'status-list';
    entries.forEach(function (result) {
      const item = document.createElement('li');
      item.textContent = result.worksheet + ': ' + (result.success
        ? (result.inserted + ' row(s) imported' + (result.created_module ? '; worksheet table created' : ''))
        : (result.message || 'Import failed'));
      list.appendChild(item);
    });
    resultsNode.appendChild(list);
    const firstImported = entries.find(function (result) { return result.success && result.table; });
    if (firstImported) {
      const openButton = document.createElement('button');
      openButton.type = 'button';
      openButton.className = 'secondary';
      openButton.textContent = 'Open first imported sheet: ' + (firstImported.sidebar_name || firstImported.table);
      openButton.addEventListener('click', function () {
        window.parent.postMessage({ type: 'inventory-open-module', table: firstImported.table }, window.location.origin);
      });
      resultsNode.appendChild(openButton);
    }
    resultSection.hidden = false;
  }

  async function importWorkbook() {
    if (importing) return;
    let selected;
    let requests;
    let sidebarName;
    try {
      selected = collectModules();
      sidebarName = workbookSidebarName.value.trim();
      if (!sidebarName || sidebarName.length > 100) {
        throw new Error('Enter a sidebar name of 1–100 characters for this workbook.');
      }
    } catch (error) {
      await window.appDialogs?.alert(error.message || 'Check the selected worksheets and try again.');
      return;
    }

    importing = true;
    importButton.disabled = true;
    progress.textContent = 'Preparing import…';
    const dialog = document.getElementById('progressDialog');
    const dialogText = document.getElementById('progressText');
    const spinner = document.getElementById('importSpinner');
    spinner.hidden = false;
    document.getElementById('closeProgress').hidden = true;
    if (previewDialog && previewDialog.open) previewDialog.close();
    if (dialog && !dialog.open) dialog.showModal();
    if (dialogText) dialogText.textContent = 'Validating worksheet mappings and contacting the server.';
    const resultsBySheet = new Map();
    let currentRequest = null;
    let applyCollisionMode = null;
    async function chooseCollisionMode(request) {
      if (applyCollisionMode) return applyCollisionMode;
      const conflictDialog = document.getElementById('tableConflictDialog');
      const conflictText = document.getElementById('tableConflictText');
      const applyAll = document.getElementById('applyConflictToAll');
      conflictText.textContent = 'A table named "' + request.module.table
        + '" already exists. Keep its current rows and add this worksheet, or replace it? Replacing first saves the current table to the Recycle Bin.';
      applyAll.checked = false;
      if (!conflictDialog.open) conflictDialog.showModal();
      const choice = await new Promise(function (resolve) {
        const onCancel = function (event) {
          event.preventDefault();
          finish(null);
        };
        const finish = function (mode) {
          conflictDialog.removeEventListener('cancel', onCancel);
          conflictDialog.close();
          resolve(mode);
        };
        conflictDialog.querySelector('[data-conflict-mode="existing"]').onclick = function () {
          finish('existing');
        };
        conflictDialog.querySelector('[data-conflict-mode="overwrite"]').onclick = function () {
          finish('overwrite');
        };
        conflictDialog.querySelector('[data-conflict-mode="cancel"]').onclick = function () {
          finish(null);
        };
        conflictDialog.addEventListener('cancel', onCancel);
      });
      if (choice && applyAll.checked) applyCollisionMode = choice;
      return choice;
    }
    try {
      const token = await fetchCsrf();
      await uploadEmbeddedImages(selected, token, dialogText);
      requests = createImportRequests(selected, sidebarName);
      for (let index = 0; index < requests.length; index++) {
        const request = requests[index];
        currentRequest = request;
        const label = 'Importing ' + request.worksheet + ' (' + (request.chunkIndex + 1)
          + ' of ' + request.chunkCount + ' batches; ' + (index + 1) + ' of ' + requests.length + ' overall)…';
        progress.textContent = label;
        if (dialogText) dialogText.textContent = label + ' Keep this page open until the import finishes.';
        let moduleResult = null;
        while (true) {
          const response = await fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: request.body
          });
          const data = await response.json();
          moduleResult = Array.isArray(data.modules) ? data.modules[0] : null;
          if (response.ok && moduleResult && moduleResult.success) break;
          if (moduleResult && moduleResult.code === 'table_exists' && request.chunkIndex === 0) {
            const mode = await chooseCollisionMode(request);
            if (!mode) throw new Error('Import canceled at worksheet "' + request.worksheet + '". No existing table was changed.');
            request.module.mode = mode;
            request.body = JSON.stringify(request.envelope);
            continue;
          }
          throw new Error(moduleResult?.message || data.message || 'Import failed for worksheet "' + request.worksheet + '".');
        }
        const saved = resultsBySheet.get(request.worksheet) || {
          worksheet: request.worksheet,
          table: moduleResult.table,
          sidebar_name: workbookSidebarName.value.trim(),
          success: true,
          created_module: false,
          inserted: 0
        };
        saved.inserted += Number(moduleResult.inserted || 0);
        saved.created_module = saved.created_module || moduleResult.created_module === true;
        resultsBySheet.set(request.worksheet, saved);
      }
      const finished = Array.from(resultsBySheet.values());
      const finalData = {
        success: finished.length === selected.length,
        message: finished.length + ' worksheet table(s) imported; 0 failed.',
        modules: finished
      };
      renderResults(finalData);
      progress.textContent = finalData.message;
      document.getElementById('progressTitle').textContent = 'Import finished';
      if (dialogText) dialogText.textContent = finalData.message;
    } catch (error) {
      const failedWorksheet = currentRequest?.worksheet || 'Workbook';
      const failedResult = resultsBySheet.get(failedWorksheet) || {
        worksheet: failedWorksheet,
        success: false,
        inserted: 0
      };
      failedResult.success = false;
      failedResult.message = error.message || 'Import failed.';
      resultsBySheet.set(failedWorksheet, failedResult);
      const completed = Array.from(resultsBySheet.values());
      const failureMessage = (completed.filter(function (result) { return result.success; }).length)
        + ' worksheet table(s) imported; import stopped at "' + failedWorksheet + '". ' + failedResult.message;
      progress.textContent = failureMessage;
      document.getElementById('progressTitle').textContent = 'Import needs attention';
      if (dialogText) dialogText.textContent = failureMessage;
      renderResults({ success: false, message: failureMessage, modules: completed });
    } finally {
      importing = false;
      importButton.disabled = !canCreateModule;
      spinner.hidden = true;
      const close = document.getElementById('closeProgress');
      if (close) close.hidden = false;
    }
  }

  downloadButton.addEventListener('click', makeTemplate);
  fileInput.addEventListener('change', async function () {
    resultSection.hidden = true;
    mappingsNode.replaceChildren();
    sheetListNode.replaceChildren();
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    fileInput.value = '';
    setStatus('Reading workbook…');
    try {
      await loadWorkbook(file);
    } catch (error) {
      setStatus(error.message || 'Unable to read this workbook.', 'error');
    }
  });
  importButton.addEventListener('click', importWorkbook);
  document.getElementById('cancelPreview').addEventListener('click', function () {
    if (previewDialog.open) previewDialog.close();
  });
  document.getElementById('selectAllSheets').addEventListener('click', function () {
    sheets.forEach(function (sheet) {
      sheet.selected = !sheet.empty;
      sheet.checkbox.checked = sheet.selected;
    });
    updateSheetSelection();
  });
  document.getElementById('clearSheetSelection').addEventListener('click', function () {
    sheets.forEach(function (sheet) {
      sheet.selected = false;
      sheet.checkbox.checked = false;
    });
    updateSheetSelection();
  });
  document.getElementById('closeProgress').addEventListener('click', function () {
    if (!importing) document.getElementById('progressDialog').close();
  });
})();
