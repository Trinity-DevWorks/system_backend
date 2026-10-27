@if (!empty($showSignatures))
    <table class="signatures">
        <tr>
            <td>
                <div class="signature-line">{{ $labels['prepared_by'] }}</div>
            </td>
            <td>
                <div class="signature-line">{{ $labels['approved_by'] }}</div>
            </td>
        </tr>
    </table>
@endif
