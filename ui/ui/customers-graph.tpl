{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                Bandwidth Graph &mdash; {$c['username']}
                <a href="{$_url}customers/edit/{$c['id']}"     class="btn btn-warning btn-sm pull-right" style="margin-left:6px">Edit</a>
                <a href="{$_url}customers/browsing/{$c['id']}" class="btn btn-default btn-sm pull-right" style="margin-left:6px">Browsing</a>
                <a href="{$_url}customers/diagnose/{$c['id']}" class="btn btn-info    btn-sm pull-right" style="margin-left:6px">Diagnose</a>
                <span class="pull-right" style="margin-right:10px">
                    <small id="g-status" class="text-muted">loading…</small>
                </span>
            </div>
            <div class="panel-body">

                <div class="row mb20">
                    <div class="col-md-4">
                        <strong>{$c['fullname']|default:''}</strong><br>
                        <small class="text-muted">{$c['phonenumber']|default:''}</small>
                    </div>
                    <div class="col-md-4">
                        {if $r}
                            Plan <strong>{$r['namebp']}</strong> ({$r['type']})<br>
                            <small class="text-muted">expires {$r['expiration']}</small>
                        {else}
                            <span class="text-muted">No recharge</span>
                        {/if}
                    </div>
                    <div class="col-md-4 text-right">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-default g-range" data-mins="15">15 m</button>
                            <button type="button" class="btn btn-default g-range" data-mins="60">1 h</button>
                            <button type="button" class="btn btn-primary g-range" data-mins="360">6 h</button>
                            <button type="button" class="btn btn-default g-range" data-mins="1440">24 h</button>
                            <button type="button" class="btn btn-default g-range" data-mins="10080">7 d</button>
                        </div>
                    </div>
                </div>

                <div style="height:380px; position:relative;">
                    <canvas id="g-chart"></canvas>
                    <div id="g-empty" style="position:absolute; inset:0; display:none; align-items:center; justify-content:center; color:#94a3b8; font-style:italic;">
                        No data for the selected range yet — the poller runs every minute, so come back in a minute or two.
                    </div>
                </div>
                <pre id="g-debug" style="display:none; background:#fff3cd; border:1px solid #ffc107; padding:8px; margin-top:10px; font-size:12px; color:#856404;"></pre>

                <hr>

                <div class="row">
                    <div class="col-md-3">
                        <strong>Current ↓</strong><br>
                        <span id="g-rate-in" class="text-success" style="font-size:1.4em">—</span>
                    </div>
                    <div class="col-md-3">
                        <strong>Current ↑</strong><br>
                        <span id="g-rate-out" class="text-success" style="font-size:1.4em">—</span>
                    </div>
                    <div class="col-md-3">
                        <strong>Session ↓</strong><br>
                        <span id="g-bytes-in" style="font-size:1.2em">—</span>
                    </div>
                    <div class="col-md-3">
                        <strong>Session ↑</strong><br>
                        <span id="g-bytes-out" style="font-size:1.2em">—</span>
                    </div>
                </div>
                <div class="row mt20">
                    <div class="col-md-12">
                        <small class="text-muted">
                            IP: <code id="g-ip">—</code> &middot;
                            Uptime: <span id="g-uptime">—</span> &middot;
                            MAC: <code id="g-mac">—</code>
                        </small>
                    </div>
                </div>

                <p class="text-muted small mt20">
                    Historical samples are written by a 1-minute cron from <code>/queue/simple/print</code>;
                    the latest point is live (refreshed every 3&nbsp;s). Retention: 7 days.
                </p>
            </div>
        </div>
    </div>
</div>

<div id="g-libstatus" style="display:none; background:#f8d7da; color:#721c24; padding:10px; margin:10px 0; border:1px solid #f5c6cb; font-family:monospace; font-size:13px;"></div>
<script>
{literal}
// Visible report on whether each library actually loaded.
window.__libs = {chart:false, luxon:false, adapter:false};
function __libNote(name, ok, err) {
    window.__libs[name] = ok;
    if (!ok) {
        var box = document.getElementById('g-libstatus');
        if (box) {
            box.style.display = 'block';
            box.textContent += '⚠ ' + name + ' failed to load: ' + (err || '') + '\n';
        }
    }
}
{/literal}
</script>
<script src="ui/ui/scripts/vendor/chart.umd.min.js"
        onload="__libNote('chart.js', typeof Chart !== 'undefined')"
        onerror="__libNote('chart.js', false, 'local file missing')"></script>
<script src="ui/ui/scripts/vendor/luxon.min.js"
        onload="__libNote('luxon', typeof luxon !== 'undefined')"
        onerror="__libNote('luxon', false, 'local file missing')"></script>
<script src="ui/ui/scripts/vendor/chartjs-adapter-luxon.umd.min.js"
        onload="__libNote('adapter', true)"
        onerror="__libNote('adapter', false, 'local file missing')"></script>
<script>
var GRAPH_USER = '{$c.username|escape:"javascript"}';
var GRAPH_URL  = '{$_url}customers/graph-data/' + encodeURIComponent(GRAPH_USER);
{literal}
(function () {
    var LIVE_POLL_MS = 1000;
    var rangeMinutes = 360;
    var chart;

    function fmtBytes(n) {
        if (!n || n < 1024) return (n || 0) + ' B';
        if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
        if (n < 1024*1024*1024) return (n/1024/1024).toFixed(2) + ' MB';
        return (n/1024/1024/1024).toFixed(2) + ' GB';
    }
    // Mikrotik /queue/simple "rate" is already in bits/sec — earlier we multiplied
    // by 8, which made every reading 8x too high (a 75 Mbps capped session looked
    // like ~600 Mbps). Verified by cross-checking against (delta_bytes / delta_sec).
    function fmtRate(bps) {
        if (!bps || bps < 0) return '0 bps';
        if (bps < 1000) return Math.round(bps) + ' bps';
        if (bps < 1e6) return (bps/1000).toFixed(1) + ' Kbps';
        if (bps < 1e9) return (bps/1e6).toFixed(2) + ' Mbps';
        return (bps/1e9).toFixed(2) + ' Gbps';
    }

    function buildChart(samples) {
        var canvas = document.getElementById('g-chart');
        // bits/sec → Mbps
        var dataIn  = samples.map(function (s) { return { x: s.ts, y: s.rateIn  / 1e6 }; });
        var dataOut = samples.map(function (s) { return { x: s.ts, y: s.rateOut / 1e6 }; });
        if (chart) {
            chart.data.datasets[0].data = dataIn;
            chart.data.datasets[1].data = dataOut;
            chart.update('none');
            return;
        }
        // Defensive: another Chart instance may already own this canvas
        // (race between two concurrent polls, or stale instance after range change).
        var existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'Download (Mbps)',
                        data: dataOut,
                        borderColor: '#16A34A',
                        backgroundColor: 'rgba(22,163,74,0.15)',
                        borderWidth: 2, tension: 0.25, fill: true, pointRadius: 0,
                    },
                    {
                        label: 'Upload (Mbps)',
                        data: dataIn,
                        borderColor: '#0F2742',
                        backgroundColor: 'rgba(15,39,66,0.10)',
                        borderWidth: 2, tension: 0.25, fill: true, pointRadius: 0,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                scales: {
                    x: { type: 'time', time: { tooltipFormat: 'MMM d HH:mm:ss' }, ticks: { maxRotation: 0 } },
                    y: { beginAtZero: true, title: { display: true, text: 'Mbps' } },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: {
                        label: function (ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' Mbps'; },
                    } },
                },
            },
        });
    }

    function setStatus(text, cls) {
        var el = document.getElementById('g-status');
        el.className = cls || 'text-muted';
        el.textContent = text;
    }

    function updateLive(live) {
        if (!live) {
            document.getElementById('g-rate-in').textContent  = '0 bps';
            document.getElementById('g-rate-out').textContent = '0 bps';
            return;
        }
        document.getElementById('g-rate-in').textContent  = fmtRate(live.rateIn);
        document.getElementById('g-rate-out').textContent = fmtRate(live.rateOut);
        document.getElementById('g-bytes-in').textContent  = fmtBytes(live.bytesIn);
        document.getElementById('g-bytes-out').textContent = fmtBytes(live.bytesOut);
        document.getElementById('g-ip').textContent     = live.address || '—';
        document.getElementById('g-uptime').textContent = live.uptime  || '—';
        document.getElementById('g-mac').textContent    = live.callerId|| '—';
    }

    var inFlight = false;
    function showDebug(msg) {
        var d = document.getElementById('g-debug');
        if (d) { d.style.display = 'block'; d.textContent = msg; }
    }
    function showEmpty(show) {
        var e = document.getElementById('g-empty');
        if (e) e.style.display = show ? 'flex' : 'none';
    }
    var inFlight = false;
    function fetchAndRender() {
        if (typeof Chart === 'undefined') {
            setStatus('⚠ Chart.js failed to load (CDN blocked?)', 'text-danger');
            showDebug('Chart.js library is not loaded. Check the browser console for blocked CDN requests.\nURL: https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js');
            return;
        }
        if (inFlight) { return; }
        inFlight = true;
        // GRAPH_URL already contains "?_route=...", so additional params join with "&".
        // Using "?" here would produce two "?" in the URL and PHP would treat the second
        // one as part of the _route value (username) — that was the empty-graph bug.
        var url = GRAPH_URL + '&minutes=' + rangeMinutes;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (!r.ok || ct.indexOf('application/json') < 0) {
                    throw new Error('Session expired — please reload the page and log in again.');
                }
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    setStatus('⚠ ' + data.error, 'text-danger');
                    showDebug('Server-side error: ' + data.error);
                    return;
                }
                var samples = (data.samples || []).slice();
                if (data.live) samples.push(data.live);
                if (samples.length === 0) {
                    showEmpty(true);
                    setStatus('● live (no data yet)', 'text-warning');
                } else {
                    showEmpty(false);
                    try {
                        buildChart(samples);
                    } catch (err) {
                        setStatus('⚠ Chart build error: ' + err.message, 'text-danger');
                        showDebug('Chart build error: ' + err.message + '\n\nStack:\n' + (err.stack || ''));
                        return;
                    }
                    setStatus('● live  ' + new Date().toLocaleTimeString() +
                              '  (' + samples.length + ' samples)', 'text-success');
                }
                updateLive(data.live);
            })
            .catch(function (e) {
                setStatus('⚠ ' + e.message, 'text-danger');
                showDebug('Fetch error: ' + e.message);
            })
            .finally(function () {
                inFlight = false;
                setTimeout(fetchAndRender, LIVE_POLL_MS);
            });
    }

    document.querySelectorAll('.g-range').forEach(function (btn) {
        btn.addEventListener('click', function () {
            rangeMinutes = parseInt(btn.getAttribute('data-mins'), 10);
            document.querySelectorAll('.g-range').forEach(function (b) {
                b.classList.remove('btn-primary'); b.classList.add('btn-default');
            });
            btn.classList.remove('btn-default'); btn.classList.add('btn-primary');
            // Destroy properly before rebuilding so Chart.js releases the canvas.
            if (chart) { chart.destroy(); chart = null; }
            fetchAndRender();
        });
    });

    // Single polling loop — .finally always schedules the next tick, so we
    // never have two in-flight fetches racing to construct the Chart.
    fetchAndRender();
})();
{/literal}
</script>

{include file="sections/footer.tpl"}
