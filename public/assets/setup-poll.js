(function () {
    const container = document.getElementById('device-pending');
    if (!container) return;
    let interval = parseInt(container.dataset.pollInterval, 10) * 1000;
    if (isNaN(interval) || interval < 2000) interval = 5000;

    function poll() {
        fetch('/setup/smtp/poll', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const statusEl = document.getElementById('poll-status');
                const errorEl = document.getElementById('poll-error');
                const continueForm = document.getElementById('continue-form');
                const restartForm = document.getElementById('restart-form');
                if (data.status === 'success') {
                    statusEl.classList.add('hidden');
                    continueForm.classList.remove('hidden');
                    return;
                }
                if (data.status === 'pending') {
                    if (data.slow_down) interval += 5000;
                    setTimeout(poll, interval);
                    return;
                }
                if (data.status === 'expired') {
                    statusEl.classList.add('hidden');
                    errorEl.textContent = 'Der Code ist abgelaufen. Bitte neu holen.';
                    errorEl.classList.remove('hidden');
                    restartForm.classList.remove('hidden');
                    return;
                }
                statusEl.classList.add('hidden');
                errorEl.textContent = 'Fehler: ' + (data.message || data.status);
                errorEl.classList.remove('hidden');
                restartForm.classList.remove('hidden');
            })
            .catch(function () { setTimeout(poll, interval); });
    }
    setTimeout(poll, interval);
})();
