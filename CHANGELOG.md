# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.30.0 — 2026-11-21

### Added

- A declared shared kernel (FE-76, ADR-034): `ModuleRules::sharedKernel('Modules\Shared\Contracts')` lets every layer
  of every module use one module's Contracts, Domain and Contracts included, for values such as money or a clock.
  The kernel itself may depend only on plain PHP, `Zana\Ddd` and the other kernels, so it stays a set of values; its
  implementations live in its module's Infrastructure. Only a module's Contracts can be the kernel: another layer,
  part of a Contracts namespace or an unknown module is refused, with the reason. Another module's Contracts are
  still refused in a Domain.
- `ModuleRules::forApplication($app)`: the application's modules and `architecture.shared_kernel`, as `check` reads
  them, so an application's own architecture test and `check` agree. `kernel()` and `emptyKernel()` (a kernel whose
  module has no Contracts directory).

## 2.27.0 — 2026-11-21

### Added

- `assertRecordsStayWithTheirOwners()` in `ApplicationTestCase`, and `Security\RecordAttacks`: one user must not reach
  another's records, the attack 2.22 left to the application, because only it knows who owns what. It says so with
  two sign-ins, `owner` and `other`, and `records`, by route parameter, functions that make a fresh record of the
  owner's and give its id, or the ids of several parameters. For every route with a parameter named there, on a fresh
  record each time:
  - the other user asks first and must be refused, with 401, 403 or 404: a page that shows the record, a change, or a
    redirect after one, fails;
  - the owner asks after and must get through, so the refusal is known to be about the owner. When the owner gets
    through on no route, the run fails: it proved nothing.
- `writes: true` tries POST, PUT, PATCH and DELETE too, with a valid CSRF token when mj-zana/security issues them.
  `bodies`, by route, gives a write input the owner's request succeeds with: when both users get the same 400 or 422,
  the application checked the input before the owner, and the route is reported as not proven; a 400 or 422 that only
  the other user gets fails. `json: true` asks as an API client: JSON answers, JSON bodies, the CSRF token in
  `X-CSRF-Token`. `except` leaves a route out, with the reason.
- Public routes are tried as well: a public page can leak a record too.

## 2.22.0 — 2026-11-14

### Added

- `assertRoutesWithstandAttacks()` in `ApplicationTestCase`, and `Security\RouteAttacks`: every route of the
  application attacked in memory (FE-68, layer 2 of ADR-033), so a new route is attacked as soon as it exists:
  - a route that says it needs a user (enforced or not): an anonymous request and an expired session must not reach
    its handler, which also
    tests an application's own sign-in middleware declared in `security.access_middleware`;
  - a public route behind `CsrfMiddleware` that changes data: no token, a forged one, a cross-site `Origin`;
  - a route with `#[RateLimit]`: the limit plus one request must not all reach the handler;
  - every route anyone may reach: script, SQL, template, traversal, NUL and long payloads, a list where a value is
    expected, a body that is not JSON and a 2 MB body must not cause a server error, and an HTML page must not print a
    script payload as it was sent. A PHP warning that hostile input causes in the application's own code fails too.
    An ordinary request goes first: if it already fails, that one failure is reported, not each payload.
  A probe, the route's last middleware, tells whether a request reached the handler; for the access, CSRF and rate
  limit attacks it answers itself, so the handler never runs. `except: ['POST /webhook' => 'reason']` leaves a route
  out, with the reason in the report.
- As a signed-in user too, with `signIn: fn ($request) => …`, which the application writes, as only it knows how its
  users sign in, and `holding: [...]`, the permissions that user holds: a route that needs another must refuse them; a
  route that needs only a user, or permissions it holds, gets the CSRF attacks, unless the sign-in is a bearer token,
  and the hostile input. When no signed-in request gets through to any such route, the sign-in itself fails, so the
  attacks cannot pass by proving nothing; a route that refuses a permission `holding` names is reported too. One user
  reaching another's data stays for the application's own tests.
- Who may reach a route is read with mj-zana/security's `RouteAccess` (2.22), as `check` reads it; it conflicts with
  mj-zana/security below 2.22.

## 2.20.0 — 2026-11-14

### Changed

- `ApplicationTestCase` documents `withoutDotEnv()` (core 2.20, FE-73) for tests that build another environment,
  such as production, so the developer's `.env` cannot change their result.

## 2.4.0 — 2026-10-26

### Added

- `TestForm::check()`, `uncheck()` and `choose()`: tick checkboxes and select radio buttons in form
  tests, as a visitor does. `fromResponse()` records every checkbox and radio button with the values
  it offers (`TestForm::$choices`), ticked or not, and `fill()` accepts a list for a `name[]` group.
  A box, a name or a value the page does not have fails the test (FE-39).

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.1.0 — 2026-10-26

### Added

- `browser()`, `assertSee()`, `assertDontSee()`, `assertRedirect()`, `assertSelector()`, and forms:
  `HttpTestClient::form()->fill()->submit()` (FE-08).
- `withConfig()` and `createApplicationBuilder()` (FE-09).
- `assertMailSent()` and `assertNoMailSent()` with mj-zana/communication.

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-beta.3 — 2026-10-25

### Added

- `ModuleRules` and `assertModuleArchitecture()`: architecture tests for module layers and the
  dependencies between modules.
- `HttpTestClient::submit()` posts form fields.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Test Zana applications with PHPUnit: boot the application for each test, send HTTP requests and run commands in memory, record events, and enforce architecture rules. Requires PHP 8.5 or later.

Documentation: [the testing guide](https://github.com/mjnable/zana-docs/blob/main/guides/testing.md).
