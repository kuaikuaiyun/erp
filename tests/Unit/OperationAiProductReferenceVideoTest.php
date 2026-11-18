<?php

namespace Tests\Unit;

use App\Models\OperationAiProductReferenceVideo;
use PHPUnit\Framework\TestCase;

class OperationAiProductReferenceVideoTest extends TestCase
{
    public function test_operator_upload_has_its_own_stable_number_namespace(): void
    {
        $firstOperator = OperationAiProductReferenceVideo::operatorFileName(
            'kky-146',
            18,
            '261117',
            1,
            'MP4',
        );
        $secondOperator = OperationAiProductReferenceVideo::operatorFileName(
            'kky-146',
            29,
            '261117',
            1,
            '.mp4',
        );

        $this->assertTrue(OperationAiProductReferenceVideo::isOperatorMaterialFile($firstOperator));
        $this->assertTrue(OperationAiProductReferenceVideo::isOperatorMaterialFile($secondOperator));
        $this->assertTrue(OperationAiProductReferenceVideo::isOperatorMaterialFile('kky-146-ZZ-261117-placement-3ptpid.mp4'));
        $this->assertFalse(OperationAiProductReferenceVideo::isOperatorMaterialFile('kky-146-flow-261117-001.mp4'));
        $this->assertFalse(OperationAiProductReferenceVideo::isOperatorMaterialFile('kky-146-spy-261117-002.mp4'));

        self::assertSame('kky-146-op18-min-261117-001.mp4', $firstOperator);
        self::assertSame('kky-146-op29-min-261117-001.mp4', $secondOperator);
        self::assertNotSame($firstOperator, $secondOperator);
    }
}
