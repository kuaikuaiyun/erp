@php
    $year = now($filters[$module.'_timezone'])->year;
    $from = $filters[$module.'_date_from'];
    $to = $filters[$module.'_date_to'];
@endphp
<div class="payment-renewal-months" role="group" aria-label="{{ __('payment-renewal.quick_dates') }}">
    <span class="payment-renewal-month-label" aria-hidden="true">{{ __('payment-renewal.quick_dates') }}：</span>
    @foreach (__('payment-renewal.quick_months') as $index => $label)
        @php
            $start = sprintf('%04d-%02d-01', $year, $index + 1);
            $active = $from === $start && $to === (new \DateTimeImmutable($start))->format('Y-m-t');
        @endphp
        <button class="flt-btn" type="button" data-renewal-month="{{ $index + 1 }}" aria-pressed="{{ $active ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
</div>
