<?php

declare(strict_types=1);

namespace Zana\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
use Zana\Testing\TestForm;

/**
 * FE-39: checkboxes and radio buttons are known even when nothing is
 * ticked, and tests tick them as a visitor does.
 */
final class FormChoicesTest extends ApplicationTestCase
{
    protected function createApplicationBuilder(): ApplicationBuilder
    {
        return Application::configure(sys_get_temp_dir() . '/zana-testing-choices')
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, ChoicesProvider::class);
    }

    public function testUntickedBoxesAreKnownButNotSent(): void
    {
        $form = $this->form();

        $this->assertSame(['_token' => 'csrf-123', 'name' => '', 'needs' => ['website']], $form->fields);
        $this->assertSame([
            'consent' => ['type' => 'checkbox', 'values' => ['on'], 'list' => false],
            'needs' => ['type' => 'checkbox', 'values' => ['website', 'hosting', 'support'], 'list' => true],
            'budget' => ['type' => 'radio', 'values' => ['small', 'medium', 'large'], 'list' => false],
        ], $form->choices);
    }

    public function testTickingBoxesAndChoosingRadioButtons(): void
    {
        $sent = $this->sent($this->form()
            ->fill(['name' => 'Ada'])
            ->check('consent')
            ->check('needs[]', 'support')
            ->uncheck('needs', 'website')
            ->choose('budget', 'medium')
            ->choose('budget', 'large'));

        $this->assertSame(['_token' => 'csrf-123', 'name' => 'Ada', 'needs' => ['support'], 'consent' => 'on', 'budget' => 'large'], $sent);
    }

    public function testUntickingTheLastBoxOfAGroupSendsNothing(): void
    {
        $sent = $this->sent($this->form()->uncheck('needs[]', 'website')->uncheck('consent'));

        $this->assertSame(['_token' => 'csrf-123', 'name' => ''], $sent);
    }

    public function testFillAcceptsAListForACheckboxGroup(): void
    {
        $this->assertSame(['hosting', 'support'], $this->sent($this->form()->fill(['needs[]' => ['hosting', 'support']]))['needs']);
        $this->assertSame(['website'], $this->sent($this->form()->fill(['needs' => ['website']]))['needs']);
        $this->assertArrayNotHasKey('needs', $this->sent($this->form()->fill(['needs' => []])));
        $this->assertSame('small', $this->sent($this->form()->fill(['budget' => 'small']))['budget']);
    }

    /**
     * @return iterable<string, array{\Closure(TestForm): TestForm, string}>
     */
    public static function impossibleChoices(): iterable
    {
        yield 'a misspelt box' => [static fn (TestForm $f): TestForm => $f->check('concent'), 'The form has no checkbox "concent"'];
        yield 'a value the group does not offer' => [static fn (TestForm $f): TestForm => $f->check('needs[]', 'design'), 'The form has no "needs" option with the value "design"; it has: website, hosting, support'];
        yield 'a group without a value' => [static fn (TestForm $f): TestForm => $f->check('needs[]'), 'The form has 3 "needs" boxes; say which: website, hosting, support'];
        yield 'a radio button ticked as a box' => [static fn (TestForm $f): TestForm => $f->check('budget', 'small'), 'The form has no checkbox "budget"; it is a radio'];
        yield 'a radio value the page lacks' => [static fn (TestForm $f): TestForm => $f->choose('budget', 'huge'), 'The form has no "budget" option with the value "huge"'];
        yield 'a box chosen as a radio button' => [static fn (TestForm $f): TestForm => $f->choose('consent', 'on'), 'The form has no radio button "consent"; it is a checkbox'];
        yield 'fill with a value the group lacks' => [static fn (TestForm $f): TestForm => $f->fill(['needs' => ['website', 'design']]), 'The form has no "needs" option with the value "design"'];
        yield 'fill a radio group with a list' => [static fn (TestForm $f): TestForm => $f->fill(['budget' => ['small', 'large']]), 'The field "budget" takes one value, not a list.'];
        yield 'fill an unknown field' => [static fn (TestForm $f): TestForm => $f->fill(['email' => 'x']), 'The form has no field "email"; it has: _token, name, needs, consent, budget.'];
    }

    /**
     * @param \Closure(TestForm): TestForm $action
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('impossibleChoices')]
    public function testWhatAVisitorCannotDoFails(\Closure $action, string $message): void
    {
        try {
            $action($this->form());
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail('Expected the form to refuse.');
    }

    public function testFormsBuiltByHandKeepWorking(): void
    {
        $form = new TestForm($this->browser(), '/quote', 'POST', ['_token' => 'csrf-123', 'name' => 'Ada', 'consent' => 'on']);

        $this->assertSame('Ada', json_decode((string) $form->submit()->assertOk()->body(), true)['name']);
    }

    private function form(): TestForm
    {
        return $this->browser()->form('/quote');
    }

    /**
     * @return array<string, mixed> What the application received
     */
    private function sent(TestForm $form): array
    {
        $sent = json_decode((string) $form->submit()->assertOk()->body(), true);
        $this->assertIsArray($sent);

        return $sent;
    }
}

final class ChoicesProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
    }

    public function boot(ContainerInterface $container): void
    {
        $router = $container->get(Router::class);
        assert($router instanceof Router);

        $router->get('/quote', static fn (): string => <<<'HTML'
            <form method="post" action="/quote">
              <input type="hidden" name="_token" value="csrf-123">
              <input name="name">
              <label><input type="checkbox" name="consent"> I agree</label>
              <input type="checkbox" name="needs[]" value="website" checked>
              <input type="checkbox" name="needs[]" value="hosting">
              <input type="checkbox" name="needs[]" value="support">
              <input type="radio" name="budget" value="small">
              <input type="radio" name="budget" value="medium">
              <input type="radio" name="budget" value="large">
              <button type="submit">Send</button>
            </form>
            HTML);

        $router->post('/quote', static fn (ServerRequestInterface $request, ResponseFactory $responses): ResponseInterface => $responses->json($request->getParsedBody()));
    }
}
