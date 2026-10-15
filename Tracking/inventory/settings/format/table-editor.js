(() => {
  const openButton = document.getElementById('editTableBtn');
  const modal = document.getElementById('tableEditorModal');
  const closeButton = document.getElementById('closeTableEditor');
  const doneButton = document.getElementById('doneTableEditor');
  const columnsNode = document.getElementById('tableEditorColumns');
  const rowsNode = document.getElementById('tableEditorRows');
  const statusNode = document.getElementById('tableEditorStatus');
  const searchInput = document.getElementById('tableEditorRowSearch');
  const apiPath = './table_editor_api.php';
  let csrfToken = '';

  function endpoint(path, table) {
    const url = new URL(path, window.location.href);
    if (table) url.searchParams.set('table', table);
    return url.href;
  }

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch(endpoint('./attachment_api.php?action=csrf', window.__inventory.getEditorState().table), {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const result = await response.json();
    if (!response.ok || !result.success || !result.csrf) {
      throw new Error(result.message || 'Refresh the page and sign in again.');
    }
    csrfToken = result.csrf;
    return csrfToken;
  }

  async function post(action, values = {}) {
    const state = window.__inventory.getEditorState();
    const token = await csrf();
    const response = await fetch(endpoint(apiPath, state.table), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(Object.assign({ action: action }, values))
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'The table change could not be saved.');
    }
    return result;
  }

  function message(text, isError = false) {
    statusNode.textContent = text;
    statusNode.dataset.error = isError ? 'true' : 'false';
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function selectedColumns(selector) {
    return Array.from(columnsNode.querySelectorAll(selector))
      .filter(input => input.checked)
      .map(input => input.value);
  }

  async function saveColumnPreferences() {
    const hiddenColumns = selectedColumns('[data-column-hidden]');
    const attachmentColumns = selectedColumns('[data-column-attachment]');
    const filterColumns = selectedColumns('[data-column-filter]');
    try {
      message('Saving column settings…');
      await post('save_preferences', {
        hidden_columns: hiddenColumns,
        attachment_columns: attachmentColumns,
        filter_columns: filterColumns
      });
      await window.__inventory.reload();
      renderColumns();
      renderRows();
      message('Column settings saved.');
    } catch (error) {
      message(error.message, true);
      await window.appDialogs?.alert(error.message);
      renderColumns();
    }
  }

  function renderColumns() {
    const state = window.__inventory.getEditorState();
    columnsNode.replaceChildren();
    state.columns.forEach(column => {
      const name = String(column.Field);
      const key = name.toLowerCase();
      const primary = Number(column.Key === 'PRI');
      const autoIncrement = String(column.Extra || '').toLowerCase().includes('auto_increment');
      const generated = /\b(?:virtual|stored)\s+generated\b/i.test(String(column.Extra || ''));
      const protectedField = primary || autoIncrement || generated
        || ['id', 'asset_code', 'ref_no', 'refno', 'last_user'].includes(key);
      const row = element('article', 'table-editor-item');
      const description = element('div', 'table-editor-item-copy');
      const label = state.headerLabels[name] || name.replace(/_/g, ' ').toUpperCase();
      description.append(
        element('strong', '', label),
        element('span', 'table-editor-meta', `${name} · ${column.Type || 'field'}`)
      );
      const controls = element('div', 'table-editor-item-controls');

      const hideLabel = element('label', 'table-editor-check');
      const hide = document.createElement('input');
      hide.type = 'checkbox';
      hide.value = name;
      hide.checked = state.hiddenColumns.includes(name);
      hide.dataset.columnHidden = 'true';
      hide.setAttribute('aria-label', `Hide ${name} in the table and edit form`);
      hide.addEventListener('change', saveColumnPreferences);
      hideLabel.append(hide, document.createTextNode('Hidden'));

      const attachLabel = element('label', 'table-editor-check');
      const attach = document.createElement('input');
      attach.type = 'checkbox';
      attach.value = name;
      attach.checked = state.attachmentColumns.includes(name);
      attach.disabled = protectedField;
      attach.dataset.columnAttachment = 'true';
      attach.setAttribute('aria-label', `Enable attachment button for ${name}`);
      attach.addEventListener('change', saveColumnPreferences);
      attachLabel.append(attach, document.createTextNode('Attach'));

      const filterLabel = element('label', 'table-editor-check');
      const filter = document.createElement('input');
      filter.type = 'checkbox';
      filter.value = name;
      filter.checked = state.filterColumns.includes(name);
      filter.dataset.columnFilter = 'true';
      filter.setAttribute('aria-label', `Enable value filter for ${label}`);
      filter.addEventListener('change', saveColumnPreferences);
      filterLabel.append(filter, document.createTextNode('Filter'));

      controls.append(hideLabel, attachLabel, filterLabel);
      if (!protectedField) {
        const remove = element('button', 'table-editor-delete-column', 'Move to Recycle Bin');
        remove.type = 'button';
        remove.title = 'Remove this column and save its data in the Recycle Bin';
        remove.addEventListener('click', () => deleteColumn(name));
        controls.appendChild(remove);
      }
      row.append(description, controls);
      columnsNode.appendChild(row);
    });
  }

  async function deleteColumn(name) {
    const confirmed = await window.appDialogs?.confirm(
      `Remove the "${name}" column and all values in it? Its data will be saved in the Recycle Bin for 30 days.`,
      { title: 'Remove column', danger: true }
    );
    if (!confirmed) return;
    try {
      message(`Deleting ${name}…`);
      await post('delete_column', { column: name });
      await window.__inventory.reload();
      renderColumns();
      renderRows();
      message(`Column "${name}" moved to the Recycle Bin.`);
    } catch (error) {
      message(error.message, true);
      await window.appDialogs?.alert(error.message);
    }
  }

  function rowLabel(asset, key) {
    const preferred = ['asset_code', 'ref_no', 'name', 'model'];
    const detail = preferred.map(field => asset[field]).find(value => value !== undefined && String(value).trim());
    return detail ? `${detail} · ${key}` : `Record ${key}`;
  }

  function renderRows() {
    const state = window.__inventory.getEditorState();
    const query = (searchInput.value || '').trim().toLowerCase();
    rowsNode.replaceChildren();
    if (!state.primaryKey) {
      rowsNode.appendChild(element('p', 'table-editor-empty', 'Row hide and delete actions need one unique primary-key column in this table.'));
      document.getElementById('hideSelectedRows').disabled = true;
      document.getElementById('showSelectedRows').disabled = true;
      document.getElementById('deleteSelectedRows').disabled = true;
      return;
    }
    document.getElementById('hideSelectedRows').disabled = false;
    document.getElementById('showSelectedRows').disabled = false;
    document.getElementById('deleteSelectedRows').disabled = false;
    const rows = state.assets.filter(asset => {
      const key = String(asset[state.primaryKey] ?? asset.id ?? asset.ID ?? asset.Id ?? '');
      if (!key) return false;
      return !query || rowLabel(asset, key).toLowerCase().includes(query)
        || Object.values(asset).some(value => String(value ?? '').toLowerCase().includes(query));
    });
    if (!rows.length) {
      rowsNode.appendChild(element('p', 'table-editor-empty', 'No matching records.'));
      return;
    }
    rows.forEach(asset => {
      const key = String(asset[state.primaryKey] ?? asset.id ?? asset.ID ?? asset.Id ?? '');
      const row = element('article', 'table-editor-item table-editor-record');
      const identity = element('label', 'table-editor-record-identity');
      const select = document.createElement('input');
      select.type = 'checkbox';
      select.value = key;
      select.setAttribute('aria-label', `Select ${rowLabel(asset, key)}`);
      identity.append(select, element('span', '', rowLabel(asset, key)));

      const status = element('span', state.hiddenRows.has(key) ? 'table-editor-hidden' : 'table-editor-visible',
        state.hiddenRows.has(key) ? 'Hidden' : 'Visible');
      const toggle = element('button', 'btn-secondary', state.hiddenRows.has(key) ? 'Show' : 'Hide');
      toggle.type = 'button';
      toggle.addEventListener('click', async () => {
        toggle.disabled = true;
        try {
          const hide = !state.hiddenRows.has(key);
          await post('toggle_row_visibility', { row_id: key, hidden: hide });
          await window.__inventory.reload();
          renderRows();
          message(hide ? 'Record hidden.' : 'Record is visible again.');
        } catch (error) {
          toggle.disabled = false;
          message(error.message, true);
          await window.appDialogs?.alert(error.message);
        }
      });
      row.append(identity, status, toggle);
      rowsNode.appendChild(row);
    });
  }

  async function toggleSelectedRows(hidden) {
    const ids = Array.from(rowsNode.querySelectorAll('input[type="checkbox"]:checked')).map(input => input.value);
    if (!ids.length) {
      message('Select at least one record first.', true);
      return;
    }
    try {
      for (let index = 0; index < ids.length; index++) {
        message(`${hidden ? 'Hiding' : 'Showing'} record ${index + 1} of ${ids.length}…`);
        await post('toggle_row_visibility', { row_id: ids[index], hidden: hidden });
      }
      await window.__inventory.reload();
      renderRows();
      message(`${ids.length} record(s) ${hidden ? 'hidden' : 'shown'}.`);
    } catch (error) {
      await window.__inventory.reload();
      renderRows();
      message(error.message, true);
      await window.appDialogs?.alert(error.message);
    }
  }

  async function deleteSelectedRows() {
    const state = window.__inventory.getEditorState();
    if (!state.primaryKey) {
      message('Row deletion requires one unique primary-key column.', true);
      return;
    }
    const ids = Array.from(rowsNode.querySelectorAll('input[type="checkbox"]:checked')).map(input => input.value);
    if (!ids.length) {
      message('Select at least one record first.', true);
      return;
    }
    const confirmed = await window.appDialogs?.confirm(
      `Move ${ids.length} selected record(s) to the Recycle Bin?`,
      { title: 'Delete records', danger: true }
    );
    if (!confirmed) return;
    try {
      message('Moving selected records to the Recycle Bin…');
      const response = await fetch(endpoint('./delete.php', state.table), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_selected', tableContext: state.table, refNos: ids })
      });
      const result = await response.json();
      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'The selected records could not be deleted.');
      }
      await window.__inventory.reload();
      renderRows();
      message(result.message || 'Selected records moved to the Recycle Bin.');
      window.appUndo?.show(result.undo_token, `Moved ${ids.length} record(s) to the Recycle Bin.`);
    } catch (error) {
      message(error.message, true);
      await window.appDialogs?.alert(error.message);
    }
  }

  function setTab(name) {
    document.querySelectorAll('[data-editor-tab]').forEach(button => {
      const selected = button.dataset.editorTab === name;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-selected', String(selected));
    });
    document.querySelectorAll('[data-editor-panel]').forEach(panel => {
      panel.hidden = panel.dataset.editorPanel !== name;
    });
  }

  function openEditor() {
    if (!window.__inventory || !window.__inventory.getEditorState().canEdit) return;
    renderColumns();
    renderRows();
    message('');
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeEditor() {
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
  }

  openButton?.addEventListener('click', openEditor);
  closeButton?.addEventListener('click', closeEditor);
  doneButton?.addEventListener('click', closeEditor);
  modal?.addEventListener('click', event => {
    if (event.target === modal) closeEditor();
  });
  document.querySelectorAll('[data-editor-tab]').forEach(button => {
    button.addEventListener('click', () => setTab(button.dataset.editorTab));
  });
  searchInput?.addEventListener('input', renderRows);
  document.getElementById('hideSelectedRows')?.addEventListener('click', () => toggleSelectedRows(true));
  document.getElementById('showSelectedRows')?.addEventListener('click', () => toggleSelectedRows(false));
  document.getElementById('deleteSelectedRows')?.addEventListener('click', deleteSelectedRows);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal?.classList.contains('active')) closeEditor();
  });
})();
