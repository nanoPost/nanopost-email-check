=== nanoPost Email Check ===
Contributors: nanopost
Tags: email, test email, wp_mail, email monitoring, smtp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email not sending? Run scheduled email tests, log each result and get admin notices when a check fails or scheduled checks stop running.

== Description ==

WordPress can stop sending email while the rest of your site keeps working. A host might disable PHP's `mail()` function, an SMTP password might change, or a plugin update might reset your mail settings. You may only notice when a customer reports a missing order confirmation.

nanoPost Email Check sends a test email on a schedule and logs each result. It shows a notice in wp-admin when a check fails or the scheduled checks stop running.

= What to expect =

1. **Set up on one screen.** Choose the mailbox for the test emails and how often to check, then press **Send test now** to see a first result.
2. **Then it runs on its own.** A short test email is sent on schedule, daily by default. The plugin logs every result.
3. **When sending breaks,** a notice in wp-admin shows the error and links to an explanation of it. With a webhook set, a message also goes to Slack, Google Chat, Discord or another service that accepts a webhook, so the alert does not depend on the email that just failed.
4. **When it is fixed,** the next passing check clears the notice, and the webhook sends a recovery message.
5. **If the checks themselves stop,** for example because WP-Cron is not running, a separate notice tells you.

= Who it is for =

* **Site owners** who want to hear about a mail problem before a customer does.
* **Shops and lead-form sites,** where a lost order confirmation or enquiry is lost business.
* **Agencies and maintainers,** who want a message in the team chat when a client site stops sending, and who set up many sites with WP-CLI or `wp-config.php`.

= How it differs from SMTP and email log plugins =

SMTP and email log plugins send and record the site's real mail, and some of them alert when a message fails. Those logs and alerts concern messages the site actually sends. Email Check sends its own test on a schedule, so it can check on a day the site sends nothing else, and it tells you when the tests themselves have stopped. The test goes through `wp_mail()`, so an SMTP plugin that handles `wp_mail()` sends it through its configured mailer. Email Check does not replace or configure the mailer.

= Features =

* **Scheduled test email:** twice daily, daily (the default) or weekly.
* **Send test now:** send a test email immediately from the plugin screen, for example right after you change the mail settings.
* **Result log:** the last 30 results, with the time, what started the check (the schedule, a user or WP-CLI), pass or fail, the recipient and the error text.
* **Notice when a check fails,** for administrators, with the error and a link that explains it. It clears by itself when the next check passes. Each user can dismiss it, and a new failure shows it again.
* **Notice when the checks stop:** if no scheduled check has finished for twice the interval. The usual causes are WP-Cron not running, or a check that crashes with a PHP error.
* **Optional chat alerts:** post failures to Slack, Google Chat, Discord or any service that accepts a webhook, so you can receive alerts even when WordPress cannot send email. It also sends a recovery message when a check passes after a failure.
* **Send test alert:** check the webhook without waiting for a real failure.
* **Troubleshooting help:** links from failed or stopped checks to help pages on nanopo.st, including an error decoder.
* **WP-CLI:** `wp email-check` changes the settings, runs a check, shows the status and sends a test alert, with exit codes for scripts.
* **Settings in code:** set the recipient, interval and webhook URL in `wp-config.php`. The plugin screen shows them read-only.
* **Mailer shown:** each result records how the site sends: SMTP server and port, PHP `mail()`, or another plugin, and which plugin set it up. When PHP `mail()` fails because the sendmail program is missing, the error says so.
* **Multisite:** each site runs its own checks, with its own settings, log and notices.
* **Optional Email Canary:** turn it on (opt-in) to see whether each test email arrived at nanoPost, with its SPF, DKIM and DMARC results, in the log, in a notice and in Site Health, and to get an email when a test email does not arrive.
* **No outside requests** unless you set a webhook or turn on Email Canary.

= What a pass means =

Each check sends a short test message through `wp_mail()`, WordPress's email function. The plugin records whether the call succeeded. If it fails, the log includes any error text reported through `wp_mail_failed`, such as `SMTP Error: Could not authenticate.`

A successful check does not confirm delivery. Check the recipient mailbox to make sure the test message arrived.

The plugin screen is at **Tools > Email Check**.

The plugin is free, with no paid version. Email Canary, the optional service, is free in this early period.

Full documentation: [nanopo.st/email-check-plugin](https://nanopo.st/email-check-plugin/). Source code: [GitHub](https://github.com/nanoPost/nanopost-email-check).

Writing about the plugin? The [reviewer's guide](https://nanopo.st/email-check-plugin/reviewers/) has the facts, a test-site walkthrough and a press kit.

== Installation ==

1. Install the plugin from **Plugins > Add Plugin** (**Add New Plugin** in older WordPress versions) and activate it, or upload the `nanopost-email-check` folder to `/wp-content/plugins/`.
2. Go to **Tools > Email Check**.
3. Enter the address that should receive the test emails, or leave it empty to use the site's Administration Email Address. Use a mailbox someone reads, at a different provider from the site if you can. To send each test to more than one address, enter up to 3 addresses separated by commas.
4. Choose how often to check, and save.
5. Press **Send test now** and check that the email arrives.

The first scheduled check runs one interval after activation. Changing the schedule starts the count again.

With WP-CLI:

    wp plugin install nanopost-email-check --activate
    wp email-check settings set recipient alerts@example.com
    wp email-check run

See "Can I set it up from the command line?" below.

== Frequently Asked Questions ==

= Does a pass mean my email is delivered? =

No. A pass means WordPress handed the message to its mail system without an error. The message can still bounce or go to spam after that. The test email in your inbox is the proof of delivery. Some plugins also make every check pass: a plugin that disables email on staging sites, or a queue plugin that accepts messages and sends them later.

= Does the plugin send email to my users or customers? =

No. It sends test emails only to the address or addresses you set (at most 3).

= Does the plugin send data to nanoPost? =

Only if you turn on Email Canary on the plugin screen. Until then, nothing is sent to nanoPost. See "External services" below for what the site sends while Email Canary is on.

= What does Email Canary add? =

Email Check can tell you that WordPress accepted the test email. Email Canary checks that it arrived. While it is on, each test email goes to Email Canary instead of the recipient you set. The plugin screen then shows, for each test, whether it arrived at nanoPost, with its SPF, DKIM and DMARC results, and Email Canary emails you when a test email does not arrive or does not pass. Type an alert address and click "Turn on Email Canary": a test email goes out at once, and you confirm the address by email. Until you confirm, alerts are off. Email Canary is optional, and the plugin works in full without it. Turn it off on the plugin screen at any time: test emails then go to your recipient again.

= The notice says the checks have stopped. What does that mean? =

No scheduled check has finished for twice the interval. Usually WP-Cron is not running them: it runs when people visit the site, so on a site with few visitors, or when `DISABLE_WP_CRON` is set without a server cron job, scheduled events stop. Less often the check starts but crashes, for example with a PHP error in a mail plugin; the PHP error log then shows the cause. The help link in the notice explains how to fix it.

= Can I get alerts somewhere other than email? =

Yes. Enter a webhook URL on the plugin screen. Slack and Google Chat incoming webhooks work directly. For Discord, add `/slack` to the end of the webhook URL. Automation services can pass the message on to SMS, Microsoft Teams or other tools.

= Can I set it up from the command line? =

Yes, with WP-CLI. The `wp email-check` command has these subcommands:

* `wp email-check settings get` and `wp email-check settings set <key> <value>`: show or change a setting. The keys are `recipient`, `interval` (`twicedaily`, `daily` or `weekly`) and `webhook_url`. A value is checked with the same rules as the plugin screen. An invalid value changes nothing and exits with 1.
* `wp email-check run`: send a test email now. The log shows "WP-CLI" as what started it. Exits with 0 when the check passed and 1 when it failed.
* `wp email-check status`: show whether the checks run and pass, and whether WP-Cron is disabled or has stopped running events. Add `--format=json` for scripts. Exits with 0 when all is well, 1 when the last check failed, 2 when scheduled checks have stopped or are not scheduled, and 3 when no check has run yet.
* `wp email-check test-alert`: send a test alert to the saved webhook URL. Exits with 0 when the receiver accepted it, and 1 otherwise.

`run` and `test-alert` have no once-a-minute limit. On multisite, choose the site with `--url`. For details, run `wp help email-check`, or read [settings in code and WP-CLI](https://nanopo.st/email-check-plugin/code-and-cli/).

= Can I turn on Email Canary on many sites at once? =

Yes, with an account key. Make one in nanoPost (Settings > Profile > Account key), then add `define( 'NANOPOST_EMAIL_CHECK_KEY', '...' );` to `wp-config.php`, or run `wp email-check connect --key=...` on each site. Email Canary then turns on by itself, with alerts to your nanoPost account's address and no confirm email. The key only adds sites; it cannot read your account. It turns a site on once: not again after you turn it off, and not on a copy of the site at another address or on a site address that is not public. On a multisite network, the constant turns on only the main site; use WP-CLI with `--url` for the other sites.

= Does it work on multisite? =

Yes. Each site runs its own checks, with its own settings, log and notices. Network activation schedules the checks on every site, and on each new site. On a network of more than 10,000 sites, each site is scheduled on its next request instead. On a network where all sites use the same mail setup, activating the plugin on the main site is enough: network activation then sends one test email per site, which repeats the same check.

= Can I set the settings in wp-config.php? =

Yes. Add any of these constants to `wp-config.php` or a must-use plugin:

    define( 'NANOPOST_EMAIL_CHECK_RECIPIENT', 'alerts@example.com' );
    define( 'NANOPOST_EMAIL_CHECK_INTERVAL', 'daily' ); // twicedaily, daily or weekly
    define( 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL', 'https://hooks.slack.com/services/...' );

A value set in code wins over the plugin screen, and the screen shows it read-only. It must pass the same checks as the screen: an invalid value is ignored, and the plugin screen says so. On multisite, a constant applies to every site. Developers can also use the `nanopost_email_check_settings` filter, which gets the array of settings (`recipient`, `interval`, `webhook_url`).

= What happens to the data when I delete the plugin? =

Deleting the plugin removes its settings, the log, the webhook state, the dismissed-notice records and the scheduled event. With WP-CLI, use `wp plugin uninstall nanopost-email-check --deactivate`: `wp plugin delete` removes only the files and leaves the data in the database.

== External services ==

The plugin makes no outside requests unless you set a webhook URL or turn on Email Canary.

= nanoPost Email Canary (optional, after you turn it on) =

nanoPost Email Canary (app.nanopo.st) is a service run by the plugin's authors. It checks that each test email arrives, and emails you when one does not. The plugin calls it only after you type an alert address and click "Turn on Email Canary" on the plugin screen, or after you set a nanoPost account key in `wp-config.php` or run `wp email-check connect`. Until then, nothing is sent. When you turn it off, deactivate the plugin or delete it, the plugin tells Email Canary.

While Email Canary is on, the site sends:

* when you turn it on: the site address, the alert address you typed, the site's time zone, the test interval, the webhook URL if you set one, the versions of the plugin, WordPress and PHP, and a random key that the plugin makes for this site;
* if you set a nanoPost account key (in `wp-config.php` or with WP-CLI) in place of an alert address: that key, once, in the call that turns Email Canary on. The plugin does not store it;
* the same site settings again when one of them changes;
* for each test: a test ID, the time it was sent, what started it (the schedule, a user or WP-CLI), whether WordPress accepted the email, and its error text if it did not (at most 500 characters; it can contain host names or email addresses);
* a request for the result of each test, after it is due, and for the state of the site when you open the plugin screen;
* when you resend the confirm email or change the alert address: that address;
* when you turn it off or on, deactivate or activate the plugin: which of these happened;
* the plugin version in every call.

The test emails go to Email Canary's address for the site instead of the recipient you set. Email Canary receives each test email and its headers, as for any email. nanoPost keeps the IP address of each call for 30 days. The plugin sends no user names or other user data, no other email, and no other settings. The alert address is sent only as you typed it.

* Terms of use: https://nanopo.st/terms/#the-app-appnanopost
* Privacy policy: https://nanopo.st/privacy/#the-app-appnanopost

= Webhook (optional) =

When you enter a webhook URL, the plugin sends an HTTP POST to that URL when a check fails, and once when checks pass again after a failure. The JSON body holds a short text line, the site address, the status (fail or pass), the error text and the time of the check. For a Google Chat URL, only the text line is sent. The request goes only to the URL you enter, and it is governed by the terms and privacy policy of the service that URL belongs to. Leave the field empty to send nothing.

The "Send test alert" button on the plugin screen, or `wp email-check test-alert`, sends one test message to the webhook URL, only when you use it. Its text says that it is a test and that no check failed. It has the same fields as a failure message, plus `"test": true`, so a receiver can handle it like a real alert or skip it. Real alerts do not have the `test` field. For a Google Chat URL, only the text line is sent. Example:

`{"text": "[Email Check] Test alert from example.com. This is only a test of the webhook. No check failed.", "site": "https://example.com", "status": "fail", "error": "Test alert. No check failed.", "time": "2026-11-24T09:30:00+00:00", "test": true}`

The setup help names these services. The plugin sends to them only when you enter one of their webhook URLs:

* Slack incoming webhooks: [terms](https://slack.com/terms-of-service), [privacy policy](https://slack.com/trust/privacy/privacy-policy)
* Google Chat webhooks: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy)
* Discord webhooks: [terms](https://discord.com/terms), [privacy policy](https://discord.com/privacy)

= Help pages on nanopo.st =

The plugin screen and notices link to help pages on nanopo.st. These are plain links: nothing is sent until you click one. When you click the help link for an error, the error text is put in the link after the `#`. Your browser does not send that part of the address to the server; the nanopo.st page reads it in your browser.

* Terms of use: https://nanopo.st/terms/
* Privacy policy: https://nanopo.st/privacy/

== Screenshots ==

1. The plugin screen at Tools > Email Check: the Email Check and Email Canary areas, the settings and the recent tests.
2. The notice in wp-admin when the site could not send its test email, with a link to decode the error.
3. The notice when scheduled tests have stopped running.
4. An alert in a chat app when the site could not send its test email, sent through the optional webhook.

== Changelog ==

= 1.9.1 =
* Removed what was left of the 1.7 browser connect. nanoPost no longer serves it, and Email Canary is turned on from the plugin screen or with an account key.

= 1.9.0 =
* Account key for agencies: `NANOPOST_EMAIL_CHECK_KEY` in `wp-config.php` turns Email Canary on by itself, once per site address, with alerts to the nanoPost account's address and no confirm email. The key is never stored.
* New WP-CLI command `wp email-check connect`, with `--key` or `--email`.

= 1.8.0 =
* Email Canary replaces "Connect to nanoPost": type an alert address and turn it on from the plugin screen. No sign-up in the browser. A test email goes out at once, with its proof of arrival.
* The plugin screen, notices and Site Health show the state from Email Canary: whether each test email arrived, with SPF, DKIM and DMARC as labels, also for scheduled tests.
* Resend or change the alert address, and turn Email Canary off and on again, on the plugin screen.
* A deactivation tells Email Canary to stop watching; an activation starts it again.
* While Email Canary is not watching (before the confirm, or off), the plugin's own webhook alerts as when it is not on.
* The plugin's webhook messages start with "[Email Check]" and name the site, for example "[Email Check] example.com could not send its test email: ...". The JSON fields do not change.
* Sites connected with 1.7 stay on, with no new confirm.

= 1.7.0 =
* Optional connection to nanoPost (opt-in): connect the site on the plugin screen and approve it in your browser. While connected, each test email goes to nanoPost, which records whether it arrived, with its SPF, DKIM and DMARC results, and alerts you when a test does not arrive. Nothing is sent before you connect. See "External services" for what a connected site sends.
* Proof of arrival: after "Send test now", a connected site shows whether the test email arrived at nanoPost, with its SPF, DKIM and DMARC results.
* While connected, the plugin's own webhook stays quiet for failures that nanoPost alerts on, and alerts only when it cannot reach nanoPost.
* WP-CLI: `wp email-check status` shows the nanoPost connection; `wp email-check run` names the test ID and the check page.
* Disconnect at any time on the plugin screen. Deleting the plugin also ends the connection.

= 1.6.0 =
* The recipient setting takes up to 3 addresses, separated by commas, for example your own address and a monitoring address. Each test is one email to all of them. The same rules apply on the plugin screen, in WP-CLI and in the `NANOPOST_EMAIL_CHECK_RECIPIENT` constant.

= 1.5.0 =
* WP-CLI: `wp email-check status` says whether WP-Cron is disabled in `wp-config.php`, and whether an event has been due for more than 10 minutes, which shows that nothing has run WP-Cron since then.
* WP-CLI: `wp email-check run` says what it sends before it sends. When PHP `mail()` fails because the sendmail program is missing, it explains the shell's own error line.

= 1.4.0 =
* Mailer: Status, the log and `wp email-check status` name the plugin that set up the mailer, for example "Sends through SMTP smtp.example.com:587, set up by WP Mail SMTP". The plugin is named only when all other `phpmailer_init` callbacks belong to one plugin.

= 1.3.3 =
* Mailer: a plugin that sends through its own PHPMailer class (for example by an API) is shown as handling the sending, not as PHP `mail()`, and gets no sendmail hint.

= 1.3.2 =
* WP-CLI: `wp help email-check status` lists the `not-checked` state and exit code 3.

= 1.3.1 =
* WP-CLI: `wp email-check status` reports `not-checked` and exits with 3 when no check has run yet, so a script can tell it from a passing check.
* WP-CLI: help links printed by the command are tagged as coming from WP-CLI.
* Readme: with WP-CLI, `wp plugin uninstall` removes the plugin's data; `wp plugin delete` removes only the files.

= 1.3.0 =
* WP-CLI: `wp email-check` with `settings get` and `settings set`, `run`, `status` and `test-alert`, with exit codes for scripts. A check started this way shows "WP-CLI" in the log.
* Settings in code: the constants `NANOPOST_EMAIL_CHECK_RECIPIENT`, `NANOPOST_EMAIL_CHECK_INTERVAL` and `NANOPOST_EMAIL_CHECK_WEBHOOK_URL`, and the `nanopost_email_check_settings` filter. The plugin screen shows these settings read-only.
* The mailer of each check (SMTP server and port, PHP `mail()`, or another plugin) is shown in Status and in the log.
* When PHP `mail()` fails because the sendmail program is missing, the error names it.

= 1.2.1 =
* Multisite: network activation now schedules the checks on every site, and on each new site. Sites left without a schedule by an earlier network activation are scheduled on their next request.
* WP-CLI: a setting that is refused, for example an invalid recipient address, now prints a warning.

= 1.2.0 =
* Send test alert: a button under the webhook URL posts a test message to the saved URL, at most once a minute per site. The message says that it is a test, and its JSON has `"test": true`. The webhook status shows when the last message was a test.
* A link to rate the plugin on WordPress.org, at the bottom of the plugin screen.

= 1.1.0 =
* Send test now runs at most once a minute per site.
* Error text is cut at 4,000 characters in the log, the notices and the webhook.
* Webhook messages escape the error text, so it cannot add links or mention everyone in Slack, Google Chat, Discord, Mattermost or Rocket.Chat.

= 1.0.0 =
* First release on WordPress.org. Rebuilt from Daily Email Tester 0.1.
* Scheduled test email with a choice of interval, and Send test now.
* Result log with the error text from wp_mail_failed.
* Admin notices for failed and stopped checks.
* Optional webhook alert.
* Help links to nanopo.st.
