{include file="sections/header.tpl"}

<style>
  .browsing-controls { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;
                       padding: 14px 16px; margin-bottom: 16px; }
  .browsing-controls label { font-weight: 600; color: #475569; font-size: 13px;
                             text-transform: uppercase; letter-spacing: .5px; }
  .browsing-controls .form-control { display: inline-block; width: auto; min-width: 160px; }
  .ip-pill { display: inline-block; padding: 4px 10px; border-radius: 4px;
             background: #E0F2FE; color: #075985; font-family: monospace; font-size: 13px;
             font-weight: 700; }
  .ip-pill.manual { background: #FEF3C7; color: #92400E; }
  .ip-pill.missing { background: #FEE2E2; color: #991B1B; }
  .domain-chips { margin: 8px 0 18px; }
  .domain-chip { display: inline-block; padding: 5px 12px; margin: 3px; border-radius: 999px;
                 background: #F1F5F9; border: 1px solid #CBD5E1;
                 font-family: monospace; font-size: 13px; color: #0F2742;
                 transition: background .15s; cursor: pointer; }
  .domain-chip:hover { background: #E2E8F0; }
  .domain-chip .count { color: #64748B; margin-left: 6px; font-weight: 600; }
  .domain-chip a { color: inherit; text-decoration: none; }
  table.log-table { width: 100%; font-size: 13px; }
  table.log-table th { background: #0F2742; color: #fff; padding: 8px 10px;
                       text-align: left; font-weight: 600; }
  table.log-table td { padding: 6px 10px; border-bottom: 1px solid #E2E8F0;
                       font-family: monospace; }
  table.log-table tr:hover td { background: #F8FAFC; }
  td.col-ts    { color: #64748B; width: 160px; white-space: nowrap; }
  td.col-type  { width: 70px; }
  td.col-type .qt { background: #EDE9FE; color: #5B21B6;
                   padding: 2px 6px; border-radius: 4px; font-size: 11px;
                   font-weight: 700; }
  td.col-type .qt-AAAA { background: #DBEAFE; color: #1E40AF; }
  td.col-type .qt-PTR  { background: #FEF3C7; color: #92400E; }
  td.col-type .qt-MX   { background: #FCE7F3; color: #9D174D; }
  td.col-type .qt-TXT  { background: #DCFCE7; color: #166534; }
  td.col-domain { font-weight: 600; color: #0F2742; word-break: break-all; }
  .empty-state { text-align: center; padding: 48px 24px; color: #94A3B8; }
  .empty-state .icon { font-size: 48px; opacity: .4; margin-bottom: 12px; }
  .notice-banner { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px;
                   font-size: 13px; }
  .notice-banner.error { background: #FEE2E2; color: #991B1B; border-left: 4px solid #DC2626; }
  .notice-banner.warn  { background: #FEF3C7; color: #92400E; border-left: 4px solid #F59E0B; }
  .notice-banner.info  { background: #DBEAFE; color: #1E40AF; border-left: 4px solid #3B82F6; }
  .legal-footer { margin-top: 30px; padding-top: 16px; border-top: 1px solid #E2E8F0;
                  color: #94A3B8; font-size: 11px; line-height: 1.5; }
</style>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered mb20">
            <div class="panel-heading">
                Browsing History &mdash; {$c['username']}
                <a href="{$_url}customers/edit/{$c['id']}"     class="btn btn-warning btn-sm pull-right" style="margin-left:6px">Edit</a>
                <a href="{$_url}customers/diagnose/{$c['id']}" class="btn btn-info    btn-sm pull-right" style="margin-left:6px">Diagnose</a>
                <a href="{$_url}customers/graph/{$c['id']}"    class="btn btn-success btn-sm pull-right">Graph</a>
            </div>
            <div class="panel-body">

                <p class="text-muted" style="margin-bottom:14px">
                    <strong>{$c['fullname']}</strong>
                    {if $c['phonenumber']} &middot; <a href="tel:{$c['phonenumber']}">{$c['phonenumber']}</a>{/if}
                    &middot; DNS queries observed by our recursive resolver. Hostnames only — no full URLs or content.
                </p>

                {* ====================================================================
                   STATUS BANNERS
                   ==================================================================== *}

                {if $resolveErr}
                    <div class="notice-banner warn">
                        <strong>Couldn't resolve customer IP:</strong> {$resolveErr|escape}
                    </div>
                {/if}
                {if $readErr}
                    <div class="notice-banner error">
                        <strong>Couldn't read log:</strong> {$readErr|escape}
                    </div>
                {/if}

                {* ====================================================================
                   CONTROLS (time window, filter, manual IP override)
                   ==================================================================== *}

                <form method="GET" action="{$_url}customers/browsing/{$c['id']}" class="browsing-controls">
                    <input type="hidden" name="_route" value="customers/browsing/{$c['id']}">

                    <label style="margin-right:6px">Window</label>
                    <select name="hours" class="form-control" onchange="this.form.submit()">
                        <option value="1"   {if $hours == 1}selected{/if}>Last 1 hour</option>
                        <option value="6"   {if $hours == 6}selected{/if}>Last 6 hours</option>
                        <option value="24"  {if $hours == 24}selected{/if}>Last 24 hours</option>
                        <option value="48"  {if $hours == 48}selected{/if}>Last 48 hours</option>
                        <option value="168" {if $hours == 168}selected{/if}>Last 7 days</option>
                    </select>

                    &nbsp;&nbsp;
                    <label style="margin-right:6px">Filter domain</label>
                    <input type="text" name="filter" value="{$filter|escape}" class="form-control"
                           placeholder="e.g. youtube" maxlength="100">

                    &nbsp;&nbsp;
                    <label style="margin-right:6px">IP override</label>
                    <input type="text" name="ip" value="{$manualIp|escape}" class="form-control"
                           placeholder="172.16.16.243" maxlength="15"
                           pattern="^(\d{literal}{1,3}{/literal}\.){literal}{3}{/literal}\d{literal}{1,3}{/literal}$">

                    <button type="submit" class="btn btn-primary" style="margin-left:8px">
                        <i class="fa fa-search"></i> Look up
                    </button>

                    <div style="margin-top:10px; color:#475569; font-size:13px">
                        Querying for IP:
                        {if $clientIp}
                            <span class="ip-pill {if $ipSource == 'manual'}manual{/if}">{$clientIp}</span>
                            {if $ipSource == 'manual'} (manual override){else} (live PPPoE session){/if}
                        {else}
                            <span class="ip-pill missing">— no IP —</span>
                        {/if}
                    </div>
                </form>

                {* ====================================================================
                   TOP DOMAINS (summary chips)
                   ==================================================================== *}

                {if $topDomains|@count > 0}
                    <h5 style="margin-top:18px; margin-bottom:8px">
                        Top {$topDomains|@count} domains in this window
                    </h5>
                    <div class="domain-chips">
                        {foreach $topDomains as $domain => $count}
                            <span class="domain-chip">
                                <a href="{$_url}customers/browsing/{$c['id']}?hours={$hours}&filter={$domain|escape:'url'}{if $manualIp}&ip={$manualIp|escape:'url'}{/if}">{$domain|escape}</a>
                                <span class="count">×{$count}</span>
                            </span>
                        {/foreach}
                    </div>
                {/if}

                {* ====================================================================
                   QUERY TABLE
                   ==================================================================== *}

                {if $rows|@count > 0}
                    <h5 style="margin-top:12px; margin-bottom:8px">
                        {$rows|@count} queries
                        {if $rows|@count == $maxRows}
                            <span class="text-muted" style="font-size:12px">(capped at {$maxRows} — narrow the window or use a filter for full results)</span>
                        {/if}
                    </h5>
                    <div style="max-height:560px; overflow-y:auto; border:1px solid #E2E8F0; border-radius:6px">
                        <table class="log-table">
                            <thead>
                                <tr><th>Time</th><th>Type</th><th>Domain</th></tr>
                            </thead>
                            <tbody>
                                {foreach $rows as $row}
                                    <tr>
                                        <td class="col-ts">{$row.ts|date_format:"%Y-%m-%d %H:%M:%S"}</td>
                                        <td class="col-type"><span class="qt qt-{$row.qtype|escape}">{$row.qtype|escape}</span></td>
                                        <td class="col-domain">{$row.qname|escape}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted" style="font-size:11px; margin-top:6px">
                        Scanned {$totalScanned} log lines.
                    </p>
                {elseif $clientIp && !$readErr}
                    <div class="empty-state">
                        <div class="icon">🔍</div>
                        No DNS queries logged for <strong>{$clientIp}</strong> in the last {$hours} hour{if $hours != 1}s{/if}.
                        <br><span style="font-size:12px">
                            Either the customer wasn't online during this window, used DoH/DoT to bypass our resolver,
                            or the Mikrotik DNS-force rule isn't catching their subnet.
                        </span>
                    </div>
                {/if}

                {* ====================================================================
                   LEGAL DISCLOSURE
                   ==================================================================== *}

                <div class="legal-footer">
                    <strong>DNS query logging is enabled under BTRC license requirements.</strong>
                    Hostnames only are recorded; full URLs, HTTPS payload, page content, search queries,
                    and DoH/DoT traffic are not captured. Logs are retained for 7 days then permanently
                    deleted via automated log rotation. Access is restricted to admin users; queries
                    are not exported or shared except as required by competent legal authority.
                </div>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
