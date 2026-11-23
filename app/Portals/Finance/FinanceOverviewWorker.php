<?php

namespace App\Portals\Finance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

class FinanceOverviewWorker
{
    /** Wake a detached worker; never calculate reports in the HTTP process. */
    public function wake(): bool
    {
        if (! config('finance.overviews.auto_start', true)) return true;

        $db = app(FinanceWorkbookService::class)->database();
        $key = 'finance:overview-wakeup:'.hash('sha256', $db->getName().':'.$db->getDatabaseName());
        $cache = Cache::store('file');
        // Polls and simultaneous tabs must not start a process every two seconds.
        // The command's database/session lock still serializes all actual work.
        if (! $cache->add($key, true, 30)) return true;

        try {
            $php = $this->phpBinary(PHP_BINARY);
            if (! $php || ! is_executable($php)) throw new \RuntimeException('找不到财务报表计算使用的 PHP CLI。');
            $this->launch($php);
            return true;
        } catch (\Throwable $error) {
            $cache->forget($key);
            report($error);
            return false;
        }
    }

    protected function phpBinary(string $runtimeBinary): string|false
    {
        if ($configured = config('finance.overviews.php_binary')) return $configured;

        // PHP-CGI on Windows reports php-cgi.exe, while PHP_BINDIR can still
        // contain the build machine's path. phpStudy does not add PHP to PATH.
        // Prefer the CLI beside the active runtime instead of that build path.
        $sibling = dirname($runtimeBinary).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (is_file($sibling) && is_executable($sibling)) return $sibling;

        return (new PhpExecutableFinder)->find(false);
    }

    protected function launch(string $php): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $literal = fn (string $value) => "'".str_replace("'", "''", $value)."'";
            // Start-Process detaches the child from the request's process handles.
            // Quote the complete artisan path again for Windows argument parsing.
            $script = '$ErrorActionPreference = \'Stop\'; Start-Process -WindowStyle Hidden -FilePath '.$literal($php)
                .' -WorkingDirectory '.$literal(base_path())
                .' -ArgumentList '.$literal('"'.base_path('artisan').'" finance:overviews:build');
            $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
                base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'))];
        } else {
            $command = ['/bin/sh', '-c', escapeshellarg($php).' '.escapeshellarg(base_path('artisan'))
                .' finance:overviews:build >> '.escapeshellarg(storage_path('logs/finance-overviews-worker.log')).' 2>&1 < /dev/null &'];
        }

        Process::path(base_path())->timeout(10)->run($command)->throw();
    }
}
