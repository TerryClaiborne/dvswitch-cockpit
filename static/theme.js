(() => {
    'use strict';

    const STORAGE_KEY = 'dvswitch_cockpit_theme';

    function normalizeTheme(value) {
        return value === 'light' ? 'light' : 'dark';
    }

    function readStoredTheme() {
        try {
            return normalizeTheme(window.localStorage.getItem(STORAGE_KEY));
        } catch (error) {
            return 'dark';
        }
    }

    function saveTheme(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // Keep the active theme even if storage is unavailable.
        }
    }

    function syncToggle(theme) {
        const toggle = document.getElementById('theme-toggle');
        if (!toggle) return;

        const isLight = theme === 'light';
        toggle.setAttribute('aria-checked', isLight ? 'true' : 'false');
        toggle.setAttribute(
            'title',
            isLight ? 'Light theme active' : 'Dark theme active'
        );
    }

    function applyTheme(theme) {
        const normalized = normalizeTheme(theme);
        document.documentElement.setAttribute('data-theme', normalized);
        syncToggle(normalized);
        return normalized;
    }

    let currentTheme = applyTheme(readStoredTheme());

    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('theme-toggle');
        syncToggle(currentTheme);

        if (!toggle) return;

        toggle.addEventListener('click', () => {
            currentTheme = currentTheme === 'light' ? 'dark' : 'light';
            applyTheme(currentTheme);
            saveTheme(currentTheme);
        });
    });
})();
