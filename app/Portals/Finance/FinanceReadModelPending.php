<?php

namespace App\Portals\Finance;

class FinanceReadModelPending extends \RuntimeException
{
    public function report(): bool { return true; }

    public function render(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\Response
    {
        FinanceQueryDiagnostics::current()?->event('read_model.pending', ['state' => $this->state]);
        return app(\App\Http\Middleware\MonitorFinancePerformance::class)->preparing($request, $this);
    }

    public function __construct(public readonly string $state = 'preparing')
    {
        parent::__construct($state === 'unavailable' ? '后台计算尚未配置，请完成财务查询迁移。'
            : match ($state) {
                'failed' => '后台计算失败，请联系管理员查看执行日志。',
                'queued' => '所选数据已排队，后台任务启动后自动计算。',
                'running' => '后台正在计算所选数据，完成后自动显示。',
                'retry' => '后台正在等待重试，完成后自动显示。',
                default => '后台正在准备所选数据，完成后自动显示。',
            });
    }
}
