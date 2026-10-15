(() => {
    const form = document.getElementById('loginForm');
    if (!form) return;

    form.addEventListener('submit', async event => {
        if (document.getElementById('username').value.trim() !== 'kradmin') return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const button = document.getElementById('loginBtn');
        const alertBox = document.getElementById('loginAlert');
        button.disabled = true;
        window.showBrandEntrance?.();
        try {
            const csrfResponse = await fetch('admin_api.php?action=csrf', { credentials: 'same-origin' });
            const csrfResult = await csrfResponse.json();
            if (!csrfResponse.ok || !csrfResult.csrf) throw new Error('Unable to start a secure admin sign-in.');

            const response = await fetch('admin_api.php?action=login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfResult.csrf
                },
                body: JSON.stringify({
                    username: 'kradmin',
                    password: document.getElementById('password').value
                })
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Admin sign-in failed.');
            if (window.completeBrandEntrance) window.completeBrandEntrance('admin/dashboard.html');
            else location.assign('admin/dashboard.html');
        } catch (error) {
            window.hideBrandEntrance?.();
            alertBox.innerHTML = '<div class="alert alert-danger" role="alert"></div>';
            alertBox.firstElementChild.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    }, true);
})();
