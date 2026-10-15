(() => {
    const chat = document.getElementById('aiChat');
    const launcher = document.getElementById('aiChatLauncher');
    const panel = document.getElementById('aiChatPanel');
    const closeButton = document.getElementById('aiChatClose');
    const newChatButton = document.getElementById('aiChatNew');
    const messagesElement = document.getElementById('aiChatMessages');
    const welcome = document.getElementById('aiChatWelcome');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const sendButton = document.getElementById('aiChatSend');
    const characterCount = document.getElementById('aiChatCharCount');
    const suggestions = document.querySelector('.ai-chat-suggestions');

    if (!chat || !launcher || !panel || !form || !input || !messagesElement || !welcome) return;

    const apiUrl = new URL('./local_assistant.php', window.location.href);
    const csrfUrl = new URL('../../page/auth_api.php?action=csrf', window.location.href);
    let sending = false;

    function setOpen(open) {
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', String(open));
        launcher.querySelector('.ai-chat-launcher-label').textContent = open ? 'Close chat' : 'HDJV Assistant';
        if (open) {
            window.setTimeout(() => input.focus(), 40);
        } else {
            launcher.focus();
        }
    }

    function scrollToLatest() {
        messagesElement.scrollTop = messagesElement.scrollHeight;
    }

    function addMessage(role, content, { error = false, typing = false } = {}) {
        const wrapper = document.createElement('div');
        wrapper.className = `ai-chat-message ${role}${error ? ' error' : ''}`;

        const label = document.createElement('span');
        label.className = 'ai-chat-message-label';
        label.textContent = role === 'user' ? 'You' : 'HDJV Assistant';
        wrapper.appendChild(label);

        const bubble = document.createElement('div');
        bubble.className = 'ai-chat-message-bubble';
        if (typing) {
            bubble.classList.add('ai-chat-typing');
            bubble.setAttribute('aria-label', 'HDJV Assistant is responding');
            for (let index = 0; index < 3; index += 1) {
                bubble.appendChild(document.createElement('span'));
            }
        } else {
            bubble.textContent = content;
        }
        wrapper.appendChild(bubble);
        messagesElement.appendChild(wrapper);
        scrollToLatest();
        return wrapper;
    }

    async function getCsrfToken() {
        const response = await fetch(csrfUrl, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.success || typeof data.csrf !== 'string') {
            throw new Error(data.message || 'Your session expired. Sign in again to use the AI assistant.');
        }
        return data.csrf;
    }

    function resizeInput() {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 112)}px`;
        characterCount.textContent = `${input.value.length} / 2000`;
        sendButton.disabled = sending || input.value.trim().length === 0;
    }

    async function sendMessage(message) {
        if (sending || !message) return;
        sending = true;
        welcome.hidden = true;
        addMessage('user', message);
        const typing = addMessage('assistant', '', { typing: true });
        sendButton.disabled = true;

        try {
            const csrfToken = await getCsrfToken();
            const response = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ message })
            });
            const data = await response.json();
            if (!response.ok || !data.success || typeof data.message !== 'string') {
                throw new Error(data.message || `The AI assistant request failed (${response.status}).`);
            }

            typing.remove();
            addMessage('assistant', data.message);
        } catch (error) {
            typing.remove();
            addMessage('assistant', error instanceof Error ? error.message : 'The AI assistant could not respond. Please try again.', { error: true });
        } finally {
            sending = false;
            resizeInput();
            input.focus();
        }
    }

    launcher.addEventListener('click', () => setOpen(panel.hidden));
    closeButton?.addEventListener('click', () => setOpen(false));
    newChatButton?.addEventListener('click', () => {
        if (sending) return;
        messagesElement.replaceChildren(welcome);
        welcome.hidden = false;
        input.value = '';
        resizeInput();
        input.focus();
    });

    suggestions?.addEventListener('click', event => {
        const button = event.target.closest('button[data-prompt]');
        if (!button || sending) return;
        setOpen(true);
        input.value = button.dataset.prompt || '';
        resizeInput();
        form.requestSubmit();
    });

    form.addEventListener('submit', event => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message || sending) return;
        input.value = '';
        resizeInput();
        sendMessage(message);
    });

    input.addEventListener('input', resizeInput);
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) setOpen(false);
    });

    resizeInput();
})();
