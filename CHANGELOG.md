# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Added
- Initial scaffold: two dashboard widget stubs (Open Issues, Closed Issues) registered for Nextcloud 30–34
- OAuth authorization-code redirect route (implementation lands in the next release)
- Encrypted per-user OAuth token storage (`Service\TokenStorage`)
- Admin and Personal settings pages (placeholder UI — OAuth connect flow lands in the next release)
- Webpack build pipeline emits four bundles: `dashboardOpen`, `dashboardClosed`, `personalSettings`, `adminSettings`

## 0.0.1

Initial baseline commit.
