{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{$_L['Edit_Contact']} &mdash; {$d['username']}</div>
            <div class="panel-body">

                <form class="form-horizontal" method="post" role="form" action="{$_url}customers/edit-post">
                    <input type="hidden" name="id" value="{$d['id']}">

                    <h4>Customer details</h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Username']}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="username" value="{$d['username']}" required>
                            <span class="help-block">The PPP login name on Mikrotik. Changing this re-creates the secret on the router.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Full_Name']}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="fullname" value="{$d['fullname']}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Password']}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" name="password" autocomplete="new-password">
                            <span class="help-block">Leave blank to keep the existing password. Changes are pushed to the Mikrotik secret.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Confirm_Password']}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" name="cpassword" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Phone_Number']}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="phonenumber" value="{$d['phonenumber']}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Email</label>
                        <div class="col-md-6">
                            <input type="email" class="form-control" name="email" value="{$d['email']|default:''}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Address']}</label>
                        <div class="col-md-6">
                            <textarea name="address" class="form-control" rows="2">{$d['address']}</textarea>
                        </div>
                    </div>

                    <h4>Service &amp; plan</h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Plan / Package</label>
                        <div class="col-md-6">
                            <select name="plan_id" class="form-control">
                                <option value="">— Keep DB-only (no router change) —</option>
                                {foreach $plans as $p}
                                    <option value="{$p['id']}"
                                        {if $r && $p['id'] eq $r['plan_id']}selected{/if}>
                                        {$p['name_plan']} — {$p['type']} — {$p['price']}৳ / {$p['validity']}{$p['validity_unit']}
                                    </option>
                                {/foreach}
                            </select>
                            <span class="help-block">
                                {if $r}Current plan: <strong>{$r['namebp']}</strong> ({$r['type']}){else}<em>No plan / recharge yet</em>{/if}
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Next Billing Date</label>
                        <div class="col-md-4">
                            <input type="date" name="expiration" class="form-control"
                                   value="{if $r}{$r['expiration']}{/if}">
                            <span class="help-block">
                                {if $r}Currently expires <strong>{$r['expiration']}</strong>.{else}Empty defaults to 30 days from today when a plan is selected.{/if}
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Status</label>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="on"  {if !$r || $r['status'] eq 'on'}selected{/if}>Active</option>
                                <option value="off" {if $r && $r['status'] eq 'off'}selected{/if}>Suspended</option>
                            </select>
                            <span class="help-block">Suspended = disable PPP secret on router + kick session.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="push_to_router" value="1" checked>
                                <strong>Push changes to router</strong> &mdash;
                                applies username / password / plan / status changes to the Mikrotik immediately
                            </label>
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <button class="btn btn-primary waves-effect waves-light" type="submit">
                                <i class="ion ion-checkmark"></i> {$_L['Save']}
                            </button>
                            Or <a href="{$_url}customers/list">{$_L['Cancel']}</a>
                            {if $r}
                                <a href="{$_url}customers/graph/{$d['id']}" class="btn btn-success" style="margin-left:8px">
                                    <i class="fa fa-line-chart"></i> View Graph
                                </a>
                            {/if}
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
