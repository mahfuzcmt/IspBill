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
        font-size: 12px;
        background: #f5f5f5;
    }
    page[size="A4"] {
        background: white;
        width: 21cm;
        display: block;
        margin: 0 auto;
        padding: 1cm;
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

    /* Voucher Grid */
    .voucher-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }

    /* Voucher Card */
    .voucher-card {
        border: 2px dashed #667eea;
        border-radius: 12px;
        padding: 15px;
        background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
        page-break-inside: avoid;
    }
    .voucher-header {
        text-align: center;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        margin-bottom: 10px;
    }
    .voucher-header h3 {
        color: #1a1a2e;
        font-size: 16px;
        margin-bottom: 3px;
    }
    .voucher-header p {
        color: #667eea;
        font-size: 11px;
    }
    .voucher-body {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    .qr-section {
        flex-shrink: 0;
    }
    .qr-section img {
        width: 80px;
        height: 80px;
        border: 2px solid #667eea;
        border-radius: 8px;
        padding: 4px;
        background: white;
    }
    .info-section {
        flex-grow: 1;
    }
    .code-display {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        color: white;
        padding: 10px;
        border-radius: 8px;
        text-align: center;
        margin-bottom: 8px;
    }
    .code-label {
        font-size: 10px;
        color: rgba(255,255,255,0.7);
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .code-value {
        font-size: 18px;
        font-weight: bold;
        letter-spacing: 2px;
        margin-top: 3px;
    }
    .plan-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .plan-name {
        font-size: 12px;
        color: #333;
        font-weight: 600;
    }
    .plan-price {
        background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: bold;
        font-size: 13px;
    }
    .voucher-footer {
        text-align: center;
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px dashed #ddd;
        font-size: 10px;
        color: #999;
    }
    .voucher-id {
        position: absolute;
        top: 5px;
        right: 10px;
        font-size: 9px;
        color: #ccc;
    }

    /* Print Styles */
    @media print {
        body {
            background: white;
        }
        page[size="A4"] {
            margin: 0;
            padding: 0.5cm;
            box-shadow: none;
        }
        .no-print {
            display: none !important;
        }
        .voucher-card {
            border: 2px dashed #000;
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
                    <p>Scan QR or enter code to connect</p>
                </div>
                <div class="voucher-body">
                    <div class="qr-section">
                        <img src="qrcode/?data={$vs['code']}" alt="QR Code">
                    </div>
                    <div class="info-section">
                        <div class="code-display">
                            <div class="code-label">Voucher Code</div>
                            <div class="code-value">{$vs['code']}</div>
                        </div>
                        <div class="plan-info">
                            <span class="plan-name">{$vs['name_plan']}</span>
                            <span class="plan-price">{$_c['currency_code']} {number_format($vs['price'],0)}</span>
                        </div>
                    </div>
                </div>
                <div class="voucher-footer">
                    {$_c['CompanyName']|default:'AHAD Network'} | {$_c['phone']|default:''}
                    <span class="no-print" style="display:block;color:#aaa;font-size:8px">ID: {$vs['id']}</span>
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
