<?php

namespace App\Portals\Finance;

class FinanceWorldpayBillAdjustments extends FinanceYeBillAdjustments
{
    protected const CHANNEL = 'worldpay';

    protected const FIELDS = [
        'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
        'rdr_refund' => '拒付预警RDR金额', 'rdr_fee' => '拒付预警RDR+CDRN服务费用',
        'ethoca_fee' => '拒付预警ETHOCA服务费用', 'pre_arbitration' => '预仲裁金额', 'pre_arbitration_fee' => '预仲裁费用',
        'release' => '保证金返还', 'rdr_appeal' => 'RDR申诉成功', 'appeal' => '申诉成功',
        'logistics' => '物流-元', 'purchase' => '采购成本-元',
    ];
}
