@if ($history->isEmpty())
    <p class="text-muted portal-history-empty">{{ __('portal.history.empty') }}</p>
@else
    <div class="portal-history-compact-wrap">
        <table class="table portal-history-compact">
            <thead>
                <tr>
                    <th>{{ __('portal.history.compact_time') }}</th>
                    <th>{{ __('portal.history.compact_actor') }}</th>
                    <th>{{ __('portal.history.compact_change') }}</th>
                    <th>{{ __('portal.history.compact_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($history as $row)
                    @php
                        $isRevoked = $row['status'] === 'revoked';
                        $occurredAt = $isRevoked && $row['revoked_at'] ? $row['revoked_at'] : $row['granted_at'];
                        $actor = $isRevoked && $row['revoked_by'] ? $row['revoked_by'] : $row['granted_by'];
                        $date = $occurredAt instanceof \DateTimeInterface
                            ? \Illuminate\Support\Carbon::instance($occurredAt)
                            : \Illuminate\Support\Carbon::parse((string) $occurredAt);
                    @endphp
                    <tr>
                        <td>{{ $date->setTimezone('Asia/Shanghai')->translatedFormat(__('portal.history.compact_datetime_format')) }}</td>
                        <td>{{ $actor }}</td>
                        <td>{{ __('portal.history.change_'.$row['status'], ['department' => $row['department_name']]) }}</td>
                        <td>
                            <span class="label {{ $isRevoked ? 'label-default' : 'label-success' }}">
                                {{ __('portal.history.'.$row['status']) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif


