{include file="sections/header.tpl"}
<!-- routers-console -->

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">
                {Lang::T('Remote Console')} &mdash; {$d['name']}
                <span class="label label-{if $used_target == 'primary'}success{else}warning{/if}" style="margin-left:8px">
                    {if $used_target == 'primary'}{Lang::T('Primary')}{else}{Lang::T('Secondary')}{/if}
                </span>
            </div>
            <div class="panel-body">
                <p>
                    <a href="{$_url}routers/list" class="btn btn-default btn-xs">&laquo; {$_L['Cancel']}</a>
                    {if $used_target == 'primary' && $d['secondary_enabled']}
                        <a href="{$_url}routers/remote-login/{$d['id']}/secondary" class="btn btn-warning btn-xs">{Lang::T('Switch to Secondary')}</a>
                    {/if}
                    {if $used_target == 'secondary'}
                        <a href="{$_url}routers/remote-login/{$d['id']}/primary" class="btn btn-success btn-xs">{Lang::T('Switch to Primary')}</a>
                    {/if}
                    <a href="{$_url}routers/remote-login/{$d['id']}/{$used_target}" class="btn btn-info btn-xs">{Lang::T('Refresh')}</a>
                </p>

                {if $sectionErrors}
                    <div class="alert alert-warning">
                        <strong>{Lang::T('Some sections failed to load:')}</strong>
                        <ul style="margin:6px 0 0 18px">
                            {foreach $sectionErrors as $sec => $err}
                                <li><code>{$sec}</code>: {$err}</li>
                            {/foreach}
                        </ul>
                    </div>
                {/if}

                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-bordered table-condensed">
                            <tbody>
                                <tr><th style="width:40%">{Lang::T('Identity')}</th><td>{if $identity}{$identity}{else}<em class="text-muted">n/a</em>{/if}</td></tr>
                                <tr><th>{Lang::T('Board')}</th><td>{$resource['board-name']} ({$resource['architecture-name']})</td></tr>
                                <tr><th>{Lang::T('RouterOS')}</th><td>{$resource['version']}</td></tr>
                                <tr><th>{Lang::T('Uptime')}</th><td>{$resource['uptime']}</td></tr>
                                <tr><th>{Lang::T('CPU Load')}</th><td>{$resource['cpu-load']}%</td></tr>
                                <tr><th>{Lang::T('Memory')}</th><td>{$resource['free-memory']} / {$resource['total-memory']}</td></tr>
                                <tr><th>{Lang::T('Endpoint')}</th><td>{if $used_target == 'primary'}{$d['ip_address']}{else}{$d['secondary_ip_address']}{/if}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="panel panel-default">
                            <div class="panel-heading">{Lang::T('Active Sessions')}</div>
                            <div class="panel-body">
                                <p><strong>{Lang::T('Hotspot')}:</strong>
                                    {if $hotspotAvailable}
                                        {$activeCount.hotspot}
                                    {else}
                                        <span class="text-muted">{Lang::T('not configured on this router')}</span>
                                    {/if}
                                </p>
                                <p><strong>PPPoE / PPP:</strong> {$activeCount.pppoe}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {if $hotspotAvailable}
                    <h4>{Lang::T('Hotspot Active')}</h4>
                    <div class="table-responsive">
                        <table class="table table-condensed table-striped table-bordered">
                            <thead><tr><th>{$_L['Username']}</th><th>{$_L['IP_Address']}</th><th>{Lang::T('Uptime')}</th></tr></thead>
                            <tbody>
                                {foreach $hotspotActive as $a}
                                    <tr><td>{$a.user}</td><td>{$a.address}</td><td>{$a.uptime}</td></tr>
                                {foreachelse}
                                    <tr><td colspan="3" class="text-muted text-center">{Lang::T('No active sessions')}</td></tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {/if}

                <h4>PPPoE / PPP {Lang::T('Active')}</h4>
                <div class="table-responsive">
                    <table class="table table-condensed table-striped table-bordered">
                        <thead><tr><th>{$_L['Username']}</th><th>{$_L['IP_Address']}</th><th>Service</th><th>{Lang::T('Uptime')}</th></tr></thead>
                        <tbody>
                            {foreach $pppoeActive as $a}
                                <tr><td>{$a.name}</td><td>{$a.address}</td><td>{$a.service}</td><td>{$a.uptime}</td></tr>
                            {foreachelse}
                                <tr><td colspan="4" class="text-muted text-center">{Lang::T('No active sessions')}</td></tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
