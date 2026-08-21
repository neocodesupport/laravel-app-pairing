# Release Notes

## [Unreleased](https://github.com/neocodesupport/laravel-app-pairing/compare/v1.1.1...1.x)


## [v1.1.1](https://github.com/neocodesupport/laravel-app-pairing/compare/v1.1.0...v1.1.1) - 2026-08-21

Drop the declared Laravel 11 support added in v1.1.0: every Laravel 11.x release carries at least one known security advisory, so Composer's default advisory policy already made it uninstallable in practice (this surfaced as a CI failure trying to install `laravel/framework:11.*`). Laravel 12 is the minimum supported version again.

## [v1.1.0](https://github.com/neocodesupport/laravel-app-pairing/compare/v1.0.0...v1.1.0) - 2026-08-21

**Breaking**: minimum PHP version raised from `^8.2` to `^8.3`. `src/Support/PairingHeaders.php` now uses native typed class constants, which require PHP 8.3.

## [v1.0.0](https://github.com/neocodesupport/laravel-app-pairing/releases/tag/v1.0.0) - 2026-08-21

Initial stable release.
