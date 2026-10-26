<?php

namespace App\Portals\Payment;

use App\Portals\Logistics\LogisticsProductStatisticsService;

class PaymentProductRiskService
{
    public function __construct(private readonly LogisticsProductStatisticsService $statistics)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dashboard(array $filters): array
    {
        $data = $this->statistics->page($filters);
        $summary = $data['summary'];
        $revenue = (float) ($summary['revenue'] ?? 0);
        $refunds = (float) ($summary['actual_refunds'] ?? 0);

        return [
            ...$data,
            'filters' => $filters,
            'stores' => $this->statistics->storeOptions($filters),
            'refund_rate' => $revenue > 0 ? round($refunds / $revenue * 100, 2) : 0.0,
        ];
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $options @return array<string,mixed>|null */
    public function detail(array $filters, string $productId, array $options): ?array
    {
        return $this->statistics->detailPage($filters, $productId, $options);
    }
}
