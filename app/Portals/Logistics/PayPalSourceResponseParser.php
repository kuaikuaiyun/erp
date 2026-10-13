<?php

namespace App\Portals\Logistics;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PayPalSourceResponseParser
{
    /** @return list<array<string,mixed>> */
    public function transactions(array $payload): array
    {
        $segments = Arr::get($payload, 'source_responses.transaction_search.segments', []);
        if (!is_array($segments)) {
            throw new \RuntimeException('PayPal 原始响应缺少交易查询分段。');
        }

        $records = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $responses = $segment['responses'] ?? [];
            if (!is_array($responses)) {
                continue;
            }
            foreach ($responses as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $details = $response['transaction_details'] ?? [];
                if (!is_array($details)) {
                    continue;
                }
                foreach ($details as $detail) {
                    if (is_array($detail)) {
                        $records[] = $detail;
                    }
                }
            }
        }

        return $records;
    }

    /** @return list<array<string,mixed>> */
    public function disputes(array $payload): array
    {
        $search = Arr::get($payload, 'source_responses.dispute_search', []);
        if (!is_array($search)) {
            throw new \RuntimeException('PayPal 原始响应缺少纠纷查询结果。');
        }

        $recordsById = [];
        $listResponses = $search['list_responses'] ?? [];
        if (is_array($listResponses)) {
            foreach ($listResponses as $response) {
                if (!is_array($response) || !is_array($response['items'] ?? null)) {
                    continue;
                }
                foreach ($response['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $disputeId = trim((string) ($item['dispute_id'] ?? ''));
                    if ($disputeId !== '') {
                        $recordsById[$disputeId] = $item;
                    }
                }
            }
        }

        $detailResponses = $search['detail_responses'] ?? [];
        if (is_array($detailResponses)) {
            foreach ($detailResponses as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $disputeId = trim((string) ($entry['dispute_id'] ?? ''));
                $response = $entry['response'] ?? null;
                if ($disputeId === '' || !is_array($response)) {
                    continue;
                }

                $recordsById[$disputeId] = array_replace(
                    $recordsById[$disputeId] ?? [],
                    $response,
                );
            }
        }

        return array_values($recordsById);
    }

    /** @return list<string> */
    public function errors(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $errors = [];
        foreach ($value as $error) {
            if (!is_array($error)) {
                continue;
            }

            $source = trim((string) ($error['source'] ?? 'paypal'));
            $context = [];
            if (!blank($error['segment'] ?? null)) {
                $context[] = '分段'.$error['segment'];
            }
            if (!blank($error['page'] ?? null)) {
                $context[] = '第'.$error['page'].'页';
            }
            if (!blank($error['dispute_id'] ?? null)) {
                $context[] = '纠纷'.$error['dispute_id'];
            }
            $message = trim((string) ($error['message'] ?? '未知错误'));
            if ($message !== '') {
                $label = $source.($context === [] ? '' : '（'.implode('，', $context).'）');
                $errors[] = $label.'：'.Str::limit($message, 500);
            }
        }

        return $errors;
    }
}
