{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="panel panel-primary panel-hovered">
            <div class="panel-heading">
                <h3 class="panel-title">Add New OLT</h3>
            </div>
            <div class="panel-body">
                <form method="post" action="{$_url}olt/add">
                    <div class="form-group">
                        <label>OLT Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               placeholder="e.g., Main OLT, Building-A OLT">
                    </div>

                    <div class="form-group">
                        <label>IP Address <span class="text-danger">*</span></label>
                        <input type="text" name="ip_address" class="form-control" required
                               placeholder="e.g., 192.168.100.1">
                    </div>

                    <div class="form-group">
                        <label>OLT Type</label>
                        <select name="type" class="form-control">
                            <option value="media">Media</option>
                            <option value="vsol">VSOL</option>
                            <option value="bdcom">BDCOM</option>
                            <option value="cdata">C-Data</option>
                            <option value="huawei">Huawei</option>
                            <option value="zte">ZTE</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>SNMP Community</label>
                                <input type="text" name="snmp_community" class="form-control"
                                       value="public" placeholder="public">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>SNMP Version</label>
                                <select name="snmp_version" class="form-control">
                                    <option value="2c">v2c</option>
                                    <option value="1">v1</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5>Web Management (Optional)</h5>

                    <div class="form-group">
                        <label>Web URL</label>
                        <input type="url" name="web_url" class="form-control"
                               placeholder="http://192.168.100.1">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Web Username</label>
                                <input type="text" name="web_user" class="form-control"
                                       placeholder="admin">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Web Password</label>
                                <input type="password" name="web_pass" class="form-control">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="ion ion-checkmark"></i> Add OLT
                        </button>
                        <a href="{$_url}olt" class="btn btn-default">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
