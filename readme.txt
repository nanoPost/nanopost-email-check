=== nanoPost Email Check ===
Contributors: nanopost
Tags: email, test email, wp_mail, email monitoring, smtp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email not sending? Find out the day it breaks. Sends a scheduled test email, logs each result and warns you in wp-admin when a check fails.

== Description ==

A site can lose its email without any sign on the front end. A host switches off PHP's `mail()`, an SMTP password changes, an API key is revoked, a plugin update resets the mailer settings. The pages still load. The first report is usually a customer asking where their order confirmation went.

nanoPost Email Check sends a test email on a schedule, logs every result, and puts a notice in wp-admin when a check fails or when the checks stop running. It finds the break on the day it happens.

= What to expect =

1. **Set up on one screen.** Choose the mailbox for the test emails and how often to check, then press **Send test now** to see a first result.
2. **Then there is nothing to do.** A short test email arrives on schedule, daily by default. The plugin keeps a log of every result.
3. **When sending breaks,** a notice in wp-admin shows the error and links to a plain-language explanation. With a webhook set, a message also goes to Slack, Google Chat, Discord or another chat app, so the alert does not depend on the email that just broke.
4. **When it is fixed,** the next passing check clears the notice, and the webhook sends one message to say email works again.
5. **If the checks themselves stop,** for example because WP-Cron is not running, a separate notice tells you.

= Who it is for =

* **Site owners** who want to hear about a mail problem before a customer does.
* **Shops and lead-form sites,** where a lost order confirmation or enquiry is lost business.
* **Agencies and maintainers,** who want a message in the team chat when a client site stops sending.

= How it differs from SMTP and email log plugins =

SMTP and email log plugins send and record the site's real mail, and some of them alert on a failure. All of that depends on the site sending something. Email Check sends its own test on a schedule, so it finds a break on a quiet day too, and it tells you when the checks themselves have stopped. It works with any SMTP plugin. It does not replace one.

= Features =

* **Scheduled test email:** twice daily, daily (the default) or weekly.
* **Send test now:** check at once, for example right after you change the mail settings.
* **Result log:** the last 30 results, with the time, what started the check, pass or fail, the recipient and the error text.
* **Notice when a check fails,** for administrators, with the error and a link that explains it. It clears by itself when the next check passes. Each user can dismiss it, and a new failure shows it again.
* **Notice when the checks stop:** if no scheduled check has finished for twice the interval. The usual causes are WP-Cron not running, or a check that crashes with a PHP error.
* **Optional chat alerts:** a webhook to Slack, Google Chat, Discord or any service that accepts one, and one more message when checks pass again.
* **Plain-language help** for failed and stopped checks, on nanopo.st, including an error decoder.
* **Multisite:** each site runs its own checks, with its own settings, log and notices.
* **No outside requests** unless you set a webhook. Nothing is sent to nanoPost.

= What a pass means =

Each check sends a short test message with `wp_mail()`, the function WordPress and well-behaved plugins use to send email. The plugin records whether `wp_mail()` accepted the message. When it did not, it also records the error text WordPress reported through the `wp_mail_failed` hook, for example `SMTP Error: Could not authenticate.`

A pass means the mail system WordPress uses accepted the message. It does not prove the message reached an inbox: read the test email in the recipient mailbox to confirm that.

The plugin screen is at **Tools > Email Check**.

Free. No paid version.

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

Yes. Each site runs its own checks, with its own settings, log and notices. When the plugin is network-activated, each site schedules its checks the first time a request loads that site's admin side, usually when someone opens its dashboard. On a network where all sites use the same mail setup, activating the plugin on the main site is enough: network activation then sends one test email per site, which repeats the same check.

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
4. An alert in a chat app when a check fails, sent through the optional webhook.

== Changelog ==

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
