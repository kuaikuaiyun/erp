@php
    $reviewStatus = (string) ($status ?? 'pending');
    $reviewStatusMeta = [
        'approved' => ['label' => '已审核', 'class' => ''],
        'rejected' => ['label' => '已驳回', 'class' => 'red'],
        'pending' => ['label' => '待审核', 'class' => 'orange'],
    ][$reviewStatus] ?? ['label' => '待审核', 'class' => 'orange'];
@endphp
<div class="ot-stage-status">
    <span class="ot-badge {{ $reviewStatusMeta['class'] }}">{{ $reviewStatusMeta['label'] }}</span>
    <small>{{ $reviewerNames ?: '未配置' }}</small>
</div>
