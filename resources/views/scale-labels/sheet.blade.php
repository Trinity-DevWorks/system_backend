<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $template->name }}</title>
    {{-- A4 label sheet: a fixed grid, filled left to right, top to bottom. --}}
    <style>
        @page { margin: 0; size: A4 portrait; }
        @include('scale-labels._styles')
        .page { position: relative; width: 210mm; height: 296mm; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
    @foreach ($pages as $pageLabels)
        <div class="page">
            @foreach ($pageLabels as $i => $label)
                <div class="label" style="left: {{ $positions[$i]['left'] }}mm; top: {{ $positions[$i]['top'] }}mm;">@include('scale-labels._label', ['label' => $label])</div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
