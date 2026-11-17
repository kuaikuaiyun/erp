@php
    $requiredRectifications = $task->rectifications->where('status', 'required');
@endphp

<div class="ot-history-section" id="rectifications">
    <h4>{{ __('review_center.copy.text_190') }}</h4>
    <div class="ot-history-list">
        @forelse($task->rectifications->sortByDesc('id') as $rectification)
            <div class="ot-history-row ot-rectification-row">
                <span class="ot-badge {{ $rectification->status === 'required' ? 'red' : '' }}">
                    {{ ['required' => __('review_center.copy.text_184'), 'resubmitted' => __('review_center.copy.text_185'), 'restored' => __('review_center.copy.text_186'), 'completed' => __('review_center.copy.text_186')][$rectification->status] ?? $rectification->status }}
                </span>
                <div>
                    <b>{{ $rectification->review_channel === 'leader' ? __('review_center.copy.text_021') : __('review_center.copy.text_187') }}{{ __('review_center.copy.text_191') }} {{ $rectification->rejection_scope === 'asset' ? __('review_center.copy.text_188') : __('review_center.copy.text_189') }}</b>
                    <p>{{ $rectification->rejection_opinion }}</p>
                    <small>{{ __('review_center.copy.text_192') }}{{ $rectification->reviewer_name }} {{ __('review_center.copy.text_193') }}{{ $rectification->target_version }}</small>
                </div>
                
            </div>
        @empty
            <div class="ot-history-empty">{{ __('review_center.copy.text_194') }}</div>
        @endforelse
    </div>
</div>

