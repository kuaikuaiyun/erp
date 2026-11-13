<form method="post" enctype="multipart/form-data" action="{{ route('operation.ai-product-library.placement-evaluations.store', $product) }}">
    @csrf
    <div class="alert alert-info"><i class="fa fa-info-circle"></i> 当前产品：<strong>{{ $product->product_code }} · {{ $product->name }}</strong>。此处只显示您本人提交的测评记录。</div>
    <div class="row">
        <div class="col-sm-6 form-group"><label>开始测试日期</label><input class="form-control" type="date" name="test_started_on" required></div>
        <div class="col-sm-6 form-group"><label>数据截至日期</label><input class="form-control" type="date" name="data_ended_on" required></div>
        <div class="col-sm-3 form-group"><label>投放平台</label><select class="form-control" name="platform" required><option value="facebook">Facebook</option><option value="google">Google</option><option value="tiktok">TikTok</option><option value="mediago">MediaGo</option><option value="newsbreak">NewsBreak</option><option value="applovin">AppLovin</option></select></div>
        <div class="col-sm-3 form-group"><label>币种</label><select class="form-control" name="currency" required><option value="usd">美元</option><option value="eur">欧元</option><option value="cny">人民币</option></select></div>
        <div class="col-sm-3 form-group"><label>实际消耗</label><input class="form-control" type="number" min="0" step="0.01" name="actual_spend" required></div>
        <div class="col-sm-3 form-group"><label>出单量</label><input class="form-control" type="number" min="0" step="1" name="orders" required></div>
        <div class="col-sm-6 form-group"><label>素材名称</label><input class="form-control" type="text" maxlength="191" name="material_name" placeholder="填写对应投放素材名称" required></div>
        <div class="col-sm-6 form-group"><label>上传视频</label><input class="form-control" type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm,video/x-matroska,video/x-m4v" required><small class="text-muted">最大 300MB</small></div>
        <div class="col-sm-12 form-group"><label>备注</label><textarea class="form-control" name="remark" rows="2" maxlength="2000"></textarea></div>
    </div>
    <div class="text-right"><button class="btn btn-primary" type="submit"><i class="fa fa-check"></i> 保存测评</button></div>
</form>
<hr><h4><i class="fa fa-history"></i> 我的投放记录</h4>
<div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>测评编号</th><th>测试周期</th><th>平台</th><th>消耗</th><th>出单量</th><th>素材名称</th><th>视频</th></tr></thead><tbody>
@forelse($evaluations as $evaluation) @foreach($evaluation->records as $record)
<tr><td>{{ $evaluation->evaluation_code }}</td><td>{{ $evaluation->test_started_on?->format('Y-m-d') }} 至 {{ $evaluation->data_ended_on?->format('Y-m-d') }}</td><td>{{ ucfirst($record->platform) }}</td><td>{{ strtoupper($record->currency) }} {{ $record->actual_spend }}</td><td>{{ $record->orders }}</td><td>{{ $record->material_name ?: '—' }}</td><td>@if($record->video_path)<a class="btn btn-xs btn-info" target="_blank" href="{{ route('operation.ai-product-library.placement-evaluations.video', [$evaluation, $record]) }}"><i class="fa fa-play"></i> 播放</a>@else — @endif</td></tr>
@endforeach @empty <tr><td colspan="7" class="text-center text-muted">暂无本人提交的投放记录</td></tr> @endforelse
</tbody></table></div>
