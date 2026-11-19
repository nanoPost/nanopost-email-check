# nanoPost Email Check

A WordPress plugin that checks that your site can still send email. It sends a test email on
a schedule, logs each result, and warns you in wp-admin when a check fails or when the checks
stop running. An optional webhook sends failures to Slack, Google Chat or any other receiver.

- Documentation: https://nanopo.st/email-check-plugin/
- License: GPLv2 or later

`readme.txt` is the full description used by WordPress.org.

## Development

Requirements: Docker, Node.js and Composer.

```sh
composer install          # PHPCS with the WordPress Coding Standards
npm install               # wp-env
npm run env:start         # WordPress with the plugin and Plugin Check
composer lint             # coding standards
npm run plugin-check      # Plugin Check
npm run zip               # release zip from tracked files (git archive)
```

Bug reports and pull requests are welcome.
