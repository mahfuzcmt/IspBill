{include file="sections/header.tpl"}

		<div class="row">
			<div class="col-sm-12 col-md-12">
				<div class="panel panel-primary panel-hovered panel-stacked mb30">
					<div class="panel-heading">{$_L['Recharge_Account']}</div>
					<div class="panel-body">
						<form class="form-horizontal" method="post" role="form" action="{$_url}prepaid/recharge-post" >
							<div class="form-group">
								<label class="col-md-2 control-label">{$_L['Select_Account']}</label>
								<div class="col-md-6">
									<select id="personSelect" class="form-control" name="id_customer" style="width: 100%" data-placeholder="Select a customer...">
									<option></option>
										{foreach $c as $cs}
											{if $id eq $cs['id']}
												<option value="{$cs['id']}" selected>{$cs['username']}</option>
											{else}
												<option value="{$cs['id']}">{$cs['username']}</option>
											{/if}
										{/foreach}
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-md-2 control-label">{$_L['Type']}</label>
								<div class="col-md-6">
									<input type="radio" id="Hot" name="type" value="Hotspot" {if $current_type eq 'Hotspot'}checked{/if}> {$_L['Hotspot_Plans']}
									<input type="radio" id="POE" name="type" value="PPPOE" {if $current_type eq 'PPPOE' || $current_type eq 'PPPoE'}checked{/if}> {$_L['PPPOE_Plans']}
								</div>
							</div>
							<div class="form-group">
								<label class="col-md-2 control-label">{$_L['Routers']}</label>
								<div class="col-md-6">
									<select id="server" name="server" class="form-control">
										<option value=''>Select Routers</option>
										{foreach $r as $rs}
											<option value="{$rs['name']}" {if $current_router eq $rs['name']}selected{/if}>{$rs['name']}</option>
										{/foreach}
									</select>
								</div>
							</div>

							<div class="form-group">
								<label class="col-md-2 control-label">{$_L['Service_Plan']}</label>
								<div class="col-md-6">
									<select id="plan" name="plan" class="form-control">
										<option value=''>Select Plans</option>
										{foreach $p as $ps}
											<option value="{$ps['id']}" {if $current_plan eq $ps['id']}selected{/if}>{$ps['name_plan']} - {$ps['price']}</option>
										{/foreach}
									</select>
								</div>
							</div>

							<div class="form-group">
								<label class="col-md-2 control-label">Options</label>
								<div class="col-md-6">
									<label class="checkbox-inline">
										<input type="checkbox" name="credit_sale" value="1"> Credit Sale (Due)
									</label>
									<label class="checkbox-inline">
										<input type="checkbox" name="send_sms" value="1"> Send SMS
									</label>
								</div>
							</div>

							<div class="form-group">
								<div class="col-lg-offset-2 col-lg-10">
									<button class="btn btn-success waves-effect waves-light" type="submit">{$_L['Recharge']}</button>
									Or <a href="{$_url}customers/list">{$_L['Cancel']}</a>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>


{include file="sections/footer.tpl"}
