@if (!empty($parties))
    <table class="parties">
        <tr>
            @foreach ($parties as $party)
                <td>
                    <div class="section-title">{{ $party['title'] }}</div>
                    <div><strong>{{ $party['name'] }}</strong></div>
                    @foreach (($party['lines'] ?? []) as $line)
                        @if ($line !== null && $line !== '')
                            <div class="muted">{{ $line }}</div>
                        @endif
                    @endforeach
                </td>
            @endforeach
        </tr>
    </table>
@endif
