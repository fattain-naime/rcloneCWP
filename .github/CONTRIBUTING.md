# Contributing to rcloneCWP

Thank you for considering contributing to rcloneCWP! This document provides guidelines and instructions for contributing.

## Development Setup

### Prerequisites

- PHP 7.1+ (CWP uses `/usr/local/cwp/php71/bin/php`)
- MySQL/MariaDB
- rclone v1.75.0+
- CWP (CentOS Web Panel) installed

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/fattain_naive/rcloneCWP.git
   cd rcloneCWP
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Set up the database (CWP environment):
   ```bash
   mysql -u root root_cwp < sql/install.sql
   ```

4. Sync to CWP module path:
   ```bash
   php sync_live.php
   ```

## Project Structure

```
rcloneCWP/
├── rcloneCWP.php          # Main entry point
├── config.php             # Configuration constants
├── install.php            # Database schema + setup
├── uninstall.php          # Cleanup
├── lib/                   # Core PHP classes
│   ├── Backup/            # Backup engine and collectors
│   ├── Destinations/      # Destination backends (12 types)
│   ├── Restore/           # Restore engine
│   ├── Scheduling/        # Cron parser, schedule manager, retention
│   ├── API.php            # RESTful API engine
│   ├── CLI.php            # CLI toolsuite
│   ├── CSRF.php           # CSRF token management
│   ├── Database.php       # Database abstraction
│   ├── Encryption.php     # AES-256-GCM encryption
│   ├── Hook.php           # Hook execution engine
│   ├── Logger.php         # Structured logging
│   ├── Notification.php   # Notification channels
│   ├── Rclone.php         # rclone wrapper
│   └── Validator.php      # Input validation
├── api/                   # REST API endpoints
├── cli/                   # CLI entry points
├── views/                 # UI templates
├── cron/                  # Scheduled tasks
├── templates/             # Email/notification templates
├── assets/                # CSS/JS/images
├── language/              # Translations (en, bn)
├── sql/                   # Database schemas
└── tests/                 # Test suite
```

## Coding Standards

- **Style**: PSR-12
- **Minimum PHP version**: 7.1
- **Naming**: PascalCase classes, camelCase methods, snake_case DB columns
- **Namespace**: `CWP\RcloneCWP`
- **Documentation**: PHPDoc for all public methods

### Key Rules

- All database queries use prepared statements
- User input passes through `Validator.php`
- Command execution uses `escapeshellarg()` for all arguments
- Output is encoded with `htmlspecialchars()` before rendering
- Credentials are encrypted with AES-256-GCM via `Encryption.php`
- File permissions: 0600 for configs, 0700 for directories

## Running Tests

```bash
# Run the full test suite
php tests/run_tests.php

# Run a specific test file
php tests/unit/ValidatorTest.php
```

Tests are in `tests/unit/` and `tests/` (root). Add tests for new functionality where practical.

## Pull Request Guidelines

1. **One feature/fix per PR** - keep changes focused.
2. **Include tests** when adding new functionality or fixing bugs.
3. **Update documentation** if changing API behavior or adding features.
4. **Follow existing patterns** - match the style of surrounding code.
5. **No breaking changes** without discussion - open an issue first for architectural changes.

### Commit Messages

- Use present tense ("Add feature" not "Added feature")
- Reference issues when applicable (`#123`)

## Security

If you discover a security vulnerability, please do **not** open a public issue. Instead, see our [Security Policy](SECURITY.md) for responsible disclosure instructions.

## License

By contributing to rcloneCWP, you agree that your contributions will be licensed under the [MIT License](LICENSE).

rcloneCWP is provided as-is with no warranties. Use in production environments is at your own risk. Always test changes in a staging environment before deploying to production servers.
