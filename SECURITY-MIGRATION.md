# RxSource Hub security fixes — development review, 18 November 2026

No live changes or One Source requests were made. Imports remain GET-only.

The importer and administrator viewer now share `private/sourcedata` (a sibling of
`web`, matching the existing private database configuration). Raw downloads go
through `admin/view-json.php` after `hub_require_admin()`, with no-store and
nosniff headers. Symlinks are excluded. The existing retention policy is unchanged.
All six development archives were copied, SHA-256 verified, then removed from the
public directory. Development PHP-FPM runs as `web16:client7`; explicit access
ACLs on the new directory and files permit that user to read/write, with default
ACLs for future files. Other users have no access.

`ijw.php` was already absent in development and is explicitly blocked. The unused
`testgit.php` was deleted and blocked. A PHP source search found no remaining
phpinfo/var_dump/print_r diagnostics. The existing CLI-only developer-task test is
retained; it refuses HTTP access.

Import source saving and fetching both require HTTPS. Certificate and hostname
verification are unconditional; the legacy `verify_tls` database field cannot
turn them off. Initial and redirect protocols are restricted to HTTPS. Verification
failures return a failed fetch without importing the body. No remote write methods
were added.

Session configuration precedes session_start: host-only, browser-session cookies,
Path=/, Secure, HttpOnly, SameSite=Lax, cookie-only IDs and strict session mode.
Lax supports top-level email-link navigation and same-site confirmation forms.

## Deployment procedure (operator; not executed on live)

1. Pause scheduled/manual imports during migration. Install access-denial rules
   before moving archives. **`.htaccess` is ignored by Git**, so explicitly merge
   these rules immediately after `RewriteEngine On` in the live web-root file;
   preserve all other site rules:

   ```apache
   RewriteRule ^sourcedata(?:/|$) - [F,L,NC]
   RewriteRule ^(?:ijw|testgit)\.php(?:/|$) - [F,L,NC]
   ```

   Apache requires mod_rewrite and AllowOverride permitting these directives
   (development uses AllowOverride All). For a server that ignores .htaccess,
   install equivalent deny rules in its virtual host. Do not expose the private
   directory through aliases or symlinks. Purge any cached public archive URLs at
   the reverse proxy/CDN after blocking them.
2. Create `<site>/private/sourcedata` outside the document root, readable/writable
   by the actual PHP-FPM and CLI import accounts. Prefer their site ownership with
   directory mode 0770, file mode 0660, and a restrictive umask/default ACL. The
   development ACL account must not be copied blindly to another server.
3. Copy **all existing** `<site>/web/sourcedata` files into the private directory,
   preserving names and timestamps. Compare SHA-256 checksums and counts, handle
   any collisions without overwriting different contents, then remove all public
   copies. Do not merely deploy the PHP changes or copy development archives to
   live: migrate live's own existing archives. Keep private backups private.
4. Deploy the PHP changes and explicitly delete live `web/ijw.php` and
   `web/testgit.php` even if an incremental upload does not propagate deletions.
   No database migration is needed. Existing HTTP import URLs must be corrected
   to valid HTTPS URLs before resuming imports; do not test by running One Source.
5. Ensure the server has an up-to-date CA trust store for cURL. If a private CA is
   required, install its CA chain via system trust or curl.cainfo; never disable
   peer/hostname verification. Check CLI and PHP-FPM separately. Development uses
   PHP-FPM 8.4; session.auto_start=0. Keep auto-start disabled so bootstrap can set
   cookie options first, and avoid a pool override that weakens them.
6. Require HTTPS at the public virtual host or trusted reverse proxy and redirect
   HTTP to HTTPS there. The development Apache origin is HTTP behind the edge;
   cookie security is deliberately unconditional. Ensure application/email base
   URLs use HTTPS. Never add a forwarded-header-dependent cookie downgrade.
7. Verify file ownership/ACLs against working files. Public PHP files normally
   use 0664. Run php -l on the three changed PHP files; reload PHP/opcache as
   required by the deployment's cache policy. Resume imports after verification.

## Read-only post-deployment checks

Run against the real HTTPS hostname, without cookies, without `-k` or redirect
following. Replace the archive filename with an actual pre-migration filename.
These requests do not trigger imports or consume sign-in links.

```sh
BASE='https://your-hub-hostname'
curl -sS -I "$BASE/sourcedata/actual-old-archive.json"
curl -sS -I "$BASE/sourcedata/"
curl -sS -I "$BASE/sourcedata/probe.txt"
curl -sS -I "$BASE/ijw.php"
curl -sS -I "$BASE/testgit.php"
curl -sS -I "$BASE/admin/view-json.php?raw=1&file=actual-old-archive.json"
curl -sS -I "$BASE/index.php"
curl -sS -I "http://your-hub-hostname/index.php"
```

Archive and retired diagnostic URLs must return 403/404, never content or a 200.
Unauthenticated viewer access must redirect to sign-in or deny access and return
no archive body. The fresh index response must set a session cookie with Secure,
HttpOnly, SameSite=Lax and Path=/, without Domain. HTTP should redirect to HTTPS.
Repeat known archive checks through each public hostname/CDN route. Inspect the
filesystem read-only to confirm the public archive directory has no remaining
files, the retired PHP files are absent, and private ACLs allow the PHP account.
Using an existing admin session, view/download an archive and confirm no-store;
using an existing non-admin session, confirm denial. Do not trigger an import.

Separately perform an authorized browser smoke test over HTTPS: password login,
opening a fresh emailed sign-in link from an external mail client, its same-site
confirmation POST, email/authenticator additional verification, and sign-out.
Check cookies after ID rotation and confirm protected pages require sign-in after
logout. These functional tests change authentication state and are not part of
the read-only checks above.

## Validation completed and limitations

- PHP syntax checks passed; git diff --check passed.
- Isolated local HTTPS fixture: HTTPS success with verify_tls=0; untrusted
  certificate rejection; hostname rejection; HTTP/file source rejection;
  downgrade redirect rejection. Only expected GETs reached the fixture.
- Isolated session test: required cookie options, CSRF/pending-verification state
  continuity, login/logout session ID rotation, and logout state cleanup passed.
  These exercise extracted production functions without DB writes or email.
- Development Apache origin: index 200 with all required cookie flags;
  an actual old archive URL, /sourcedata/probe.txt, /ijw.php and /testgit.php
  returned 403; guessed /private/sourcedata archive URL returned 404; anonymous
  raw viewer returned 302 to sign-in. Public viewer mode is 0664 and its ACL
  matches a working administrator page for web-server readability.
- Public development HTTPS timed out from this environment. Full browser login,
  delivery/consumption of emailed links and additional-verification completion
  were not exercised; perform the smoke test above before live handover.
- Local fixture test source is retained in `private/security-review/check.py`;
  run `python3 ../private/security-review/check.py` from the web root. It needs
  Python 3, OpenSSL CLI, PHP CLI with cURL, and permission to bind loopback.
  It contacts only its temporary loopback HTTPS server, not One Source.

## Follow-up: prevent account discovery during sign-in

The email step is now **Continue**, validates format only, and enables the same
Password / Auth App / Email Link choices for every syntactically valid address.
The new `login-auth-app.php` shows the same code-entry form before looking up any
account. Unknown, disabled, archived, unconfigured and incorrect-code attempts
receive the same failure; an authenticator must already be enabled and confirmed
for this path to sign in. Anonymous selection never unlocks authenticator setup,
QR codes, or the verified-password second-factor session. Existing-password
second-factor sessions expire after ten minutes. Previously pending sessions
without the new primary-credential marker must restart sign-in after deployment.

Password failures use one message, including disabled accounts, and run a dummy
password verification for absent/passwordless accounts. A minimum 500ms processing
period covers normal legacy password-cost differences; this is not a guarantee
against every statistical timing signal under server overload. Authenticator
codes and emailed second-factor codes are consumed with conditional updates to
prevent concurrent reuse. Existing configured authenticator secrets are no longer
available through the pending-setup QR helper.

Email-link and password-recovery pages always give neutral responses, including
ineligible accounts, delivery failures and throttling. For anonymous requests,
account lookup and mail delivery run in a shutdown callback **after PHP-FPM
finishes the response** and releases the session lock. There is no mail queue,
new cron job or database migration. Operational errors are logged privately;
public delivery errors must not be reintroduced. Existing authenticated account
password-reset actions keep their existing synchronous behavior.

### Additional deployment requirements

- Deploy `includes/app/auth_security.php`, `login-auth-app.php`, the updated
  `includes/app/auth.php`, `index.php`, `forgot-password.php`, and
  `login-email-sent.php`. New public PHP files must be readable (normally 0664).
- **PHP-FPM and an enabled `fastcgi_finish_request()` are required for anonymous
  authentication email delivery.** This matches development's PHP-FPM 8.4 setup
  (`disable_functions` is empty). Other SAPIs fail closed: they show the same
  neutral response but log the missing capability and do not send. Confirm the
  live pool supports the function; do not introduce synchronous fallback delivery.
- The PHP account must be able to create/read/write/lock
  `<site>/private/auth-rate-limits`. It is created automatically with mode 0770
  under the existing private directory when possible. If pre-created by an
  operator, assign the live PHP account and private ACLs first. Storage failures
  deny attempts and log a warning rather than bypass limits. Keep these files
  private; do not migrate development counters or wipe live counters routinely.
- Limits per 15-minute window: Continue/method selection 30 per address and 120
  per IP; credential/code attempts 10 per address and 100 per IP; authentication
  email requests 3 per address and 20 per IP. Password and authenticator guesses
  share the credential budget; anonymous sign-in links, reset requests and
  additional-verification emails share the mail budget. Counters are independent
  of sessions/account existence, case-normalized, hashed, locked across workers,
  and expire automatically. Only 64 shard files can be created; each has bounded
  entries and refuses new identities if full. No permanent account lockout.
- IP limits use REMOTE_ADDR by default and ignore untrusted forwarding headers.
  **Behind a reverse proxy, configure `HUB_AUTH_TRUSTED_PROXIES` in the PHP-FPM
  pool environment** with a comma-separated list of exact trusted proxy IPs
  (e.g. `env[HUB_AUTH_TRUSTED_PROXIES] = 192.0.2.10`). The edge must correctly
  overwrite/append X-Forwarded-For; the code walks the chain right-to-left and
  stops at the first untrusted address. Never trust arbitrary peers or client
  headers. Without this setting, users behind one proxy share its IP budget.
  Check the actual topology; the example IP is not a deployment setting.
- This filesystem limiter is for a single application server or a correctly
  shared filesystem with working flock semantics. Multiple independent servers
  need shared limits (database/Redis or edge controls) before scaling out.

### Follow-up validation

`php8.4 tools/test_auth_security.php` passed 109 isolated checks using temporary
files and an in-memory test database: identical HTML/redirects across account
states, neutral password/reset/authenticator failures, successful password and
app sign-in, replay rejection, logout, timing floor, address/IP/session-independent
limits, trusted-proxy parsing, expiry/corruption/storage failure, bounded storage,
and concurrent requests. Fake email workers confirmed lookup/delivery happens
only after response completion and that send success/failure cannot alter the
public response. No real email or external service is involved.

A development Apache/PHP-FPM origin check also passed: secure session-cookie
flags, all three Continue choices for a synthetic unknown address, the generic
authenticator-code screen with no setup secret, and HTTP 403 for both CLI test
entry points. The PHP account created its private limit directory successfully.
New public PHP files are 0664 and have the same readable ACL pattern as the
existing password page. No email request was submitted during this origin check.

`php8.4 tools/test_auth_mysql.php` passed 18 checks against development MySQL with
connection-local TEMPORARY tables shadowing users, TOTP, email codes and audit
logs: required second factor, new/existing authenticator setup, fresh-code sign-in,
TOTP replay rejection, direct app login and expiry, email-code verification and
reuse rejection, and denial of
anonymous authenticator enrollment/secret access. No real account/audit records
were changed and no email was sent. Both test entry points deny HTTP requests.

After deployment, compare status, redirects and rendered controls for an unknown
address and an authorized test account after Continue and method selection. Check
neutral reset and sign-in email responses. With the authorized test account,
verify actual email delivery, password sign-in, configured app sign-in, required
additional verification, and logout. Confirm counters survive a new browser
session and expire; use dedicated test identities/IPs to avoid disrupting users.
Do not automate repeated attempts against real users. Review private logs for
storage/FPM/delivery failures without exposing operational details in the UI.
