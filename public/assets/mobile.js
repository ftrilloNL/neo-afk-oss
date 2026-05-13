// Mobile-Sidebar-Toggle. Greift auf <aside> via id="sidebar" und
// Steuer-Elemente mit data-mobile-* zu. CSP-konform self-hosted.
(function () {
    'use strict';
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('mobile-overlay');
    if (!sidebar || !overlay) return;

    function show() {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
    function hide() {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('[data-mobile-open]').forEach(function (el) {
        el.addEventListener('click', show);
    });
    document.querySelectorAll('[data-mobile-close]').forEach(function (el) {
        el.addEventListener('click', hide);
    });
    overlay.addEventListener('click', hide);

    // Beim Klick auf einen Navigation-Link das Menü schließen (Mobile-UX).
    sidebar.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            if (window.innerWidth < 768) hide();
        });
    });

    // Bei Resize auf Desktop sicherstellen, dass Sidebar sichtbar ist.
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 768) {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    });
})();
