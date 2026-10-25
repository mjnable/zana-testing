<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Communication\CommunicationServiceProvider;
use Zana\Communication\MailerInterface;
use Zana\Communication\Message;
use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ServiceProvider;
use Zana\Http\HttpServiceProvider;
use Zana\Http\ResponseFactory;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;
use Zana\Testing\ApplicationTestCase;

final class WebsiteTestingTest extends ApplicationTestCase
{
    protected function createApplicationBuilder(): ApplicationBuilder
    {
        return Application::configure(sys_get_temp_dir() . '/zana-testing-website')
            ->withEnvironment('testing')
            ->withConfiguration(['site.name' => 'Acme', 'mail.from' => 'site@example.com'])
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, CommunicationServiceProvider::class, WebsiteProvider::class);
    }

    public function testHtmlAssertions(): void
    {
        $this->browser()->get('/')
            ->assertOk()
            ->assertSee('Tom & Jerry')
            ->assertDontSee('<script>', escape: false)
            ->assertSelector('h1', 'Acme')
            ->assertSelector('nav a');
    }

    public function testFailedSelectorsExplainThemselves(): void
    {
        try {
            $this->browser()->get('/')->assertSelector('h1', 'Globex');
            $this->fail('Expected the assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('No "h1" element contains "Globex"; found: ["Welcome to Acme"]', $e->getMessage());
        }
    }

    public function testFormsKeepHiddenFieldsAndDefaults(): void
    {
        $form = $this->browser()->form('/contact');

        $this->assertSame('/contact', $form->action);
        $this->assertSame('POST', $form->method);
        $this->assertSame(['_token' => 'csrf-123', 'name' => '', 'topic' => 'sales', 'copy' => 'yes', 'message' => 'Hello'], $form->fields);
    }

    public function testSubmittingAFormFollowsTheBrowser(): void
    {
        $this->browser()->form('/contact')->fill(['name' => 'Ada'])->submit()->assertRedirect('/thanks');

        $sent = $this->assertMailSent(static fn (Message $m): bool => $m->subject === 'Contact from Ada');
        $this->assertSame('site@example.com', $sent[0]->from?->email);
    }

    public function testUnknownFieldsFailTheTest(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The form has no field "email"');
        $this->browser()->form('/contact')->fill(['email' => 'x']);
    }

    public function testNoMail(): void
    {
        $this->assertNoMailSent();
    }

    public function testConfigurationPerTest(): void
    {
        $this->withConfig(['site.name' => 'Globex']);

        $this->browser()->get('/')->assertSelector('h1', 'Globex');
        $this->assertSame('site@example.com', $this->service(ConfigurationInterface::class)->string('mail.from'), 'other settings are kept');
    }

    public function testTheOverridesLastOneTestOnly(): void
    {
        $this->browser()->get('/')->assertSelector('h1', 'Acme');
    }
}

final class WebsiteProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
    }

    public function boot(ContainerInterface $container): void
    {
        $router = $container->get(Router::class);
        assert($router instanceof Router);

        $router->get('/', static fn (ConfigurationInterface $config): string => '<h1>Welcome to ' . htmlspecialchars($config->string('site.name')) . '</h1>'
            . '<nav><a href="/contact">Contact</a></nav><p>Tom &amp; Jerry</p>');

        $router->get('/contact', static fn (): string => <<<'HTML'
            <form method="post" action="/contact">
              <input type="hidden" name="_token" value="csrf-123">
              <input name="name">
              <select name="topic"><option value="support">Support</option><option value="sales" selected>Sales</option></select>
              <input type="checkbox" name="copy" value="yes" checked>
              <input type="checkbox" name="newsletter" value="yes">
              <textarea name="message">Hello</textarea>
              <button type="submit" name="send">Send</button>
            </form>
            HTML);

        $router->post('/contact', static function (ServerRequestInterface $request, MailerInterface $mailer, ResponseFactory $responses): ResponseInterface {
            $fields = (array) $request->getParsedBody();
            if (($fields['_token'] ?? null) !== 'csrf-123') {
                return $responses->text('Forbidden', 403);
            }
            $mailer->send(Message::create()->to('contact@example.com')->subject('Contact from ' . (string) ($fields['name'] ?? ''))->text((string) ($fields['message'] ?? '')));

            return $responses->redirect('/thanks', 303);
        });
    }
}
