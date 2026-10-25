# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.0.0-beta.3 — 2026-10-25

### Added

- `ModuleRules` and `assertModuleArchitecture()`: architecture tests for module layers and the
  dependencies between modules.
- `HttpTestClient::submit()` posts form fields.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Test Zana applications with PHPUnit: boot the application for each test, send HTTP requests and run commands in memory, record events, and enforce architecture rules. Requires PHP 8.5 or later.

Documentation: [the testing guide](https://github.com/mjnable/zana-docs/blob/main/guides/testing.md).
