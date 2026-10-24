# EcoCycle User Flows

These flows describe behavior visible in the current application source. PHP filenames refer to pages or shared functions; they are not claims about live deployment settings.

## (a) Sign up

**Goal:** Create a member account and receive the welcome points.  
**Who:** Visitor.

1. The visitor opens `register.php`, enters a username, email, birth date, password, and confirmation, then submits. Client-side JavaScript checks formatting/availability for convenience; the server repeats validation. (`register.php`)
2. The server checks the CSRF token and honeypot, requires a 3–12-character alphanumeric username, valid email, valid birth date, and age 13 or above using UTC. It enforces the password policy configured in `config/config.php`. (`register.php`, `includes/functions.php`, `config/config.php`)
3. Within one database transaction, the server creates the user, creates the wallet, credits the 20-point welcome bonus to paired balances and the transaction ledger, then commits. (`register.php`, `includes/functions.php`)
4. Email verification is implemented but disabled by default (`EMAIL_FEATURES_ENABLED=false`). If enabled, EcoCycle creates a verification token valid for 24 hours and sends it through the configured mail adapter. Email failure is logged and does not undo account creation. (`config/config.php`, `register.php`, `includes/functions.php`)
5. The new user is logged in and sent to `dashboard.php`. (`register.php`, `includes/functions.php`)

**Success result:** The member has a session, a wallet, and a welcome-bonus deposit transaction.  
**Errors/edge cases:** Invalid or duplicate username/email, invalid age/date, failed password policy, CSRF/honeypot rejection, or database failure prevents registration. When email is disabled, no verification message is sent and email verification does not gate redemption.

## (b) Log in, failed-attempt lockout, and forced password change

**Goal:** Authenticate and access member features.  
**Who:** Visitor/member.

1. The user opens `login.php`, enters username or email and password, and submits a CSRF-protected form. (`login.php`)
2. EcoCycle looks up the account and verifies the password hash using `password_verify`. It records failed attempts against a hashed identifier and IP. A generic invalid-credentials response is used. (`login.php`, `includes/functions.php`)
3. Login is blocked when the identifier has at least 5 failures or the IP has at least 20 failures in a rolling 15 minutes. A successful login is recorded but does not count toward those failure counts. (`includes/functions.php`)
4. On success, EcoCycle regenerates the session ID and rotates the CSRF token. If `must_change_password` is set, the member is sent to `force_password_change.php`; otherwise they go to `dashboard.php`. (`includes/functions.php`, `login.php`)
5. A forced change requires a matching, policy-compliant password that differs from the temporary password. On success the flag is cleared and the user continues to the dashboard. (`force_password_change.php`, `config/config.php`)

**Success result:** Authenticated session; where required, a new permanent password is set before normal navigation.  
**Errors/edge cases:** Incorrect credentials and rate-limited attempts look the same. The session expires after more than 30 minutes without activity. Malformed or expired sessions are not a separate user-visible recovery flow.

## (c) Forgot password

**Goal:** Recover account access.  
**Who:** Member who cannot sign in.

1. When email features are enabled, the user selects “Forgot password?” from `login.php` and enters an email address at `forgot_password.php`. (`login.php`, `forgot_password.php`)
2. The request is CSRF-protected, uses a honeypot and per-account/IP rate limits, and returns generic “if an account exists” wording. (`forgot_password.php`, `includes/functions.php`)
3. If the email matches an account, EcoCycle issues a one-time link whose token expires after 30 minutes and sends it through the configured mail adapter. Only a token hash is stored. (`forgot_password.php`, `includes/functions.php`)
4. The user opens the link and submits a new matching password at `reset_password.php`. The server rechecks the token under a database transaction, enforces the configured password policy, changes the password, and consumes active reset tokens. (`reset_password.php`)

**Success result:** The member can sign in with the new password.  
**Errors/edge cases:** With `EMAIL_FEATURES_ENABLED=false` (the config default), `forgot_password.php` redirects to `login.php`, and `reset_password.php` also redirects to login. Expired, used, malformed, or unknown links cannot reset a password. Email send failures are logged; the browser response remains generic. No account password is changed merely by requesting a link.

## (d) Log recycling and earn points

**Goal:** Record recycling and receive points/impact estimates.  
**Who:** Member.

1. The member opens `log.php`, selects a material, enters quantity and weight, optionally adds a note, then submits with the CSRF token. The form shows a points estimate per kilogram and supports an item-count weight estimate. (`log.php`)
2. The server validates the material and a weight above zero and no greater than the configured 100 kg per-entry maximum. Quantity is coerced to at least one. (`config/config.php`, `log.php`)
3. `recordRecyclingLog()` rounds `weight × material points_per_kg`, calculates estimated CO₂ savings, inserts the log, updates the user's streak, and calls the paired balance/ledger helper inside a transaction. If the wallet is missing, the helper creates it within the transaction. (`includes/functions.php`)
4. After commit, badge criteria are evaluated. The user is redirected to the log page and receives a success message with points and CO₂; newly earned badges appear as messages. (`includes/functions.php`, `log.php`)

**Success result:** A recycling log exists, both point balances are credited consistently, a deposit ledger entry is recorded, streak is updated, and any newly met badge criteria are awarded. The log's `verification_status` defaults to the app label `verified`; it is not an independent verification.
**Errors/edge cases:** Invalid material or weight is rejected. Recycling logs are self-reported. There is no collection-center verification, independent weighing, or fraud review; the points/CO₂ are estimates calculated from the submitted weight and configured material factors.

## (e) Dashboard, badges, streaks, leaderboard, and Learn pages

**Goal:** Review impact, progress, community standing, and recycling guidance.  
**Who:** Dashboard/profile are members; leaderboard and learning content are available to visitors.

1. The member opens `dashboard.php` to see logged kilograms, estimated CO₂, tree-year equivalent, current spendable balance, lifetime-point level/progress, material breakdown, seven-day CO₂ trend, and community average. (`dashboard.php`, `includes/functions.php`)
2. After logging recycling, the streak calculation in `includes/functions.php` keeps the same count for another log on the same date, increments after a one-day gap, or resets to one after a longer gap. (`includes/functions.php`)
3. Badge criteria are checked after a recycling log: first log, seven-day streak, 100 kg total, 1,000 lifetime points, or logs across all materials. The member sees earned/locked badges and redemption history on `profile.php`. (`includes/functions.php`, `profile.php`)
4. The member or visitor opens `leaderboard.php` and switches between the top 50 individual recyclers and neighborhood aggregates. The individual list is ordered by lifetime points, with total kilograms as a tie-breaker. (`leaderboard.php`)
5. The visitor/member opens `learn.php` for material-specific sorting guidance, contamination myths, and a static Plastic-Free July callout. (`learn.php`)

**Success result:** The user can inspect personal/community summaries, badge status, rankings, and educational content.  
**Errors/edge cases:** An entry is rejected if its points would put the member over the configured 3,000 recycling-points-per-day cap; the message reports points earned from recycling logs today and the cap. The daily sum is checked inside the log transaction using MySQL `CURDATE()`; the MySQL session time zone follows PHP's `Asia/Manila` setting. The leaderboard is based on recorded `total_points` and self-reported recycling log totals, not a live third-party verification feed. The seasonal callout is static page content; no challenge enrollment or event tracking was found.

## (f) Redeem a reward

**Goal:** Exchange points for a marketplace reward code.  
**Who:** Member with sufficient points and a wallet PIN.

1. The member browses `rewards.php` (guests may browse), optionally filters by category, and selects a reward. (`rewards.php`)
2. The server checks stock, current user balance, and wallet PIN presence; it then asks for the wallet PIN. (`rewards.php`)
3. On confirmation, the server verifies the PIN and lockout state, then starts one transaction and locks the user, wallet, and reward rows. It rechecks reward existence, stock, and balance. (`rewards.php`)
4. The transaction inserts a unique redemption code, deducts the reward cost from both balances using the shared points helper, writes a redemption ledger transaction, decrements reward stock, and commits. Notification is attempted after commit; notification failure is logged without undoing the redemption. (`rewards.php`, `includes/functions.php`)
5. Email verification is implemented but disabled by default (`EMAIL_FEATURES_ENABLED=false`). When enabled, redemption is permitted only if the locked user row has a non-null `email_verified_at`; this check occurs inside the redemption transaction. The catalogue shows a verification notice and disables redemption until verified; a resend link opens `resend_verification.php`, which sends a new 24-hour link subject to the existing email-action rate limit. (`config/config.php`, `rewards.php`, `resend_verification.php`, `includes/functions.php`)
6. When email features are disabled, no email-verification check or verification wording applies to reward redemption. (`config/config.php`, `rewards.php`)
7. The user sees the code and can see redemption history on `profile.php`. (`rewards.php`, `profile.php`)

**Success result:** One redemption record and one ledger entry correspond to one stock decrement and one paired balance deduction.  
**Errors/edge cases:** Out-of-stock, insufficient balance, missing wallet/PIN, PIN lockout, wrong PIN, an unverified email while email features are enabled, or a race that changes balance/stock stops redemption. Failed transactional writes roll back.

## (g) EcoWallet: setting and resetting a PIN

**Goal:** Protect wallet withdrawals and reward redemptions with a PIN; recover if forgotten.  
**Who:** Member; admin may clear a member PIN as a fallback.

1. The member opens `wallet.php` and selects “Set PIN”; the form asks for a 4–6 digit PIN twice and includes a CSRF token. (`wallet.php`)
2. The initial setup stores a password hash and clears failed-attempt/lockout data. Initial PIN setup enforces the digit length but does not apply the reset flow's trivial-sequence rule. (`wallet.php`, `includes/functions.php`)
3. From the wallet or withdrawal review page, the member selects “Forgot PIN?” and opens `pin_reset.php`. They first submit their current EcoCycle account password. (`wallet.php`, `withdrawal_checkout.php`, `pin_reset.php`)
4. The server verifies the account password with `password_verify`, records attempts through the login-attempts table, applies the login identifier/IP rate limits, and grants a 10-minute session authorization on success. Error wording does not distinguish an incorrect password from other verification failure. (`pin_reset.php`, `includes/functions.php`)
5. The member submits and confirms a new 4–6 digit PIN. The reset rejects repeated-digit PINs, simple ascending/descending sequences, and the current PIN. The stored value is a password hash; the submitted PIN is not included in the audit record. (`pin_reset.php`, `includes/functions.php`)
6. A successful reset clears the PIN failure count and lockout, cancels pending/authorized withdrawals and returns their reserved points, records a wallet audit event, stamps `pin_reset_at`, and starts the configured cooldown. If email is enabled, a PIN-changed notice is sent best-effort. (`pin_reset.php`, `includes/functions.php`)
7. During the cooldown, Step 1 shows a hold-until time in `Asia/Manila`; checkout enforces the cooldown server-side too. (`withdrawal_checkout.php`, `includes/functions.php`, `config/config.php`)
8. As fallback, an administrator selects “Reset wallet PIN” for a member in `admin.php`. The admin action is CSRF-protected, clears the PIN and lockout, cancels open withdrawals/releases reservations, audits the action, and applies the same cooldown. The member sees the Set PIN flow next time. (`admin.php`, `includes/functions.php`)

**Success result:** The member's PIN is reset or cleared, open requests are cancelled and reservations returned once, and the cooldown is active.  
**Errors/edge cases:** A wrong/expired password authorization, rate limit, invalid/trivial/reused PIN, or database failure prevents the reset. The admin fallback does not set a new PIN; the user must use wallet PIN setup. Email delivery failure is logged and does not undo a successful reset.

## (h) Withdraw points using gift card, e-wallet, or bank transfer

**Goal:** Create a simulated withdrawal with a receipt; no real funds are sent.  
**Who:** Member with a set PIN, unlocked wallet, at least 500 available points, and sufficient daily quota.

1. The member selects **Withdraw Points** from `wallet.php`. This opens `withdrawal_checkout.php?step=start`, which begins with a clean Payout Info form. Existing active requests are shown as an explicit in-progress notice with Resume and Cancel actions. (`wallet.php`, `withdrawal_checkout.php`)
2. On Payout Info (Step 1), the member enters whole-number points and chooses Gift Card/Voucher, E-Wallet, or Bank Transfer. Available saved methods can be selected; new e-wallet/bank destinations are validated and can be saved. Daily use is shown. (`withdrawal_checkout.php`)
3. Server-side checks enforce minimum, available balance, daily cap, PIN existence/lock, cooldown, method/provider/destination validity, and idempotency. In one transaction, the system locks the wallet, deducts the requested points from both user and wallet balances to reserve them, and creates a request expiring after the configured 10 minutes. It does not create a completed ledger transaction at this point. (`withdrawal_checkout.php`, `includes/functions.php`)
4. The member reviews the payout details in the **Review + Confirm** step, accepts terms, and enters the wallet PIN. A wrong PIN increments the failure counter; three failures lock the wallet for 15 minutes. (`withdrawal_checkout.php`, `includes/functions.php`)
5. **Gift Card/Voucher:** After PIN confirmation, the request completes directly and the voucher code is shown. An on-ledger withdrawal transaction/receipt is created; lifetime redeemed is incremented. (`withdrawal_checkout.php`, `includes/functions.php`)
6. **E-Wallet/Bank Transfer:** After PIN confirmation, the checkout opens Payout Authorization and renders a one-time QR code. The QR links to `withdrawal_authorize.php`; the user confirms authorization on that page. The checkout polls status, and when authorized finalizes a simulated payout and records the transaction/receipt. (`withdrawal_checkout.php`, `withdrawal_authorize.php`, `assets/js/withdrawal-qr.js`, `includes/functions.php`)
7. The checkout countdown begins with server-computed remaining seconds and polls status. Durations under one hour show `MM:SS`; one hour or more show `H:MM:SS`. (`includes/functions.php`, `withdrawal_checkout.php`)
8. The member can cancel an open request or change payout information. Expiry is lazy: expired requests are swept when pages/operations that invoke the sweep run. Until the next sweep, reserved points can remain held after the expiry time. Cancellation or expiry changes the request to a terminal state and returns the reservation to both balances exactly once; completion leaves the earlier deduction in place as spent. (`withdrawal_checkout.php`, `wallet.php`, `includes/functions.php`)
9. A terminal screen offers “Back to EcoWallet” and “New withdrawal.” Completed requests have a linked receipt; transaction history is visible in `wallet.php`. (`withdrawal_checkout.php`, `wallet.php`, `receipt.php`)

**Success result:** The ledger reflects a completed simulated withdrawal; reserved points are not deducted a second time at completion.  
**Errors/edge cases:** A request cannot be started during cooldown, below the minimum, beyond balance/quota, or while the PIN is absent/locked. A QR authorization is single-use and expires with the request. The code does not implement real payment processor/bank/e-wallet settlement. The precise delay before reserved points are released depends on when a page/operation next invokes the expiry sweep.

## (i) Transaction history, filters, receipts, and CSV

**Goal:** Review or export wallet activity and inspect a receipt.  
**Who:** Member.

1. The member opens `wallet.php` and views the most recent 20 transactions, with pagination for more. Search filters source description; type filter includes deposits, withdrawals, redemptions, and adjustments. The query and anchor keep the user at the Transaction History section. (`wallet.php`, `includes/functions.php`)
2. Each transaction links to a receipt view using its transaction ID. `receipt.php` verifies that the transaction belongs to the signed-in user's wallet before rendering it. (`wallet.php`, `receipt.php`)
3. The receipt shows type, signed point change, status, reference, balance after, date, and applicable detail fields from receipt JSON. The “Download as HTML” action returns an HTML attachment; the UI does not generate a PDF. (`receipt.php`, `includes/functions.php`)
4. The member selects Export CSV to download the wallet's ledger entries. (`wallet.php`, `receipt.php`, `includes/functions.php`)

**Success result:** The member can filter history, open own receipts, print or save the receipt HTML, and export ledger rows.  
**Errors/edge cases:** A transaction ID outside the user's wallet is treated as not found. The page's receipt `format=pdf` route currently returns HTML rather than a generated PDF.

## (j) Admin tasks

**Goal:** Review community activity and administer member accounts.  
**Who:** Admin.

1. An admin opens `admin.php`; `requireAdmin()` sends guests to login and non-admin members back to the dashboard. (`admin.php`, `includes/functions.php`)
2. The page displays registered members, activity participation, recycling and CO₂ totals, neighborhood breakdown, materials, recent recycling activity, rewards redeemed, and related impact metrics. (`admin.php`)
3. The admin can export an impact CSV. (`admin.php`)
4. For another user, the admin can promote/demote admin access, generate a temporary password (forcing the user to choose a new one at next login), or reset the wallet PIN. All POST actions verify CSRF. (`admin.php`)
5. Password reset invalidates target sessions using the auth-session version, clears login-attempt records and wallet PIN lockout, and records an admin password reset log. The temporary password is shown once in a flash message. (`admin.php`, `includes/functions.php`)
6. Wallet PIN reset clears the PIN and lockout, cancels open withdrawals and releases reserved points, writes a withdrawal audit record, applies the cooldown, and optionally sends a notice. (`admin.php`, `includes/functions.php`)

**Success result:** Admin can review/export aggregate impact and execute the listed member-management actions.  
**Errors/edge cases:** An admin cannot promote/demote their own account. Password/PIN reset failures are logged and shown as generic errors. No user deletion or direct point-adjustment UI is visible in `admin.php`.

## Verified against

- `register.php`
- `login.php`
- `logout.php`
- `force_password_change.php`
- `forgot_password.php`
- `reset_password.php`
- `verify_email.php`
- `resend_verification.php`
- `log.php`
- `dashboard.php`
- `profile.php`
- `leaderboard.php`
- `learn.php`
- `rewards.php`
- `wallet.php`
- `withdrawal_checkout.php`
- `withdrawal_authorize.php`
- `pin_reset.php`
- `receipt.php`
- `admin.php`
- `includes/functions.php`
- `config/config.php`

## Not sure about

- Actual email delivery depends on deployment-specific mail configuration and was not tested.
- QR scanning depends on a device/browser camera or the checkout's built-in simulate-scan option; the latter is a demo path.
- External payout processing is not present in the reviewed code, so no real-world delivery time or settlement status can be asserted.
- Whether admins use additional database tools or operational procedures outside `admin.php` is not represented in these page flows.
