{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" role="form" action="{$_url}settings/service-controls-post">
    <div class="row">
        <div class="col-sm-12 col-md-10">

            <div class="panel panel-primary panel-hovered mb20">
                <div class="panel-heading">Service Controls</div>
                <div class="panel-body">

                    <div class="form-group">
                        <label class="col-md-3 control-label">Free Trial (Hotspot)</label>
                        <div class="col-md-7">
                            <label class="checkbox-inline" style="font-weight:600">
                                <input type="checkbox" name="hotspot_trial_enabled" value="1"
                                    {if $trial_enabled}checked{/if}>
                                Enable the free trial on the captive portal
                            </label>
                            <p class="help-block">
                                When off, the trial button disappears from the login page and the router
                                rejects new trial logins. This writes <code>login-by</code> on the
                                MikroTik hotspot profile, so it takes effect immediately.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Trial Duration</label>
                        <div class="col-md-7">
                            <div class="input-group" style="max-width:260px">
                                <input type="number" class="form-control" name="hotspot_trial_duration_value"
                                    min="1" step="1" value="{$trial_duration_value}" required>
                                <span class="input-group-btn">
                                    <select class="form-control" name="hotspot_trial_duration_unit"
                                        style="min-width:110px">
                                        <option value="minutes" {if $trial_duration_unit == 'minutes'}selected{/if}>minutes</option>
                                        <option value="hours" {if $trial_duration_unit == 'hours'}selected{/if}>hours</option>
                                    </select>
                                </span>
                            </div>
                            <p class="help-block">
                                How long each free trial lasts per device (per MAC), reset daily. This writes
                                <code>trial-uptime</code> on the MikroTik hotspot profile. New trials get the
                                updated limit; devices already inside their daily window keep their current
                                allotment until the next reset. Max 24 hours.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">SMS Service</label>
                        <div class="col-md-7">
                            <label class="checkbox-inline" style="font-weight:600">
                                <input type="checkbox" name="sms_enabled" value="1"
                                    {if $sms_enabled}checked{/if}>
                                Enable sending SMS
                            </label>
                            <p class="help-block">
                                Master switch. When off, <strong>no SMS is sent for any purpose</strong>
                                (OTP, expiry, payment, manual) regardless of other settings.
                                WhatsApp and Telegram are not affected.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-7">
                            <button class="btn btn-primary waves-effect waves-light" type="submit">{$_L['Save']}</button>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</form>
{include file="sections/footer.tpl"}
