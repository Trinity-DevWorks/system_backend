@if (!empty($allocations))
    <table class="lines" style="margin-top: 12px;">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ $labels['document'] }}</th>
                <th class="num">{{ $labels['allocated'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($allocations as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $row['document_number'] ?? '—' }}</td>
                    <td class="num">{{ $row['amount'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (!empty($totals))
    <table class="totals-wrap">
        <tr>
            @if ($rtl)
                <td style="width: 45%;">
                    <table class="totals">
                        @foreach ($totals as $row)
                            <tr @class(['grand' => !empty($row['emphasis'])])>
                                <td class="label">{{ $row['label'] }}</td>
                                <td class="value">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
                <td class="spacer"></td>
            @else
                <td class="spacer"></td>
                <td style="width: 45%;">
                    <table class="totals">
                        @foreach ($totals as $row)
                            <tr @class(['grand' => !empty($row['emphasis'])])>
                                <td class="label">{{ $row['label'] }}</td>
                                <td class="value">{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endif
        </tr>
    </table>
@endif
