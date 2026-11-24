<div class="modal fade applovin-gift-modal" id="{{ $modalId }}" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">扣减赠金后消耗计算明细</h4>
                <p class="text-muted gift-modal-subtitle">{{ $row['operator'] }} · {{ $row['store_domain'] }}</p>
            </div>
            <div class="modal-body">
                <div class="gift-formula-note">
                    <strong>计算公式</strong>
                    <div>本段分到赠金 ＝ min(max(S＋D－5,000, 0), 5,000) － min(max(S－5,000, 0), 5,000)</div>
                    <div>扣减赠金后消耗 ＝ D － 本段分到赠金</div>
                    <small>S＝接手前累计消耗，D＝当前人员、当前项目的本段消耗；换人或换项目会开启新的投放段。</small>
                </div>
                @forelse($row['gift_calculations'] as $calculation)
                    <div class="gift-calculation-card">
                        <div class="gift-calculation-head">
                            <strong>账号 {{ $calculation['account_number'] }}</strong>
                            <span>{{ $calculation['start_date'] ?: '—' }} 至 {{ $calculation['end_date'] ?: '—' }}</span>
                        </div>
                        <div class="gift-calculation-grid">
                            <div><span>接手前消耗 S</span><b>${{ number_format($calculation['before_consumption'], 2) }}</b></div>
                            <div><span>本段消耗 D</span><b>${{ number_format($calculation['segment_consumption'], 2) }}</b></div>
                            <div><span>接手前已占赠金</span><b>${{ number_format($calculation['gift_at_start'], 2) }}</b></div>
                            <div><span>本段结束累计赠金</span><b>${{ number_format($calculation['gift_at_end'], 2) }}</b></div>
                            <div><span>本段分到赠金</span><b class="text-warning">${{ number_format($calculation['allocated_gift'], 2) }}</b></div>
                            <div><span>扣减赠金后消耗</span><b class="text-success">${{ number_format($calculation['deducted_consumption'], 2) }}</b></div>
                        </div>
                        @if($calculation['has_gift'])
                            <div class="gift-substitution">
                                min(max({{ number_format($calculation['before_consumption'], 2) }}＋{{ number_format($calculation['segment_consumption'], 2) }}－5,000, 0), 5,000)
                                － min(max({{ number_format($calculation['before_consumption'], 2) }}－5,000, 0), 5,000)
                                ＝ <strong>${{ number_format($calculation['allocated_gift'], 2) }}</strong>
                            </div>
                        @else
                            <div class="gift-substitution text-muted">该账号未确认礼金，本段不扣减赠金，扣减后消耗等于本段消耗。</div>
                        @endif
                    </div>
                @empty
                    <p class="text-center text-muted">当前记录没有可计算的 AppLovin 账号。</p>
                @endforelse
                <div class="gift-calculation-total">
                    该行扣减赠金后消耗合计：<strong>${{ number_format($row['deducted_consumption'], 2) }}</strong>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div>
    </div>
</div>
