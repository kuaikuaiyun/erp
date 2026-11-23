<?php

namespace Tests\Feature;

use App\Portals\Finance\FinanceOverviewWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FinanceOverviewWorkerTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = storage_path('framework/testing/overview-worker-'.bin2hex(random_bytes(8)));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'admin.database.connection' => null, 'cache.stores.file.path' => $this->cachePath,
            'finance.overviews.auto_start' => true, 'finance.overviews.php_binary' => PHP_BINARY]);
        DB::purge('sqlite');
        Process::fake();
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_polls_throttle_detached_worker_launches_and_recover_after_expiry(): void
    {
        $worker = app(FinanceOverviewWorker::class);
        $this->assertTrue($worker->wake());
        $this->assertTrue($worker->wake());
        Process::assertRanTimes(function ($process) {
            $command = $process->command;
            if (PHP_OS_FAMILY === 'Windows') {
                $script = mb_convert_encoding(base64_decode($command[4]), 'UTF-8', 'UTF-16LE');
                return str_contains($script, 'Start-Process -WindowStyle Hidden')
                    && str_contains($script, 'finance:overviews:build');
            }
            return str_contains($command[2], 'finance:overviews:build') && str_ends_with($command[2], '&');
        }, 1);
        $this->travel(31)->seconds();
        $this->assertTrue($worker->wake());
        Process::assertRanTimes(fn () => true, 2);
        $this->travelBack();
    }

    public function test_launch_failure_releases_the_throttle_for_retry(): void
    {
        Process::fake(['*' => Process::sequence()->push(Process::result('', 'launch failed', 1))->push('')]);
        $worker = app(FinanceOverviewWorker::class);
        $this->assertFalse($worker->wake());
        $this->assertTrue($worker->wake());
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_scheduled_worker_only_mode_does_not_launch_processes(): void
    {
        config(['finance.overviews.auto_start' => false]);
        $this->assertTrue(app(FinanceOverviewWorker::class)->wake());
        Process::assertNothingRan();
    }

    public function test_cgi_runtime_finds_the_cli_in_its_own_directory_without_explicit_configuration(): void
    {
        config(['finance.overviews.php_binary' => null]);
        $worker = new class extends FinanceOverviewWorker {
            public function resolve(string $runtime): string|false { return $this->phpBinary($runtime); }
        };
        $runtime = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        $this->assertSame(realpath(PHP_BINARY), realpath($worker->resolve($runtime)));
        config(['finance.overviews.php_binary' => '/explicit/php-cli']);
        $this->assertSame('/explicit/php-cli', $worker->resolve($runtime));
    }
}
