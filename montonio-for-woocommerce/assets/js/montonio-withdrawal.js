/**
 * Progressive enhancement for the four-step customer withdrawal journey.
 * All actions also work through ordinary form POSTs without JavaScript.
 */
(() => {
    document.querySelectorAll('.montonio-withdrawal').forEach(root => {
        let pending;
        let pendingAction;
        const identity = () => ['order_number', 'email'].map(key => root.querySelector(`[name="montonio_withdrawal[${key}]"]`)?.value).join('\n');
        // Only lookup results are bound to the order number and email they were requested with.
        // Change order clears the match server-side, so editing either must not cancel it.
        const identityBound = action => action === 'lookup';
        const showMessage = (text, error = false) => {
            const message = root.querySelector('[data-montonio-lookup-message]');
            if (!message) return;
            message.textContent = text;
            message.hidden = !text;
            message.classList.toggle('montonio-withdrawal-error', error);
            message.setAttribute('role', error ? 'alert' : 'status');
        };
        // A quantity is only offered, and only submitted, for a checked item.
        const updateItems = () => {
            root.querySelectorAll('.montonio-withdrawal-item').forEach(row => {
                const group = row.querySelector('.montonio-withdrawal-quantity');
                if (!group) return;
                const checked = row.querySelector('input[type="checkbox"]').checked;
                group.hidden = !checked;
                group.querySelector('select, input').disabled = !checked;
            });
            updateSelection();
        };
        // Mirrors the server count: a checked item counts its quantity, kept within the ordered quantity.
        const updateSelection = () => {
            const selection = root.querySelector('[data-montonio-selection]');
            if (!selection) return;
            let count = 0;
            let total = 0;
            root.querySelectorAll('.montonio-withdrawal-item').forEach(row => {
                const ordered = Number(row.dataset.quantity) || 0;
                total += ordered;
                if (!row.querySelector('input[type="checkbox"]').checked) return;
                const control = row.querySelector('.montonio-withdrawal-quantity select, .montonio-withdrawal-quantity input');
                count += control ? Math.min(ordered, Math.max(1, Math.trunc(Number(control.value)) || 1)) : ordered;
            });
            selection.querySelector('[data-montonio-selection-count]').textContent = count
                ? selection.dataset.format.replace('%1$s', count).replace('%2$s', total)
                : selection.dataset.none;
        };
        const enhance = (focus = false) => {
            updateItems();
            if (focus) {
                const target = root.querySelector('[data-focus]');
                target?.focus({ preventScroll: true });
                if (root.dataset.stage === 'form') target?.scrollIntoView({ block: 'start', behavior: 'auto' });
            }
            const print = root.querySelector('.montonio-withdrawal-print');
            if (print) print.hidden = false;
        };
        root.addEventListener('input', event => {
            if (['montonio_withdrawal[order_number]', 'montonio_withdrawal[email]'].includes(event.target.name) && root.dataset.stage === 'order') {
                if (identityBound(pendingAction)) {
                    pending?.abort();
                    pending = undefined;
                    pendingAction = undefined;
                }
                if (!pending) showMessage('');
            }
            updateItems();
        });
        root.addEventListener('change', updateItems);
        root.addEventListener('click', event => {
            if (event.target.closest('.montonio-withdrawal-print')) window.print();
        });
        root.addEventListener('submit', async event => {
            const button = event.submitter;
            if (!button || !['lookup', 'change'].includes(button.value)) return;
            event.preventDefault();
            pending?.abort();
            const controller = new AbortController();
            pending = controller;
            pendingAction = button.value;
            const snapshot = identity();
            const data = new FormData(event.target);
            data.set('montonio_withdrawal_action', button.value);
            data.set('montonio_withdrawal_async', '1');
            showMessage(root.dataset.loading);
            button.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(event.target.action, { method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Lookup failed');
                const result = await response.json();
                if (pending !== controller) return;
                if (identityBound(button.value) && snapshot !== identity()) {
                    // Stale: the identity changed with no input event to abort this request.
                    showMessage('');
                    return;
                }
                const replacement = new DOMParser().parseFromString(result.html, 'text/html').querySelector('.montonio-withdrawal');
                if (!replacement) throw new Error('Invalid form response');
                const position = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
                root.replaceChildren(...replacement.childNodes);
                root.dataset.stage = replacement.dataset.stage;
                enhance(!result.error);
                if (result.error) {
                    root.querySelector('[data-focus]')?.focus({ preventScroll: true });
                    window.scrollTo(position);
                    requestAnimationFrame(() => window.scrollTo(position));
                }
            } catch (error) {
                if (error.name !== 'AbortError' && pending === controller) {
                    showMessage(root.dataset.loadError, true);
                }
            } finally {
                if (pending === controller) { pending = undefined; pendingAction = undefined; }
                button.removeAttribute('aria-busy');
            }
        });
        window.addEventListener('pagehide', () => { pending?.abort(); pending = undefined; pendingAction = undefined; });
        // Pages restored from the back/forward cache skip the direct call below.
        window.addEventListener('pageshow', event => { if (event.persisted) enhance(); });
        enhance();
        root.querySelector('[data-focus]')?.focus({ preventScroll: true });
    });
})();
