# USD / Toman Pricing for WooCommerce

Safe WooCommerce pricing workflow for stores whose canonical catalog prices are entered in Iranian Toman. See [issue #1](https://github.com/hamidnoshady/woocammerce-usd-to-toman/issues/1) for the product requirements.

## Development and releases

- `composer test` runs PHPUnit when dependencies are installed.
- `composer build` creates a clean `release/woocammerce-usd-to-toman-<version>.zip`.
- The build never writes the ZIP into the plugin source directory and excludes CI, tests, Git metadata, and development files.
- GitHub Actions runs PHP lint, tests, package validation, and uploads the ZIP as an artifact. Pushing a version tag such as `v0.1.0` creates a GitHub Release with the clean ZIP attached.
