# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

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
