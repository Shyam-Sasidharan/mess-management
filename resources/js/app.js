import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('open'));
    document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirm || 'Are you sure?')) event.preventDefault();
    }));
    document.querySelectorAll('[data-counter]').forEach(element => {
        const target = Number(element.dataset.counter || 0); let current = 0; const step = Math.max(target / 35, 1);
        const tick = () => { current = Math.min(target, current + step); element.textContent = target % 1 ? current.toFixed(2) : Math.floor(current).toLocaleString(); if (current < target) requestAnimationFrame(tick); }; tick();
    });
});
