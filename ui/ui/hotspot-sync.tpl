{include file="sections/header.tpl"}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary">
            <div class="panel-heading">
                <h3 class="panel-title">Hotspot Auto Sync</h3>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="panel panel-info">
                            <div class="panel-heading">
                                <h4 class="panel-title">Sync Information</h4>
                            </div>
                            <div class="panel-body">
                                <p><strong>Login Format:</strong></p>
                                <ul>
                                    <li>Username: Mobile Number (e.g., 01975585960)</li>
                                    <li>Password: Last 6 digits (e.g., 585960)</li>
                                </ul>
                                <p><strong>Router:</strong> {if $router}{$router['name']} ({$router['ip_address']}){else}No router configured{/if}</p>
                                <p><strong>Total Customers:</strong> {$customers|@count}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="panel panel-warning">
                            <div class="panel-heading">
                                <h4 class="panel-title">Sync Actions</h4>
                            </div>
                            <div class="panel-body">
                                <p>Sync all existing customers to Mikrotik Hotspot with phone number as username and last 6 digits as password.</p>
                                <a href="{$_url}hotspot-sync/sync-all" class="btn btn-success btn-lg" onclick="return confirm('Sync all customers to Hotspot?')">
                                    <i class="fa fa-refresh"></i> Sync All Customers
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">Recent Customers (Last 100)</h4>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Phone Number</th>
                                    <th>Hotspot Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach $customers as $c}
                                <tr>
                                    <td>{$c['id']}</td>
                                    <td>{$c['username']}</td>
                                    <td>{$c['fullname']}</td>
                                    <td>{$c['phonenumber']}</td>
                                    <td>
                                        {assign var="phone" value=$c['phonenumber']|default:$c['username']}
                                        {if preg_match('/^01\d{9}$/', $phone)}
                                            <span class="label label-success">{$phone} / {$phone|substr:-6}</span>
                                        {else}
                                            <span class="label label-warning">Invalid phone format</span>
                                        {/if}
                                    </td>
                                    <td>
                                        <a href="{$_url}hotspot-sync/sync-one/{$c['id']}" class="btn btn-xs btn-primary" onclick="return confirm('Sync this customer?')">
                                            <i class="fa fa-refresh"></i> Sync
                                        </a>
                                    </td>
                                </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
{include file="sections/footer.tpl"}
