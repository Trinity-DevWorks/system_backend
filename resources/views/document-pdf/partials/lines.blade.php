@php
    $columns = $lineColumns ?? [];
    $show = static fn (string $key): bool => in_array($key, $columns, true);
@endphp
@if (!empty($lines))
    <table class="lines">
        <thead>
            <tr>
                <th>#</th>
                @if ($show('code')) <th>{{ $labels['code'] }}</th> @endif
                @if ($show('description')) <th>{{ $labels['description'] }}</th> @endif
                @if ($show('account')) <th>{{ $labels['account'] }}</th> @endif
                @if ($show('qty')) <th class="num">{{ $labels['qty'] }}</th> @endif
                @if ($show('uom')) <th>{{ $labels['uom'] }}</th> @endif
                @if ($show('unit_price')) <th class="num">{{ $labels['unit_price'] }}</th> @endif
                @if ($show('unit_cost')) <th class="num">{{ $labels['unit_cost'] }}</th> @endif
                @if ($show('discount')) <th class="num">{{ $labels['discount'] }}</th> @endif
                @if ($show('tax')) <th class="num">{{ $labels['tax'] }}</th> @endif
                @if ($show('invoiced_qty')) <th class="num">{{ $labels['invoiced_qty'] }}</th> @endif
                @if ($show('remaining_qty')) <th class="num">{{ $labels['remaining_qty'] }}</th> @endif
                @if ($show('debit')) <th class="num">{{ $labels['debit'] }}</th> @endif
                @if ($show('credit')) <th class="num">{{ $labels['credit'] }}</th> @endif
                @if ($show('line_total')) <th class="num">{{ $labels['line_total'] }}</th> @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    @if ($show('code')) <td>{{ $line['code'] ?? '—' }}</td> @endif
                    @if ($show('description')) <td>{{ $line['description'] ?? '—' }}</td> @endif
                    @if ($show('account')) <td>{{ $line['account'] ?? '—' }}</td> @endif
                    @if ($show('qty')) <td class="num">{{ $line['qty'] ?? '—' }}</td> @endif
                    @if ($show('uom')) <td>{{ $line['uom'] ?? '—' }}</td> @endif
                    @if ($show('unit_price')) <td class="num">{{ $line['unit_price'] ?? '—' }}</td> @endif
                    @if ($show('unit_cost')) <td class="num">{{ $line['unit_cost'] ?? '—' }}</td> @endif
                    @if ($show('discount')) <td class="num">{{ $line['discount'] ?? '—' }}</td> @endif
                    @if ($show('tax')) <td class="num">{{ $line['tax'] ?? '—' }}</td> @endif
                    @if ($show('invoiced_qty')) <td class="num">{{ $line['invoiced_qty'] ?? '—' }}</td> @endif
                    @if ($show('remaining_qty')) <td class="num">{{ $line['remaining_qty'] ?? '—' }}</td> @endif
                    @if ($show('debit')) <td class="num">{{ $line['debit'] ?? '—' }}</td> @endif
                    @if ($show('credit')) <td class="num">{{ $line['credit'] ?? '—' }}</td> @endif
                    @if ($show('line_total')) <td class="num">{{ $line['line_total'] ?? '—' }}</td> @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
