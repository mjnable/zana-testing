<?php

declare(strict_types=1);

namespace Zana\Testing;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zana\Console\ConsoleKernel;

/**
 * Runs console commands in memory and captures their output.
 */
final readonly class ConsoleTester
{
    public function __construct(private ConsoleKernel $kernel)
    {
    }

    /**
     * @param string|array<string, mixed> $command "migrate --step=2", or ['command' => 'migrate', '--step' => '2']
     */
    public function run(string|array $command): CommandResult
    {
        $input = is_string($command) ? new StringInput($command) : new ArrayInput($command);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->kernel->run($input, $output);

        return new CommandResult($exitCode, $output->fetch());
    }
}
