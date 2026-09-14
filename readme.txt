=== DiluxOne Mail – SMTP, Email Log & Deliverability Diagnostics ===
Contributors: soydiloreto
Tags: smtp, email log, deliverability, spf dkim dmarc, wp mail
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through your providers, with a fallback, log every message — one history per person — and find out why it is not arriving.

== Description ==

WordPress sends mail through the PHP function that hands it to the operating
system, and on today's hosting that reaches nobody: with no server to
authenticate it, Gmail and Outlook drop it without telling anyone. The site
works, the form says thank you, and the email never existed.

Connecting an SMTP server is something eight plugins already do, and do well.
This one does it too — over SMTP or over the provider's own API, with as many
providers configured as you like and a fallback when the first one will not
take a message. That part is solid and boring, because it is the floor, not
the product. The two things it does that no other plugin does are the reason
it exists.

= A mail history on every user's profile =

Every competitor shows one global list of sent messages. None of them lets you
open a user in the dashboard and see what was sent to *that person*: date,
subject and status. When somebody writes "I never got the email", the answer is
on their profile, not in a list of ten thousand rows.

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

= As many providers as you want, in the order you want =

Providers are a list, and the list is the whole configuration: the one at the
top sends, and if it refuses a message the next one is asked, in order, until
one of them takes it or there is nobody left. Drag a row to change who sends —
there is no "primary" dropdown, because the order already said it.

Each one is set up in four steps that unlock as you go: the method and the
provider, the credentials, the sender address, and a real test message. A
provider that has not sent that message is not in service, so a half-filled
form can never become the thing your site sends through.

Every attempt is a row in the log, on purpose. A message that went out through
the second provider after the first refused it is two events, and a log that
showed one of them would be hiding the reason the site has a second provider
at all.

= Three levels of logging =

* **Log** (on by default): date, recipients, sender, subject, outcome, error,
  provider, and which plugin sent it. This is what the user profile shows.
* **Extended log** (off): also the headers, attachment names and the full SMTP
  conversation with the provider — what you need when arguing with their support.
* **The content of the messages is never stored**, and there is no setting to
  turn that on. A log that kept bodies would be keeping every password-reset
  link the site has ever sent, and a reset link is not a record of what
  happened: it is a key to the account, valid for whoever reads the table next.

= Multisite =

On a network the mail server belongs to the network: settings live under
Network Admin → Settings and apply to every site, read-only, unless the network
allows sites to override them. The log is one table for the whole network, and
a person's profile shows their mail from every site.

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

There is one set of those constants and a list of providers, so they describe
the one at the top — the one that sends. Anything below it is configured on
its own screen and used as written, which is the only way a fallback can work:
a second provider reached with the first one's password is the one setup
guaranteed to fail, at the exact moment the site is counting on it.

== Frequently Asked Questions ==

= Does it store the content of my emails? =

No, and there is no option to. What is kept is who was written to, when, about
what, and how it went — never the message itself.

The reason is not only that a body is personal data. The mail WordPress sends
most often is the password reset, and that link is a key to the account for as
long as it is valid: anybody who can read the database, restore a backup or log
in as an administrator would be able to take over accounts without knowing a
single password. An earlier version of this plugin could be told to store
bodies; updating removes the column and everything that was in it.

= SMTP or the provider's API? =

Both, and you choose on the first step. **SMTP** works with every provider
listed and with any other one: picking a profile only fills in the host, the
port and the encryption for you, and "Other SMTP server" is there for anything
not on the list.

**The provider's API** goes out over HTTPS with a key instead of a server. Two
reasons to prefer it: plenty of hosts block outbound ports 587 and 465, and on
those SMTP simply does not work while HTTPS does; and when a message is
refused, an API answers with a sentence — the domain is not verified, the
sender is not allowed — where SMTP answers `535` and leaves you guessing.

Not every provider has one here. The API path covers Mailtrap, SendGrid,
Postmark, Brevo, Resend and Mailjet — the ones whose send is one header and
one JSON body. The ones that sign every request (Amazon SES, Azure
Communication Services) or need an OAuth consent screen (Microsoft 365, Gmail)
stay on SMTP. Choosing the API of a provider that has none is refused on the
spot rather than falling back quietly.

Nothing stops you from having both: the same provider over its API at the top
of the list and over SMTP underneath it is a reasonable fallback, and so is
two different providers.

= Where does the SMTP password end up? =

Encrypted, with AES-256-GCM and a key derived from your site's own WordPress
salts, so a database dump or a backup taken elsewhere cannot read it. Rotating
the salts makes it unreadable, which is the point; the settings screen says so
and asks you to type it again rather than failing at the next send.

Better than encrypting it is not storing it: define `DILUXONE_MAIL_PASS` in
`wp-config.php`, or set it as an environment variable, and the plugin reads it
from there. The field then shows as read-only and says where the value comes
from. Host, port, encryption, username and the sender address work the same
way.

Encryption at rest is worth being honest about: it protects the credential
where it travels — dumps, backups, staging copies — not from code running on
the site, which can always ask the plugin for it.

= Where is all of this stored? =

The list of providers is one WordPress option, `diluxone_mail_connections`:
one record per provider, each with its own host, credential and sender, in the
order the screen shows them. Removing a provider removes its record and
nothing else, and reordering rewrites only the order.

Passwords and API keys inside those records are encrypted; everything else is
stored as you typed it. The log lives in two tables of its own.

Deleting the plugin leaves all of it alone unless you tick "Delete the mail
log and every setting when the plugin is deleted" under Log and privacy.
Deleting a plugin to reinstall it is something people do, and a year of mail
history that disappears because of that would be this plugin's doing.
Deactivating never removes anything either way.

= Do I have to uninstall my current SMTP plugin? =

No. Install this one and it will detect the other, stay out of the way, and
start logging and diagnosing immediately.

= Does it send email through its own service? =

No, and it never will. It connects to the provider you already have.

== Screenshots ==

1. Every message sent to one person, on their own user profile.
2. The deliverability diagnosis in prose: SPF lookup count, DKIM selectors and DMARC policy, each with what it means.
3. The mail log: one row per recipient, filterable by status, with the real SMTP error on a failure.
4. One message in full — recipients, headers and the SMTP conversation that delivered it.
5. Provider settings. Picking a profile fills in host, port and encryption; values the environment sets are read-only.
6. Status: who sends the mail, where each value comes from, and what happened on the last send.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
