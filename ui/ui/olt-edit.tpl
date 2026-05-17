{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">
                <h3 class="panel-title">Edit OLT: {$olt.name}</h3>
            </div>
            <div class="panel-body">
                <form method="post" action="{$_url}olt/edit/{$olt.id}">
                    <div class="form-group">
                        <label>OLT Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               value="{$olt.name}">
                    </div>

                    <div class="form-group">
                        <label>IP Address <span class="text-danger">*</span></label>
                        <input type="text" name="ip_address" class="form-control" required
                               value="{$olt.ip_address}">
                    </div>

                    <div class="form-group">
                        <label>OLT Type</label>
                        <select name="type" class="form-control">
                            <option value="media" {if $olt.type == 'media'}selected{/if}>Media</option>
                            <option value="vsol" {if $olt.type == 'vsol'}selected{/if}>VSOL</option>
                            <option value="bdcom" {if $olt.type == 'bdcom'}selected{/if}>BDCOM</option>
                            <option value="cdata" {if $olt.type == 'cdata'}selected{/if}>C-Data</option>
                            <option value="huawei" {if $olt.type == 'huawei'}selected{/if}>Huawei</option>
                            <option value="zte" {if $olt.type == 'zte'}selected{/if}>ZTE</option>
                            <option value="other" {if $olt.type == 'other'}selected{/if}>Other</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>SNMP Community</label>
                                <input type="text" name="snmp_community" class="form-control"
                                       value="{$olt.snmp_community|default:'public'}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>SNMP Version</label>
                                <select name="snmp_version" class="form-control">
                                    <option value="2c" {if $olt.snmp_version == '2c'}selected{/if}>v2c</option>
                                    <option value="1" {if $olt.snmp_version == '1'}selected{/if}>v1</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="enabled" value="1" {if $olt.enabled}checked{/if}>
                            Enabled
                        </label>
                    </div>

                    <hr>
                    <h5>Web Management (Optional)</h5>

                    <div class="form-group">
                        <label>Web URL</label>
                        <input type="url" name="web_url" class="form-control"
                               value="{$olt.web_url}">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Web Username</label>
                                <input type="text" name="web_user" class="form-control"
                                       value="{$olt.web_user}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Web Password</label>
                                <input type="password" name="web_pass" class="form-control"
                                       placeholder="(leave blank to keep current)">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="ion ion-checkmark"></i> Save Changes
                        </button>
                        <a href="{$_url}olt" class="btn btn-default">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
