<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentNumber }}</title>
    <style>
        /* DomPDF: avoid floats and CSS transforms — they create blank trailing pages. */
        * { box-sizing: border-box; }
        @page {
            margin: 28px 28px 40px 28px;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            text-align: {{ $rtl ? 'right' : 'left' }};
            direction: {{ $rtl ? 'rtl' : 'ltr' }};
        }
        .watermark {
            position: fixed;
            top: 280px;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 48px;
            color: #d0d0d0;
            letter-spacing: 0.12em;
            font-weight: bold;
            z-index: -1000;
        }
        .letterhead {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        .letterhead td {
            vertical-align: top;
            width: 50%;
            padding-bottom: 10px;
        }
        .letterhead-right { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .logo { height: 48px; max-width: 140px; margin-bottom: 6px; }
        .company-name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .muted { color: #666; }
        .doc-title {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin: 0 0 4px;
        }
        .doc-number { font-size: 13px; font-weight: bold; }
        .meta-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        .meta-label { color: #666; width: 40%; }
        .parties {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .parties td {
            vertical-align: top;
            width: 50%;
            padding-{{ $rtl ? 'left' : 'right' }}: 12px;
        }
        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #444;
            margin: 0 0 5px;
        }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.lines th,
        table.lines td {
            border: 1px solid #cfcfcf;
            padding: 6px 7px;
            text-align: {{ $rtl ? 'right' : 'left' }};
        }
        table.lines th {
            background: #f5f5f5;
            font-size: 10px;
            text-transform: uppercase;
        }
        table.lines td.num,
        table.lines th.num { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .totals-wrap {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
        }
        .totals-wrap td.spacer { width: 55%; }
        table.totals {
            width: 100%;
            border-collapse: collapse;
        }
        table.totals td {
            border: 1px solid #cfcfcf;
            padding: 5px 7px;
        }
        table.totals td.label { color: #444; }
        table.totals td.value { text-align: {{ $rtl ? 'left' : 'right' }}; font-weight: bold; }
        table.totals tr.grand td { border-top: 2px solid #333; }
        .notes {
            margin-top: 16px;
            padding: 8px 10px;
            border: 1px solid #e0e0e0;
            white-space: pre-wrap;
        }
        .signatures {
            width: 100%;
            border-collapse: collapse;
            margin-top: 28px;
        }
        .signatures td {
            width: 50%;
            vertical-align: top;
            padding-{{ $rtl ? 'left' : 'right' }}: 24px;
        }
        .signature-line {
            margin-top: 28px;
            border-top: 1px solid #333;
            width: 70%;
            padding-top: 4px;
            font-size: 10px;
            color: #555;
        }
        .footer {
            position: fixed;
            bottom: -20px;
            left: 0;
            right: 0;
            height: 24px;
            font-size: 9px;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 4px;
        }
        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }
        .footer-table td { font-size: 9px; color: #888; vertical-align: top; }
        .footer-right { text-align: {{ $rtl ? 'left' : 'right' }}; }
    </style>
</head>
<body>
    @if (!empty($statusWatermark))
        <div class="watermark">{{ $statusWatermark }}</div>
    @endif

    <table class="letterhead">
        <tr>
            <td>
                @if (!empty($letterhead['logo_data_uri']))
                    <img class="logo" src="{{ $letterhead['logo_data_uri'] }}" alt="">
                @endif
                <div class="company-name">{{ $letterhead['company_name'] }}</div>
                @if (!empty($letterhead['legal_name']))
                    <div class="muted">{{ $letterhead['legal_name'] }}</div>
                @endif
                @if (!empty($letterhead['tax_number']))
                    <div class="muted">{{ $letterhead['tax_number'] }}</div>
                @endif
                @if (!empty($letterhead['address']))
                    <div class="muted">{{ $letterhead['address'] }}</div>
                @endif
                @if (!empty($letterhead['phone']) || !empty($letterhead['email']))
                    <div class="muted">
                        {{ collect([$letterhead['phone'] ?? null, $letterhead['email'] ?? null])->filter()->implode(' · ') }}
                    </div>
                @endif
            </td>
            <td class="letterhead-right">
                <div class="doc-title">{{ $documentTitle }}</div>
                <div class="doc-number">{{ $documentNumber }}</div>
                @if (!empty($meta))
                    <table class="meta-table">
                        @foreach ($meta as $row)
                            <tr>
                                <td class="meta-label">{{ $row['label'] }}</td>
                                <td>{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td>
        </tr>
    </table>

    @include('document-pdf.partials.party')

    @yield('body')

    @include('document-pdf.partials.notes')
    @include('document-pdf.partials.signatures')

    <div class="footer">
        <table class="footer-table">
            <tr>
                <td>{{ $letterhead['company_name'] }} · {{ $labels['generated'] }} {{ $generatedAt }}</td>
                <td class="footer-right"></td>
            </tr>
        </table>
    </div>
</body>
</html>
