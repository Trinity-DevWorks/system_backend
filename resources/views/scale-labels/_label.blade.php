{{-- One label. $label from ScaleLabelService::labels(); $fields = enabled field keys. --}}
<div class="label-inner">
    @isset($fields['company'])
        <div class="company">{{ $company }}</div>
    @endisset
    @isset($fields['name'])
        <div class="name">{{ $label['name'] }}</div>
    @endisset
    @if (isset($fields['second_name']) && $label['second_name'] !== '')
        <div class="second">{{ $label['second_name'] }}</div>
    @endif
    @if (isset($fields['unit_price']) || isset($fields['value']))
        <table class="row"><tr>
            @isset($fields['unit_price'])
                <td>{{ $captions['unit_price'] }}: <b>{{ $label['unit_price'] }}</b>@if ($label['unit_code'] !== '') /{{ $label['unit_code'] }}@endif</td>
            @endisset
            @isset($fields['value'])
                <td class="end">{{ $captions['value'] }}: <b>{{ $label['quantity'] }}</b> {{ $label['unit_code'] }}</td>
            @endisset
        </tr></table>
    @endif
    @isset($fields['total'])
        <div class="total">{{ $captions['total'] }}: {{ $label['total'] }}</div>
    @endisset
    @if (isset($fields['packed_date']) || (isset($fields['use_by']) && $label['use_by']))
        <table class="row small"><tr>
            @isset($fields['packed_date'])
                <td>{{ $captions['packed_date'] }}: {{ $label['packed_date'] }}</td>
            @endisset
            @if (isset($fields['use_by']) && $label['use_by'])
                <td class="end">{{ $captions['use_by'] }}: <b>{{ $label['use_by'] }}</b></td>
            @endif
        </tr></table>
    @endif
    @if (isset($fields['barcode']) && $label['barcode'])
        <div class="barcode"><img src="{{ $label['barcode']['image'] }}" alt=""></div>
    @endif
    @if (isset($fields['barcode_text']) && $label['barcode'])
        @php
            // Shrink long GS1 text to the label width (DejaVu digits ≈ 0.62 em; 1 pt = 0.3528 mm),
            // and let it break before each "(AI)" when even the smallest size doesn't fit.
            $barcodeText = (string) $label['barcode']['text'];
            $baseText = max(5, $fontSize - 2);
            $fitText = ($width - 4) / max(1, mb_strlen($barcodeText) * 0.62 * 0.3528);
            $textSize = round(max(4, min($baseText, $fitText)), 1);
        @endphp
        <div class="barcode-text" style="font-size: {{ $textSize }}pt;@if ($textSize < $baseText) letter-spacing: 0;@endif">{{ preg_replace('/(?<!^)\(/', ' (', $barcodeText) }}</div>
    @endif
    @isset($fields['plu'])
        <div class="small">{{ $captions['plu'] }}: {{ $label['plu'] }}</div>
    @endisset
</div>
