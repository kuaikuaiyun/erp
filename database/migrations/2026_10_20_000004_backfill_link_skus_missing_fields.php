<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 历史数据回填：product_request_link_skus 表中缺失的
 * pcl_product_no / product_name / sku_values / product_image 字段，
 * 全部从同 supplier_url_hash 且审核通过的 product_requests 提报记录回填。
 *
 * 触发场景：过去采购检验通过时 persistLinkSkuSet 仅写入 supplier_url + skus
 * 两个字段，导致 link_sku 表长期缺失关键展示信息，列表与查重工具读到空白。
 */
return new class extends Migration {
    public function up(): void
    {
        // 选取字段缺失的 link_sku 记录：任一字段为空/JSON空 视为缺失
        $missingLinks = DB::table('product_request_link_skus')
            ->select('id', 'supplier_url_hash')
            ->where(function ($q) {
                $q->whereNull('pcl_product_no')
                    ->orWhere('pcl_product_no', '')
                    ->orWhereNull('product_name')
                    ->orWhere('product_name', '')
                    ->orWhereNull('sku_values')
                    ->orWhereRaw("JSON_LENGTH(sku_values) = 0")
                    ->orWhereNull('product_image')
                    ->orWhere('product_image', '');
            })
            ->whereNotNull('supplier_url_hash')
            ->get();

        if ($missingLinks->isEmpty()) {
            return;
        }

        $hashes = $missingLinks->pluck('supplier_url_hash')->unique()->all();

        // 收集每个链接下「审核通过」的最新提报记录作为回填数据源
        $candidates = DB::table('product_requests')
            ->select([
                'id',
                'supplier_url_hash',
                'pcl_product_no',
                'standard_chinese_name',
                'procurement_sku_values',
                'status',
            ])
            ->whereIn('supplier_url_hash', $hashes)
            ->where('status', 'inspection_passed')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('supplier_url_hash');

        // 为每个链接挑选最新一条；提报记录的运营阶段首图作为产品图封面
        $productImages = DB::table('product_request_attachments')
            ->select('product_request_id', 'path')
            ->whereIn('product_request_id', $candidates->flatten(1)->pluck('id')->all())
            ->where('stage', 'operation')
            ->orderBy('id')
            ->get()
            ->groupBy('product_request_id');

        foreach ($missingLinks as $link) {
            $hash = $link->supplier_url_hash;
            $rows = $candidates->get($hash);
            if (!$rows || $rows->isEmpty()) {
                continue;
            }

            $best = $rows->first();

            $update = [];

            if (($link->pcl_product_no ?? null) === null || $link->pcl_product_no === '') {
                if (trim((string) $best->pcl_product_no) !== '') {
                    $update['pcl_product_no'] = $best->pcl_product_no;
                }
            }
            if (($link->product_name ?? null) === null || $link->product_name === '') {
                if (trim((string) $best->standard_chinese_name) !== '') {
                    $update['product_name'] = $best->standard_chinese_name;
                }
            }
            if (($link->sku_values ?? null) === null) {
                if (!is_null($best->procurement_sku_values)) {
                    $update['sku_values'] = $best->procurement_sku_values;
                }
            }

            $imageList = $productImages->get($best->id);
            if (($link->product_image ?? null) === null || $link->product_image === '') {
                $firstImage = $imageList ? $imageList->first() : null;
                if ($firstImage && trim((string) $firstImage->path) !== '') {
                    $update['product_image'] = $firstImage->path;
                }
            }

            if ($update !== []) {
                $update['updated_at'] = now();
                DB::table('product_request_link_skus')
                    ->where('id', $link->id)
                    ->update($update);
            }
        }
    }

    public function down(): void
    {
        // 回滚策略：迁移前 link_sku 字段为空状态被认为是「不完整」状态，
        // 回填不可逆（无法识别哪些是从 pr 表同步过来的、哪些是用户后续填写的）。
        // 因此 down 不做清理，保留当前已回填的数据，便于审计回溯。
    }
};