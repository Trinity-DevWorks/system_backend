<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            margin: 0;
            padding: 20px 24px;
            text-align: {{ $arabic ? 'right' : 'left' }};
        }
        h1 {
            font-size: 18px;
            margin: 0 0 4px;
        }
        .muted { color: #666; }
        .company {
            font-size: 12px;
            margin-bottom: 12px;
        }
        .filters {
            margin: 0 0 14px;
            padding: 0;
            list-style: none;
        }
        .filters li { margin: 0 0 2px; }
        table.lines {
            width: 100%;
            border-collapse: collapse;
        }
        table.lines th,
        table.lines td {
            border: 1px solid #d9d9d9;
            padding: 6px 7px;
            text-align: {{ $arabic ? 'right' : 'left' }};
        }
        table.lines th {
            background: #f5f5f5;
            font-size: 10px;
        }
        table.summary {
            width: 48%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.summary th,
        table.summary td {
            border: 1px solid #d9d9d9;
            padding: 5px 7px;
            text-align: {{ $arabic ? 'right' : 'left' }};
        }
        table.summary th { background: #f5f5f5; }
        .summaries {
            margin-top: 16px;
        }
        .summary-block {
            display: inline-block;
            vertical-align: top;
            width: 48%;
            margin: 0 1% 12px 0;
        }
        .footer {
            margin-top: 22px;
            font-size: 10px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="company">{{ $companyName }}</div>
    <h1>{{ $document->title }}</h1>
    @if ($document->filters !== [])
        <ul class="filters">
            @foreach ($document->filters as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    <table class="lines">
        <thead>
            <tr>
                @foreach ($document->headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($document->rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($document->headings)) }}">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($document->summaries !== [])
        <div class="summaries">
            @foreach ($document->summaries as $block)
                <div class="summary-block">
                    <table class="summary">
                        <thead>
                            <tr>
                                <th colspan="2">{{ $block['heading'] }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($block['lines'] as $line)
                                <tr>
                                    @if ($arabic)
                                        <td>{{ $line['value'] }}</td>
                                        <td>{{ $line['label'] }}</td>
                                    @else
                                        <td>{{ $line['label'] }}</td>
                                        <td>{{ $line['value'] }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif

    <div class="footer">{{ $generatedAt }}</div>
</body>
</html>
