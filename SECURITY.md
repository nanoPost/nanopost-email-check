# Security policy

## Supported versions

Only the latest release gets security fixes. Please check that the problem is present in it.

## How to report

Report security problems privately, through GitHub:
https://github.com/nanoPost/nanopost-email-check/security/advisories/new

Do not open a public issue or pull request for a security problem.

Please include:

- the plugin version, the WordPress version and the PHP version
- single site or multisite
- the steps to reproduce the problem
- what an attacker can do with it, and what access they need

## What happens next

- We reply within 7 days.
- We tell you when a fix is ready and when it is released.
- With your permission, we name you in the changelog.
- Please wait for the fixed release before you publish details.

## Scope

In scope: the code in this repository.

Out of scope:

- Actions that need the `manage_options` capability on a single site. Site administrators
  are trusted. On multisite, problems that let a site administrator affect the network or
  other sites are in scope.
- Problems in WordPress core, in PHP, or in a mail plugin that this plugin uses through
  `wp_mail()`. Report those to their maintainers.
- Problems that need an attacker to change the server files or the database directly.
