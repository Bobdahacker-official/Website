'use strict';

const theme = document.querySelector('#theme');
if (theme) {
    const root = document.documentElement;
    const setTheme = (dark) => {
        root.dataset.theme = dark ? 'dark' : 'light';
        theme.setAttribute('aria-pressed', String(dark));
    };
    const preference = localStorage.getItem('kiowiki-theme');
    setTheme(preference ? preference === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches);
    theme.addEventListener('click', () => {
        const dark = root.dataset.theme !== 'dark';
        setTheme(dark);
        localStorage.setItem('kiowiki-theme', root.dataset.theme);
    });
}

const search = document.querySelector('.search input');
document.addEventListener('keydown', (event) => {
    if (search && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        search.focus();
        search.select();
    }
});

const previewButton = document.querySelector('[data-preview-button]');
if (previewButton) {
    const editor = previewButton.closest('form');
    const compose = editor.querySelector('[data-preview]');
    const panel = compose.querySelector('.preview-panel');
    const result = panel.querySelector('[data-preview-body]');
    const body = editor.elements.body;
    let activeRequest;
    let delay;
    const preview = async () => {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        try {
            const data = new URLSearchParams({csrf: editor.elements.csrf.value, body: body.value});
            const response = await fetch(compose.dataset.preview, {method: 'POST', body: data, signal: request.signal});
            if (!response.ok) throw new Error('Preview failed');
            result.innerHTML = await response.text();
        } catch (error) {
            if (error.name !== 'AbortError') result.textContent = 'Preview unavailable. You can still save your article.';
        }
    };
    previewButton.hidden = false;
    previewButton.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        compose.classList.toggle('split', !panel.hidden);
        previewButton.setAttribute('aria-expanded', String(!panel.hidden));
        previewButton.textContent = panel.hidden ? 'Preview' : 'Hide preview';
        if (!panel.hidden) preview();
    });
    body.addEventListener('input', () => {
        if (panel.hidden) return;
        clearTimeout(delay);
        delay = setTimeout(preview, 300);
    });
}

const outline = [...document.querySelectorAll('.toc-link')];
if (outline.length) {
    const sections = outline.map((anchor) => document.getElementById(anchor.getAttribute('href').slice(1)));
    const update = () => {
        const marker = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header')) + 25;
        let current = 0;
        sections.forEach((section, index) => {
            if (section && section.getBoundingClientRect().top <= marker) current = index;
        });
        outline.forEach((anchor, index) => {
            anchor.classList.toggle('current', index === current);
            if (index === current) anchor.setAttribute('aria-current', 'location');
            else anchor.removeAttribute('aria-current');
        });
    };
    window.addEventListener('scroll', update, {passive: true});
    update();
}
