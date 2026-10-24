# EcoCycle Flowcharts

## Whole-system overview

```mermaid
flowchart LR
    Visitor[Visitor] --> Account[Register or log in]
    Account --> Home[Dashboard]
    Home --> Recycling[Log recycling]
    Recycling --> Wallet[EcoWallet and points ledger]
    Wallet --> Reward[Rewards marketplace]
    Wallet --> Checkout[Withdrawal checkout]
    Checkout --> QR[One-time QR authorization]
    Reward --> History[Transaction history and receipts]
    Checkout --> History
    Home --> Community[Leaderboard and Learn pages]
    Admin[Admin dashboard] --> CommunityData[Aggregate impact and member actions]
```

## Sign-up

```mermaid
flowchart TD
    Start[Visitor opens register.php] --> Validate[Validate CSRF, fields, age and password]
    Validate -->|Valid| Create[Create user and wallet]
    Create --> Bonus[Apply welcome deposit in same transaction]
    Bonus --> OptionalMail{Email features enabled?}
    OptionalMail -->|Yes| VerifyMail[Send optional verification link]
    OptionalMail -->|No| Session[Create session]
    VerifyMail --> Session
```

Email verification is implemented but disabled by default (`EMAIL_FEATURES_ENABLED=false`).

## Login, lockout, and forced password change

```mermaid
flowchart TD
    Login[Submit credentials at login.php] --> Limit{Within failure limits?}
    Limit -->|No| Invalid[Generic invalid-credentials response]
    Limit -->|Yes| Password{password_verify succeeds?}
    Password -->|No| Invalid
    Password -->|Yes| Forced{Temporary password?}
    Forced -->|Yes| Change[force_password_change.php]
    Forced -->|No| Dashboard[Open dashboard.php]
    Change --> Dashboard
```

## Recycling log to points to wallet

```mermaid
flowchart TD
    Form[Member submits log.php form] --> Validate[Validate material and weight]
    Validate --> Calc[Calculate rounded points and estimated CO2]
    Calc --> Label[Store self-reported log; verified is an app label only]
    Label --> Begin[Begin database transaction]
    Begin --> Log[Insert recycling_logs row]
    Log --> Streak[Update user streak]
    Streak --> Adjust[Lock user and wallet; create wallet if absent]
    Adjust --> Ledger[Update paired balances and insert deposit transaction]
    Ledger --> Commit[Commit transaction]
    Commit --> Badges[Evaluate badge criteria]
    Badges --> Result[Show earned points and impact]
```

## Reward redemption

```mermaid
flowchart TD
    Browse[Browse and select reward in rewards.php] --> Precheck[Check balance, stock, wallet PIN]
    Precheck --> Pin[Submit wallet PIN]
    Pin --> Verify{PIN valid and unlocked?}
    Verify -->|No: show error or lockout| Pin
    Verify -->|Yes| Lock[Begin transaction; lock user, wallet and reward]
    Lock --> Email{Email gate passes? Check locked user row when enabled}
    Email -->|Feature disabled| Recheck[Recheck stock and balance]
    Email -->|Enabled and verified| Recheck
    Email -->|Enabled and unverified| Resend[Show resend_verification.php link]
    Recheck --> Code[Insert redemption record/code]
    Code --> Adjust[Deduct paired balances and insert ledger entry]
    Adjust --> Commit[Decrease stock and commit]
    Commit --> Success[Show redemption code]
```

## Withdrawal request lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending: Create request; deduct from balances and reserve points
    pending --> authorized: E-wallet/bank QR accepted once
    pending --> completed: Voucher confirmed with PIN
    authorized --> completed: Simulated payout finalized
    pending --> expired: Expiry sweep; release reservation once
    authorized --> expired: Expiry sweep; release reservation once
    pending --> cancelled: Member/admin cancellation; release reservation once
    authorized --> cancelled: Member/admin cancellation; release reservation once
    note right of pending
        Expiry is swept lazily when a page or operation invokes the sweep.
        Reserved points can remain held until the next sweep.
    end note
    completed --> [*]: Keep original deduction; write transaction and receipt
    expired --> [*]: Reservation returned to both balances
    cancelled --> [*]: Reservation returned to both balances
```

## PIN reset and cooldown

```mermaid
flowchart TD
    Forgot[Forgot PIN link] --> Password[Enter account password]
    Password --> Rate{Password attempt rate limit passed?}
    Rate -->|No| Generic[Generic verification error]
    Rate -->|Yes| Verify{password_verify succeeds?}
    Verify -->|No| Generic
    Verify -->|Yes| Grant[Grant 10-minute session authorization]
    Grant --> NewPin[Enter and confirm new 4-6 digit PIN]
    NewPin --> Validate[Reject trivial or reused PIN]
    Validate --> Reset[Hash PIN; clear lockout; cancel open requests]
    Reset --> Release[Release each reserved amount once]
    Release --> Audit[Write audit entry and pin_reset_at]
    Audit --> Cooldown[Block withdrawals for configured hours]
```

## Admin actions

```mermaid
flowchart TD
    Admin[Admin opens admin.php] --> Guard[requireAdmin authorization]
    Guard --> Dashboard[View analytics and members]
    Dashboard --> Export[Export impact CSV]
    Dashboard --> Role[Promote or demote another user]
    Dashboard --> Password[Generate temporary password]
    Password --> Force[Invalidate sessions; require new password]
    Dashboard --> Pin[Reset wallet PIN]
    Pin --> Cancel[Cancel open requests and release points]
    Cancel --> Audit[Audit reset and apply cooldown]
```

## Security layers

```mermaid
flowchart TD
    Request[Browser request] --> Session[Secure session cookie and idle timeout]
    Session --> CSRF[CSRF token for state-changing forms]
    CSRF --> Validate[Server-side input validation and escaping]
    Validate --> Rate[Login/PIN attempt rate limits and lockouts]
    Rate --> Hash[password_hash and password_verify]
    Hash --> Prepared[PDO prepared statements]
    Prepared --> Lock[Database row locks for balance-sensitive actions]
    Lock --> Atomic[Transaction, ledger entry and commit/rollback]
    Atomic --> Audit[Withdrawal/PIN reset audit records]
```

## Verified against

- `register.php`
- `login.php`
- `force_password_change.php`
- `log.php`
- `rewards.php`
- `withdrawal_checkout.php`
- `withdrawal_authorize.php`
- `pin_reset.php`
- `resend_verification.php`
- `admin.php`
- `includes/functions.php`
- `config/config.php`
- `sql/schema.sql`

## Not sure about

- The deployment's mail adapter, database server version/settings, and production security headers may vary from local configuration.
- Real payout settlement is not shown in source; the diagrams represent the simulated withdrawal lifecycle only.
- Actual email delivery depends on deployment-specific mail configuration and was not tested. Email verification itself is implemented, disabled by default, and enforced during redemption when enabled.
- The exact delay between request expiry and the next page/operation that invokes the lazy sweep depends on when that sweep is next invoked.
