import './stimulus_bootstrap.js';
import './styles/app.css';

// When the user types in a remote autocomplete (ux-autocomplete), clear the existing
// options and the search cache so that new results replace the previous ones instead
// of being appended. This runs after Stimulus has connected all controllers.
document.addEventListener('DOMContentLoaded', () => {
    requestAnimationFrame(() => {
        document.querySelectorAll('[data-controller~="symfony--ux-autocomplete--autocomplete"]').forEach(el => {
            const ts = el.tomselect;
            if (!ts) return;
            ts.on('type', () => {
                ts.clearOptions();
                ts.loadedSearches = {};
            });
        });
    });
});
