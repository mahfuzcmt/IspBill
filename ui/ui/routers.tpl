{include file="sections/header.tpl"}
<!-- routers -->

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">{$_L['Routers']}</div>
            <div class="panel-body">
                <div class="md-whiteframe-z1 mb20 text-center" style="padding: 15px">
                    <div class="col-md-8">

                        <form id="site-search" method="post" action="{$_url}routers/list/">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <input type="text" name="name" class="form-control"
                                    placeholder="{$_L['Search_by_Name']}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success">{$_L['Search']}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <a href="{$_url}routers/add" class="btn btn-primary btn-block waves-effect"><i
                                class="ion ion-android-add"> </i> {$_L['New_Router']}</a>
                    </div>&nbsp;
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{$_L['Router_Name']}</th>
                                <th>{Lang::T('Role')}</th>
                                <th>{$_L['IP_Address']}</th>
                                <th>{$_L['Username']}</th>
                                <th>{$_L['Description']}</th>
                                <th>{Lang::T('Status')}</th>
                                <th>{$_L['Manage']}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $rows as $r}
                                <tr {if $r['enabled'] != 1}class="danger" title="disabled" {/if}>
                                    <td>{$r['name']}</td>
                                    <td>
                                        {if $r['is_primary']}
                                            <span class="label label-primary">{Lang::T('Primary')}</span>
                                        {else}
                                            <span class="label label-warning">{Lang::T('Secondary')}</span>
                                        {/if}
                                    </td>
                                    <td>{$r['ip_address']}</td>
                                    <td>{$r['username']}</td>
                                    <td>{$r['description']}</td>
                                    <td>{if $r['enabled'] == 1}Enabled{else}Disabled{/if}</td>
                                    <td>
                                        {if $r['enabled'] == 1}
                                            <a href="{$_url}routers/remote-login/{$r['id']}/{$r['role']}" class="btn btn-success btn-xs">
                                                <i class="ion ion-log-in"></i> {Lang::T('Remote Login')}
                                            </a>
                                        {else}
                                            <button class="btn btn-success btn-xs" disabled title="{Lang::T('Endpoint disabled')}">
                                                <i class="ion ion-log-in"></i> {Lang::T('Remote Login')}
                                            </button>
                                        {/if}
                                        {if $r['webfig_url']}
                                            <a href="{$r['webfig_url']}" target="_blank" rel="noopener" class="btn btn-primary btn-xs">
                                                <i class="ion ion-android-globe"></i> {Lang::T('Web Login')}
                                            </a>
                                        {else}
                                            <button class="btn btn-primary btn-xs" disabled title="{Lang::T('Set Web URL in Edit')}">
                                                <i class="ion ion-android-globe"></i> {Lang::T('Web Login')}
                                            </button>
                                        {/if}
                                        {if $r['enabled'] == 1}
                                            <a href="{$_url}routers/import-plans/{$r['id']}/{$r['role']}" class="btn btn-warning btn-xs"
                                               onclick="return confirm('{Lang::T('Import hotspot + PPPoE profiles from this router as draft plans? Existing plans with the same name are skipped.')}');">
                                                <i class="ion ion-android-download"></i> {Lang::T('Import Plans')}
                                            </a>
                                        {/if}
                                        <a href="{$_url}routers/edit/{$r['id']}" class="btn btn-info btn-xs">{$_L['Edit']}</a>
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                {$paginator['contents']}
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}