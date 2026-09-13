=== DiluxOne Mail – SMTP, Email Log & Deliverability Diagnostics ===
Contributors: soydiloreto
Tags: smtp, email log, deliverability, spf dkim dmarc, wp mail
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through any SMTP provider, log every message — one history per person — and find out why your mail is not arriving.

== Description ==

WordPress sends mail through the PHP function that hands it to the operating
system, and on today's hosting that reaches nobody: with no server to
authenticate it, Gmail and Outlook drop it without telling anyone. The site
works, the form says thank you, and the email never existed.

Connecting an SMTP server is something eight plugins already do, and do well.
This one does it too — solid and boring, because it is the floor, not the
product. The two things it does that no other plugin does are the reason it
exists.

= A mail history on every user's profile =

Every competitor shows one global list of sent messages. None of them lets you
open a user in the dashboard and see what was sent to *that person*: date,
subject, status, and a resend button. When somebody writes "I never got the
email", the answer is on their profile, not in a list of ten thousand rows.

The log is indexed by email address rather than by user ID, on purpose: sites
send mail to addresses that belong to no user, and people change their address.

= A DNS diagnosis, in plain language =

No plugin on the market reads your domain's SPF, DKIM and DMARC records to tell
you what is broken. This one does:

* **SPF** — expands every `include` recursively and counts DNS lookups against
  the standard's limit of 10. Going over makes the whole SPF record fail, and
  nothing warns you. It also flags providers your record still declares but no
  configured transport uses, as candidates for deletion.
* **DKIM** — selectors cannot be enumerated over DNS, so it probes the known
  ones, plus whatever the active provider profile declares, plus any you add.
* **DMARC** — presence, policy, where the reports go, and what the policy
  actually implies: with `p=quarantine` or `p=reject` you need SPF or DKIM
  alignment, and it tells you whether you have it.

Hosts that disable `dns_get_record()` are common, so it falls back to
DNS-over-HTTPS automatically. Results are cached, with a revalidate button.

= Observer mode =

If another plugin is already handling your site's email, this one does not
fight it. It logs and diagnoses without touching delivery, and says so clearly,
with a button to take over when you want it to. A site whose mail works should
not have to break to try something new.

= Configuration comes from the environment =

Host, port, user, password, encryption and sender can come from PHP constants
or environment variables, which take precedence over anything stored in the
database. Production credentials live in your hosting's variables and never
touch the database or your repository, and the same code works locally and in
production without editing settings on every deploy.

== Frequently Asked Questions ==

= Does it store the content of my emails? =

Not by default. The body of a message is personal data, and storing it is what
turns a technical log into a legal problem. It is a separate checkbox, off,
with its own shorter retention period.

= Do I have to uninstall my current SMTP plugin? =

No. Install this one and it will detect the other, stay out of the way, and
start logging and diagnosing immediately.

= Does it send email through its own service? =

No, and it never will. It connects to the provider you already have.

== Screenshots ==

1. Provider settings, showing which values come from the environment.
2. The mail history of a single person, on their user profile.
3. The DNS diagnosis: SPF lookup count, DKIM selectors and DMARC policy.
4. Status: active profile, detected environment and observer mode.

== Changelog ==

= 1.0.0 =
* Initial release.
