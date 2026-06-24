# Symfony Flex recipe

These files are the Symfony Flex recipe for this package. **They are not part of
the published Composer package** — a recipe lives in a separate recipe repository
and is keyed by the package name. This folder is `export-ignore`d from the dist
tarball; it lives here only as the source of truth to copy into a recipe repo.

When present, the recipe automates install-time setup so that
`composer require herdwatch-oss/monolog-ecs-formatter` will:

- register `MonologEcsFormatterBundle` in `config/bundles.php` (via `manifest.json` `bundles`)
- copy `config/packages/monolog_ecs_formatter.yaml` into the consuming app (via `copy-from-recipe`)

## Contents

```
manifest.json
config/packages/monolog_ecs_formatter.yaml
```

## Publishing — two options

### A. Public OSS — symfony/recipes-contrib

1. Ensure the package is on Packagist with a stable tag (recipes require a released version).
2. Fork https://github.com/symfony/recipes-contrib
3. Create `herdwatch-oss/monolog-ecs-formatter/<major.minor>/` and copy these files in.
4. Open a PR; the automated checks validate the manifest and config.

Contrib recipes are opt-in for consumers (Flex prompts, or set
`extra.symfony.allow-contrib: true` in the app's `composer.json`).

### B. Private org recipe server

Host a recipe repository and point consuming Herdwatch apps at it:

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://api.github.com/repos/herdwatch-oss/recipes/contents/index.json?ref=flex/main",
                "flex://defaults"
            ]
        }
    }
}
```

## Without a recipe

Nothing breaks — Flex just reports "no recipe found" and the user performs the two
manual steps documented in the project README (register the bundle, create the
config file).
