<!DOCTYPE html>
<html>
<head>
    <title>{$_title} - AHAD WiFi Zone</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <link rel="shortcut icon" type="image/x-icon" href="ui/ui/images/favicon.ico">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 10px;
        background: #f5f5f5;
    }
    page[size="A4"] {
        background: white;
        width: 21cm;
        height: 29.7cm;
        display: block;
        margin: 0 auto;
        padding: 0.3cm;
    }
    .no-print {
        background: #fff;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .controls {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }
    .controls input, .controls select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
    }
    .controls button {
        padding: 10px 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        cursor: pointer;
    }
    .controls button:hover {
        opacity: 0.9;
    }
    .print-btn {
        background: linear-gradient(135deg, #00b894 0%, #00cec9 100%) !important;
        font-size: 16px !important;
        padding: 12px 30px !important;
    }
    .info-bar {
        text-align: center;
        color: #666;
        margin-top: 10px;
    }

    /* Voucher Grid - 4 columns */
    .voucher-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 4px;
    }

    /* Compact Voucher Card */
    .voucher-card {
        border: 1.5px dashed #667eea;
        border-radius: 6px;
        padding: 6px;
        background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
        page-break-inside: avoid;
        height: 5.6cm;
    }
    .voucher-header {
        text-align: center;
        padding-bottom: 4px;
        border-bottom: 1px solid #eee;
        margin-bottom: 4px;
    }
    .voucher-header h3 {
        color: #1a1a2e;
        font-size: 10px;
        font-weight: bold;
        margin-bottom: 1px;
    }
    .voucher-header p {
        color: #667eea;
        font-size: 7px;
    }
    .voucher-body {
        text-align: center;
    }
    .qr-section {
        margin-bottom: 4px;
    }
    .qr-section img {
        width: 50px;
        height: 50px;
        border: 1px solid #667eea;
        border-radius: 4px;
        padding: 2px;
        background: white;
    }
    .code-display {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        color: white;
        padding: 4px 6px;
        border-radius: 4px;
        text-align: center;
        margin-bottom: 4px;
    }
    .code-label {
        font-size: 6px;
        color: rgba(255,255,255,0.7);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .code-value {
        font-size: 11px;
        font-weight: bold;
        letter-spacing: 1px;
        margin-top: 1px;
    }
    .plan-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    .plan-name {
        font-size: 8px;
        color: #333;
        font-weight: 600;
    }
    .plan-price {
        background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
        color: white;
        padding: 2px 6px;
        border-radius: 10px;
        font-weight: bold;
        font-size: 9px;
    }
    .voucher-footer {
        text-align: center;
        padding-top: 3px;
        border-top: 1px dashed #ddd;
        font-size: 7px;
        color: #999;
    }

    /* Print Styles */
    @media print {
        body {
            background: white;
        }
        page[size="A4"] {
            margin: 0;
            padding: 0.3cm;
            box-shadow: none;
        }
        .no-print {
            display: none !important;
        }
        .voucher-card {
            border: 1.5px dashed #000;
        }
        .page-break {
            page-break-before: always;
        }
    }
    </style>
</head>
<body>
<page size="A4">
    <div class="no-print">
        <form method="post" action="{$_url}prepaid/print-voucher/">
            <div class="controls">
                <label>From ID: <input type="text" name="from_id" style="width:60px" value="{$from_id}"></label>
                <label>Limit: <input type="text" name="limit" style="width:60px" value="{$limit}"></label>
                <label>Page Break: <input type="text" name="pagebreak" style="width:60px" value="{$pagebreak}"></label>
                <label>Plan:
                    <select name="planid">
                        <option value="0">-- All Plans --</option>
                        {foreach $plans as $plan}
                        <option value="{$plan['id']}" {if $plan['id']==$planid}selected{/if}>{$plan['name_plan']}</option>
                        {/foreach}
                    </select>
                </label>
                <button type="submit">Filter</button>
                <button type="button" id="actprint" class="print-btn">Print Vouchers</button>
            </div>
            <div class="info-bar">
                Showing {$v|@count} vouchers from {$vc} total | Starting from ID {$v[0]['id']|default:'0'}
            </div>
        </form>
    </div>

    <div id="printable">
        <div class="voucher-grid">
            {assign var="jml" value=0}
            {foreach $v as $vs}
            {$jml = $jml + 1}
            <div class="voucher-card">
                <div class="voucher-header">
                    <h3>AHAD WiFi Zone</h3>
                    <p>Scan QR or enter code</p>
                </div>
                <div class="voucher-body">
                    <div class="qr-section">
                        <img src="qrcode/?data={$vs['code']}" alt="QR">
                    </div>
                    <div class="code-display">
                        <div class="code-label">Code</div>
                        <div class="code-value">{$vs['code']}</div>
                    </div>
                    <div class="plan-info">
                        <span class="plan-name">{$vs['name_plan']}</span>
                        <span class="plan-price">{$_c['currency_code']} {number_format($vs['price'],0)}</span>
                    </div>
                </div>
                <div class="voucher-footer">
                    {$_c['CompanyName']|default:'AHAD Network'}
                    <span class="no-print" style="display:block;color:#aaa;font-size:6px">ID: {$vs['id']}</span>
                </div>
            </div>

            {if $jml == $pagebreak}
            {$jml = 0}
            </div>
            <div class="page-break"></div>
            <div class="voucher-grid">
            {/if}
            {/foreach}
        </div>
    </div>
</page>

<script src="ui/ui/scripts/jquery-1.10.2.js"></script>
<script>
jQuery(document).ready(function() {
    $("#actprint").click(function() {
        window.print();
        return false;
    });
});
</script>
</body>
</html>
