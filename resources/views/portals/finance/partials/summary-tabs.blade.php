<nav class="finance-summary-tabs" aria-label="汇总明细视图">
    <a href="{{ $reportTabUrls['settlement'] }}" @class(['is-active' => $activeTab === 'settlement']) @if($activeTab === 'settlement') aria-current="page" @endif>结算汇总</a>
    <a href="{{ $reportTabUrls['transactions'] }}" @class(['is-active' => $activeTab === 'transactions']) @if($activeTab === 'transactions') aria-current="page" @endif>交易汇总</a>
</nav>
