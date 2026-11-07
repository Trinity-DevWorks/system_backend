@if (!empty($payments))
    <table class="lines" style="margin-top: 12px;">
        <thead>
            <tr>
                <th>{{ $labels['payments'] }}</th>
                <th class="num">{{ $labels['amount'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payments as $row)
                <tr>
                    <td>
                        {{ $row['method'] }}
                        @foreach ($row['details'] as $detail)
                            <div class="muted">{{ $detail }}</div>
                        @endforeach
                    </td>
                    <td class="num">
                        {{ $row['amount'] }}
                        @if (!empty($row['equivalent']))
                            <div class="muted">{{ $row['equivalent'] }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
