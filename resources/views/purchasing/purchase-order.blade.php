<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $poNumber }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
            padding: 24px 28px;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 4px;
            letter-spacing: 0.02em;
        }
        .muted { color: #666; }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 24px;
        }
        .header-left, .header-right {
            display: table-cell;
            vertical-align: top;
            width: 50%;
        }
        .header-right { text-align: right; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #444;
            margin: 0 0 6px;
        }
        .box {
            margin-bottom: 18px;
        }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.lines th,
        table.lines td {
            border: 1px solid #d9d9d9;
            padding: 7px 8px;
            text-align: left;
        }
        table.lines th {
            background: #f5f5f5;
            font-size: 11px;
            text-transform: uppercase;
        }
        table.lines td.num,
        table.lines th.num { text-align: right; }
        .total-row td {
            font-weight: bold;
            border-top: 2px solid #333;
        }
        .notes {
            margin-top: 18px;
            padding: 10px 12px;
            background: #fafafa;
            border: 1px solid #e8e8e8;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 28px;
            font-size: 10px;
            color: #888;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>Purchase Order</h1>
            <div class="muted">{{ $companyName }}</div>
        </div>
        <div class="header-right">
            <div><strong>{{ $poNumber }}</strong></div>
            <div class="muted">Order date: {{ $orderDate }}</div>
            @if ($expectedDate)
                <div class="muted">Expected: {{ $expectedDate }}</div>
            @endif
        </div>
    </div>

    <div class="header">
        <div class="header-left box">
            <div class="section-title">Supplier</div>
            <div><strong>{{ $supplierName }}</strong></div>
            @if ($supplierCode)
                <div class="muted">Code: {{ $supplierCode }}</div>
            @endif
            @if ($supplierEmail)
                <div>{{ $supplierEmail }}</div>
            @endif
            @if ($supplierPhone)
                <div>{{ $supplierPhone }}</div>
            @endif
        </div>
        <div class="header-right box">
            <div class="section-title">Deliver to</div>
            <div><strong>{{ $warehouseName }}</strong></div>
            @if ($warehouseShortcut)
                <div class="muted">{{ $warehouseShortcut }}</div>
            @endif
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>SKU</th>
                <th class="num">Qty</th>
                <th>Unit</th>
                <th class="num">Unit price</th>
                <th class="num">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['sku'] }}</td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td>{{ $line['uom'] }}</td>
                    <td class="num">{{ $line['unit_price'] ?? '—' }}</td>
                    <td class="num">{{ $line['line_total'] ?? '—' }}</td>
                </tr>
            @endforeach
            @if ($totalAmount !== null)
                <tr class="total-row">
                    <td colspan="6" class="num">Total</td>
                    <td class="num">{{ $totalAmount }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($notes)
        <div class="box">
            <div class="section-title">Notes</div>
            <div class="notes">{{ $notes }}</div>
        </div>
    @endif

    <div class="footer">Generated {{ $generatedAt }}</div>
</body>
</html>
