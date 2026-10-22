if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Silent fail: app remains usable without offline cache.
        });
    });
}

// ⌘K / Ctrl+K opens the admin command palette. Bound once here: listeners declared in Alpine
// markup would pile up across wire:navigate visits.
document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        if (!document.querySelector('[data-command-palette]')) {
            return;
        }
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('toggle-command-palette'));
    }
});
