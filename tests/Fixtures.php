<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Http\Exception\HttpException;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Http\ResponseFactory;
use Zana\Testing\ApplicationTestCase;
use Zana\Testing\EventRecorder;

final class FixtureApp
{
    public static function create(): ApplicationInterface
    {
        return Application::configure(sys_get_temp_dir() . '/zana-testing-app')
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, ConsoleServiceProvider::class)
            ->withServices(static function (ServiceCollectionInterface $s): void {
                $s->replace(HttpKernel::HANDLER, EchoHandler::class)
                    ->singleton(EventRecorder::class, static fn (): EventRecorder => new EventRecorder())
                    ->alias(EventDispatcherInterface::class, EventRecorder::class)
                    ->singleton(GreetCommand::class)->tag(ConsoleKernel::TAG, GreetCommand::class);
            })
            ->build();
    }
}

final readonly class PatientRegistered
{
    public function __construct(public string $id)
    {
    }
}

/**
 * Echoes the request as JSON. /status/<code> answers with that status;
 * /event/<id> dispatches PatientRegistered.
 */
final readonly class EchoHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactory $responses, private EventDispatcherInterface $events)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
            $status = (int) $m[1];

            return match (true) {
                $status === 204 => $this->responses->noContent(),
                $status >= 400 => throw new HttpException($status, "status {$status}"),
                default => $this->responses->json(['status' => $status], $status),
            };
        }
        if (preg_match('#^/event/(\w+)$#', $path, $m) === 1) {
            $this->events->dispatch(new PatientRegistered($m[1]));
        }
        if ($path === '/text') {
            return $this->responses->text('not json');
        }

        return $this->responses->json([
            'method' => $request->getMethod(),
            'path' => $path,
            'query' => $request->getQueryParams(),
            'accept' => $request->getHeaderLine('Accept'),
            'contentType' => $request->getHeaderLine('Content-Type'),
            'authorization' => $request->getHeaderLine('Authorization'),
            'tenant' => $request->getHeaderLine('X-Tenant'),
            'body' => (string) $request->getBody(),
            'parsed' => $request->getParsedBody(),
            'data' => ['items' => [['id' => 7, 'tags' => ['a', 'b']], ['id' => 8, 'tags' => []]], 'total' => 2, 'empty' => null],
        ], 200, ['X-Handled-By' => 'echo']);
    }
}

#[AsCommand(name: 'greet')]
final class GreetCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, '', 'world');
    }

    public function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $name = (string) $input->getArgument('name');
        if ($name === 'nobody') {
            $io->error('Nobody to greet.');

            return self::FAILURE;
        }
        $io->writeln("Hello, {$name}!");

        return self::SUCCESS;
    }
}

/**
 * Exposes the protected lifecycle for the lifecycle test.
 */
final class LifecycleCase extends ApplicationTestCase
{
    protected function createApplication(): ApplicationInterface
    {
        return FixtureApp::create();
    }

    public function start(): void
    {
        $this->setUp();
    }

    public function stop(): void
    {
        $this->tearDown();
    }

    public function application(): ApplicationInterface
    {
        return $this->app();
    }
}
