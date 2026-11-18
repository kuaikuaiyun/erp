<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use App\Portals\Logistics\Http\Controllers\ShopifyStoreController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopifyStoreFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        Schema::create((new AssistantDomainStore())->getTable(), function (Blueprint $table): void {
            $table->increments('id');
            foreach (['shop_name', 'store_prefix', 'site_builder', 'shop_status'] as $column) $table->string($column);
        });
        $rows = [
            [1, '测试甲店', 'ABC-1', 'shopify'], [2, '测试乙店', 'XYZ-2', 'shopify'],
            [3, '测试甲店', 'XYZ-3', 'shopify'], [4, '测试乙店', 'ABC-4', 'shopline'],
            [5, '特殊%_!店', '0', 'shopify'],
        ];
        foreach ($rows as [$id, $name, $prefix, $platform]) {
            DB::table((new AssistantDomainStore())->getTable())->insert(['id' => $id, 'shop_name' => $name, 'store_prefix' => $prefix, 'site_builder' => $platform, 'shop_status' => AssistantDomainStore::SHOP_STATUS_IN_USE]);
        }
    }

    private function matchingIds(string $name = '', string $prefix = ''): array
    {
        $query = AssistantDomainStore::query()->where('site_builder', 'shopify')->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE);
        $controller = (new \ReflectionClass(ShopifyStoreController::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($controller, 'applyStoreSearch'))->invoke($controller, $query, ['shop_name' => $name, 'store_prefix' => $prefix]);
        return $query->orderBy('id')->pluck('id')->all();
    }

    public function test_multiple_terms_allow_either_match_but_fields_must_both_match(): void
    {
        self::assertSame([1, 2, 3], $this->matchingIds(' 甲，乙,甲, , '));
        self::assertSame([1], $this->matchingIds('甲', 'ABC'));
        self::assertSame([1, 3], $this->matchingIds('甲', 'ABC，XYZ'));
        self::assertSame([], $this->matchingIds('不存在', 'ABC'));
    }

    public function test_literals_zero_and_empty_terms_do_not_broaden_original_scope(): void
    {
        self::assertSame([5], $this->matchingIds('%_!'));
        self::assertSame([5], $this->matchingIds('', '0'));
        self::assertSame([1, 2, 3, 5], $this->matchingIds(' ， , ', ''));
        self::assertSame([1, 2, 3], $this->matchingIds('甲,乙', ''));
    }
}
