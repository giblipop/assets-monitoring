(() => {
  if (window.appDialogs) return;
  const source = document.currentScript?.src || Array.from(document.scripts)
    .find(script => script.src.includes('/ui-dialog.js'))?.src || './ui-dialog.js';
  const scriptUrl = new URL(source, window.location.href);

  const style = document.createElement('style');
  style.textContent = `
    .app-dialog-backdrop{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:20px;background:var(--ui-overlay,rgba(17,32,45,.58));backdrop-filter:blur(8px) saturate(110%);-webkit-backdrop-filter:blur(8px) saturate(110%);animation:appDialogBackdropIn .18s ease-out both}
    .app-dialog{width:min(100%,460px);padding:24px;border:1px solid var(--ui-line,#dce4e9);border-radius:16px;background:var(--ui-surface,#fff);color:var(--ui-ink,#1e2d3a);box-shadow:var(--ui-modal-shadow,0 28px 80px rgba(14,31,45,.24));font:inherit;animation:appDialogSurfaceIn .22s cubic-bezier(.2,.75,.25,1) both}
    .app-dialog h2{margin:0 0 12px;color:var(--ui-ink,#1e2d3a);font-size:1.15rem}
    .app-dialog p{margin:0;color:var(--ui-muted,#637687);line-height:1.55;white-space:pre-line}
    .app-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}
    .app-dialog-actions button{min-height:40px;padding:8px 16px;border:1px solid var(--ui-line,#dce4e9);border-radius:9px;background:var(--ui-surface-raised,#f8fafb);color:var(--ui-ink,#1e2d3a);font:inherit;font-weight:600;cursor:pointer}
    .app-dialog-actions button.primary{border-color:var(--ui-button-bg,#176f78);background:var(--ui-button-bg,#176f78);color:#fff}
    .app-dialog-actions button.danger{border-color:var(--ui-danger,#b4443d);background:var(--ui-danger,#b4443d);color:#fff}
    .app-dialog-actions button:focus-visible{outline:3px solid color-mix(in srgb,var(--ui-accent,#176f78) 35%,transparent);outline-offset:2px}
    .app-undo-notice{position:fixed;right:20px;bottom:20px;z-index:99999;display:flex;align-items:center;gap:16px;max-width:min(540px,calc(100vw - 40px));padding:14px 16px;border:1px solid var(--ui-line,#dce4e9);border-radius:12px;background:var(--ui-surface-raised,#f8fafb);color:var(--ui-ink,#1e2d3a);box-shadow:var(--ui-modal-shadow,0 12px 36px rgba(15,23,42,.28));font:500 14px/1.4 system-ui,sans-serif}
    .app-undo-notice button{border:0;background:transparent;color:var(--ui-accent,#176f78);font:700 14px system-ui,sans-serif;cursor:pointer;text-decoration:underline}
    .app-undo-notice button:disabled{opacity:.65;cursor:wait}
    @keyframes appDialogBackdropIn{from{opacity:0}to{opacity:1}}
    @keyframes appDialogSurfaceIn{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
    @media(max-width:480px){.app-dialog-backdrop{align-items:end;padding:12px}.app-dialog{padding:20px;border-radius:16px 16px 12px 12px}.app-dialog-actions button{min-height:44px}}
    @media(prefers-reduced-motion:reduce){.app-dialog-backdrop,.app-dialog{animation-duration:.01ms!important}}
  `;
  document.head.appendChild(style);

  function showDialog(title, message, options = {}) {
    return new Promise(resolve => {
      const backdrop = document.createElement('div');
      backdrop.className = 'app-dialog-backdrop';
      const dialog = document.createElement('section');
      dialog.className = 'app-dialog';
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'app-dialog-title');
      const heading = document.createElement('h2');
      heading.id = 'app-dialog-title';
      heading.textContent = title;
      const body = document.createElement('p');
      body.textContent = String(message ?? '');
      const actions = document.createElement('div');
      actions.className = 'app-dialog-actions';

      const finish = value => {
        document.removeEventListener('keydown', onKeyDown);
        backdrop.remove();
        if (options.restoreFocus?.isConnected) options.restoreFocus.focus();
        resolve(value);
      };
      const onKeyDown = event => {
        if (event.key === 'Escape' && options.cancelable !== false) finish(false);
        if (event.key === 'Enter' && options.confirm) finish(true);
      };
      if (options.confirm) {
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = options.cancelText || 'Cancel';
        cancel.addEventListener('click', () => finish(false));
        actions.append(cancel);
      }
      const proceed = document.createElement('button');
      proceed.type = 'button';
      proceed.className = options.danger ? 'danger' : 'primary';
      proceed.textContent = options.confirm ? (options.confirmText || 'Continue') : 'OK';
      proceed.addEventListener('click', () => finish(options.confirm ? true : undefined));
      actions.append(proceed);
      dialog.append(heading, body, actions);
      backdrop.append(dialog);
      backdrop.addEventListener('click', event => {
        if (event.target === backdrop && options.confirm) finish(false);
      });
      document.body.append(backdrop);
      document.addEventListener('keydown', onKeyDown);
      proceed.focus();
    });
  }

  window.appDialogs = {
    confirm(message, options = {}) {
      return showDialog(options.title || 'Please confirm', message, {
        confirm: true,
        confirmText: options.confirmText || 'Continue',
        cancelText: options.cancelText || 'Cancel',
        danger: Boolean(options.danger),
        restoreFocus: document.activeElement
      });
    },
    alert(message, options = {}) {
      return showDialog(options.title || 'Notice', message, {
        restoreFocus: document.activeElement
      });
    }
  };

  window.alert = message => { void window.appDialogs.alert(message); };

  window.appUndo = {
    show(token, message = 'Your changes were saved.') {
      document.querySelector('.app-undo-notice')?.remove();
      if (!token) return;
      const notice = document.createElement('div');
      notice.className = 'app-undo-notice';
      notice.setAttribute('role', 'status');
      const text = document.createElement('span');
      text.textContent = `${message} Undo is available for 30 seconds.`;
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'Undo';
      notice.append(text, button);
      document.body.append(notice);
      let remaining = 30;
      const countdown = window.setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          window.clearInterval(countdown);
          notice.remove();
        } else {
          text.textContent = `${message} Undo available for ${remaining}s.`;
        }
      }, 1000);
      button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = 'Undoing…';
        try {
          const csrfResponse = await fetch(new URL('../../page/auth_api.php?action=csrf', scriptUrl), {
            credentials: 'same-origin',
            cache: 'no-store'
          });
          if (!csrfResponse.ok) throw new Error(`Undo authorization failed (${csrfResponse.status}).`);
          const csrf = await csrfResponse.json();
          const response = await fetch(new URL('../inventory/settings/undo_api.php', scriptUrl), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf.csrf
            },
            body: JSON.stringify({ token })
          });
          const result = await response.json();
          if (!response.ok || result.success !== true) {
            throw new Error(result.message || `Undo failed (${response.status}).`);
          }
          window.clearInterval(countdown);
          notice.remove();
          await window.appDialogs.alert(result.message || 'The change was undone.');
          window.location.reload();
        } catch (error) {
          button.disabled = false;
          button.textContent = 'Undo';
          await window.appDialogs.alert(error.message || 'Undo failed. Refresh and try again.');
        }
      });
    }
  };
})();
