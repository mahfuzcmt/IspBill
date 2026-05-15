{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">Edit Billing — {$c['username']}</div>
            <div class="panel-body">

                <div class="row">
                    <div class="col-md-6">
                        <dl class="dl-horizontal">
                            <dt>Customer</dt><dd>{$c['fullname']} ({$c['username']})</dd>
                            <dt>Phone</dt><dd>{$c['phonenumber']|default:'—'}</dd>
                            <dt>Email</dt><dd>{$c['email']|default:'—'}</dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <dl class="dl-horizontal">
                            {if $r}
                                <dt>Current Plan</dt><dd>{$r['namebp']} <small class="text-muted">({$r['type']})</small></dd>
                                <dt>Recharged On</dt><dd>{$r['recharged_on']}</dd>
                                <dt>Expiration</dt><dd>{$r['expiration']}</dd>
                                <dt>Status</dt>
                                <dd>
                                    {if $r['status'] eq 'on'}<span class="label label-success">Active</span>
                                    {else}<span class="label label-default">Suspended</span>{/if}
                                </dd>
                                <dt>Router</dt><dd>{$r['routers']}</dd>
                            {else}
                                <dt>Current Plan</dt><dd><span class="text-muted">No recharge yet — this customer is Pending</span></dd>
                            {/if}
                        </dl>
                    </div>
                </div>

                <hr>

                <form method="POST" action="{$_url}customers/billing-save" class="form-horizontal">
                    <input type="hidden" name="customer_id" value="{$c['id']}">

                    <div class="form-group">
                        <label class="col-md-3 control-label">Plan / Package</label>
                        <div class="col-md-6">
                            <select name="plan_id" class="form-control" required>
                                {foreach $plans as $p}
                                    <option value="{$p['id']}"
                                        {if $r && $p['id'] eq $r['plan_id']}selected{/if}>
                                        {$p['name_plan']} — {$p['type']} — {$p['price']}৳ / {$p['validity']}{$p['validity_unit']}
                                    </option>
                                {/foreach}
                            </select>
                            <span class="help-block">Changing this calls Mikrotik to swap the user's PPP profile immediately.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Expiration Date</label>
                        <div class="col-md-4">
                            <input type="date" name="expiration" class="form-control"
                                   value="{if $r}{$r['expiration']}{else}{$smarty.now|date_format:"%Y-%m-%d"}{/if}"
                                   required>
                            <span class="help-block">Auto-suspend runs hourly: if this date passes, user is disabled on the router.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Status</label>
                        <div class="col-md-4">
                            <select name="status" class="form-control">
                                <option value="on"  {if !$r || $r['status'] eq 'on'}selected{/if}>Active</option>
                                <option value="off" {if $r && $r['status'] eq 'off'}selected{/if}>Suspended</option>
                            </select>
                            <span class="help-block">Suspended → disable PPP secret + kick active session on Mikrotik.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <button type="submit" class="btn btn-primary">
                                <i class="ion ion-checkmark"></i> Save Billing
                            </button>
                            <a href="{$_url}customers/list" class="btn btn-default">Cancel</a>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
