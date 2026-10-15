(function () {
    'use strict';

    const preferenceKey = 'assetMonitorTheme';
    const choices = ['system', 'light', 'dark'];
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

    function getPreference() {
        const saved = localStorage.getItem(preferenceKey);
        return choices.includes(saved) ? saved : 'light';
    }

    function applyTheme() {
        const preference = getPreference();
        const resolvedTheme = preference === 'system'
            ? (systemTheme.matches ? 'dark' : 'light')
            : preference;
        document.documentElement.dataset.theme = resolvedTheme;
        document.documentElement.dataset.themePreference = preference;
        document.documentElement.style.colorScheme = resolvedTheme;
        window.dispatchEvent(new CustomEvent('asset-monitor-theme-change', {
            detail: { preference, theme: resolvedTheme }
        }));
    }

    function setPreference(preference) {
        if (!choices.includes(preference)) {
            throw new TypeError('Appearance must be system, light, or dark.');
        }
        localStorage.setItem(preferenceKey, preference);
        applyTheme();
    }

    window.AssetMonitorTheme = { getPreference, setPreference, applyTheme };
    window.addEventListener('storage', event => {
        if (event.key === preferenceKey) applyTheme();
    });
    systemTheme.addEventListener('change', () => {
        if (getPreference() === 'system') applyTheme();
    });
    applyTheme();

    const api = new URL('../../page/auth_api.php', document.currentScript.src);
    api.searchParams.set('action', 'me');
    fetch(api, { credentials: 'same-origin', cache: 'no-store' })
        .then(response => {
            if (response.status === 401) return null;
            if (!response.ok) throw new Error(`Appearance preference could not be loaded (${response.status}).`);
            return response.json();
        })
        .then(result => {
            const preference = result?.user?.ThemePreference;
            if (result?.success && choices.includes(preference)) setPreference(preference);
        })
        .catch(error => console.error('Appearance preference sync failed.', error));
})();
