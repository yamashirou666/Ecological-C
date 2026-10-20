# 🏦 EcoWallet Feature — Complete Implementation

## Executive Summary

**EcoWallet** transforms EcoCycle's points system from a simple counter into a secure, bank-like personal account. Every user automatically gets a wallet where recycling points are deposited as verified transactions, with PIN-protected withdrawals and redemptions. This increases trust, security, and perceived value of the rewards program.

---

## What's New

### For Users

✨ **Banking Experience**
- Beautiful wallet dashboard with gradient card design
- Real-time balance, lifetime earned/redeemed stats
- Transaction ledger with search & filter

🔐 **Security**
- 4-6 digit PIN required for withdrawals & redemptions
- Automatic 15-minute lockout after 3 failed attempts
- Bcrypt-hashed PIN storage (never plain text)

💸 **Flexibility**
- Withdraw to voucher, e-wallet, or bank transfer
- Redeem marketplace rewards with one PIN entry
- Digital receipts & CSV export of all transactions

📧 **Transparency**
- Email notifications for every transaction
- Detailed receipts with reference IDs
- Full transaction history filterable by type/date

### For Developers

📊 **Database**
- 4 new tables: `wallets`, `transactions`, `payout_methods`, `pin_reset_requests`
- Transaction ledger with atomic operations (all-or-nothing)
- Unique reference IDs for every transaction

🔧 **API**
- 20+ wallet functions in `includes/functions.php`
- PIN verification with automatic lockout
- Deposit, withdrawal, and redemption flows
- Receipt generation and CSV export

🎨 **UI/UX**
- Mobile-friendly numeric keypad for PIN entry
- Modal dialogs for secure actions
- Banking-style card gradient & styling
- Responsive transaction list with status badges

---

## File Structure

```
Project EchoCycle/
├── wallet.php                      [NEW] Main wallet dashboard
├── receipt.php                     [NEW] Receipt viewer & export
├── migrate_wallets.php             [NEW] Migration for existing users
├── ECOCYCLE_WALLET_GUIDE.md        [NEW] Full feature documentation
├── DEPLOYMENT.md                   [NEW] Deployment checklist
│
├── sql/schema.sql                  [MODIFIED] +4 tables
├── includes/functions.php          [MODIFIED] +20 wallet functions
├── includes/header.php             [MODIFIED] +EcoWallet nav link
├── register.php                    [MODIFIED] Auto-create wallet
├── rewards.php                     [MODIFIED] PIN-gated redemption
├── assets/css/style.css            [MODIFIED] Wallet styling
│
└── logs/
    └── wallet-emails.log           [AUTO-CREATED] Email notifications log
```

---

## Getting Started

### 1. Deploy Database Changes
```bash
mysql -u root -p ecocycle < sql/schema.sql
```

### 2. Migrate Existing Users (if applicable)
```bash
php migrate_wallets.php
```

### 3. Create Logs Directory
```bash
mkdir -p logs && chmod 755 logs
```

### 4. Test a User Workflow
- Register → wallet auto-created
- Log recycling → points auto-deposited
- Redeem reward → PIN required
- Withdraw points → receipt generated

---

## Key Flows

### Flow 1: New User Registration
```
User registers → Wallet auto-created → Points earned → Auto-deposited to wallet
```

### Flow 2: First Redemption
```
User tries to redeem → No PIN? → PIN setup modal → User enters PIN twice
→ PIN saved (bcrypt-hashed) → User can now redeem with PIN
```

### Flow 3: Withdraw Points
```
User clicks "Withdraw" → Enters amount & PIN → Server verifies PIN (3 attempts max)
→ Points deducted from wallet → Receipt generated → Email sent
```

### Flow 4: Redeem Marketplace Reward
```
User selects reward → Clicks "Redeem" → PIN modal appears → User enters PIN
→ Server validates PIN (counts failed attempts, locks if 3+ failures)
→ On success: points deducted, transaction logged, code generated
```

---

## Security Features

### PIN Protection
- **Hashing:** bcrypt (PASSWORD_DEFAULT algorithm)
- **Length:** 4-6 digits
- **Validation:** Server-side regex check (`/^\d{4,6}$/`)
- **Confirmation:** User enters PIN twice when setting up

### Failed Attempt Lockout
- **Threshold:** 3 failed PIN attempts
- **Duration:** 15 minutes (900 seconds)
- **Enforcement:** Server-side (database `locked_until` field)
- **Reset:** Auto-resets on successful attempt or after lockout expires

### Transaction Atomicity
- **Database Transactions:** All wallet operations wrapped in `BEGIN...COMMIT...ROLLBACK`
- **Consistency:** Balance and transaction records always in sync
- **Isolation:** Prevents race conditions with row locking (`FOR UPDATE`)

### CSRF Protection
- **Tokens:** All forms include CSRF token via `csrfField()`
- **Verification:** POST requests verified with `verifyCsrf()`

---

## Database Schema

### wallets
```sql
id              INT PRIMARY KEY
user_id         INT UNIQUE FK → users(id)
balance         INT (current points)
lifetime_earned INT (total earned)
lifetime_redeemed INT (total spent)
pin_hash        VARCHAR(255) bcrypt hash
pin_created_at  DATETIME
failed_pin_attempts INT (0-3)
locked_until    DATETIME NULL
created_at      DATETIME
```

### transactions
```sql
id              INT PRIMARY KEY
wallet_id       INT FK → wallets(id)
type            ENUM('deposit', 'withdrawal', 'redemption')
amount          INT (points)
source_description VARCHAR(255)
status          ENUM('pending', 'completed', 'failed', 'cancelled')
reference_id    VARCHAR(50) UNIQUE (DEP-, WIT-, RED- prefix)
balance_after   INT (wallet balance after txn)
receipt_data    JSON (structured receipt details)
created_at      DATETIME
```

### payout_methods
```sql
id              INT PRIMARY KEY
user_id         INT FK → users(id)
type            ENUM('voucher', 'bank_transfer', 'ewallet')
label           VARCHAR(100)
account_details VARCHAR(500) encrypted
is_default      TINYINT(1)
created_at      DATETIME
```

### pin_reset_requests
```sql
id              INT PRIMARY KEY
user_id         INT FK → users(id)
otp_code_hash   VARCHAR(255) bcrypt hash
expires_at      DATETIME
verified        TINYINT(1)
created_at      DATETIME
```

---

## API Reference

### Core Functions

#### Wallet Management
```php
createWallet(int $userId): int
// Creates wallet for user. Called on signup.

getUserWallet(int $userId): ?array
// Fetches wallet row or null if not found.

getWalletTransactions(int $userId, ?string $type, ?string $search, int $limit, int $offset): array
// Gets filtered transaction history with pagination.
```

#### Points & Transactions
```php
depositPointsToWallet(int $userId, int $points, string $source): array
// Deposits points from verified recycling log. Returns transaction.

createWithdrawal(int $userId, int $points, string $payoutMethod, string $description): array
// Creates cash-out transaction. Returns transaction with reference_id.

createRedemptionTransaction(int $userId, int $points, string $rewardTitle): array
// Records reward redemption. Returns transaction.
```

#### PIN Security
```php
setWalletPin(int $userId, string $pin): void
// Sets 4-6 digit PIN. Throws InvalidArgumentException if invalid.

verifyPin(int $userId, string $pinAttempt): bool
// Verifies PIN. Returns true if correct, false if incorrect.
// Increments failed_pin_attempts and locks wallet after 3 failures.

hasPin(int $userId): bool
// Checks if user has PIN set up.

isWalletLocked(int $userId): bool
// Checks if wallet is currently locked.

getWalletLockoutRemaining(int $userId): int
// Returns remaining lockout time in seconds (0 if not locked).
```

#### Receipts & Export
```php
generateReceipt(array $transaction, int $userId): string
// Generates formatted HTML receipt for a transaction.

exportTransactionsCSV(int $userId): string
// Returns CSV-formatted transaction history (all transactions).
```

#### Notifications
```php
sendWalletNotification(int $userId, string $type, array $data): void
// Sends email notification. Types: deposit, withdrawal, redemption, pin_setup, failed_pin_attempts.
// Logs to logs/wallet-emails.log in development.

notifyWalletEvent(string $type, array $data): void
// Creates in-app flash notification (success/info message).
```

---

## User Interface

### wallet.php — Dashboard
**URL:** `/wallet.php`

**Sections:**
1. **Balance Card** (hero gradient)
   - Current balance (large, prominent)
   - Lifetime earned & redeemed stats
   - Security status (PIN set? locked?)
   - Quick action buttons (Set PIN, Withdraw, Redeem)

2. **Transaction History**
   - Chronological list with pagination
   - Filters: By type (Deposit/Withdrawal/Redemption), search by description
   - Each entry shows: icon, description, amount, status, timestamp
   - "📄 Receipt" link for each transaction
   - Export as CSV button

3. **Modals**
   - PIN Setup: 2 password inputs (4-6 digits each)
   - Withdrawal: Amount input, payout method dropdown, PIN entry

### rewards.php — Modified
**URL:** `/rewards.php`

**Changes:**
- Redemption now two-step (initiate + PIN verify)
- First POST: `action=initiate_redemption` validates reward & balance
- On success, PIN modal appears (`redemptionPinModal`)
- Second POST: `action=confirm_redemption` with PIN
- PIN verification same as wallet PIN (3 attempts, 15-min lockout)

### receipt.php — New
**URL:** `/receipt.php?action=receipt&txn_id=123` or `?action=export_csv`

**Actions:**
- `action=receipt&txn_id=X` — View HTML receipt
- `action=receipt&txn_id=X&format=pdf` — Download HTML as file
- `action=export_csv` — Download all transactions as CSV

---

## Email Notifications

Wallet sends emails for:
1. **Deposit** — "You've earned 50 points from verified recycling"
2. **Withdrawal** — "Your 500-point withdrawal has been processed"
3. **Redemption** — "You've redeemed 'Coffee Voucher' for 250 points"
4. **PIN Setup** — "Your EcoWallet PIN has been set"
5. **Failed Attempts** — "⚠ Multiple failed PIN attempts detected"

**Implementation:**
- **Development:** Logged to `logs/wallet-emails.log`
- **Production:** Configure with mail service (SendGrid, Mailgun, AWS SES, etc.)

---

## Testing Scenarios

### Scenario 1: New User Workflow
```
1. Register new user → wallet auto-created
2. View wallet → balance = 0, no PIN
3. Log 5kg plastic recycling → balance = 50 pts (10 pts/kg)
4. Check wallet history → deposit transaction visible
5. Try to redeem reward → forced to set PIN
6. Set PIN "1234" (confirm "1234")
7. Redeem reward → PIN modal, enter "1234" → success
8. Check balance → decreased by reward cost
```

### Scenario 2: PIN Security
```
1. Attempt to redeem with wrong PIN "0000" → error
2. Attempt 2 with wrong PIN → attempt counter shows "2 remaining"
3. Attempt 3 with wrong PIN → wallet locked "Try again in 15 minutes"
4. Refresh page → still locked
5. Wait 15 minutes (or manually reset in DB)
6. Try again with correct PIN → works
```

### Scenario 3: Withdrawal Flow
```
1. Click "Withdraw Points"
2. Modal appears: amount input, payout method, PIN entry
3. Enter 100 pts, select "Gift Card", enter PIN
4. Click withdraw → success with reference ID "WIT-XXXX"
5. Check transaction history → withdrawal entry visible
6. Click "📄 Receipt" → detailed receipt page
7. Click "📥 Download" → receipt.html downloaded
```

### Scenario 4: CSV Export
```
1. On wallet dashboard, click "📥 Export as CSV"
2. Browser downloads "ecocycle-wallet-export-2026-09-13.csv"
3. Open in Excel/Sheets → columns: ID, Type, Amount, Description, Status, Reference, Balance After, Date
4. All transactions visible in chronological order
```

---

## Configuration & Customization

### Change PIN Requirements
**File:** `includes/functions.php`

```php
// Change PIN length validation
// Current: 4-6 digits
// To require exactly 6: /^\d{6}$/
if (!preg_match('/^\d{4,6}$/', $pin)) {
```

### Change Lockout Duration
**File:** `includes/functions.php`

```php
// Current: 15 minutes (900 seconds)
// To change to 30 minutes: 1800
$lockedUntil = date('Y-m-d H:i:s', time() + 900);
```

### Change Failed Attempts Threshold
**File:** `includes/functions.php`

```php
// Current: 3 attempts
// To change to 5: >= 5
if ($attempts >= 3) {
```

### Enable Real Email Service
**File:** `includes/functions.php`

Replace in `sendWalletNotification()`:
```php
// From:
@file_put_contents($logFile, $logEntry, FILE_APPEND);

// To (using PHP mail):
mail($user['email'], $subject, $body, "From: noreply@ecocycle.local");

// Or using SendGrid API, PHPMailer, etc.
```

---

## Deployment Checklist

- [ ] Backup database
- [ ] Run `sql/schema.sql` to create tables
- [ ] Run `migrate_wallets.php` if migrating existing users
- [ ] Create `logs/` directory with 755 permissions
- [ ] Clear browser cache (new CSS)
- [ ] Test new user registration → wallet created
- [ ] Test recycling log → auto-deposit
- [ ] Test reward redemption → PIN required
- [ ] Test withdrawal → receipt generated
- [ ] Test CSV export → file downloads
- [ ] Check `logs/wallet-emails.log` for notifications
- [ ] Verify navigation link shows "EcoWallet 🏦"

---

## Troubleshooting

### Problem: "Wallet locked" appears immediately
**Solution:** Check if user already has `locked_until` in database. Reset:
```sql
UPDATE wallets SET locked_until = NULL, failed_pin_attempts = 0 
WHERE user_id = 123;
```

### Problem: New users don't get wallets
**Solution:** Run migration script:
```bash
php migrate_wallets.php
```

### Problem: Email notifications not appearing in log
**Solution:** Check if `logs/` directory exists and is writable:
```bash
ls -la logs/
touch logs/wallet-emails.log
chmod 666 logs/wallet-emails.log
```

### Problem: Transaction balance mismatch
**Solution:** Rare race condition. Recalculate balance:
```sql
UPDATE wallets 
SET balance = (
    SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END), 0)
    FROM transactions WHERE wallet_id = wallets.id
)
WHERE user_id = 123;
```

---

## Security Best Practices

1. **PIN Storage** — Never log or display PIN in plain text
2. **Database Backups** — Backup before and after deployment
3. **HTTPS** — Use SSL/TLS in production (especially for PIN entry)
4. **Rate Limiting** — Consider rate-limiting PIN attempts at web server level
5. **Audit Logging** — Monitor failed PIN attempts and account for fraud
6. **Email Verification** — Link email changes to account to prevent takeovers

---

## Performance Considerations

### Database Indexes
- `transactions.wallet_id, created_at` — indexed for fast history queries
- `wallets.user_id` — UNIQUE, for direct lookups

### Caching
- Wallet balance should be cached in session to avoid on-page-load query
- Consider Redis cache for high-traffic scenarios

### Query Optimization
- Pagination limited to 20 transactions per page
- Search & filter use LIKE with wildcards (indexes help)
- Transactions indexed by wallet_id & created_at

---

## Future Enhancements

1. **OTP Recovery** — PIN reset via SMS/email OTP
2. **Biometric Login** — Fingerprint/Face ID as PIN alternative (mobile web)
3. **Real Bank Integration** — Stripe/PayPal for actual bank payouts
4. **Wallet Limits** — Daily/weekly withdrawal limits
5. **Gift Wallet** — Send points to friends
6. **Analytics** — Charts of earning/spending trends
7. **Referral Bonuses** — Points for inviting users
8. **Scheduler** — Auto-withdrawal on recurring schedule

---

## Support & FAQ

**Q: Can a user change their PIN?**  
A: Yes, just go to wallet and click "Set PIN" again. It overwrites the old PIN.

**Q: What happens if a user forgets their PIN?**  
A: Future enhancement: PIN reset via email OTP. Currently, admin can reset in database.

**Q: Can users transfer points to each other?**  
A: Not in v1.0. Planned for future release.

**Q: What's the conversion rate for points to cash?**  
A: Currently not implemented (vouchers only). To add: configure in `wallet.php` withdrawal flow.

**Q: Can admins see user wallets?**  
A: Not in v1.0. Planned for admin dashboard.

**Q: Is the PIN secure?**  
A: Yes. PINs are bcrypt-hashed (same algorithm as passwords). Never stored or transmitted in plain text.

---

## Support Contact

- **Documentation:** See `ECOCYCLE_WALLET_GUIDE.md`
- **Deployment:** See `DEPLOYMENT.md`
- **Issues:** Check logs:
  - `logs/wallet-emails.log` — notification logs
  - PHP error log — server errors
  - Browser console — JavaScript errors

---

**Version:** 1.0  
**Release Date:** September 13, 2026  
**Status:** Production-Ready  
**Maintained By:** EcoCycle Development Team

🌱 Turn Recycling into Banking. Make Points Matter.
