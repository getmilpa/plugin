# Changelog

## [0.10.1](https://github.com/getmilpa/plugin/compare/v0.10.0...v0.10.1) (2026-08-09)


### Bug Fixes

* **deps:** reach milpa/command ^0.7, where the ceiling grew a fifth dimension ([804d860](https://github.com/getmilpa/plugin/commit/804d860f22f566582d68bf24349c8ff991792e5f))

## [0.10.0](https://github.com/getmilpa/plugin/compare/v0.9.1...v0.10.0) (2026-08-05)


### Features

* **supply:** un claim de paquete no es una clasificación, y las 15 operaciones declaran su efecto ([60dcbf9](https://github.com/getmilpa/plugin/commit/60dcbf9117ca9634b15c5069829da3b473d68a53))

## [0.9.1](https://github.com/getmilpa/plugin/compare/v0.9.0...v0.9.1) (2026-08-04)


### Bug Fixes

* **deps:** admite milpa/resolver ^0.6, y no llama lo que el pin permite que falte ([01493a3](https://github.com/getmilpa/plugin/commit/01493a3646a2c6f7e23599dccd33e4e23715b17e))

## [0.9.0](https://github.com/getmilpa/plugin/compare/v0.8.0...v0.9.0) (2026-08-04)


### Features

* **plugins:** plugins.register — declarar un plugin andamiado, con el objeto nombrado por el humano ([eb547b2](https://github.com/getmilpa/plugin/commit/eb547b27e8aa74f26b8c275850d70a3c0979795a))

## [0.8.0](https://github.com/getmilpa/plugin/compare/v0.7.2...v0.8.0) (2026-08-02)


### Features

* plugins.enable and plugins.disable declare their named target ([c9bff5b](https://github.com/getmilpa/plugin/commit/c9bff5bf82290c74f3fa35d1ff582f3f31e0b4f8))

## [0.7.2](https://github.com/getmilpa/plugin/compare/v0.7.1...v0.7.2) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/command deja de ser una jaula de un minor ([9454eae](https://github.com/getmilpa/plugin/commit/9454eae00f40a45ddeb21945ef5e66cc2b6990f8))

## [0.7.1](https://github.com/getmilpa/plugin/compare/v0.7.0...v0.7.1) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([a1ee7a3](https://github.com/getmilpa/plugin/commit/a1ee7a364b8902b4ee7a3b0147c35f1cbad935c6))

## [0.7.0](https://github.com/getmilpa/plugin/compare/v0.6.0...v0.7.0) (2026-08-01)


### Features

* la reparacion de seguridad del apagado, y la linea base del reporte ([b0e2c65](https://github.com/getmilpa/plugin/commit/b0e2c65b4261f2566a2b33e5ce831c5da49b0cbc))


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([f848467](https://github.com/getmilpa/plugin/commit/f848467f6c0cdf2c0eef93c26e108698fa5f4ade))

## [0.6.0](https://github.com/getmilpa/plugin/compare/v0.5.0...v0.6.0) (2026-07-31)


### Features

* the five operations that INSPECT plugins live here now ([7a27e4a](https://github.com/getmilpa/plugin/commit/7a27e4a82f803024be11f0e5653c8aba08d800da))

## [0.5.0](https://github.com/getmilpa/plugin/compare/v0.4.0...v0.5.0) (2026-07-31)


### Features

* disabling a plugin can no longer lock a host out of enabling it ([45b9603](https://github.com/getmilpa/plugin/commit/45b96030f41412267792a3551f0a614f6e299411))

## [0.4.0](https://github.com/getmilpa/plugin/compare/v0.3.0...v0.4.0) (2026-07-30)


### ⚠ BREAKING CHANGES

* the constraint on `milpa/command` moves from ^0.2 to ^0.3, so this package can no longer be installed alongside command 0.2.

### Features

* require milpa/command ^0.3 ([bf18c4e](https://github.com/getmilpa/plugin/commit/bf18c4efa450f19c50012de403f7252448004225))

## [0.3.0](https://github.com/getmilpa/plugin/compare/v0.2.0...v0.3.0) (2026-07-27)


### Features

* activación declarada en código, conmutada en runtime ([cda9605](https://github.com/getmilpa/plugin/commit/cda9605fd0b54bb44cf8c2a643ba606ffa3b7e66))
* costura PSR-18 en GitHubDownloader (78.5% -&gt; 94.3% de cobertura) ([53b0fcd](https://github.com/getmilpa/plugin/commit/53b0fcdf6e76bf7d07c4d7c52f1abf56a76c8b8c))
* gestión de plugins como operaciones — una definición, siete surfaces ([d9a83df](https://github.com/getmilpa/plugin/commit/d9a83df15a9aedcb3c71e4bbbb10546b08cc3a8a))
* publicar el sistema de plugins completo — el paquete tenía 5 de 24 clases ([39887ce](https://github.com/getmilpa/plugin/commit/39887cef272e8f5e2c6e4dce43e11615f1269caa))

## [0.2.0](https://github.com/getmilpa/plugin/compare/v0.1.2...v0.2.0) (2026-07-13)


### Features

* generateFromMetadata emits canonical capabilities from rich records — never invents metadata ([242ed42](https://github.com/getmilpa/plugin/commit/242ed42a79587b5624e22e861e3d068495fa380a))

## [0.1.2](https://github.com/getmilpa/plugin/compare/v0.1.1...v0.1.2) (2026-07-12)


### Bug Fixes

* receive milpa/core 0.6 — pin bump; ContractResolver deprecated in favor of milpa/resolver ([0e811a8](https://github.com/getmilpa/plugin/commit/0e811a8e248c60cf3b761476f357048148f269c9))

## [0.1.1](https://github.com/getmilpa/plugin/compare/v0.1.0...v0.1.1) (2026-07-08)


### Bug Fixes

* require milpa/core ^0.5 ([0e75cb0](https://github.com/getmilpa/plugin/commit/0e75cb01496ef051d399fade7e0ebdb744a9f448))


### Miscellaneous Chores

* release 0.1.0 ([5b35406](https://github.com/getmilpa/plugin/commit/5b354062695a76e77ef6f6ed2aefb431b663ddae))
* release 0.1.1 ([dc64fc1](https://github.com/getmilpa/plugin/commit/dc64fc1fd2904a770266972c5e4b30c2f89feb04))

## 0.1.0 (2026-07-08)


### Features

* milpa/plugin initial public release ([cdb3ffd](https://github.com/getmilpa/plugin/commit/cdb3ffdbd6f56a972ae16c9deae4b1660b5ade2f))


### Miscellaneous Chores

* release 0.1.0 ([43abc03](https://github.com/getmilpa/plugin/commit/43abc03d4c922902c9382878fbff488cc9057524))
