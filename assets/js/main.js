document.addEventListener('DOMContentLoaded', function () {
    // ===== SCROLL REVEAL =====
    var els = document.querySelectorAll('article,table,section,.grid,hgroup,form,h1');

    if (!('IntersectionObserver' in window)) {
        els.forEach(function (el) {
            el.classList.add('visible');
        });
    } else {
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08 });

        els.forEach(function (el, i) {
            el.classList.add('reveal');
            el.style.transitionDelay = (i * 60) + 'ms';
            obs.observe(el);
        });
    }

    // ===== NOTIFICATIONS (SSE + Toast + Badge) =====
    var badge = document.getElementById('notif-badge');
    var toastContainer = null;

    function getToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    function updateBadge(count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }

    function showToast(data) {
        var container = getToastContainer();
        var toast = document.createElement('div');
        toast.className = 'toast';

        var accentClass = 'toast-accent--' + (data.type || 'broadcast');

        toast.innerHTML =
            '<div class="toast-accent ' + accentClass + '"></div>' +
            '<div class="toast-content">' +
                '<div class="toast-title">' + escapeHtml(data.title) + '</div>' +
                (data.body ? '<div class="toast-body">' + escapeHtml(data.body) + '</div>' : '') +
            '</div>' +
            '<button class="toast-close" aria-label="Tutup">&times;</button>';

        container.appendChild(toast);

        var closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', function () {
            dismissToast(toast);
        });

        // Auto dismiss after 5 seconds
        setTimeout(function () {
            dismissToast(toast);
        }, 5000);
    }

    function dismissToast(toast) {
        if (toast.classList.contains('toast-out')) return;
        toast.classList.add('toast-out');
        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // Fetch initial unread count
    function fetchBadgeCount() {
        fetch('?page=notif_count')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                updateBadge(data.count || 0);
            })
            .catch(function () {});
    }

    // Connect to SSE stream
    function connectSSE() {
        if (!('EventSource' in window)) return;
        if (!badge) return; // not logged in

        var source = new EventSource('?page=sse');

        source.onmessage = function (event) {
            try {
                var data = JSON.parse(event.data);
                showToast(data);
                // Increment badge
                var current = parseInt(badge.textContent) || 0;
                updateBadge(current + 1);
            } catch (e) {}
        };

        source.onerror = function () {
            // EventSource will auto-reconnect via retry directive
        };
    }

    // Initialize notifications if user is logged in
    if (badge) {
        fetchBadgeCount();
        connectSSE();
    }
});
