{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">
                WAN Dashboard
                <span class="pull-right">
                    <small id="w-status" class="text-muted">loading…</small>
                </span>
            </div>
            <div class="panel-body">

                <div class="row mb20">
                    <div class="col-md-9">
                        <strong>Interface:</strong>
                        <select id="w-iface" class="form-control" style="display:inline-block; width:auto; max-width:300px; margin-left:6px">
                            {foreach $ifaces as $i}<option value="{$i.interface|escape:'html'}">{$i.interface|escape:'html'}</option>{/foreach}
                        </select>
                    </div>
                    <div class="col-md-3 text-right">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-default w-range" data-mins="60">1 h</button>
                            <button class="btn btn-primary w-range" data-mins="360">6 h</button>
                            <button class="btn btn-default w-range" data-mins="1440">24 h</button>
                            <button class="btn btn-default w-range" data-mins="10080">7 d</button>
                        </div>
                    </div>
                </div>

                <div style="height:380px; position:relative;">
                    <canvas id="w-chart"></canvas>
                </div>

                <hr>
                <div class="row">
                    <div class="col-md-3"><strong>Current ↓</strong><br>
                        <span id="w-rx" style="font-size:1.4em" class="text-success">—</span>
                    </div>
                    <div class="col-md-3"><strong>Current ↑</strong><br>
                        <span id="w-tx" style="font-size:1.4em" class="text-success">—</span>
                    </div>
                    <div class="col-md-3"><strong>Peak ↓ (range)</strong><br>
                        <span id="w-peak-rx" style="font-size:1.2em">—</span>
                    </div>
                    <div class="col-md-3"><strong>Peak ↑ (range)</strong><br>
                        <span id="w-peak-tx" style="font-size:1.2em">—</span>
                    </div>
                </div>

                {if $lastErrors}
                <hr>
                <h4>Cumulative WAN error / drop counters (latest sample)</h4>
                <div class="row">
                    <div class="col-md-3">rx-errors<br><span style="font-size:1.2em">{$lastErrors.rx_error|default:0}</span></div>
                    <div class="col-md-3">tx-errors<br><span style="font-size:1.2em">{$lastErrors.tx_error|default:0}</span></div>
                    <div class="col-md-3">rx-drops<br><span style="font-size:1.2em">{$lastErrors.rx_drop|default:0}</span></div>
                    <div class="col-md-3">tx-drops<br><span style="font-size:1.2em">{$lastErrors.tx_drop|default:0}</span></div>
                </div>
                <p class="text-muted small">
                    Errors are counted since the Mikrotik booted ({if $lastErrors.ts}sample at {$lastErrors.ts}{/if}).
                    A growing rx-error rate on the WAN often signals upstream link quality issues with your ISP.
                </p>
                {/if}

                <p class="text-muted small mt20">
                    Polled every minute; live point refreshes every 3 seconds. Retention 7 days.
                </p>
            </div>
        </div>
    </div>
</div>

<script src="ui/ui/scripts/vendor/chart.umd.min.js"></script>
<script src="ui/ui/scripts/vendor/luxon.min.js"></script>
<script src="ui/ui/scripts/vendor/chartjs-adapter-luxon.umd.min.js"></script>
<script>
var WAN_DATA_URL = '{$_url}wan/data';
{literal}
(function () {
    var POLL_MS = 1000;
    var rangeMin = 360;
    var chart, ifaceSel = document.getElementById('w-iface'), inFlight = false;

    function fmtRate(b) {
        if (!b || b < 0) return '0 bps';
        if (b < 1e3) return Math.round(b) + ' bps';
        if (b < 1e6) return (b/1e3).toFixed(1) + ' Kbps';
        if (b < 1e9) return (b/1e6).toFixed(2) + ' Mbps';
        return (b/1e9).toFixed(2) + ' Gbps';
    }
    function setStatus(t, c) { var s = document.getElementById('w-status'); s.className = c || 'text-muted'; s.textContent = t; }

    function buildChart(samples) {
        var canvas = document.getElementById('w-chart');
        var rx = samples.map(function (s) { return { x: s.ts, y: s.rxBps / 1e6 }; });
        var tx = samples.map(function (s) { return { x: s.ts, y: s.txBps / 1e6 }; });
        if (chart) {
            chart.data.datasets[0].data = rx;
            chart.data.datasets[1].data = tx;
            chart.update('none'); return;
        }
        var existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { datasets: [
                { label: 'Download (Mbps)', data: rx, borderColor: '#16A34A',
                  backgroundColor: 'rgba(22,163,74,0.15)', tension:0.25, fill:true, pointRadius:0, borderWidth:2 },
                { label: 'Upload (Mbps)',   data: tx, borderColor: '#0F2742',
                  backgroundColor: 'rgba(15,39,66,0.10)', tension:0.25, fill:true, pointRadius:0, borderWidth:2 },
            ] },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { x: { type:'time', time:{tooltipFormat:'MMM d HH:mm:ss'} }, y: { beginAtZero:true, title:{display:true,text:'Mbps'} } },
                plugins: { legend: { position:'bottom' } },
            },
        });
    }

    function poll() {
        if (inFlight) return; inFlight = true;
        var ifa = ifaceSel ? ifaceSel.value : '';
        var url = WAN_DATA_URL + '&minutes=' + rangeMin + (ifa ? '&iface=' + encodeURIComponent(ifa) : '');
        fetch(url, { credentials:'same-origin' })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (!r.ok || ct.indexOf('application/json') < 0) throw new Error('Session expired — reload.');
                return r.json();
            })
            .then(function (d) {
                if (d.error) { setStatus('⚠ ' + d.error, 'text-danger'); return; }
                var samples = (d.samples || []).slice();
                if (d.live) samples.push(d.live);
                buildChart(samples);
                var peakRx = 0, peakTx = 0;
                samples.forEach(function (s) {
                    if (s.rxBps > peakRx) peakRx = s.rxBps;
                    if (s.txBps > peakTx) peakTx = s.txBps;
                });
                if (d.live) {
                    document.getElementById('w-rx').textContent = fmtRate(d.live.rxBps);
                    document.getElementById('w-tx').textContent = fmtRate(d.live.txBps);
                }
                document.getElementById('w-peak-rx').textContent = fmtRate(peakRx);
                document.getElementById('w-peak-tx').textContent = fmtRate(peakTx);
                setStatus('● live  ' + new Date().toLocaleTimeString() + ' (' + samples.length + ' samples)', 'text-success');
            })
            .catch(function (e) { setStatus('⚠ ' + e.message, 'text-danger'); })
            .finally(function () { inFlight = false; setTimeout(poll, POLL_MS); });
    }

    document.querySelectorAll('.w-range').forEach(function (b) {
        b.addEventListener('click', function () {
            rangeMin = parseInt(b.getAttribute('data-mins'), 10);
            document.querySelectorAll('.w-range').forEach(function (x) { x.classList.remove('btn-primary'); x.classList.add('btn-default'); });
            b.classList.remove('btn-default'); b.classList.add('btn-primary');
            if (chart) { chart.destroy(); chart = null; }
            poll();
        });
    });
    if (ifaceSel) ifaceSel.addEventListener('change', function () { if (chart) { chart.destroy(); chart = null; } poll(); });

    poll();
})();
{/literal}
</script>

{include file="sections/footer.tpl"}
