{include file="sections/header.tpl"}
<!-- routers-edit -->

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{$_L['Edit_Router']}</div>
                <div class="panel-body">
                    <form class="form-horizontal" method="post" role="form" action="{$_url}routers/edit-post" >
                        <input type="hidden" name="id" value="{$d['id']}">
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Status')}</label>
                            <div class="col-md-10">
                                <label class="radio-inline warning">
                                    <input type="radio" {if $d['enabled'] == 1}checked{/if} name="enabled" value="1"> Enable
                                </label>
                                <label class="radio-inline">
                                    <input type="radio" {if $d['enabled'] == 0}checked{/if} name="enabled" value="0"> Disable
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{$_L['Router_Name']}</label>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="name" name="name" maxlength="32" value="{$d['name']}">
                                <p class="help-block">{Lang::T('Name of Area that router operated')}</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{$_L['IP_Address']}</label>
                            <div class="col-md-6">
                                <input type="text" placeholder="192.168.88.1:8728" class="form-control" id="ip_address" name="ip_address" value="{$d['ip_address']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{$_L['Username']}</label>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="username" name="username" value="{$d['username']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{$_L['Router_Secret']}</label>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="password" name="password" value="{$d['password']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Web Login URL')}</label>
                            <div class="col-md-6">
                                <input type="text" placeholder="http://your-vps:8090/" class="form-control" id="webfig_url" name="webfig_url" value="{$d['webfig_url']}">
                                <p class="help-block">{Lang::T('Public Webfig URL for the Web Login button (leave blank to hide).')}</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{$_L['Description']}</label>
                            <div class="col-md-6">
                                <textarea class="form-control" id="description" name="description">{$d['description']}</textarea>
                                <p class="help-block">{Lang::T('Explain Coverage of router')}</p>
                            </div>
                        </div>

                        <hr>
                        <h4 style="margin-left:15px">{Lang::T('Secondary Router (Failover)')}</h4>
                        <p class="help-block" style="margin-left:15px">{Lang::T('Used automatically when the primary is unreachable, and selectable from Remote Login.')}</p>

                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Secondary Status')}</label>
                            <div class="col-md-10">
                                <label class="radio-inline">
                                    <input type="radio" {if $d['secondary_enabled'] == 1}checked{/if} name="secondary_enabled" value="1"> Enable
                                </label>
                                <label class="radio-inline">
                                    <input type="radio" {if $d['secondary_enabled'] != 1}checked{/if} name="secondary_enabled" value="0"> Disable
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Secondary IP')}</label>
                            <div class="col-md-6">
                                <input type="text" placeholder="192.168.88.2:8728" class="form-control" id="secondary_ip_address" name="secondary_ip_address" value="{$d['secondary_ip_address']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Secondary Username')}</label>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="secondary_username" name="secondary_username" value="{$d['secondary_username']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Secondary Secret')}</label>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="secondary_password" name="secondary_password" value="{$d['secondary_password']}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Secondary Web Login URL')}</label>
                            <div class="col-md-6">
                                <input type="text" placeholder="http://your-vps:8091/" class="form-control" id="secondary_webfig_url" name="secondary_webfig_url" value="{$d['secondary_webfig_url']}">
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-lg-offset-2 col-lg-10">
                                <button class="btn btn-primary waves-effect waves-light" type="submit">{$_L['Save']}</button>
                                Or <a href="{$_url}routers/list">{$_L['Cancel']}</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
