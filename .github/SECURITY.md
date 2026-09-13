# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.0.x   | Yes       |

## Reporting a Vulnerability

If you discover a security vulnerability in rcloneCWP, please report it responsibly:

**Do NOT open a public GitHub issue.**

Instead, email the maintainers directly or use GitHub's private vulnerability reporting feature.

### What to include

- Description of the vulnerability
- Steps to reproduce
- Potential impact assessment
- Suggested fix (if any)

### Response timeline

- **Acknowledgment**: within 48 hours
- **Initial assessment**: within 1 week
- **Fix or mitigation**: depends on severity, typically within 2 weeks for critical issues

## Security Measures

rcloneCWP implements the following security controls:

### Data Protection

- **AES-256-GCM encryption** for all destination credentials at rest
- **In-memory credential passing** - secrets exist only in PHP memory during rclone execution
- **Zero plaintext on disk** - credentials never written to rclone.conf or temp files

### Input Validation

- All user input validated via `Validator.php` with whitelist approach
- SQL injection prevented with prepared statements on all queries
- XSS prevented with `htmlspecialchars()` on all output
- Command injection prevented with `escapeshellarg()` on all exec calls
- CSRF tokens on all state-changing forms

### Access Control

- Module runs as root via CWP PHP-FPM (required for system operations)
- API key authentication with scoped permissions
- Rate limiting on API endpoints
- Admin-only actions verified via session checks

### File Security

- Configuration files: mode 0600 (owner read/write only)
- Directories: mode 0700 (owner access only)
- Encryption key stored with restrictive permissions
- Off-htdocs placement prevents direct web access to sensitive files

### Network Security

- SSRF protection on URL-type hooks and webhooks
- Private/internal IP blocking for webhook destinations
- TLS enforcement for SMTP connections

## Scope

This security policy covers:

- The rcloneCWP module code
- API endpoints
- CLI tools
- Installation/uninstallation scripts

This policy does NOT cover:

- rclone itself (report to rclone project)
- CWP (CentOS Web Panel) vulnerabilities
- Server-level security (OS, firewall, SSH)

## Disclosure

We follow responsible disclosure principles. Please allow reasonable time for a fix before any public disclosure.
