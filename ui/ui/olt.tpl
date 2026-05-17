{include file="sections/header.tpl"}

<div class="row">
    <!-- Summary Cards -->
    <div class="col-lg-3 col-md-6 col-sm-6">
        <div class="small-box bg-green">
            <div class="inner">
                <h3>{$onlineOnus}</h3>
                <p>ONUs Online</p>
            </div>
            <div class="icon"><i class="ion ion-checkmark-circled"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6">
        <div class="small-box bg-red">
            <div class="inner">
                <h3>{$offlineOnus}</h3>
                <p>ONUs Offline</p>
            </div>
            <div class="icon"><i class="ion ion-close-circled"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>{$lowSignalOnus}</h3>
                <p>Low Signal (&lt;-27 dBm)</p>
            </div>
            <div class="icon"><i class="ion ion-alert-circled"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3>{$totalOnus}</h3>
                <p>Total ONUs</p>
            </div>
            <div class="icon"><i class="ion ion-stats-bars"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <!-- OLT List -->
    <div class="col-md-6">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">
                <h3 class="panel-title">OLT Devices</h3>
                <div class="btn-group pull-right">
                    <a href="{$_url}olt/add" class="btn btn-success btn-xs">
                        <i class="ion ion-plus"></i> Add OLT
                    </a>
                </div>
            </div>
            <div class="panel-body">
                {if $olts|@count > 0}
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>IP Address</th>
                                <th>Type</th>
                                <th>Last Polled</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        {foreach $olts as $olt}
                            <tr>
                                <td><strong>{$olt.name}</strong></td>
                                <td><code>{$olt.ip_address}</code></td>
                                <td><span class="label label-info">{$olt.type|upper}</span></td>
                                <td>
                                    {if $olt.last_polled}
                                        <small class="text-muted">{$olt.last_polled}</small>
                                    {else}
                                        <span class="text-muted">Never</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $olt.enabled}
                                        <span class="label label-success">Enabled</span>
                                    {else}
                                        <span class="label label-default">Disabled</span>
                                    {/if}
                                </td>
                                <td>
                                    <a href="{$_url}olt/onus/{$olt.id}" class="btn btn-primary btn-xs" title="View ONUs">
                                        <i class="ion ion-eye"></i>
                                    </a>
                                    <a href="{$_url}olt/edit/{$olt.id}" class="btn btn-warning btn-xs" title="Edit">
                                        <i class="ion ion-edit"></i>
                                    </a>
                                    {if $olt.web_url}
                                    <a href="{$olt.web_url}" target="_blank" class="btn btn-info btn-xs" title="Open Web UI">
                                        <i class="ion ion-android-open"></i>
                                    </a>
                                    {/if}
                                    <a href="{$_url}olt/delete/{$olt.id}" class="btn btn-danger btn-xs"
                                       onclick="return confirm('Delete this OLT and all its ONU data?')" title="Delete">
                                        <i class="ion ion-trash-b"></i>
                                    </a>
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
                {else}
                <div class="text-center text-muted" style="padding: 40px;">
                    <i class="ion ion-cube" style="font-size: 48px;"></i>
                    <p>No OLTs configured yet.</p>
                    <a href="{$_url}olt/add" class="btn btn-success">
                        <i class="ion ion-plus"></i> Add Your First OLT
                    </a>
                </div>
                {/if}
            </div>
        </div>
    </div>

    <!-- Alerts Panel -->
    <div class="col-md-6">
        <div class="panel panel-danger panel-hovered">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="ion ion-alert-circled"></i> ONU Alerts
                    <span class="badge">{$alerts|@count}</span>
                </h3>
            </div>
            <div class="panel-body" style="max-height: 400px; overflow-y: auto;">
                {if $alerts|@count > 0}
                <div class="table-responsive">
                    <table class="table table-condensed table-hover">
                        <thead>
                            <tr>
                                <th>OLT</th>
                                <th>Port:ONU</th>
                                <th>Customer</th>
                                <th>Signal</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        {foreach $alerts as $a}
                            <tr class="{if $a.status != 'online'}danger{elseif $a.olt_rx_power < -27}warning{/if}">
                                <td><small>{$a.olt_name}</small></td>
                                <td><code>{$a.pon_port}:{$a.onu_id}</code></td>
                                <td>
                                    {if $a.customer_name}
                                        {$a.customer_name}
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $a.olt_rx_power}
                                        <span class="{if $a.olt_rx_power < -27}text-danger{elseif $a.olt_rx_power < -24}text-warning{else}text-success{/if}">
                                            {$a.olt_rx_power} dBm
                                        </span>
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $a.status == 'online'}
                                        <span class="label label-success">Online</span>
                                    {elseif $a.status == 'offline'}
                                        <span class="label label-danger">Offline</span>
                                    {elseif $a.status == 'power-off'}
                                        <span class="label label-default">Power Off</span>
                                    {else}
                                        <span class="label label-warning">{$a.status}</span>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
                {else}
                <div class="text-center text-muted" style="padding: 40px;">
                    <i class="ion ion-checkmark-circled text-success" style="font-size: 48px;"></i>
                    <p>All ONUs are healthy!</p>
                </div>
                {/if}
            </div>
        </div>
    </div>
</div>

<!-- Signal Level Reference -->
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h4 class="panel-title">Optical Signal Reference</h4>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="alert alert-success">
                            <strong>Excellent:</strong> -8 to -18 dBm
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-info">
                            <strong>Good:</strong> -18 to -24 dBm
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-warning">
                            <strong>Degraded:</strong> -24 to -27 dBm
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="alert alert-danger">
                            <strong>Critical:</strong> Below -27 dBm
                        </div>
                    </div>
                </div>
                <p class="text-muted small">
                    <strong>Common causes of low signal:</strong>
                    Dirty fiber connectors, fiber bend/kink, splice degradation, long distance, faulty ONU transceiver.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 30 seconds
setTimeout(function() { location.reload(); }, 30000);
</script>

{include file="sections/footer.tpl"}
