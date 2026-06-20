# Contributing

Thanks for considering a contribution to `herdwatch-oss/monolog-ecs-formatter`.

## Code of conduct

This project adheres to the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating, you are expected to uphold it.

## Requirements

- PHP 8.3+
- [Composer](https://getcomposer.org/)

## Getting started

```bash
git clone https://github.com/herdwatch-oss/monolog-ecs-formatter.git
cd monolog-ecs-formatter
composer install
```

## Running the tests

```bash
composer test        # or: vendor/bin/phpunit
```

All changes must keep the suite green, and new behaviour should come with tests. CI runs the suite on PHP 8.3 and 8.4 against the **lowest** and **highest** supported dependency versions, so please make sure your change works across the declared range.

## Coding standards

- Follow PSR-12 and the style of the surrounding code.
- `declare(strict_types=1);` in every PHP file; favour typed signatures and `final` classes where appropriate.
- Keep the public surface small and documented.

## Pull requests

1. Fork the repo and create a branch from `main` (e.g. `feat/…`, `fix/…`).
2. Make focused commits with clear messages ([Conventional Commits](https://www.conventionalcommits.org/) preferred).
3. Ensure `composer validate`, `composer audit`, and the test suite pass locally.
4. Open a PR against `main` describing the change and its motivation.

## Reporting issues

Use the issue templates for bugs and feature requests. For anything security-related, see [SECURITY.md](SECURITY.md) — please do **not** open a public issue.
