{include file="sections/header.tpl"}

<style>
  .check { padding: 14px 18px; border-radius: 8px; margin-bottom: 10px;
           border-left: 6px solid #ccc; background: #f8fafc }
  .check.ok   { border-color: #16A34A; background: #f0fdf4 }
  .check.warn { border-color: #F59E0B; background: #fffbeb }
  .check.bad  { border-color: #DC2626; background: #fef2f2 }
  .check .name { font-weight: 700; margin-right: 8px; color: #0F2742 }
  .check .badge { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; vertical-align: middle }
  .badge-ok   { background: #16A34A; color: #fff }
  .badge-warn { background: #F59E0B; color: #fff }
  .badge-bad  { background: #DC2626; color: #fff }
  .check .detail { color: #475569; font-size: 13px; margin-top: 6px }
  .checklist li { margin-bottom: 6px }
  .logline { font-family: monospace; font-size: 12px; padding: 4px 8px; border-bottom: 1px solid #eee }
  .logline .ts { color: #64748B }
  .logline .topics { color: #0F2742; font-weight: 600; margin: 0 8px }
</style>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">
                Customer Diagnostics &mdash; {$c['username']}
                <a href="{$_url}customers/edit/{$c['id']}" class="btn btn-warning btn-sm pull-right" style="margin-left:6px">Edit</a>
                <a href="{$_url}customers/graph/{$c['id']}" class="btn btn-success btn-sm pull-right">Graph</a>
            </div>
            <div class="panel-body">

                <p class="text-muted">
                    <strong>{$c['fullname']}</strong> &middot;
                    {if $c['phonenumber']}<a href="tel:{$c['phonenumber']}">{$c['phonenumber']}</a>{else}<span class="text-muted">no phone</span>{/if}
                    {if $r} &middot; Plan <strong>{$r['namebp']}</strong> ({$r['type']}) &middot; expires {$r['expiration']}{/if}
                </p>

                <h4>Automated checks</h4>
                {foreach $checks as $check}
                    <div class="check {$check.status}">
                        <span class="name">{$check.name}</span>
                        <span class="badge badge-{$check.status}">{$check.status|upper}</span>
                        &nbsp; {$check.msg}
                        {if isset($check.detail) && $check.detail}
                            <div class="detail">{$check.detail}</div>
                        {/if}
                    </div>
                {/foreach}

                {if $queue}
                <h4 style="margin-top:25px">Right-now bandwidth</h4>
                <div class="row">
                    <div class="col-md-3"><strong>Download rate</strong><br>
                        <span style="font-size:1.3em">{($queue.rateIn * 8 / 1000000)|string_format:"%.2f"} Mbps</span>
                    </div>
                    <div class="col-md-3"><strong>Upload rate</strong><br>
                        <span style="font-size:1.3em">{($queue.rateOut * 8 / 1000000)|string_format:"%.2f"} Mbps</span>
                    </div>
                    <div class="col-md-3"><strong>Session ↓</strong><br>
                        {($queue.bytesIn / 1048576)|string_format:"%.1f"} MB
                    </div>
                    <div class="col-md-3"><strong>Session ↑</strong><br>
                        {($queue.bytesOut / 1048576)|string_format:"%.1f"} MB
                    </div>
                </div>
                {/if}

                <h4 style="margin-top:25px">Manual physical-layer checklist</h4>
                <p class="text-muted small">
                    The Mikrotik can't see optical signal, ONU power, fiber breaks, or the customer's
                    indoor router LEDs. Check these on the OLT / ONU when the automated checks above
                    look OK but the customer still reports an issue.
                </p>
                <ul class="checklist">
                    <li>📡 <strong>Optical signal (laser/RX power)</strong> on the OLT for this customer's port. Healthy range ≈ −18 to −24 dBm. Below −27 dBm = degraded; below −28 dBm = link will drop.</li>
                    <li>🔌 <strong>ONU power LED</strong> at the customer end — confirm it's solid (not blinking).</li>
                    <li>🪢 <strong>Fiber drop cable</strong> — visible bend, splice damage, water ingress at junction box.</li>
                    <li>🔁 <strong>Customer router/AP</strong> — has it been replaced/reset? Re-enter the PPP username + password (Show button on the Edit page).</li>
                    <li>🌡️ <strong>ONU overheating</strong> — many cheap ONUs throttle/disconnect above 50°C.</li>
                    <li>🆕 <strong>ONU firmware</strong> — if the ONU has been online for &gt; 6 months, a power-cycle often clears state issues.</li>
                </ul>

                {if !empty($logs)}
                <h4 style="margin-top:25px">Recent router log entries mentioning {$c['username']}</h4>
                <div style="max-height:300px; overflow-y:auto; background:#fff; border:1px solid #e5e7eb; border-radius:6px">
                    {foreach $logs as $log}
                        <div class="logline">
                            <span class="ts">{$log.time}</span>
                            <span class="topics">{$log.topics}</span>
                            {$log.message}
                        </div>
                    {/foreach}
                </div>
                {else}
                <p class="text-muted small">No matching log entries found on the router.</p>
                {/if}

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
