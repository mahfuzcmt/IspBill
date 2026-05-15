{include file="sections/header.tpl"}

					<div class="row">
						<div class="col-sm-12">
							<div class="panel panel-hovered mb20 panel-primary">
								<div class="panel-heading">{$_L['Manage_Accounts']}</div>
								<div class="panel-body">
									<div class="md-whiteframe-z1 mb20 text-center" style="padding: 15px">
										<div class="col-md-8">
											<form id="site-search" method="post" action="{$_url}customers/list/">
											<div class="input-group">
												<div class="input-group-addon">
													<span class="fa fa-search"></span>
												</div>
												<input type="text" name="username" class="form-control" placeholder="{$_L['Search_by_Username']}..."
													value="{if isset($smarty.post.username)}{$smarty.post.username|escape:'html'}{/if}">
												<select name="service_type" class="form-control" style="max-width:140px">
													<option value="PPPoE"   {if !isset($service_type) || $service_type eq 'PPPoE'}selected{/if}>PPPoE</option>
													<option value="Hotspot" {if isset($service_type)  && $service_type eq 'Hotspot'}selected{/if}>Hotspot</option>
													<option value="All"     {if isset($service_type)  && $service_type eq 'All'}selected{/if}>All types</option>
												</select>
												<div class="input-group-btn">
													<button class="btn btn-success">{$_L['Search']}</button>
												</div>
											</div>
											</form>
										</div>
										<div class="col-md-4">
											<a href="{$_url}customers/add" class="btn btn-primary btn-block waves-effect"><i class="ion ion-android-add"> </i> {$_L['Add_Contact']}</a>
												<a href="{$_url}customers/live-traffic" class="btn btn-success btn-block" style="margin-top:6px"><i class="fa fa-tachometer"></i> Live Traffic Monitor</a>
												<a href="{$_url}wan" class="btn btn-info btn-block" style="margin-top:6px"><i class="fa fa-globe"></i> WAN Dashboard</a>
												{if $routerWebUrl}<a href="{$routerWebUrl}" target="_blank" rel="noopener" class="btn btn-default btn-block" style="margin-top:6px"><i class="fa fa-external-link"></i> Open Router Web UI</a>{/if}
												{if isset($routerReachable)}{if $routerReachable}<small class="text-success">● Router status: live</small>{else}<small class="text-danger">● Router unreachable — status from DB</small>{/if}{/if}
										</div>&nbsp;
									</div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>{$_L['Username']}</th>
                                                    <th>{$_L['Full_Name']}</th>
                                                    <th>{$_L['Phone_Number']}</th>
                                                    <th>Plan</th>
                                                    <th>Expiration</th>
                                                    <th>Status</th>
                                                    <th>{$_L['Recharge']}</th>
                                                    <th>{$_L['Manage']}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            {foreach $d as $ds}
                                                <tr>
                                                    <td>{$ds['username']}</td>
                                                    <td>{$ds['fullname']}</td>
                                                    <td>{$ds['phonenumber']}</td>
                                                    <td>{if $ds['plan_name']}{$ds['plan_name']}{if $ds['service_type']} <small class="text-muted">({$ds['service_type']})</small>{/if}{else}<span class="text-muted">&mdash;</span>{/if}</td>
                                                    <td>
                                                        {if $ds['expiration']}
                                                            {$ds['expiration']}
                                                            {if $ds['days_left'] < 0}
                                                                <br><small class="text-danger">{$ds['days_left']*-1} day(s) overdue</small>
                                                            {elseif $ds['days_left'] <= 7}
                                                                <br><small class="text-warning">{$ds['days_left']} day(s) left</small>
                                                            {else}
                                                                <br><small class="text-muted">{$ds['days_left']} day(s) left</small>
                                                            {/if}
                                                        {else}
                                                            <span class="text-muted">&mdash;</span>
                                                        {/if}
                                                    </td>
                                                    <td align="center">
                                                        {if isset($liveState[$ds['username']])}
                                                            {assign var=live value=$liveState[$ds['username']]}
                                                            {if ($ds['computed_status'] == 'Suspended' || $ds['computed_status'] == 'Expired' || $live.profile == 'Suspended') && $live.active}
                                                                {* SOFT SUSPEND: PPP session is up but customer isn't paying. Traffic
                                                                   is dropped at the firewall; captive-page redirect serves notice. *}
                                                                <span class="label label-warning" title="PPP session up but customer is in a non-paying state — traffic dropped at firewall, captive page serves notice on HTTP probes.">
                                                                    {if $ds['computed_status'] == 'Expired'}Expired{else}Suspended{/if} · <strong>Online</strong>
                                                                </span>
                                                                {if $live.address}<br><small class="text-muted">{$live.address}</small>{/if}
                                                            {elseif $ds['computed_status'] == 'Suspended' || $ds['computed_status'] == 'Expired' || $live.profile == 'Suspended'}
                                                                <span class="label {if $ds['computed_status'] == 'Expired'}label-danger{else}label-default{/if}">{$ds['computed_status']}</span>
                                                                {if $live.lastLoggedOut}<br><small class="text-muted">last seen {$live.lastLoggedOut}</small>{/if}
                                                            {elseif $live.disabled}
                                                                <span class="label label-default" title="Disabled on router">Disabled</span>
                                                            {elseif $live.active}
                                                                <span class="label label-success" title="Currently connected">Online</span>
                                                                {if $live.address}<br><small class="text-muted">{$live.address}</small>{/if}
                                                            {else}
                                                                <span class="label label-warning" title="Enabled on router but not currently connected">Offline</span>
                                                                {if $live.lastLoggedOut}<br><small class="text-muted">last seen {$live.lastLoggedOut}</small>{/if}
                                                            {/if}
                                                        {else}
                                                            {* Fallback: customer not present on router *}
                                                            {if $ds['computed_status'] == 'Active'}
                                                                <span class="label label-success">Active <small>(DB)</small></span>
                                                            {elseif $ds['computed_status'] == 'Expired'}
                                                                <span class="label label-danger">Expired</span>
                                                            {elseif $ds['computed_status'] == 'Suspended'}
                                                                <span class="label label-default">Suspended</span>
                                                            {else}
                                                                <span class="label label-warning">Pending</span>
                                                            {/if}
                                                            {if isset($routerReachable) && $routerReachable}
                                                                <br><small class="text-muted">not on router</small>
                                                            {/if}
                                                        {/if}
                                                    </td>
                                                    <td align="center">
                                                        <a href="{$_url}prepaid/recharge-user/{$ds['id']}" id="{$ds['id']}" class="btn btn-primary btn-sm">{$_L['Recharge']}</a>
                                                        <a href="{$_url}customers/billing/{$ds['id']}" class="btn btn-info btn-sm" title="Edit billing / migrate plan / set status">Billing</a>
                                                        <a href="{$_url}customers/graph/{$ds['id']}" class="btn btn-success btn-sm" title="Live + historical bandwidth graph"><i class="fa fa-line-chart"></i> Graph</a>
                                                        <a href="{$_url}customers/diagnose/{$ds['id']}" class="btn btn-warning btn-sm" title="Health check / why is this customer offline?"><i class="fa fa-stethoscope"></i> Diag</a>
                                                    </td>
                                                    <td align="center">
                                                        <a href="{$_url}customers/edit/{$ds['id']}" class="btn btn-warning btn-sm">{$_L['Edit']}</a>
                                                        <a href="{$_url}customers/delete/{$ds['id']}" id="{$ds['id']}" class="btn btn-danger btn-sm" onclick="confirm('{$_L['Delete']}?')">{$_L['Delete']}</a>
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

<script>
{literal}
(function () {
    var form = document.getElementById('site-search');
    if (!form) return;
    var input  = form.querySelector('input[name="username"]');
    var select = form.querySelector('select[name="service_type"]');
    var timer = null;
    function submit() {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 350);
    }
    if (input)  input.addEventListener('input',  submit);
    if (select) select.addEventListener('change', function () { form.submit(); });
    var btn = form.querySelector('button.btn-success');
    if (btn) btn.style.display = 'none';

    // Restore focus after the live-search round-trip — the page just reloaded
    // with the search value in the input but no focus. Place caret at end so
    // the user can keep typing as if nothing happened.
    if (input && input.value) {
        input.focus();
        var len = input.value.length;
        try { input.setSelectionRange(len, len); } catch (e) {}
    }
})();
{/literal}
</script>
{include file="sections/footer.tpl"}
