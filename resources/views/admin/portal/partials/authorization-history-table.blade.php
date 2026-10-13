@if ($history->isEmpty())
    <p class="text-muted portal-history-empty">{{ __('portal.history.empty') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-hover portal-history-table">
            <thead>
                <tr>
                    <th>{{ __('portal.history.department') }}</th>
                    <th>{{ __('portal.history.scope') }}</th>
                    <th>{{ __('portal.history.granted_at') }}</th>
                    <th>{{ __('portal.history.granted_by') }}</th>
                    <th>{{ __('portal.history.revoked_at') }}</th>
                    <th>{{ __('portal.history.revoked_by') }}</th>
                    <th>{{ __('portal.history.current_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($history as $row)
                    @php
                        $grantedAt = $row['granted_at'] instanceof \DateTimeInterface
                            ? $row['granted_at']->format('Y-m-d H:i')
                            : $row['granted_at'];
                        $revokedAt = $row['revoked_at'] instanceof \DateTimeInterface
                            ? $row['revoked_at']->format('Y-m-d H:i')
                            : $row['revoked_at'];
                    @endphp
                    <tr>
                        <td>{{ $row['department_name'] }}</td>
                        <td>{{ __('portal.history.scope_'.$row['scope']) }}</td>
                        <td>{{ $grantedAt }}</td>
                        <td>{{ $row['granted_by'] }}</td>
                        <td>{{ $revokedAt ?: __('portal.common.no_data') }}</td>
                        <td>{{ $row['revoked_by'] ?: __('portal.common.no_data') }}</td>
                        <td>
                            <span class="label {{ $row['status'] === 'active' ? 'label-success' : 'label-default' }}">
                                {{ __('portal.history.'.$row['status']) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif


