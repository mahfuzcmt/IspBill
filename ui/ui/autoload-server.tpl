<option value="">Select Routers</option>
{foreach $d as $o}
	<option value="{$o['value']}"{if !$o['enabled']} disabled{/if}>{$o['label']}</option>
{/foreach}
