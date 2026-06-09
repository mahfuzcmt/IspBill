{include file="sections/header.tpl"}
<!-- customers-credits: cross-customer credit sales list -->

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">Credit Sales &mdash; All Customers</div>
            <div class="panel-body">

                <div class="row" style="margin-bottom:14px">
                    <div class="col-md-6">
                        <div class="btn-group" role="group">
                            <a href="{$_url}customers/credits&status=due"
                               class="btn btn-{if $statusFilter eq 'due'}warning{else}default{/if}">
                                Due only
                            </a>
                            <a href="{$_url}customers/credits&status=paid"
                               class="btn btn-{if $statusFilter eq 'paid'}success{else}default{/if}">
                                Paid only
                            </a>
                            <a href="{$_url}customers/credits&status=all"
                               class="btn btn-{if $statusFilter eq 'all'}primary{else}default{/if}">
                                All
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6 text-right">
                        <span class="label label-warning" style="font-size:14px;padding:6px 10px">
                            Outstanding due: <strong>{$_c['currency_code']} {$totalDue|number_format:0:".":","}</strong>
                        </span>
                        &nbsp;
                        <span class="label label-success" style="font-size:14px;padding:6px 10px">
                            Total paid: <strong>{$_c['currency_code']} {$totalPaid|number_format:0:".":","}</strong>
                        </span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Created</th>
                                <th>Bill Month</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Username</th>
                                <th>Package</th>
                                <th class="text-right">Amount</th>
                                <th>Status</th>
                                <th>Paid On</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $rows as $r}
                                <tr {if $r['status'] eq 'due'}class="warning"{/if}>
                                    <td>{$r['created_at']}</td>
                                    <td>{$r['bill_month']}</td>
                                    <td>
                                        <a href="{$_url}customers/billing/{$r['customer_id']}">
                                            {if $r['fullname']}{$r['fullname']}{else}<em>(deleted)</em>{/if}
                                        </a>
                                    </td>
                                    <td>{$r['phonenumber']}</td>
                                    <td><small>{$r['username']}</small></td>
                                    <td>{$r['plan_name']}</td>
                                    <td class="text-right"><strong>{$_c['currency_code']} {$r['amount']|number_format:0:".":","}</strong></td>
                                    <td>
                                        {if $r['status'] eq 'paid'}
                                            <span class="label label-success">Paid</span>
                                        {else}
                                            <span class="label label-warning">Due</span>
                                        {/if}
                                    </td>
                                    <td>{if $r['paid_at']}{$r['paid_at']}{else}&mdash;{/if}</td>
                                    <td>
                                        {if $r['status'] eq 'due'}
                                            <button type="button" class="btn btn-warning btn-xs"
                                                    onclick="editCredit({$r['id']}, '{$r['username']|escape:'javascript'}', {$r['amount']})">
                                                <i class="ion ion-edit"></i> Edit
                                            </button>
                                            <a href="{$_url}customers/credit-paid/{$r['id']}"
                                               class="btn btn-success btn-xs"
                                               onclick="return confirm('Mark this credit ({$_c['currency_code']} {$r['amount']}, {$r['plan_name']}) as paid?');">
                                                <i class="ion ion-checkmark"></i> Mark Paid
                                            </a>
                                        {else}
                                            <a href="{$_url}customers/credit-delete/{$r['id']}"
                                               class="btn btn-danger btn-xs"
                                               onclick="return confirm('Delete this settled credit ({$_c['currency_code']} {$r['amount']}, {$r['plan_name']})? This cannot be undone.');">
                                                <i class="ion ion-trash-a"></i> Delete
                                            </a>
                                        {/if}
                                    </td>
                                </tr>
                            {foreachelse}
                                <tr><td colspan="10" class="text-center text-muted" style="padding:24px">
                                    No credit sales {if $statusFilter eq 'due'}are currently due{elseif $statusFilter eq 'paid'}have been paid yet{else}exist yet{/if}.
                                </td></tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>

                <p class="text-muted" style="margin-top:10px">
                    Showing up to 500 rows. Each row's <strong>Customer</strong> name links to that customer's Billing page.
                    Use <strong>Mark Paid</strong> when you receive the money &mdash; the dashboard's Outstanding Credit card updates on next reload.
                </p>

            </div>
        </div>
    </div>
</div>

<!-- Edit Credit Modal -->
<div class="modal fade" id="editCreditModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <form method="post" action="{$_url}customers/credit-edit">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Edit Credit Amount</h4>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="credit_id" id="edit_credit_id">
                    <div class="form-group">
                        <label>Customer</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    <div class="form-group">
                        <label>Amount ({$_c['currency_code']})</label>
                        <input type="number" name="amount" id="edit_amount" class="form-control" min="0" step="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCredit(id, username, amount) {
    document.getElementById('edit_credit_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_amount').value = amount;
    $('#editCreditModal').modal('show');
}
</script>

{include file="sections/footer.tpl"}
