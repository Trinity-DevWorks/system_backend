<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentNumber }}</title>
    {{-- 80mm thermal roll: one narrow column, no fixed-position elements. --}}
    <style>
        * { box-sizing: border-box; }
        @page { margin: 10px 8px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5px;
            color: #000;
            margin: 0;
            padding: 0;
            text-align: {{ $rtl ? 'right' : 'left' }};
            direction: {{ $rtl ? 'rtl' : 'ltr' }};
        }
        .center { text-align: center; }
        .end { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .logo { max-height: 40px; max-width: 120px; margin-bottom: 4px; }
        .company { font-size: 11px; font-weight: bold; }
        .title { font-size: 10px; font-weight: bold; margin-top: 4px; letter-spacing: 0.05em; }
        .rule { border-top: 1px dashed #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .item-name { font-weight: bold; }
        .grand td { font-size: 10px; font-weight: bold; border-top: 1px solid #000; padding-top: 3px; }
        .small { font-size: 7.5px; }
        .watermark { font-size: 12px; font-weight: bold; text-align: center; margin: 4px 0; }
        .pre { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="center">
        @if (!empty($letterhead['logo_data_uri']))
            <img class="logo" src="{{ $letterhead['logo_data_uri'] }}" alt=""><br>
        @endif
        <div class="company">{{ $letterhead['company_name'] }}</div>
        @if (!empty($letterhead['address']))
            <div>{{ $letterhead['address'] }}</div>
        @endif
        @if (!empty($letterhead['phone']))
            <div>{{ $letterhead['phone'] }}</div>
        @endif
        @if (!empty($letterhead['tax_number']))
            <div>{{ $letterhead['tax_number'] }}</div>
        @endif
        <div class="title">{{ $documentTitle }}</div>
        <div>{{ $documentNumber }}</div>
    </div>

    @if (!empty($statusWatermark))
        <div class="watermark">{{ $statusWatermark }}</div>
    @endif

    <div class="rule"></div>
    <table>
        @foreach ($meta as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="end">{{ $row['value'] }}</td>
            </tr>
        @endforeach
        @foreach ($parties as $party)
            <tr>
                <td>{{ $party['title'] }}</td>
                <td class="end">{{ $party['name'] }}</td>
            </tr>
        @endforeach
    </table>

    @if (!empty($lines))
        <div class="rule"></div>
        <table>
            @foreach ($lines as $line)
                <tr>
                    <td colspan="2" class="item-name">{{ $line['description'] ?? ($line['code'] ?? '—') }}</td>
                </tr>
                <tr>
                    <td>
                        {{ $line['qty'] ?? '' }} {{ $line['uom'] ?? '' }}
                        @if (isset($line['unit_price'])) × {{ $line['unit_price'] }} @endif
                        @if (!empty($line['discount']) && $line['discount'] !== '—') <span class="small">(-{{ $line['discount'] }})</span> @endif
                    </td>
                    <td class="end">{{ $line['line_total'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($allocations))
        <div class="rule"></div>
        <table>
            @foreach ($allocations as $row)
                <tr>
                    <td>{{ $row['document_number'] ?? '—' }}</td>
                    <td class="end">{{ $row['amount'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($totals))
        <div class="rule"></div>
        <table>
            @foreach ($totals as $row)
                <tr @class(['grand' => !empty($row['emphasis'])])>
                    <td>{{ $row['label'] }}</td>
                    <td class="end">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($notes))
        <div class="rule"></div>
        <div class="pre">{{ $notes }}</div>
    @endif
    @if (!empty($termsText))
        <div class="rule"></div>
        <div class="pre small">{{ $termsText }}</div>
    @endif

    <div class="rule"></div>
    @if (!empty($footerText))
        <div class="center pre">{{ $footerText }}</div>
    @endif
    <div class="center small">{{ $labels['generated'] }} {{ $generatedAt }}</div>
</body>
</html>
