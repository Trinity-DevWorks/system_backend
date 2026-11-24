<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $template->name }}</title>
    {{-- Label printer roll: the paper is one label; one page per label. --}}
    <style>
        @page { margin: 0; }
        @include('scale-labels._styles')
        .page { position: relative; width: {{ $width }}mm; height: {{ $height }}mm; page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .label { left: 0; top: 0; }
    </style>
</head>
<body>
    @foreach ($labels as $label)
        <div class="page"><div class="label">@include('scale-labels._label', ['label' => $label])</div></div>
    @endforeach
</body>
</html>
