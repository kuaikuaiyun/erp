<?php

namespace Tests\Feature;

use App\Portals\Assistant\DomainStoreSaveDiagnostic;
use App\Portals\Assistant\Http\Middleware\DiagnoseDomainStoreSave;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DomainStoreSaveDiagnosticTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        Schema::create('diagnostic_records', function (Blueprint $t): void {
            $t->id(); $t->string('value');
        });
        $this->logPath = tempnam(sys_get_temp_dir(), 'store-save-diagnostic-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) unlink($this->logPath);
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/domain-stores/177', 'PUT', [
            'shopify_client_secret' => 'secret-must-not-appear',
            'nbc_app_token' => 'token-must-not-appear',
            'payment_card' => '5555555555554444',
            'email' => 'private@example.com',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $route = new Route('PUT', '/domain-stores/{domainStore}', static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    private function records(): array
    {
        return array_map(static fn ($line) => json_decode($line, true), file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_success_has_same_result_and_correlated_stage_records(): void
    {
        $request = $this->request();
        $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($request, function ($request) {
            DomainStoreSaveDiagnostic::mark($request, 'store_write');
            DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () {
                DB::table('diagnostic_records')->insert(['value' => 'saved']);
            });

            return response()->json(['message' => 'original success']);
        });
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['message' => 'original success'], $response->getData(true));
        $id = $response->headers->get('X-Domain-Store-Diagnostic-Id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);
        $records = $this->records();
        self::assertSame('request_received', $records[0]['context']['event']);
        foreach ($records as $record) {
            self::assertSame($id, $record['context']['diagnostic_id']);
            self::assertSame(177, $record['context']['store_id']);
        }
        self::assertSame('committed', end($records)['context']['commit_state']);
        self::assertSame(1, DB::table('diagnostic_records')->count());
    }

    public function test_failed_transaction_rolls_back_and_preserves_the_failing_stage(): void
    {
        $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function ($request) {
            DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () use ($request) {
                DB::table('diagnostic_records')->insert(['value' => 'must roll back']);
                DomainStoreSaveDiagnostic::mark($request, 'owner_history_assignment_and_order_refresh');
                throw new \RuntimeException('secret-must-not-appear token-must-not-appear 5555555555554444 private@example.com');
            });
        });
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('not_saved', $response->getData(true)['save_outcome']);
        self::assertSame(0, DB::table('diagnostic_records')->count());
        $records = $this->records();
        $error = collect($records)->first(fn ($r) => $r['context']['event'] === 'exception');
        self::assertSame('owner_history_assignment_and_order_refresh', $error['context']['stage']);
        self::assertSame('rolled_back', $error['context']['commit_state']);
        self::assertSame(\RuntimeException::class, $error['context']['exceptions'][0]['class']);
        $log = file_get_contents($this->logPath);
        foreach (['secret-must-not-appear', 'token-must-not-appear', '5555555555554444', 'private@example.com', 'must roll back'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
            self::assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_failure_after_commit_reports_saved_without_undoing_the_record(): void
    {
        $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function ($request) {
            DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () {
                DB::table('diagnostic_records')->insert(['value' => 'committed']);
            });
            DomainStoreSaveDiagnostic::mark($request, 'webhook_registration');
            throw new \RuntimeException('remote credential details must not appear');
        });
        self::assertSame('saved', $response->getData(true)['save_outcome']);
        self::assertSame(1, DB::table('diagnostic_records')->count());
        self::assertStringContainsString('已保存', $response->getData(true)['message']);
        $records = $this->records();
        self::assertSame('webhook_registration', end($records)['context']['stage']);
        self::assertSame('committed', end($records)['context']['commit_state']);
    }

    public function test_validation_and_permission_responses_retain_their_status_and_details(): void
    {
        foreach ([422, 403] as $status) {
            $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function () use ($status) {
                if ($status === 422) throw ValidationException::withMessages(['domain' => '请填写域名。']);
                abort(403);
            });
            self::assertSame($status, $response->getStatusCode());
            self::assertNotEmpty($response->headers->get('X-Domain-Store-Diagnostic-Id'));
            if ($status === 422) self::assertSame('请填写域名。', $response->getData(true)['errors']['domain'][0]);
        }
        self::assertSame(0, DB::table('diagnostic_records')->count());
    }

    public function test_database_failures_exclude_sql_bindings_and_exception_messages(): void
    {
        $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function ($request) {
            DomainStoreSaveDiagnostic::mark($request, 'store_write');
            DB::table('diagnostic_records')->insert(['missing_column' => 'secret-must-not-appear']);
        });
        self::assertSame('not_saved', $response->getData(true)['save_outcome']);
        $records = $this->records();
        $error = collect($records)->first(fn ($r) => $r['context']['event'] === 'exception');
        self::assertNotNull($error['context']['exceptions'][0]['sql_state']);
        self::assertNotNull($error['context']['exceptions'][0]['driver_code']);
        self::assertStringNotContainsString('insert into', file_get_contents($this->logPath));
        self::assertStringNotContainsString('secret-must-not-appear', file_get_contents($this->logPath));
    }

    public function test_nested_transaction_is_not_falsely_reported_as_committed(): void
    {
        DB::beginTransaction();
        try {
            $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function ($request) {
                DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () {
                    DB::table('diagnostic_records')->insert(['value' => 'nested']);
                });
                throw new \RuntimeException('outer transaction still open');
            });
            self::assertSame('unconfirmed', $response->getData(true)['save_outcome']);
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::table('diagnostic_records')->count());
    }

    public function test_unwritable_log_does_not_break_a_valid_save_and_uses_php_fallback(): void
    {
        $fallback = $this->logPath.'.php-error';
        $previous = ini_set('error_log', $fallback);
        try {
            // An existing file cannot serve as a log directory.
            $response = (new DiagnoseDomainStoreSave($this->logPath.'/invalid.log'))->handle($this->request(), function ($request) {
                DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () {
                    DB::table('diagnostic_records')->insert(['value' => 'valid save']);
                });

                return response()->json(['saved' => true]);
            });
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, DB::table('diagnostic_records')->count());
            $log = file_get_contents($fallback);
            self::assertStringContainsString($response->headers->get('X-Domain-Store-Diagnostic-Id'), $log);
            self::assertStringNotContainsString('secret-must-not-appear', $log);
        } finally {
            ini_set('error_log', $previous);
            if (is_file($fallback)) unlink($fallback);
        }
    }

    public function test_native_fatal_and_memory_exhaustion_record_last_stage_without_payloads(): void
    {
        if (! function_exists('proc_open')) $this->markTestSkipped('PHP subprocess execution is unavailable.');
        foreach ([
            'trigger_error("private-secret-value", E_USER_ERROR);' => 'fatal_error',
            '$oversized = str_repeat("x", 64 * 1024 * 1024);' => 'memory_limit',
        ] as $failure => $category) {
            file_put_contents($this->logPath, '');
            $code = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
                .'$request = \Illuminate\Http\Request::create("/domain-stores/177", "POST");'
                .'$trace = new \App\Portals\Assistant\DomainStoreSaveDiagnostic($request, '.var_export($this->logPath, true).');'
                .'$trace->phase("owner_history_assignment_and_order_refresh");'
                .'$trace->registerShutdown();'.$failure;
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $code], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertNotSame(0, proc_close($process));
            $records = $this->records();
            $fatal = collect($records)->first(fn ($record) => $record['context']['event'] === 'fatal_shutdown');
            self::assertNotNull($fatal);
            self::assertSame($category, $fatal['context']['category']);
            self::assertSame('owner_history_assignment_and_order_refresh', $fatal['context']['stage']);
            self::assertStringNotContainsString('private-secret-value', file_get_contents($this->logPath));
        }
    }

    public function test_original_transaction_error_survives_a_second_exception_handler_load_error(): void
    {
        $target = str_replace(chr(92), '/', base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php'));
        $loader = base_path('vendor/composer/ClassLoader.php');
        $response = (new DiagnoseDomainStoreSave($this->logPath))->handle($this->request(), function ($request) use ($target, $loader) {
            try {
                DomainStoreSaveDiagnostic::transaction($request, DB::connection(), function () use ($request) {
                    DB::table('diagnostic_records')->insert(['value' => 'must roll back']);
                    DomainStoreSaveDiagnostic::mark($request, 'owner_history_assignment_and_order_refresh');
                    throw new \DomainException('original failure with secret-must-not-appear');
                });
            } catch (\DomainException) {
                // Laravel's pipeline can lose the first error while resolving its handler.
                throw new \ErrorException('include('.$target.'): Failed to open stream: No such file or directory', 0, E_WARNING, $loader, 576);
            }
        });
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, DB::table('diagnostic_records')->count());
        $records = $this->records();
        $callback = collect($records)->first(fn ($r) => $r['context']['event'] === 'transaction_callback_exception');
        $transaction = collect($records)->first(fn ($r) => $r['context']['event'] === 'transaction_exception');
        $secondary = collect($records)->first(fn ($r) => $r['context']['event'] === 'exception');
        self::assertSame(\DomainException::class, $callback['context']['exceptions'][0]['class']);
        self::assertSame(\DomainException::class, $transaction['context']['exceptions'][0]['class']);
        self::assertSame('rolled_back', $transaction['context']['commit_state']);
        self::assertSame('owner_history_assignment_and_order_refresh', $callback['context']['stage']);
        self::assertSame(\ErrorException::class, $secondary['context']['exceptions'][0]['class']);
        self::assertSame(['reason' => 'file_not_found', 'target_file' => $target], $secondary['context']['exceptions'][0]['file_load']);
        self::assertSame($callback['context']['diagnostic_id'], $secondary['context']['diagnostic_id']);
        self::assertStringNotContainsString('secret-must-not-appear', file_get_contents($this->logPath));
    }

    public function test_callback_error_is_logged_before_a_rollback_failure_replaces_it(): void
    {
        $request = $this->request();
        $trace = new DomainStoreSaveDiagnostic($request, $this->logPath);
        $connection = new class(new \PDO('sqlite::memory:')) extends \Illuminate\Database\SQLiteConnection {
            public function rollBack($toLevel = null)
            {
                throw new \RuntimeException('rollback failure must not hide original error');
            }
        };
        try {
            DomainStoreSaveDiagnostic::transaction($request, $connection, function () {
                throw new \DomainException('original callback failure');
            });
            self::fail('The rollback exception must still propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback failure must not hide original error', $error->getMessage());
        } finally {
            if ($connection->getPdo()->inTransaction()) $connection->getPdo()->rollBack();
            $trace->finish(500);
        }
        $records = $this->records();
        $callback = collect($records)->first(fn ($r) => $r['context']['event'] === 'transaction_callback_exception');
        $transaction = collect($records)->first(fn ($r) => $r['context']['event'] === 'transaction_exception');
        self::assertSame(\DomainException::class, $callback['context']['exceptions'][0]['class']);
        self::assertSame(\RuntimeException::class, $transaction['context']['exceptions'][0]['class']);
        self::assertSame('unconfirmed', $transaction['context']['commit_state']);
        self::assertLessThan(array_search($transaction, $records, true), array_search($callback, $records, true));
    }

    public function test_commit_failure_is_logged_before_propagation_without_reporting_success(): void
    {
        $request = $this->request();
        $trace = new DomainStoreSaveDiagnostic($request, $this->logPath);
        $error = new \PDOException('commit error with private data');
        $error->errorInfo = ['HY000', 2006, 'secret-must-not-appear'];
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $connection->method('transactionLevel')->willReturn(0);
        $connection->method('transaction')->willReturnCallback(function ($callback) use ($error) {
            $callback();
            throw $error;
        });
        try {
            DomainStoreSaveDiagnostic::transaction($request, $connection, static fn () => null);
            self::fail('The original commit error must propagate.');
        } catch (\PDOException $caught) {
            self::assertSame($error, $caught);
        } finally {
            $trace->finish(500);
        }
        $records = $this->records();
        $entry = collect($records)->first(fn ($r) => $r['context']['event'] === 'transaction_exception')['context'];
        self::assertSame('transaction_ready_to_commit', $entry['stage']);
        self::assertSame('unconfirmed', $entry['commit_state']);
        self::assertSame('HY000', $entry['exceptions'][0]['sql_state']);
        self::assertSame(2006, $entry['exceptions'][0]['driver_code']);
        self::assertStringNotContainsString('secret-must-not-appear', file_get_contents($this->logPath));
    }

    public function test_composer_load_failures_extract_only_target_and_reason(): void
    {
        $trace = new DomainStoreSaveDiagnostic($this->request(), $this->logPath);
        $loader = base_path('vendor/composer/ClassLoader.php');
        $target = str_replace(chr(92), '/', base_path('vendor/example/Sample.php'));
        foreach ([
            ['include('.$target.'): Failed to open stream: Permission denied secret-must-not-appear', 'permission_denied'],
            ['include(): open_basedir restriction in effect. File('.$target.') is not within allowed paths private@example.com', 'open_basedir_restriction'],
            ["include(): Failed opening '".$target."' for inclusion (include_path=private@example.com)", 'open_failed'],
        ] as [$message, $reason]) {
            $trace->exception(new \ErrorException($message, 0, E_WARNING, $loader, 576), false);
            $records = $this->records();
            self::assertSame(['reason' => $reason, 'target_file' => $target], end($records)['context']['exceptions'][0]['file_load']);
        }
        $trace->finish(500);
        $log = file_get_contents($this->logPath);
        self::assertStringNotContainsString('secret-must-not-appear', $log);
        self::assertStringNotContainsString('private@example.com', $log);
        self::assertStringNotContainsString('include_path', $log);
    }

    public function test_unrelated_exception_messages_and_untrusted_paths_remain_excluded(): void
    {
        $trace = new DomainStoreSaveDiagnostic($this->request(), $this->logPath);
        $loader = base_path('vendor/composer/ClassLoader.php');
        foreach ([
            'https://private.example/secret-must-not-appear.php',
            str_replace(chr(92), '/', base_path('vendor/Sample.php')).'?token=secret-must-not-appear',
            '/outside-project/secret-must-not-appear.php',
            str_replace(chr(92), '/', base_path('vendor/../secret-must-not-appear.php')),
        ] as $target) {
            $trace->exception(new \ErrorException('include('.$target.'): Failed to open stream: Permission denied', 0, E_WARNING, $loader, 576), false);
            $records = $this->records();
            self::assertSame(['reason' => 'permission_denied'], end($records)['context']['exceptions'][0]['file_load']);
        }
        $target = str_replace(chr(92), '/', base_path('vendor/example/secret-must-not-appear.php'));
        $trace->exception(new \RuntimeException('include('.$target.'): Failed to open stream: Permission denied'), false);
        $records = $this->records();
        self::assertArrayNotHasKey('file_load', end($records)['context']['exceptions'][0]);
        $trace->finish(500);
        self::assertStringNotContainsString('secret-must-not-appear', file_get_contents($this->logPath));
    }

    public function test_real_composer_missing_file_warning_records_the_target(): void
    {
        $trace = new DomainStoreSaveDiagnostic($this->request(), $this->logPath);
        $class = 'DiagnosticMissingClass_'.bin2hex(random_bytes(6));
        $target = str_replace(chr(92), '/', base_path('vendor/diagnostic-fixture/'.$class.'.php'));
        self::assertFileDoesNotExist($target);
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addClassMap([$class => $target]);
        $loader->register(true);
        try {
            class_exists($class);
            self::fail('Missing Composer class files must raise an error.');
        } catch (\ErrorException $error) {
            $trace->exception($error, false);
            $records = $this->records();
            self::assertSame(['reason' => 'file_not_found', 'target_file' => $target], end($records)['context']['exceptions'][0]['file_load']);
        } finally {
            $loader->unregister();
            $trace->finish(500);
        }
    }

    public function test_only_create_and_update_routes_have_the_diagnostic_middleware(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['store', 'update'] as $name) {
            self::assertContains(DiagnoseDomainStoreSave::class, $routes->getByName('assistant.domain-stores.'.$name)->gatherMiddleware());
        }
        foreach (['index', 'edit', 'export', 'destroy'] as $name) {
            self::assertNotContains(DiagnoseDomainStoreSave::class, $routes->getByName('assistant.domain-stores.'.$name)->gatherMiddleware());
        }
    }
}
