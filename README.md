<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Plugin

> **GitHub-native plugin distribution** for the Milpa PHP framework — semver-aware version resolution, manifest validation, dependency ordering, and a lock file, with no registry server required.

[![CI](https://github.com/getmilpa/plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/plugin/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/plugin.svg)](https://packagist.org/packages/milpa/plugin)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/plugin/)

`milpa/plugin` is the distinct-value core behind a Milpa host's `plugin require owner/repo`
command: resolve a semver constraint against GitHub releases/tags, download and extract the
matching zipball, read and validate the plugin's `milpa.json` manifest, resolve its plugin +
Composer dependencies, order every installed plugin by the contracts it provides/requires, and
record the result in a `milpa.lock` file. **No registry server, no Packagist-style index** —
GitHub itself is the source of truth.

## Install

```bash
composer require milpa/plugin
```

## Quick example

```php
use Milpa\Plugin\ContractResolver;
use Milpa\Plugin\DependencyResolver;
use Milpa\Plugin\GitHubDownloader;
use Milpa\Plugin\LockFileManager;
use Milpa\Plugin\PluginManifest;

// 1. Parse a GitHub source string into owner/repo/constraint.
$downloader = new GitHubDownloader();
$downloader->parseSource('acme/mail-plugin:^2.0');
// -> ['owner' => 'acme', 'repo' => 'mail-plugin', 'constraint' => '^2.0']

// 2. Read + validate a milpa.json manifest (fromArray() mirrors fromPath()).
$manifest = PluginManifest::fromArray([
    'name' => 'acme/mail-plugin',
    'version' => '2.1.0',
    'entrypoint' => 'MailPlugin.php',
    'namespace' => 'Acme\\MailPlugin',
    'contracts' => ['requires' => ['database']],
]);
$manifest->validate(); // throws InvalidArgumentException on a malformed manifest

// 3. Order plugins by their declared contracts (Kahn's algorithm; throws on cycles).
$resolver = new ContractResolver();
$loadOrder = $resolver->getLoadOrder([
    ['name' => 'DatabasePlugin', 'class' => 'Acme\\DatabasePlugin', 'provides' => ['database']],
    ['name' => 'acme/mail-plugin', 'class' => 'Acme\\MailPlugin', 'requires' => ['database']],
]);
// -> load order: DatabasePlugin, acme/mail-plugin (providers before consumers)

// 4. Resolve dependencies before installing (plugin deps, contracts, composer.lock).
$deps = new DependencyResolver(getcwd());
$resolution = $deps->resolve($manifest, [
    ['name' => 'DatabasePlugin', 'provides' => ['database']],
]);
// -> $resolution->resolvable === true, $resolution->conflicts === []

// 5. Record installed state in milpa.lock.
$lock = new LockFileManager(getcwd());
$lock->generate([
    ['name' => 'acme/mail-plugin', 'version' => '2.1.0', 'source' => 'github:acme/mail-plugin'],
]);
$lock->verify(); // true — the SHA-256 content hash matches
```

## Generating a canonical manifest

`PluginManifest::generateFromMetadata()` turns a plugin's `#[PluginMetadata]` into `milpa.json`
data — and the capability entries decide the emitted shape; the generator never invents metadata:

- **every entry a structured record** (`{id, interface, contractVersion, service, …}`) → the
  canonical `capabilities` block, each record validated through core's capability value objects
  **plus** a generation-time provider check (`service` present, autoloadable, and implementing the
  declared interface). A record that fails validation is a **hard failure**
  (`InvalidArgumentException`), never a silent downgrade;
- **every entry a bare FQCN string** → the legacy `contracts` block exactly as before, plus a
  `$warnings` entry teaching how to reach canonical;
- **a mix of both shapes** in one plugin → hard failure: one plugin migrates atomically.

The canonical shape is frozen in [`schema/milpa-plugin.schema.json`](schema/milpa-plugin.schema.json),
which ships with this package — the suite's schema-conformance tests run against that exact file.
Overwrite policy is the **host's** concern: a host command (e.g. `coa:plugins manifest --force`)
decides whether an existing `milpa.json` may be replaced; the generator only returns the array.

## What lives where

| Class | Responsibility |
|-------|-----------------|
| `GitHubDownloader` | Parses `owner/repo[:constraint]` / full GitHub URLs, lists releases/tags via the GitHub REST API, resolves the best version for a constraint, and downloads + extracts the matching zipball. Reads `GITHUB_TOKEN` for private repos or higher rate limits. |
| `PluginManifest` | Reads and validates a `milpa.json` manifest (`fromPath()` / `fromArray()`), exposes typed accessors (`getProvides()`, `getRequires()`, `getSuggests()`, typed `CapabilityProvision`/`CapabilityRequirement`/`CapabilitySuggestion` records, Composer/plugin dependencies, PHP version constraint, env vars), and generates a manifest from `#[PluginMetadata]` via `generateFromMetadata()` — canonical `capabilities` block for rich records, legacy `contracts` block (with a teaching warning) for bare FQCNs, hard failure on a mix. |
| `ContractResolver` | Validates that every plugin's `requires` is satisfied by some other plugin's `provides` (fail-fast, throws `RuntimeException`; `suggests` only logs), and topologically sorts plugins into a load order where providers come before consumers. |
| `DependencyResolver` | Resolves a plugin's contract requirements, plugin-to-plugin dependencies (with version constraint checks), and Composer dependencies (read from `composer.lock`) into a single `Milpa\DTO\DependencyResolution` — `resolvable`, `conflicts`, `missingPlugins`, `composerPackages`, `satisfiedContracts`. |
| `LockFileManager` | Generates, reads, and verifies `milpa.lock` — installed plugin names, versions, sources, install timestamps, and a SHA-256 content hash for integrity checks. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.6**
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Documentation

**Full API reference: [getmilpa.github.io/plugin](https://getmilpa.github.io/plugin/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=plugin)**.
