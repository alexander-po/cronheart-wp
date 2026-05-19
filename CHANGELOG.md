# Changelog

All notable changes to the `cronheart/wp` plugin land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Repository scaffolding: GPL-2.0-or-later license, composer manifest
  with Strauss namespace-prefix config, plugin entry point with WP
  header, empty `Cronheart\WP\Plugin` bootstrap class.
- CI matrix on PHP 8.2 / 8.3 / 8.4 running PHPUnit, PHPStan level 8,
  php-cs-fixer dry-run, `composer validate --strict`, and `composer
  audit`.
- WPCS (`WordPress-Core` + `WordPress-Extra`) ruleset scoped to
  user-facing PHP only so the SDK-style internal code can stay
  PSR-12 / strict-typed without fighting the WordPress conventions.
