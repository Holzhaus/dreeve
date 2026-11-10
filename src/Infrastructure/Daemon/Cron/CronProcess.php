<?php

declare(strict_types=1);

namespace App\Infrastructure\Daemon\Cron;

use App\Infrastructure\Time\Clock\Clock;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * @codeCoverageIgnore
 */
final readonly class CronProcess
{
    private const float POLL_INTERVAL_IN_SECONDS = 0.1;

    /**
     * @param list<string> $command
     */
    public function __construct(
        private string $cronActionId,
        private Clock $clock,
        private OutputInterface $output,
        private array $command,
    ) {
    }

    public function start(): void
    {
        $this->output->writeln(sprintf(
            '<info>[%s] Starting cron action "%s"</info>',
            $this->clock->now()->format('d-m-Y H:i:s'),
            $this->cronActionId
        ));

        // The command runs with the PHP binary of the daemon instead of through a shell,
        // so the image does not need one.
        $process = new Process([PHP_BINARY, ...$this->command], timeout: null);
        $process->start(function (string $type, string $chunk): void {
            $this->output->write(Process::ERR === $type ? sprintf('<error>%s</error>', $chunk) : $chunk);
        });

        // Checking whether the process is still running also passes its output to the callback above.
        Loop::addPeriodicTimer(self::POLL_INTERVAL_IN_SECONDS, function (TimerInterface $timer) use ($process): void {
            if ($process->isRunning()) {
                return;
            }

            Loop::cancelTimer($timer);
            $this->output->writeln(sprintf(
                '<info>[%s] Finished cron action "%s"</info>',
                $this->clock->now()->format('d-m-Y H:i:s'),
                $this->cronActionId
            ));
        });
    }
}
