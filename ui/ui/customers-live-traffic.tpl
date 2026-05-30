{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                Live Traffic Monitor
                <span class="pull-right">
                    <small id="lt-status" class="text-muted">connecting…</small>
                    &nbsp;&nbsp;
                    <small id="lt-summary" class="text-muted"></small>
                </span>
            </div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="lt-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Full name</th>
                                <th>Service</th>
                                <th>IP</th>
                                <th>Uptime</th>
                                <th class="text-right">↓ Now</th>
                                <th class="text-right">↑ Now</th>
                                <th class="text-right">Session ↓</th>
                                <th class="text-right">Session ↑</th>
                            </tr>
                        </thead>
                        <tbody id="lt-body">
                            <tr><td colspan="9" class="text-center text-muted">loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">
                    Refreshes every 1&nbsp;s. ↓/↑ Now = router-reported queue rate (bits/sec), falling
                    back to byte-counter deltas between refreshes when no live rate is available.
                    Session totals are cumulative since the user connected.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
var LIVE_TRAFFIC_URL = '{$_url}customers/live-traffic-data';
{literal}
(function () {
    var POLL_MS = 1000;
    var prev = {};   // username -> {bytesIn, bytesOut, ts}

    function fmtBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
        if (n < 1024*1024*1024) return (n/1024/1024).toFixed(2) + ' MB';
        return (n/1024/1024/1024).toFixed(2) + ' GB';
    }
    // Mikrotik /queue/simple "rate" is already in bits/sec. We used to multiply
    // by 8 here, which made every value 8x too high. Verified against
    // (delta_bytes / delta_seconds) — those align with rate / 1e6 as Mbps.
    function fmtRate(bps) {
        if (!bps || bps < 0) return '—';
        if (bps < 1000) return Math.round(bps) + ' bps';
        if (bps < 1e6)  return (bps/1000).toFixed(1) + ' kbps';
        if (bps < 1e9)  return (bps/1e6).toFixed(2) + ' Mbps';
        return (bps/1e9).toFixed(2) + ' Gbps';
    }

    function render(data) {
        var status = document.getElementById('lt-status');
        var summary = document.getElementById('lt-summary');
        var body = document.getElementById('lt-body');

        if (data.error) {
            status.className = 'text-danger';
            status.textContent = '⚠ ' + data.error;
            return;
        }
        var now = Date.now();
        status.className = 'text-success';
        status.textContent = '● live  (' + new Date(now).toLocaleTimeString() + ')';

        var rows = [];
        var totalDown = 0, totalUp = 0, totalDownRate = 0, totalUpRate = 0;
        data.sessions.sort(function (a, b) { return (a.username || '').localeCompare(b.username || ''); });
        data.sessions.forEach(function (s) {
            // Prefer router-provided rate (real-time) over client delta.
            // queue RX = customer upload, queue TX = customer download
            var rd = (typeof s.rateOut === 'number') ? s.rateOut : 0;  // Download = queue TX
            var ru = (typeof s.rateIn  === 'number') ? s.rateIn  : 0;  // Upload = queue RX
            // Fallback delta if router didn't give a rate (e.g. hotspot sessions).
            // Byte deltas are bytes/sec; multiply by 8 to match fmtRate's bits/sec.
            if (!rd && !ru) {
                var p = prev[s.username];
                if (p && p.ts) {
                    var dt = (now - p.ts) / 1000;
                    if (dt > 0) {
                        rd = Math.max(0, (s.bytesOut - p.bytesOut) * 8 / dt);  // Download = bytesOut delta
                        ru = Math.max(0, (s.bytesIn - p.bytesIn) * 8 / dt);   // Upload = bytesIn delta
                    }
                }
            }
            var rateDown = fmtRate(rd), rateUp = fmtRate(ru);
            totalDownRate += rd; totalUpRate += ru;
            prev[s.username] = { bytesIn: s.bytesIn, bytesOut: s.bytesOut, ts: now };
            totalDown += s.bytesOut; totalUp += s.bytesIn;  // bytesOut=download, bytesIn=upload
            rows.push(
                '<tr>' +
                '<td><strong>' + (s.username || '') + '</strong></td>' +
                '<td>' + (s.fullname || '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + (s.service || '') + '</td>' +
                '<td><code>' + (s.address || '') + '</code></td>' +
                '<td>' + (s.uptime || '') + '</td>' +
                '<td class="text-right"><strong>' + rateDown + '</strong></td>' +
                '<td class="text-right"><strong>' + rateUp + '</strong></td>' +
                '<td class="text-right">' + fmtBytes(s.bytesOut) + '</td>' +
                '<td class="text-right">' + fmtBytes(s.bytesIn) + '</td>' +
                '</tr>'
            );
        });
        body.innerHTML = rows.length
            ? rows.join('')
            : '<tr><td colspan="9" class="text-center text-muted">no active sessions</td></tr>';
        summary.textContent =
            data.sessions.length + ' active   |   ↓ ' + fmtRate(totalDownRate) +
            '   ↑ ' + fmtRate(totalUpRate) +
            '   |   session totals ↓ ' + fmtBytes(totalDown) +
            ' ↑ ' + fmtBytes(totalUp);
    }

    function poll() {
        fetch(LIVE_TRAFFIC_URL, { credentials: 'same-origin' })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (!r.ok || ct.indexOf('application/json') < 0) {
                    // The fetch likely got redirected to the login page (session expired).
                    throw new Error('Session expired — please reload the page and log in again.');
                }
                return r.json();
            })
            .then(render)
            .catch(function (e) {
                var s = document.getElementById('lt-status');
                s.className = 'text-danger';
                s.textContent = '⚠ ' + e.message;
            })
            .finally(function () { setTimeout(poll, POLL_MS); });
    }
    poll();
})();
{/literal}
</script>

{include file="sections/footer.tpl"}
