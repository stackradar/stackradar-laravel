<?php

declare(strict_types=1);

namespace StackRadar\Laravel\Tests;

final class ConcurrencyTest extends TestCase
{
    public function test_separate_php_processes_share_the_lease_and_persisted_due_state(): void
    {
        mkdir($this->cachePath, 0777, true);
        $processes = [];

        try {
            for ($i = 0; $i < 2; $i++) {
                $processes[] = proc_open([
                    PHP_BINARY, __DIR__.'/fixtures/concurrent-report.php', $this->cachePath,
                ], [0 => ['pipe', 'r'], 1 => ['file', $this->cachePath.'/stdout', 'a'], 2 => ['file', $this->cachePath.'/stderr', 'a']], $pipes);
                fclose($pipes[0]);
            }

            $deadline = microtime(true) + 5;
            while (count(glob($this->cachePath.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertCount(2, glob($this->cachePath.'/ready-*'), file_get_contents($this->cachePath.'/stderr'));
            touch($this->cachePath.'/start');

            foreach ($processes as $index => $process) {
                self::assertSame(0, proc_close($process), file_get_contents($this->cachePath.'/stderr'));
                unset($processes[$index]);
            }

            self::assertSame("report\n", file_get_contents($this->cachePath.'/reports'));

            // A fresh third process also sees the successful report's persisted due time.
            $third = proc_open([
                PHP_BINARY, __DIR__.'/fixtures/concurrent-report.php', $this->cachePath,
            ], [0 => ['pipe', 'r'], 1 => ['file', $this->cachePath.'/stdout', 'a'], 2 => ['file', $this->cachePath.'/stderr', 'a']], $pipes);
            fclose($pipes[0]);
            self::assertSame(0, proc_close($third));
            self::assertSame("report\n", file_get_contents($this->cachePath.'/reports'));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}
