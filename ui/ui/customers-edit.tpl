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
                        <label class="col-md-3 control-label">Current Password</label>
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="password" class="form-control" id="current-password"
                                       value="{$d['password']|escape:'html'}" readonly>
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default" onclick="
                                        var el = document.getElementById('current-password');
                                        el.type = el.type === 'password' ? 'text' : 'password';
                                        this.innerHTML = el.type === 'password' ? '<i class=&quot;fa fa-eye&quot;></i> Show' : '<i class=&quot;fa fa-eye-slash&quot;></i> Hide';
                                    "><i class="fa fa-eye"></i> Show</button>
                                    <button type="button" class="btn btn-default" onclick="
                                        var el = document.getElementById('current-password');
                                        var t = el.type; el.type = 'text';
                                        el.select(); document.execCommand('copy'); el.type = t;
                                        this.innerHTML = '<i class=&quot;fa fa-check&quot;></i> Copied';
                                        setTimeout(function(b){ b.innerHTML = '<i class=&quot;fa fa-copy&quot;></i> Copy'; }.bind(null, this), 1500);
                                    "><i class="fa fa-copy"></i> Copy</button>
                                </span>
                            </div>
                            <span class="help-block">
                                This is the PPP/Hotspot login password the customer uses on their router.
                                If they call asking to recover it, click <strong>Show</strong> or <strong>Copy</strong>.
                                To change it, use the New Password field below.
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">New Password</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" name="password" autocomplete="new-password"
                                   placeholder="Leave blank to keep the current password">
                            <span class="help-block">If set, this overwrites the Mikrotik PPP secret password.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Confirm New Password</label>
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

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="send_sms" value="1">
                                <strong>Send SMS to customer</strong> &mdash;
                                on status change, send the expiry / recharge SMS template. Default off.
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="credit_sale" value="1">
                                <strong>Is this a credit sale?</strong> &mdash;
                                tick on a renewal (Suspended → Active) if the customer hasn't paid yet.
                                A "due" row will be added to their account and they can be marked paid later.
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

                <hr>
                <h4>Send SMS to this customer</h4>
                <form class="form-horizontal" method="POST" action="{$_url}sms/send-customer" style="margin-top:10px">
                    <input type="hidden" name="customer_id" value="{$d['id']}">

                    <div class="form-group">
                        <label class="col-md-3 control-label">Template</label>
                        <div class="col-md-6">
                            <select name="template" class="form-control" id="sms-tpl">
                                <option value="">— Custom (use message box below) —</option>
                                <option value="sms_template_welcome">Welcome SMS</option>
                                <option value="sms_template_recharge">Recharge confirmation</option>
                                <option value="sms_template_expiry">Expiry reminder</option>
                                <option value="sms_template_voucher">Voucher delivery</option>
                            </select>
                            <span class="help-block">
                                If a template is picked, the message box is ignored and the template is rendered with this customer's data.
                                Edit templates at <a href="{$_url}sms/settings" target="_blank">SMS Settings</a>.
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Or freeform message</label>
                        <div class="col-md-6">
                            <textarea name="message" class="form-control" rows="3" placeholder="Hi {ldelim}fullname{rdelim}, your account..."></textarea>
                            <span class="help-block">Placeholders <code>{ldelim}fullname{rdelim}</code>, <code>{ldelim}plan{rdelim}</code>, <code>{ldelim}expiration{rdelim}</code> etc. are supported here too.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <button type="submit" class="btn btn-info">
                                <i class="ion ion-paper-airplane"></i> Send to {$d['phonenumber']|default:'(no phone)'}
                            </button>
                            <span class="help-block">
                                The SMS goes to <code>{$d['phonenumber']|default:'—'}</code>. Update the phone above and Save before sending if needed.
                            </span>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
