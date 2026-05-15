{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-7">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">SMS Settings &amp; Templates</div>
            <div class="panel-body">

                <form class="form-horizontal" method="POST" action="{$_url}sms/save-settings">

                    <h4>API connection</h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Enabled</label>
                        <div class="col-md-9">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="sms_enabled" value="1"
                                       {if $settings['sms_enabled'] eq '1'}checked{/if}>
                                Send SMS (uncheck to disable globally without losing settings)
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">API URL</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="sms_api_url"
                                   value="{$settings['sms_api_url']|escape:'html'}">
                            <span class="help-block">Default: <code>https://bulksmsbd.net/api/smsapi</code></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">API Key</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="sms_api_key"
                                   value="{$settings['sms_api_key']|escape:'html'}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Sender ID</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="sms_sender_id"
                                   value="{$settings['sms_sender_id']|escape:'html'}">
                            <span class="help-block">Approved sender ID/masking from your SMS provider.</span>
                        </div>
                    </div>

                    <h4>Templates <small>placeholders: <code>{ldelim}fullname{rdelim}</code>, <code>{ldelim}username{rdelim}</code>, <code>{ldelim}password{rdelim}</code>, <code>{ldelim}plan{rdelim}</code>, <code>{ldelim}price{rdelim}</code>, <code>{ldelim}validity{rdelim}</code>, <code>{ldelim}expiration{rdelim}</code>, <code>{ldelim}company{rdelim}</code>, <code>{ldelim}code{rdelim}</code> (vouchers)</small></h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Welcome SMS</label>
                        <div class="col-md-9">
                            <textarea name="sms_template_welcome" class="form-control" rows="4">{$settings['sms_template_welcome']|escape:'html'}</textarea>
                            <span class="help-block">Sent when a new customer is created via Customers → Add.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">Recharge SMS</label>
                        <div class="col-md-9">
                            <textarea name="sms_template_recharge" class="form-control" rows="3">{$settings['sms_template_recharge']|escape:'html'}</textarea>
                            <span class="help-block">Sent on successful recharge.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">Expiry Reminder</label>
                        <div class="col-md-9">
                            <textarea name="sms_template_expiry" class="form-control" rows="3">{$settings['sms_template_expiry']|escape:'html'}</textarea>
                            <span class="help-block">Used by the daily expiry-warning cron.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">Voucher SMS</label>
                        <div class="col-md-9">
                            <textarea name="sms_template_voucher" class="form-control" rows="3">{$settings['sms_template_voucher']|escape:'html'}</textarea>
                            <span class="help-block">Used when sending a voucher code to a customer.</span>
                        </div>
                    </div>

                    <hr>
                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-9">
                            <button type="submit" class="btn btn-primary">
                                <i class="ion ion-checkmark"></i> Save settings
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="panel panel-default mb20">
            <div class="panel-heading">Send a test SMS</div>
            <div class="panel-body">
                <form method="POST" action="{$_url}sms/send-test">
                    <div class="form-group">
                        <label>Phone number (with or without 88)</label>
                        <input type="text" class="form-control" name="to" placeholder="01XXXXXXXXX" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" rows="3" required>NetPulse test message — please ignore.</textarea>
                    </div>
                    <button type="submit" class="btn btn-info btn-block">
                        <i class="ion ion-paper-airplane"></i> Send test
                    </button>
                </form>
                <p class="text-muted small" style="margin-top:10px">
                    Test sends bypass templates and go directly to the gateway.
                    Useful for verifying credentials work.
                </p>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Template placeholders</div>
            <div class="panel-body small">
                <p>You can use any of these in template bodies. They get replaced when the SMS is rendered.</p>
                <table class="table table-condensed">
                    <tr><td><code>{ldelim}company{rdelim}</code></td><td>Brand / company name</td></tr>
                    <tr><td><code>{ldelim}fullname{rdelim}</code></td><td>Customer full name</td></tr>
                    <tr><td><code>{ldelim}username{rdelim}</code></td><td>PPP/Hotspot username</td></tr>
                    <tr><td><code>{ldelim}password{rdelim}</code></td><td>Plaintext password</td></tr>
                    <tr><td><code>{ldelim}phonenumber{rdelim}</code></td><td>Customer phone</td></tr>
                    <tr><td><code>{ldelim}plan{rdelim}</code></td><td>Plan name (e.g. 75Mbps)</td></tr>
                    <tr><td><code>{ldelim}price{rdelim}</code></td><td>Plan price (BDT)</td></tr>
                    <tr><td><code>{ldelim}validity{rdelim}</code></td><td>Plan validity (e.g. 30 Days)</td></tr>
                    <tr><td><code>{ldelim}expiration{rdelim}</code></td><td>Recharge expiration date</td></tr>
                    <tr><td><code>{ldelim}code{rdelim}</code></td><td>Voucher code (voucher SMS only)</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
