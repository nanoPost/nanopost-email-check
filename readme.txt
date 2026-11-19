=== nanoPost Email Check ===
Contributors: nanopost
Tags: email, wp_mail, smtp, email test, email log
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check that your site can still send email. Sends a scheduled test email, logs each result and warns you in wp-admin when a check fails.

== Description ==

A site can lose its email without any sign on the front end. A host switches off PHP's `mail()`, an SMTP password changes, an API key is revoked, a plugin update resets the mailer settings. The pages still load. The first report is usually a customer asking where their order confirmation went.

nanoPost Email Check sends a test email on a schedule, logs every result, and puts a notice in wp-admin when a check fails or when the checks stop running. It finds the break on the day it happens.

= What it checks =

Each check sends a short test message with `wp_mail()`, the function WordPress and well-behaved plugins use to send email. The plugin records whether `wp_mail()` accepted the message. When it did not, it also records the error text WordPress reported through the `wp_mail_failed` hook, for example `SMTP Error: Could not authenticate.`

A pass means the mail system WordPress uses accepted the message. It does not prove the message reached an inbox: read the test email in the recipient mailbox to confirm that.

= Features =

* **Scheduled checks:** twice daily, daily (the default) or weekly, on WP-Cron.
* **Send test now:** run a check at once from the plugin screen.
* **Result log:** the last 30 results, with the time, what started the check, pass or fail, the recipient and the error text.
* **Admin notice when a check fails**, for users who can manage options. It clears by itself when the next check passes, and each user can dismiss it. A new failure shows it again.
* **Admin notice when the checks stop:** if no scheduled check has finished for twice the interval, the plugin tells you. The usual causes are WP-Cron not running, or a check that crashes with a PHP error.
* **Optional webhook:** post failures to Slack, Google Chat, Discord or any service that accepts a webhook, so the alert does not depend on the email that just broke. One more message is sent when checks pass again.
* **Help links** for failed and stopped checks, to plain-language help pages on nanopo.st, including an error decoder.

The plugin screen is at **Tools > Email Check**.

Full documentation: [nanopo.st/email-check-plugin](https://nanopo.st/email-check-plugin/). Source code: [GitHub](https://github.com/nanoPost/nanopost-email-check).

== Installation ==

1. Install the plugin from **Plugins > Add New** and activate it, or upload the `nanopost-email-check` folder to `/wp-content/plugins/`.
2. Go to **Tools > Email Check**.
3. Enter the address that should receive the test emails, or leave it empty to use the site's Administration Email Address. Use a mailbox someone reads, at a different provider from the site if you can.
4. Choose how often to check, and save.
5. Press **Send test now** and check that the email arrives.

The first scheduled check runs one interval after activation. Changing the schedule starts the count again.

== Frequently Asked Questions ==

= Does a pass mean my email is delivered? =

No. A pass means WordPress handed the message to its mail system without an error. The message can still bounce or go to spam after that. The test email in your inbox is the proof of delivery. Some plugins also make every check pass: a plugin that disables email on staging sites, or a queue plugin that accepts messages and sends them later.

= Does the plugin send email to my users or customers? =

No. It sends test emails only to the one address you set.

= Does the plugin send data to nanoPost? =

No. See "External services" below.

= The notice says the checks have stopped. What does that mean? =

No scheduled check has finished for twice the interval. Usually WP-Cron is not running them: it runs when people visit the site, so on a site with few visitors, or when `DISABLE_WP_CRON` is set without a server cron job, scheduled events stop. Less often the check starts but crashes, for example with a PHP error in a mail plugin; the PHP error log then shows the cause. The help link in the notice explains how to fix it.

= Can I get alerts somewhere other than email? =

Yes. Enter a webhook URL on the plugin screen. Slack and Google Chat incoming webhooks work directly. For Discord, add `/slack` to the end of the webhook URL. Automation services can pass the message on to SMS, Microsoft Teams or other tools.

= Does it work on multisite? =

Yes. Each site runs its own checks, with its own settings, log and notices. When the plugin is network-activated, each site schedules its checks the first time an administrator opens wp-admin on that site. On a network where all sites use the same mail setup, activating the plugin on the main site is enough: network activation then sends one test email per site, which repeats the same check.

= What happens to the data when I delete the plugin? =

Deleting the plugin removes its settings, the log, the webhook state, the dismissed-notice records and the scheduled event.

== External services ==

The plugin makes no outside requests unless you set a webhook URL.

= Webhook (optional) =

When you enter a webhook URL, the plugin sends an HTTP POST to that URL when a check fails, and once when checks pass again after a failure. The JSON body holds a short text line, the site address, the status (fail or pass), the error text and the time of the check. For a Google Chat URL, only the text line is sent. The request goes only to the URL you enter, and it is governed by the terms and privacy policy of the service that URL belongs to. Leave the field empty to send nothing.

The setup help names these services. The plugin sends to them only when you enter one of their webhook URLs:

* Slack incoming webhooks: [terms](https://slack.com/terms-of-service), [privacy policy](https://slack.com/trust/privacy/privacy-policy)
* Google Chat webhooks: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy)
* Discord webhooks: [terms](https://discord.com/terms), [privacy policy](https://discord.com/privacy)

= Help pages on nanopo.st =

The plugin screen and notices link to help pages on nanopo.st. These are plain links: nothing is sent until you click one. When you click the help link for an error, the error text is put in the link after the `#`. Your browser does not send that part of the address to the server; the nanopo.st page reads it in your browser.

* Terms of use: https://nanopo.st/terms/
* Privacy policy: https://nanopo.st/privacy/

== Screenshots ==

1. The plugin screen at Tools > Email Check: status, settings, and the result log.
2. The notice in wp-admin when a check fails, with a link to decode the error.
3. The notice when scheduled checks have stopped running.

== Changelog ==

= 1.0.0 =
* First release on WordPress.org. Rebuilt from Daily Email Tester 0.1.
* Scheduled test email with a choice of interval, and Send test now.
* Result log with the error text from wp_mail_failed.
* Admin notices for failed and stopped checks.
* Optional webhook alert.
* Help links to nanopo.st.
