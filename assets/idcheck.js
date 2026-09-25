/**
 * ID check page (idcheck.php): mounts the Vouched plugin like demo/idcheck.php.
 * onSubmit / onDone → submit.php?action=idcheck (job id + result, saved on the visitor's row).
 * Verified → the server's redirect (config.php → verified_url); not verified → failure message with "Try again".
 */
(function () {
    'use strict';

    var boot = JSON.parse(document.getElementById('page-data').textContent);
    var text = boot.text || {};
    var failed = document.getElementById('idcheck-failed');
    var mountEl = document.getElementById('vouched-element');

    function send(jobId, success) {
        return fetch(boot.submitUrl + '?action=idcheck', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': boot.csrf },
            body: JSON.stringify({ job_id: jobId, success: success })
        }).then(function (res) { return res.json().catch(function () { return {}; }); });
    }

    function showFailed(message) {
        if (message) failed.querySelector('p').textContent = message;
        failed.hidden = false;
        mountEl.hidden = true;
    }

    if (!boot.appId || typeof window.Vouched !== 'function') {
        showFailed(text.unavailable || 'Identity verification is not available right now.');
        document.getElementById('idcheck-retry').hidden = true;
        return;
    }

    function mount() {
        failed.hidden = true;
        mountEl.hidden = false;
        mountEl.innerHTML = '';
        var vouched = window.Vouched({
            appId: boot.appId,
            verification: boot.person,
            liveness: 'enhanced',
            callbackURL: boot.callbackUrl,
            crossDevice: true,
            crossDeviceQRCode: true,
            crossDeviceSMS: true,
            id: 'camera',
            face: 'camera',
            includeBarcode: true,
            theme: { name: 'avant' },
            onSubmit: function (stage, attempts, job) {
                if (job && job.id) send(job.id, null).catch(function () { /* the callback still records it */ });
            },
            onDone: function (job) {
                var ok = !!(job && job.result && job.result.success);
                send(job && job.id ? job.id : '', ok).then(function (data) {
                    if (ok && data.success && data.redirect) {
                        location.replace(data.redirect + location.search);
                    } else {
                        showFailed();
                    }
                }).catch(function () { showFailed(); });
            }
        });
        vouched.mount('#vouched-element');
    }

    document.getElementById('idcheck-retry').addEventListener('click', mount);
    mount();
})();
