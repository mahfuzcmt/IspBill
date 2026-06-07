{include file="sections/header.tpl"}
<!-- reports-trial -->

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Free Trial Users &mdash; by Mobile Number</h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-condensed table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th class="text-center">Times Used</th>
                            <th class="text-right">Total Data</th>
                            <th class="text-right">Total Time</th>
                            <th>Last Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        {if $grouped}
                            {foreach $grouped as $g}
                                <tr>
                                    <td>{$g['name']|escape}</td>
                                    <td>{$g['phone']|escape}</td>
                                    <td class="text-center"><span class="badge">{$g['times']}</span></td>
                                    <td class="text-right">
                                        {if $g['total_bytes'] >= 1073741824}{number_format($g['total_bytes']/1073741824,2)} GB
                                        {else}{number_format($g['total_bytes']/1048576,1)} MB{/if}
                                    </td>
                                    <td class="text-right">
                                        {if $g['total_minutes'] >= 60}{floor($g['total_minutes']/60)}h {$g['total_minutes']%60}m
                                        {else}{$g['total_minutes']}m{/if}
                                    </td>
                                    <td>{date($_c['date_format'], strtotime($g['last_used']))} {date('H:i', strtotime($g['last_used']))}</td>
                                </tr>
                            {/foreach}
                        {else}
                            <tr><td colspan="6" class="text-center text-muted">No trial usage recorded yet.</td></tr>
                        {/if}
                    </tbody>
                </table>
            </div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Recent Trial Sessions</h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>MAC</th>
                            <th>Started</th>
                            <th>Ended</th>
                            <th class="text-right">Data Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        {if $sessions}
                            {foreach $sessions as $s}
                                <tr>
                                    <td>{$s['name']|escape}</td>
                                    <td>{$s['phone']|escape}</td>
                                    <td><small>{$s['mac']|escape}</small></td>
                                    <td>{date($_c['date_format'], strtotime($s['started_at']))} {date('H:i', strtotime($s['started_at']))}</td>
                                    <td>
                                        {if $s['ended_at']}{date($_c['date_format'], strtotime($s['ended_at']))} {date('H:i', strtotime($s['ended_at']))}
                                        {else}<span class="label label-success">active</span>{/if}
                                    </td>
                                    <td class="text-right">
                                        {assign var=tot value=$s['bytes_in']+$s['bytes_out']}
                                        {if $tot >= 1073741824}{number_format($tot/1073741824,2)} GB
                                        {else}{number_format($tot/1048576,1)} MB{/if}
                                    </td>
                                </tr>
                            {/foreach}
                        {else}
                            <tr><td colspan="6" class="text-center text-muted">No trial sessions yet.</td></tr>
                        {/if}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
