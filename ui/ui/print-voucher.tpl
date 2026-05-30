<!DOCTYPE html>
<html>
<head>
    <title>{$_title} - AHAD WiFi Zone</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <link rel="shortcut icon" type="image/x-icon" href="ui/ui/images/favicon.ico">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Roboto, -apple-system, sans-serif;
        background: #f0f2f5;
    }
    page[size="A4"] {
        background: white;
        width: 21cm;
        height: 29.7cm;
        display: block;
        margin: 0 auto;
        padding: 0.25cm;
    }
    .no-print {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 20px;
        margin-bottom: 15px;
        border-radius: 12px;
        color: white;
    }
    .controls {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }
    .controls label { font-size: 13px; }
    .controls input, .controls select {
        padding: 8px 12px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        background: rgba(255,255,255,0.9);
    }
    .controls button {
        padding: 10px 20px;
        background: rgba(255,255,255,0.2);
        color: white;
        border: 2px solid rgba(255,255,255,0.5);
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .controls button:hover { background: rgba(255,255,255,0.3); }
    .print-btn {
        background: #00b894 !important;
        border-color: #00b894 !important;
        font-weight: 600;
    }
    .info-bar {
        text-align: center;
        margin-top: 12px;
        font-size: 12px;
        opacity: 0.9;
    }

    /* Voucher Grid - 4 columns, 5 rows = 20 per page */
    .voucher-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 3px;
    }

    /* Modern Voucher Card */
    .voucher-card {
        position: relative;
        border-radius: 8px;
        padding: 8px 6px 6px;
        height: 5.4cm;
        background: linear-gradient(145deg, #ffffff 0%, #f8f9ff 100%);
        border: 1px solid #e0e5ff;
        box-shadow: 0 1px 3px rgba(102,126,234,0.1);
        overflow: hidden;
    }
    .voucher-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #00b894 100%);
    }

    /* ID Badge - top right corner */
    .id-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 18px;
        height: 18px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 6px;
        color: white;
        font-weight: bold;
    }

    /* Brand */
    .brand {
        text-align: center;
        margin-bottom: 4px;
    }
    .brand-name {
        font-size: 11px;
        font-weight: 700;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: 0.5px;
    }
    .brand-tag {
        font-size: 6px;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* QR + Code Section */
    .main-content {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-bottom: 6px;
    }
    .qr-box {
        width: 52px;
        height: 52px;
        padding: 3px;
        background: white;
        border-radius: 6px;
        border: 2px solid #667eea;
        box-shadow: 0 2px 8px rgba(102,126,234,0.2);
    }
    .qr-box img {
        width: 100%;
        height: 100%;
        display: block;
    }
    .code-box {
        text-align: center;
    }
    .code-label {
        font-size: 6px;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 2px;
    }
    .code-value {
        font-size: 14px;
        font-weight: 800;
        color: #1a1a2e;
        letter-spacing: 2px;
        font-family: 'Consolas', 'Monaco', monospace;
    }

    /* Plan & Price */
    .plan-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 5px 8px;
        background: linear-gradient(135deg, #1a1a2e 0%, #2d3436 100%);
        border-radius: 6px;
        margin-bottom: 4px;
    }
    .plan-name {
        font-size: 9px;
        color: rgba(255,255,255,0.85);
        font-weight: 500;
    }
    .plan-price {
        font-size: 12px;
        font-weight: 800;
        color: #00b894;
    }

    /* Footer */
    .card-footer {
        text-align: center;
        font-size: 6px;
        color: #aaa;
    }

    /* Print Styles */
    @media print {
        body { background: white; }
        page[size="A4"] {
            margin: 0;
            padding: 0.25cm;
            box-shadow: none;
        }
        .no-print { display: none !important; }
        .voucher-card {
            border: 1px solid #ccc;
            box-shadow: none;
        }
        .voucher-card::before {
            background: #333;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .id-badge {
            background: #333;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .plan-row {
            background: #1a1a2e;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .page-break { page-break-before: always; }
    }
    </style>
</head>
<body>
<page size="A4">
    <div class="no-print">
        <form method="post" action="{$_url}prepaid/print-voucher/">
            <div class="controls">
                <label>From ID: <input type="text" name="from_id" style="width:55px" value="{$from_id}"></label>
                <label>Limit: <input type="text" name="limit" style="width:55px" value="{$limit}"></label>
                <label>Page Break: <input type="text" name="pagebreak" style="width:55px" value="{$pagebreak}"></label>
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
                Showing {$v|@count} vouchers | Starting from ID {$v[0]['id']|default:'0'}
            </div>
        </form>
    </div>

    <div id="printable">
        <div class="voucher-grid">
            {assign var="jml" value=0}
            {foreach $v as $vs}
            {$jml = $jml + 1}
            <div class="voucher-card">
                <div class="id-badge no-print">{$vs['id']}</div>

                <div class="brand">
                    <div class="brand-name">AHAD WiFi</div>
                    <div class="brand-tag">Internet Voucher</div>
                </div>

                <div class="main-content">
                    <div class="qr-box">
                        <img src="qrcode/?data={$vs['code']}" alt="QR">
                    </div>
                    <div class="code-box">
                        <div class="code-label">Your Code</div>
                        <div class="code-value">{$vs['code']}</div>
                    </div>
                </div>

                <div class="plan-row">
                    <span class="plan-name">{$vs['name_plan']}</span>
                    <span class="plan-price">{$_c['currency_code']} {number_format($vs['price'],0)}</span>
                </div>

                <div class="card-footer">
                    {$_c['CompanyName']|default:'AHAD Network'} | ahad.net.bd
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
