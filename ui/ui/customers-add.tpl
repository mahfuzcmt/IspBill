{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{$_L['Add_Contact']}</div>
            <div class="panel-body">

                <form class="form-horizontal" method="post" role="form" action="{$_url}customers/add-post">

                    <h4>Customer details</h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Username']}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="username" name="username" required
                                   placeholder="PPP login name (or phone for hotspot)">
                            <span class="help-block">This is the PPP login (will be created on Mikrotik as <code>/ppp/secret name</code>).</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Full_Name']}</label>
                        <div class="col-md-6">
                            <input type="text" required class="form-control" id="fullname" name="fullname">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Password']}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" required id="password" name="password">
                            <span class="help-block">Used for both the customer's portal login and the PPP/Hotspot secret.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Confirm_Password']}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" required id="cpassword" name="cpassword">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Phone_Number']}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="phonenumber" name="phonenumber"
                                   placeholder="e.g. 017XXXXXXXX">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Email</label>
                        <div class="col-md-6">
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="optional">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">{$_L['Address']}</label>
                        <div class="col-md-6">
                            <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <h4>Service &amp; plan</h4>
                    <hr>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Plan / Package</label>
                        <div class="col-md-6">
                            <select name="plan_id" id="plan_id" class="form-control">
                                <option value="">— No plan (create customer only, no router push) —</option>
                                {foreach $plans as $p}
                                    <option value="{$p['id']}"
                                            data-router="{$p['routers']}"
                                            data-type="{$p['type']}">
                                        {$p['name_plan']} — {$p['type']} — {$p['price']}৳ / {$p['validity']}{$p['validity_unit']}
                                    </option>
                                {/foreach}
                            </select>
                            <span class="help-block">
                                Picking a plan will create a recharge record and push a matching
                                <code>/ppp/secret</code> (or <code>/ip/hotspot/user</code>) to the router.
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Expiration</label>
                        <div class="col-md-4">
                            <input type="date" name="expiration" id="expiration" class="form-control">
                            <span class="help-block">Optional — defaults to 30 days from today.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="push_to_router" id="push_to_router" value="1" checked>
                                <strong>Push to router immediately</strong> &mdash;
                                creates the PPP secret / hotspot user on the Mikrotik
                                so the customer can connect right away
                            </label>
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-6">
                            <button class="btn btn-primary waves-effect waves-light" type="submit">
                                <i class="ion ion-android-add"></i> {$_L['Save']}
                            </button>
                            Or <a href="{$_url}customers/list">{$_L['Cancel']}</a>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
