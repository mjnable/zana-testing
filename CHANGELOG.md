# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.22.0 — Unreleased

### Added

- `assertRoutesWithstandAttacks()` in `ApplicationTestCase`, and `Security\RouteAttacks`: every route of the
  application attacked in memory (FE-68, layer 2 of ADR-033), so a new route is attacked as soon as it exists:
  - a route that needs a user: an anonymous request and an expired session must not reach its handler, which also
    tests an application's own sign-in middleware declared in `security.access_middleware`;
  - a public route behind `CsrfMiddleware` that changes data: no token, a forged one, a cross-site `Origin`;
  - a route with `#[RateLimit]`: the limit plus one request must not all reach the handler;
  - every route anyone may reach: script, SQL, template, traversal, NUL and long payloads, a list where a value is
    expected, a body that is not JSON and a 2 MB body must not cause a server error, and an HTML page must not print a
    script payload as it was sent.
  A probe, the route's last middleware, tells whether a request reached the handler; for the access, CSRF and rate
  limit attacks it answers itself, so the handler never runs. `except: ['POST /webhook' => 'reason']` leaves a route
  out, with the reason in the report. Attacks as a signed-in user are not made: signing in belongs to the application.
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
