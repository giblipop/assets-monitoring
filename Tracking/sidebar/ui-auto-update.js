(function () {
    'use strict';

    const versionUrl = '../connector/ui_version.php';
    const pollInterval = 60000;
    const frame = document.getElementById('contentFrame');
    let currentVersion = '';
    let checking = false;
    let reloadScheduled = false;
    let updateNotice;

    function versionedUrl(value, version) {
        const url = new URL(value, window.location.href);
        url.searchParams.set('uiBuild', version);
        return url.href;
    }

    function refreshStylesheetCache(documentToUpdate, version) {
        if (!documentToUpdate || !version) return;
        documentToUpdate.querySelectorAll('link[rel="stylesheet"][href*="modern-ui.css"]').forEach(link => {
            const nextUrl = versionedUrl(link.href, version);
            if (link.href !== nextUrl) link.href = nextUrl;
        });
    }

    function showUpdateNotice(message) {
        if (!updateNotice) {
            updateNotice = document.createElement('div');
            updateNotice.setAttribute('role', 'status');
            updateNotice.style.cssText = [
                'position:fixed',
                'top:14px',
                'right:14px',
                'z-index:30000',
                'max-width:min(360px,calc(100vw - 28px))',
                'padding:12px 16px',
                'border:1px solid rgba(255,255,255,.22)',
                'border-radius:12px',
                'background:#153b56',
                'box-shadow:0 12px 32px rgba(17,44,64,.24)',
                'color:#fff',
                'font:500 13px/1.5 Inter,"Segoe UI",sans-serif'
            ].join(';');
            document.body.appendChild(updateNotice);
        }
        updateNotice.textContent = message;
    }

    function hasOpenDialog(documentToCheck) {
        if (!documentToCheck) return false;
        const selectors = [
            'dialog[open]',
            '.modal[aria-hidden="false"]',
            '.modal.active',
            '.modal-overlay.active',
            '.modal-overlay.show',
            '.dialog-backdrop:not([hidden])',
            '.employee-issue-overlay:not([hidden])',
            '#aiChatPanel:not([hidden])',
            '#databaseHealthModal:not([hidden])'
        ];
        return documentToCheck.querySelectorAll(selectors.join(',')).length > 0;
    }

    function reloadWhenSafe() {
        if (hasOpenDialog(document) || (frame && hasOpenDialog(frame.contentDocument))) {
            showUpdateNotice('A system update is ready. It will apply after you close the open dialog.');
            window.setTimeout(reloadWhenSafe, 5000);
            return;
        }
        showUpdateNotice('A system update is ready. Refreshing now…');
        window.setTimeout(() => window.location.reload(), 700);
    }

    function refreshFrameStylesheet(version) {
        if (!frame) return;
        try {
            refreshStylesheetCache(frame.contentDocument, version);
        } catch (error) {
            console.error('Could not refresh the embedded page stylesheet.', error);
        }
    }

    async function checkForUpdate() {
        if (checking || reloadScheduled) return;
        checking = true;
        try {
            const response = await fetch(versionUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                throw new Error(`UI version check failed: HTTP ${response.status}`);
            }

            const result = await response.json();
            if (typeof result.version !== 'string' || !/^[a-f0-9]{24}$/.test(result.version)) {
                throw new Error('The server returned an invalid UI version.');
            }

            if (currentVersion && result.version !== currentVersion) {
                reloadScheduled = true;
                reloadWhenSafe();
                return;
            }

            currentVersion = result.version;
            document.documentElement.dataset.uiBuild = currentVersion;
            refreshStylesheetCache(document, currentVersion);
            refreshFrameStylesheet(currentVersion);
        } catch (error) {
            console.error('Automatic UI update check failed; it will retry.', error);
        } finally {
            checking = false;
        }
    }

    if (frame) {
        frame.addEventListener('load', () => refreshFrameStylesheet(currentVersion));
    }

    checkForUpdate();
    window.setInterval(checkForUpdate, pollInterval);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') checkForUpdate();
    });
})();
