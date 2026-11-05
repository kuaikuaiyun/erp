<?php

namespace App\Portals\Finance;

class FinancePayPalEventTypes
{
    // PayPal official definitions: https://developer.paypal.com/reports/reference/t-codes/
    // Categories follow the finance workbook supplied by the user.
    public const TYPES = [
        'T0003' => ['预先核准付款账单的用户付款', '结账付款'],
        'T0005' => ['信用卡直接付款', '结账付款'],
        'T0006' => ['PayPal Checkout付款', '结账付款'],
        'T0200' => ['一般货币兑换', '币种转换'],
        'T0403' => ['由用户发起的提款', '提现'],
        'T1110' => ['纠纷调查的余额延迟过账', '争议冻结'],
        'T1111' => ['取消纠纷排解的延迟过账', '取消争议冻结'],
        'T1107' => ['付款退款', '退款'],
        'T1106' => ['付款撤销', '退款'],
        'T0114' => ['纠纷费用', '争议费用'],
        'T0106' => ['撤销付款费用', '退款费用'],
        'T2101' => ['一般延迟过账', '冻结'],
        'T2102' => ['一般延迟过账解除', '返还冻结'],
        'T1109' => ['撤销付款费用（费用退款）', '退款费用'],
        'T2103' => ['保留预备金', '冻结'],
        'T2104' => ['解除预备金', '返还冻结'],
        'T1108' => ['费用撤销', '返还争议费用'],
    ];

    public static function label(string $value): string
    {
        if (isset(self::TYPES[$value])) {
            return self::TYPES[$value][0];
        }
        if (preg_match('/^T\d{4}$/', $value)) {
            return '待确认交易类型（'.$value.'）';
        }

        return FinanceCatalog::simplified(match ($value) {
            'Express Checkout Payment' => 'PayPal Checkout付款',
            'Direct Credit Card Payment' => '信用卡直接付款',
            'Hold on Balance for Dispute Investigation' => '纠纷调查的余额延迟过账',
            'PreApproved Payment Bill User Payment' => '预先核准付款账单的用户付款',
            '撤銷付款', '撤销付款' => '付款撤销',
            default => $value,
        });
    }
}
