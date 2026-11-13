<?php

namespace Tests\Unit;

use App\Portals\OperationTasks\Http\Controllers\OperationTaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class OperationTaskValidationTranslationTest extends TestCase
{
    public function test_invalid_values_use_chinese_messages_and_field_names(): void
    {
        app()->setLocale('zh-CN');
        $cases = [
            [['assets' => [['url' => 'x']]], ['assets' => 'array|min:2']],
            [['spend_usd' => -1], ['spend_usd' => 'numeric|min:0']],
            [['spend_usd' => 'invalid'], ['spend_usd' => 'numeric']],
            [['orders' => '1.5'], ['orders' => 'integer']],
            [['questions' => [['is_required' => 'invalid']]], ['questions.0.is_required' => 'boolean']],
            [['data_date' => 'invalid'], ['data_date' => 'date']],
            [['submitted_from' => '12/11/2026'], ['submitted_from' => 'date_format:Y-m-d']],
            [['submitted_from' => '2026-11-12', 'submitted_to' => '2026-11-11'], ['submitted_to' => 'after_or_equal:submitted_from']],
            [['data_date' => '2026-12-01'], ['data_date' => 'before_or_equal:2026-11-12']],
            [['restore_confirmed' => false], ['restore_confirmed' => 'accepted']],
            [['file' => 'not-a-file'], ['file' => 'file']],
            [['assets' => [['upload_token' => 'invalid']]], ['assets.0.upload_token' => 'uuid']],
            [['assets' => [['file' => 'unexpected']]], ['assets.0.file' => 'prohibited']],
            [['landing_url' => 'invalid'], ['landing_url' => 'url']],
            [['analysis' => str_repeat('字', 5001)], ['analysis' => 'string|max:5000']],
        ];
        foreach ($cases as [$data, $rules]) {
            $validator = Validator::make($data, $rules, [], __('operation_tasks.validation.attributes'));
            self::assertTrue($validator->fails());
            foreach ($validator->errors()->all() as $message) {
                self::assertStringNotContainsString('validation.', $message);
                self::assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $message);
                self::assertDoesNotMatchRegularExpression('/(?:assets|questions)\.\d+\./', $message);
            }
        }
    }

    public function test_controller_adds_a_specific_category_requirement(): void
    {
        app()->setLocale('zh-CN');
        $method = new ReflectionMethod(OperationTaskController::class, 'validateTask');
        $request = Request::create('/', 'POST', ['page_type' => 'category', 'assets' => [['url' => 'x'], ['url' => 'y']]]);
        try {
            $method->invoke(app(OperationTaskController::class), $request, ['assets' => ['required', 'array', 'min:3']]);
            self::fail('Insufficient category assets were accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('类目页至少需要 3 条素材。', $exception->errors()['assets'][0]);
        }
    }

    public function test_each_validation_rule_used_by_the_controller_has_a_translation(): void
    {
        $translations = require lang_path('zh-CN/validation.php');
        $tokens = token_get_all(file_get_contents(app_path('Portals/OperationTasks/Http/Controllers/OperationTaskController.php')));
        $significant = array_values(array_filter($tokens, fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $rules = [];
        foreach ($significant as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'validateTask' || ($significant[$index - 1][0] ?? null) !== T_OBJECT_OPERATOR) continue;
            $start = $index;
            while (isset($significant[$start]) && $significant[$start] !== '[') $start++;
            $depth = 0; $enumDepth = null; $enumWaiting = false;
            for ($cursor = $start; isset($significant[$cursor]); $cursor++) {
                $item = $significant[$cursor];
                if (is_array($item) && $item[0] === T_STRING && $item[1] === 'in' && ($significant[$cursor + 1] ?? null) === '(' && ($significant[$cursor + 2] ?? null) === '[') $enumWaiting = true;
                if ($item === '[') { $depth++; if ($enumWaiting) { $enumDepth = $depth; $enumWaiting = false; } }
                if ($item === ']') { if ($enumDepth === $depth) $enumDepth = null; if (--$depth === 0) break; }
                if ($enumDepth !== null || ! is_array($item) || $item[0] !== T_CONSTANT_ENCAPSED_STRING || ($significant[$cursor + 1][0] ?? null) === T_DOUBLE_ARROW) continue;
                $rule = explode(':', substr($item[1], 1, -1))[0];
                if (preg_match('/^[a-z_]+$/', $rule) && ! in_array($rule, ['nullable', 'sometimes', 'bail'], true)) $rules[$rule] = true;
            }
        }
        self::assertArrayHasKey('min', $rules);
        self::assertArrayHasKey('uuid', $rules);
        foreach (array_keys($rules) as $rule) {
            self::assertArrayHasKey($rule, $translations, 'Missing validation translation: '.$rule);
            if (in_array($rule, ['min', 'max'], true)) {
                foreach (['array', 'file', 'numeric', 'string'] as $type) self::assertArrayHasKey($type, $translations[$rule]);
            }
        }
        self::assertArrayHasKey('uploaded', $translations);
    }
}
