<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1c2430;
            margin: 0;
            padding: 28px 32px 24px;
        }
        table { border-collapse: collapse; }
        .letterhead { width: 100%; margin-bottom: 0; }
        .brand { width: 62%; vertical-align: top; }
        .doc { width: 38%; vertical-align: top; text-align: right; }
        .logo { height: 58px; width: auto; margin-bottom: 8px; }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 0.01em;
            margin: 0;
        }
        .legal { color: #5c6773; margin-top: 2px; }
        .company-line { color: #3d4754; margin-top: 1px; }
        .kicker {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #16324f;
            margin: 0;
        }
        .doc-number {
            font-size: 16px;
            font-weight: bold;
            margin-top: 4px;
        }
        .meta { width: 100%; margin-top: 10px; }
        .meta td { padding: 2px 0 2px 12px; vertical-align: top; }
        .meta td.label { color: #5c6773; text-align: left; width: 46%; }
        .meta td.value { text-align: right; font-weight: bold; }
        .rule { height: 3px; background: #16324f; margin: 14px 0 16px; }
        .cards { width: 100%; margin-bottom: 16px; }
        .card {
            width: 49%;
            vertical-align: top;
            background: #f7f9fb;
            border: 1px solid #e3e8ee;
            padding: 10px 12px;
        }
        .gap { width: 2%; }
        .section-title {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #5c6773;
            margin: 0 0 6px;
        }
        .party-name { font-weight: bold; }
        .muted { color: #5c6773; }
        table.lines { width: 100%; margin-top: 4px; }
        table.lines th {
            background: #16324f;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 8px 7px;
            text-align: left;
        }
        table.lines td {
            border-bottom: 1px solid #e6ebf0;
            padding: 7px;
            vertical-align: top;
        }
        table.lines tr.alt td { background: #f7f9fb; }
        table.lines td.num, table.lines th.num { text-align: right; }
        .totals { width: 250px; margin-left: auto; margin-top: 12px; }
        .totals td { padding: 3px 8px; }
        .totals td.num { text-align: right; }
        .totals tr.grand td {
            background: #16324f;
            color: #ffffff;
            font-weight: bold;
            font-size: 12px;
            padding: 8px;
        }
        .notes {
            margin-top: 16px;
            padding: 10px 12px;
            background: #f7f9fb;
            border: 1px solid #e3e8ee;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 22px;
            padding-top: 8px;
            border-top: 1px solid #e3e8ee;
            font-size: 9px;
            color: #7b8794;
        }
        .footer table { width: 100%; }
        .footer td.right { text-align: right; }
        .proof { font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td class="brand">
                @if ($logoSrc)
                    <div><img class="logo" src="{{ $logoSrc }}" alt=""></div>
                @endif
                <div class="company-name">{{ $companyName }}</div>
                @if ($companyLegalName)
                    <div class="legal">{{ $companyLegalName }}</div>
                @endif
                @foreach ($companyLines as $line)
                    <div class="company-line">{{ $line }}</div>
                @endforeach
            </td>
            <td class="doc">
                <div class="kicker">{{ $title }}</div>
                <div class="doc-number">{{ $number }}</div>
                <table class="meta">
                    <tr>
                        <td class="label">Date</td>
                        <td class="value">{{ $invoiceDate ?? '—' }}</td>
                    </tr>
                    @if ($dueOn)
                        <tr>
                            <td class="label">Due</td>
                            <td class="value">{{ $dueOn }}</td>
                        </tr>
                    @endif
                    @if ($currencyCode)
                        <tr>
                            <td class="label">Currency</td>
                            <td class="value">{{ $currencyCode }}</td>
                        </tr>
                    @endif
                    @if ($paymentTerms)
                        <tr>
                            <td class="label">Terms</td>
                            <td class="value">{{ $paymentTerms }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <table class="cards">
        <tr>
            <td class="card">
                <div class="section-title">Supplier</div>
                @forelse ($supplierLines as $line)
                    <div class="{{ $loop->first ? 'party-name' : '' }}">{{ $line }}</div>
                @empty
                    <div class="muted">—</div>
                @endforelse
            </td>
            <td class="gap"></td>
            <td class="card">
                <div class="section-title">Buyer</div>
                @forelse ($buyerLines as $line)
                    <div class="{{ $loop->first ? 'party-name' : '' }}">{{ $line }}</div>
                @empty
                    <div class="muted">—</div>
                @endforelse
            </td>
        </tr>
    </table>

    @if (count($billingLines) > 0 || count($shippingLines) > 0)
        <table class="cards">
            <tr>
                <td class="card">
                    <div class="section-title">Billing</div>
                    @forelse ($billingLines as $line)
                        <div>{{ $line }}</div>
                    @empty
                        <div class="muted">—</div>
                    @endforelse
                </td>
                <td class="gap"></td>
                <td class="card">
                    <div class="section-title">Shipping</div>
                    @forelse ($shippingLines as $line)
                        <div>{{ $line }}</div>
                    @empty
                        <div class="muted">—</div>
                    @endforelse
                </td>
            </tr>
        </table>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th class="num">Qty</th>
                <th>UOM</th>
                <th class="num">Unit price</th>
                <th class="num">Tax</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr class="{{ $loop->iteration % 2 === 0 ? 'alt' : '' }}">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $line['item'] }}</td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td>{{ $line['uom'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['tax'] }}</td>
                    <td class="num">{{ $line['line_total'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="muted">No lines</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $subtotal ?? '—' }}</td></tr>
        <tr><td>Discount</td><td class="num">{{ $discountTotal ?? '—' }}</td></tr>
        <tr><td>Tax</td><td class="num">{{ $taxTotal ?? '—' }}</td></tr>
        <tr><td>Adjustment</td><td class="num">{{ $adjustment ?? '—' }}</td></tr>
        <tr class="grand">
            <td>Grand total</td>
            <td class="num">{{ trim(($grandTotal ?? '—').($currencyCode ? ' '.$currencyCode : '')) }}</td>
        </tr>
    </table>

    @if ($notes)
        <div class="section-title" style="margin-top: 16px;">Notes</div>
        <div class="notes">{{ $notes }}</div>
    @endif

    <div class="footer">
        <table>
            <tr>
                <td class="proof">@if ($proofId) Proof {{ $proofId }} @endif</td>
                <td class="right">Generated {{ $generatedAt }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
