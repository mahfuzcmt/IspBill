{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="ion ion-cube"></i> ONUs on {$olt.name}
                    <small class="text-muted">({$olt.ip_address})</small>
                </h3>
                <div class="btn-group pull-right">
                    <a href="{$_url}olt" class="btn btn-default btn-xs">
                        <i class="ion ion-arrow-left-c"></i> Back to Dashboard
                    </a>
                    {if $olt.web_url}
                    <a href="{$olt.web_url}" target="_blank" class="btn btn-info btn-xs">
                        <i class="ion ion-android-open"></i> Open OLT Web UI
                    </a>
                    {/if}
                </div>
            </div>
            <div class="panel-body">
                {if $onus|@count > 0}
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover" id="onu-table">
                        <thead>
                            <tr>
                                <th>PON Port</th>
                                <th>ONU ID</th>
                                <th>Status</th>
                                <th>ONU RX Power</th>
                                <th>OLT RX Power</th>
                                <th>Distance</th>
                                <th>Customer</th>
                                <th>Last Seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        {foreach $onus as $onu}
                            <tr class="{if $onu.status != 'online'}danger{elseif $onu.olt_rx_power && $onu.olt_rx_power < -27}warning{/if}">
                                <td><code>{$onu.pon_port}</code></td>
                                <td><code>{$onu.onu_id}</code></td>
                                <td>
                                    {if $onu.status == 'online'}
                                        <span class="label label-success"><i class="ion ion-checkmark"></i> Online</span>
                                    {elseif $onu.status == 'offline'}
                                        <span class="label label-danger"><i class="ion ion-close"></i> Offline</span>
                                    {elseif $onu.status == 'power-off'}
                                        <span class="label label-default"><i class="ion ion-power"></i> Power Off</span>
                                    {else}
                                        <span class="label label-warning">{$onu.status}</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $onu.rx_power}
                                        <span class="{if $onu.rx_power < -27}text-danger{elseif $onu.rx_power < -24}text-warning{else}text-success{/if}">
                                            {$onu.rx_power} dBm
                                        </span>
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $onu.olt_rx_power}
                                        <span class="{if $onu.olt_rx_power < -27}text-danger{elseif $onu.olt_rx_power < -24}text-warning{else}text-success{/if}">
                                            <strong>{$onu.olt_rx_power} dBm</strong>
                                        </span>
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $onu.distance}
                                        {$onu.distance} m
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $onu.customer_name}
                                        <a href="{$_url}customers/view/{$onu.customer_id}">
                                            {$onu.customer_name}
                                        </a>
                                        <br><small class="text-muted">{$onu.customer_username}</small>
                                    {else}
                                        <button type="button" class="btn btn-xs btn-default btn-link-customer"
                                                data-onu-id="{$onu.id}" data-pon="{$onu.pon_port}" data-onuid="{$onu.onu_id}">
                                            <i class="ion ion-link"></i> Link Customer
                                        </button>
                                    {/if}
                                </td>
                                <td>
                                    {if $onu.last_seen}
                                        <small class="text-muted">{$onu.last_seen}</small>
                                    {else}
                                        <span class="text-muted">—</span>
                                    {/if}
                                </td>
                                <td>
                                    <button type="button" class="btn btn-xs btn-info btn-view-history"
                                            data-onu-id="{$onu.id}" title="View Signal History">
                                        <i class="ion ion-stats-bars"></i>
                                    </button>
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>

                <div class="row mt20">
                    <div class="col-md-12">
                        <h5>Signal Level Legend:</h5>
                        <span class="label label-success">Excellent (-8 to -18 dBm)</span>
                        <span class="label label-info">Good (-18 to -24 dBm)</span>
                        <span class="label label-warning">Degraded (-24 to -27 dBm)</span>
                        <span class="label label-danger">Critical (below -27 dBm)</span>
                    </div>
                </div>
                {else}
                <div class="text-center text-muted" style="padding: 60px;">
                    <i class="ion ion-cube" style="font-size: 64px;"></i>
                    <p>No ONUs discovered yet.</p>
                    <p class="small">ONUs will appear after the poller runs. Check that SNMP is properly configured.</p>
                </div>
                {/if}
            </div>
        </div>
    </div>
</div>

<!-- Link Customer Modal -->
<div class="modal fade" id="linkCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Link ONU to Customer</h4>
            </div>
            <div class="modal-body">
                <p>Link ONU <strong id="modal-onu-info"></strong> to a customer:</p>
                <div class="form-group">
                    <label>Select Customer</label>
                    <select id="customer-select" class="form-control">
                        <option value="">-- Select Customer --</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btn-save-link">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#onu-table').DataTable({
        pageLength: 50,
        order: [[0, 'asc'], [1, 'asc']]
    });

    // Link customer button
    var currentOnuId = null;
    $('.btn-link-customer').click(function() {
        currentOnuId = $(this).data('onu-id');
        var pon = $(this).data('pon');
        var onuid = $(this).data('onuid');
        $('#modal-onu-info').text('Port ' + pon + ':' + onuid);

        // Load customers
        $.get('{$_url}customers/list-json', function(data) {
            var sel = $('#customer-select');
            sel.empty().append('<option value="">-- Select Customer --</option>');
            if (data && data.customers) {
                data.customers.forEach(function(c) {
                    sel.append('<option value="' + c.id + '">' + c.fullname + ' (' + c.username + ')</option>');
                });
            }
        });

        $('#linkCustomerModal').modal('show');
    });

    $('#btn-save-link').click(function() {
        var customerId = $('#customer-select').val();
        $.post('{$_url}olt/link-customer', {
            onu_id: currentOnuId,
            customer_id: customerId
        }, function(data) {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        }, 'json');
    });
});
</script>

{include file="sections/footer.tpl"}
