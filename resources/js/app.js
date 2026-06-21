import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    const sidebarBackdrop = document.querySelector('.sidebar-backdrop');
    const closeSidebar = () => { sidebar?.classList.remove('open'); document.body.classList.remove('sidebar-open'); };
    document.querySelectorAll('[data-sidebar-toggle]').forEach(button => button.addEventListener('click', () => {
        sidebar?.classList.toggle('open'); document.body.classList.toggle('sidebar-open', sidebar?.classList.contains('open'));
    }));
    document.querySelectorAll('[data-sidebar-close], .sidebar .nav-link').forEach(element => element.addEventListener('click', closeSidebar));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeSidebar(); });

    const enhanceTable = table => {
        if (table.classList.contains('no-mobile-cards')) return;
        const labels = [...table.querySelectorAll('thead th')].map((header, index) => header.textContent.trim() || (index ? 'Action' : 'Details'));
        if (!labels.length) return;
        table.classList.add('mobile-card-table');
        const labelRows = () => table.querySelectorAll('tbody tr').forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            [...row.children].forEach((cell, index) => { if (cell.tagName === 'TD') cell.dataset.label = labels[index] || 'Details'; });
        });
        labelRows();
        new MutationObserver(labelRows).observe(table.tBodies[0] || table, { childList: true, subtree: true });
    };
    document.querySelectorAll('.content table').forEach(enhanceTable);

    const filterBackdrop = document.createElement('div');
    filterBackdrop.className = 'mobile-filter-backdrop';
    document.body.appendChild(filterBackdrop);
    let activeFilter = null;
    const closeFilter = () => { activeFilter?.classList.remove('open'); filterBackdrop.classList.remove('show'); document.body.classList.remove('filter-sheet-open'); activeFilter = null; };
    document.querySelectorAll('.content form').forEach(form => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'get' || form.closest('.offcanvas,.dashboard-filter,.modal') || form.querySelectorAll('input,select').length < 2) return;
        form.classList.add('mobile-filter-sheet');
        const header = document.createElement('div');
        header.className = 'mobile-filter-sheet-head';
        header.innerHTML = '<strong>Filters</strong><button type="button" aria-label="Close filters"><i class="bi bi-x-lg"></i></button>';
        header.querySelector('button').addEventListener('click', closeFilter);
        form.prepend(header);
        const trigger = document.createElement('button');
        trigger.type = 'button'; trigger.className = 'btn btn-light mobile-filter-trigger'; trigger.innerHTML = '<i class="bi bi-sliders2 me-2"></i>Open filters';
        form.before(trigger);
        trigger.addEventListener('click', () => { activeFilter = form; form.classList.add('open'); filterBackdrop.classList.add('show'); document.body.classList.add('filter-sheet-open'); });
    });
    filterBackdrop.addEventListener('click', closeFilter);
    document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirm || 'Are you sure?')) event.preventDefault();
    }));
    document.querySelectorAll('[data-counter]').forEach(element => {
        const target = Number(element.dataset.counter || 0); let current = 0; const step = Math.max(target / 35, 1);
        const tick = () => { current = Math.min(target, current + step); element.textContent = target % 1 ? current.toFixed(2) : Math.floor(current).toLocaleString(); if (current < target) requestAnimationFrame(tick); }; tick();
    });
});
