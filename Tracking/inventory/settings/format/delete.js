// save as delete.js (complete)
(function () {
    const runWhenReady = callback => {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, { once: true });
        else callback();
    };
    runWhenReady(() => {
  const pathSegments = window.location.pathname.split('/').filter(s => s.length > 0);
  const lastSegment = pathSegments[pathSegments.length - 1] || '';
  let rawAssetContext = lastSegment.replace(/\.(html|php)$/i, '');
  if (!rawAssetContext || rawAssetContext.toLowerCase() === 'index') {
    rawAssetContext = pathSegments[pathSegments.length - 2] || pathSegments[0] || 'inventory';
  }

  const tableBody = document.getElementById('tableBody');
  const tableHead = document.getElementById('tableHead');
  const deleteBtn = document.getElementById('deleteBtn');
  const dropdownWrapper = deleteBtn ? deleteBtn.parentElement : null;
  const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
  const deleteAllBtn = document.getElementById('deleteAllBtn');
  const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
  const BACKEND_DELETE_URL = 'delete.php';
  const activeTableContext = tableBody?.getAttribute('data-table') || rawAssetContext;

  const sysConfirmModalOverlay = document.getElementById('confirmActionModal');
  const sysConfirmModalTitle = document.getElementById('confirmModalTitle');
  const sysConfirmModalMessage = document.getElementById('confirmModalMessage');
  const sysConfirmModalCancelBtn = document.getElementById('confirmModalCancelBtn');
  const sysConfirmModalProceedBtn = document.getElementById('confirmModalProceedBtn') || document.getElementById('confirmActionModalProceed');

  let isSelectMode = false;
  let pendingDeleteCallback = null;

  function showNotification(message, type = 'success') {
    let container = document.getElementById('system-notification-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'system-notification-container';
      container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; display:flex;flex-direction:column;gap:10px;';
      document.body.appendChild(container);
    }
    const note = document.createElement('div');
    note.className = `sys-alert sys-alert-${type}`;
    note.style.cssText = `background:${type === 'success' ? '#2ecc71' : '#e74c3c'}; color:#fff; padding:12px 18px; border-radius:6px; box-shadow:0 4px 8px rgba(0,0,0,0.12); opacity:0; transform:translateY(-8px); transition:all .28s;`;
    note.textContent = message;
    if (type === 'success' && /recycle bin/i.test(message)) {
      const link = document.createElement('a');
      link.href = new URL('../recycle_bin.html', window.location.href).href;
      link.textContent = 'Open Recycle Bin';
      link.style.cssText = 'display:inline-block;margin-left:10px;color:#fff;font-weight:700;text-decoration:underline;white-space:nowrap;';
      note.appendChild(link);
    }
    container.appendChild(note);
    requestAnimationFrame(() => { note.style.opacity = '1'; note.style.transform = 'translateY(0)'; });
    setTimeout(() => { note.style.opacity = '0'; note.style.transform = 'translateY(-8px)'; setTimeout(() => note.remove(), 300); }, 4200);
  }

  function openConfirmModal(title, message, callback) {
    if (!sysConfirmModalOverlay || !sysConfirmModalTitle || !sysConfirmModalMessage || !sysConfirmModalProceedBtn) {
      window.appDialogs.confirm(message, { title, danger: true }).then(confirmed => {
        if (confirmed) callback();
      });
      return;
    }
    sysConfirmModalTitle.textContent = title;
    sysConfirmModalMessage.textContent = message;
    pendingDeleteCallback = callback;
    sysConfirmModalOverlay.classList.add('active');
  }

  function closeConfirmModal() {
    if (sysConfirmModalOverlay) sysConfirmModalOverlay.classList.remove('active');
    pendingDeleteCallback = null;
  }

  if (sysConfirmModalCancelBtn) sysConfirmModalCancelBtn.addEventListener('click', closeConfirmModal);
  if (sysConfirmModalProceedBtn) sysConfirmModalProceedBtn.addEventListener('click', () => {
    if (typeof pendingDeleteCallback === 'function') pendingDeleteCallback();
    closeConfirmModal();
  });

  function extractRowId(row) {
    if (!row) return '';
    if (row.dataset && row.dataset.id) return row.dataset.id;
    const hidden = row.querySelector('input[name="id"], input[data-key="id"]');
    if (hidden && hidden.value) return hidden.value;
    const attr = row.getAttribute('data-id') || row.getAttribute('data-row-id') || row.getAttribute('id');
    if (attr) return attr;
    return '';
  }

  function sanitizeIds(ids) {
    return ids.map(String).map(s => s.trim()).filter(s => s !== '' && /^-?\d+$/.test(s)).map(s => String(parseInt(s, 10)));
  }

  function removeRowsByIdList(idList) {
    if (!tableBody) return;
    idList.forEach(id => {
      const row = tableBody.querySelector(`tr[data-id="${CSS.escape(id)}"]`) || tableBody.querySelector(`tr input[name="id"][value="${CSS.escape(id)}"]`)?.closest('tr');
      if (row) row.remove();
    });
  }

  async function refreshAfterDelete(removedIds, undoToken) {
    if (typeof window.loadAssets === 'function') {
      try {
        await window.loadAssets();
        showNotification(`Moved ${removedIds.length} item(s) to the recycle bin.`, 'success');
        window.appUndo?.show(undoToken, `Moved ${removedIds.length} item(s) to the recycle bin.`);
        return;
      } catch (e) {
        // fallback to DOM removal
      }
    }
    removeRowsByIdList(removedIds);
    showNotification(`Moved ${removedIds.length} item(s) to the recycle bin.`, 'success');
    window.appUndo?.show(undoToken, `Moved ${removedIds.length} item(s) to the recycle bin.`);
  }

  function enterSelectionMode() {
    if (!tableHead || !tableBody) return;
    isSelectMode = true;
    deleteSelectedBtn && (deleteSelectedBtn.textContent = 'Cancel Selection');
    confirmDeleteBtn && (confirmDeleteBtn.style.display = 'flex');

    const headerRow = tableHead.querySelector('tr');
    if (headerRow && !headerRow.querySelector('.checkbox-col')) {
      const th = document.createElement('th');
      th.className = 'checkbox-col';
      th.style.width = '40px';
      th.innerHTML = '<input type="checkbox" id="selectAll">';
      headerRow.insertBefore(th, headerRow.firstChild);
    }

    tableBody.querySelectorAll('tr').forEach(row => {
      if (row.querySelector('.loading') || row.querySelector('.error')) return;
      if (!row.querySelector('.checkbox-col')) {
        const td = document.createElement('td');
        td.className = 'checkbox-col';
        td.innerHTML = '<input type="checkbox" class="row-checkbox">';
        td.querySelector('input').addEventListener('click', e => e.stopPropagation());
        row.insertBefore(td, row.firstChild);
      }
    });
  }

  function exitSelectionMode() {
    isSelectMode = false;
    deleteSelectedBtn && (deleteSelectedBtn.textContent = 'Select Rows');
    confirmDeleteBtn && (confirmDeleteBtn.style.display = 'none');
    const tableElement = document.getElementById('assetTable') || document.querySelector('.table-container table');
    if (tableElement) tableElement.querySelectorAll('.checkbox-col').forEach(el => el.remove());
  }

  deleteSelectedBtn?.addEventListener('click', () => {
    dropdownWrapper && dropdownWrapper.classList.remove('show');
    if (isSelectMode) exitSelectionMode(); else enterSelectionMode();
  });

  tableHead?.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'selectAll' && tableBody) {
      tableBody.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = e.target.checked);
    }
  });

  tableBody?.addEventListener('click', (e) => {
    if (!isSelectMode) return;
    const row = e.target.closest('tr');
    if (!row) return;
    const checkbox = row.querySelector('.row-checkbox');
    if (!checkbox) return;
    if (e.target === checkbox) return;
    checkbox.checked = !checkbox.checked;
    const master = document.getElementById('selectAll');
    if (master) {
      const all = Array.from(tableBody.querySelectorAll('.row-checkbox'));
      master.checked = all.length > 0 && all.every(cb => cb.checked);
    }
  });

  confirmDeleteBtn?.addEventListener('click', () => {
    const checked = tableBody ? tableBody.querySelectorAll('.row-checkbox:checked') : [];
    if (!checked || checked.length === 0) {
      showNotification('Please select at least one record to delete.', 'error');
      return;
    }

    openConfirmModal(
      'Delete Selected Items',
      `Move ${checked.length} selected item(s) to the recycle bin? They can be restored for 30 days.`,
      async () => {
        const ids = Array.from(checked).map(cb => {
          const row = cb.closest('tr');
          return extractRowId(row);
        }).filter(Boolean);

        const sanitized = sanitizeIds(ids);
        if (sanitized.length === 0) {
          showNotification('No valid numeric IDs found for selected rows.', 'error');
          return;
        }

        try {
          const resp = await fetch(BACKEND_DELETE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'delete_selected',
              tableContext: activeTableContext,
              refNos: sanitized
            })
          });

          if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
          const json = await resp.json();
          if (json.status === 'success') {
            await refreshAfterDelete(sanitized, json.undo_token);
            exitSelectionMode();
          } else {
            showNotification('Database Error: ' + (json.message || 'Failed to delete records.'), 'error');
          }
        } catch (err) {
          console.error('Delete selected failed:', err);
          showNotification('Failed to communicate with server. Try again.', 'error');
        }
      }
    );
  });

  deleteAllBtn?.addEventListener('click', () => {
    dropdownWrapper && dropdownWrapper.classList.remove('show');

    openConfirmModal(
      `Wipe Entire ${rawAssetContext} Inventory`,
      `Move all rows in "${activeTableContext}" to the recycle bin? They can be restored for 30 days.`,
      async () => {
        try {
          const resp = await fetch(BACKEND_DELETE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'wipe_all',
              tableContext: activeTableContext
            })
          });

          if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
          const json = await resp.json();
          if (json.status === 'success') {
            if (typeof window.loadAssets === 'function') {
              try { await window.loadAssets(); } catch (e) { tableBody.innerHTML = '<tr><td colspan="100%" style="text-align:center;padding:20px;">No records remaining.</td></tr>'; }
            } else {
              tableBody.innerHTML = '<tr><td colspan="100%" style="text-align:center;padding:20px;">No records remaining.</td></tr>';
            }
            exitSelectionMode();
            showNotification(json.message || `Table "${activeTableContext}" moved to the recycle bin.`, 'success');
            window.appUndo?.show(json.undo_token, json.message || 'Records moved to the recycle bin.');
          } else {
            showNotification('Database Error: ' + (json.message || 'Failed to clear table.'), 'error');
          }
        } catch (err) {
          console.error('Wipe all failed:', err);
          showNotification('Failed to communicate with server. Try again.', 'error');
        }
      }
    );
  });

  if (deleteBtn && dropdownWrapper) {
    deleteBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdownWrapper.classList.toggle('show');
    });
    window.addEventListener('click', () => dropdownWrapper.classList.remove('show'));
  }
});
})();
