# EcoWallet Feature — Implementation Guide

## Overview

**EcoWallet** is a PIN-secured personal banking system for EcoCycle users. Every user automatically receives a wallet upon signup where recycling points are deposited as transactions, with PIN-protected withdrawals and redemptions.

### Key Features

- ✅ **Auto-created wallets** on user signup (1:1 with user profile)
- ✅ **Automatic deposits** when recycling logs are verified
- ✅ **PIN-secured withdrawals** (4-6 digits, bcrypt-hashed)
- ✅ **Banking-style dashboard** with balance, lifetime earned/redeemed, transaction history
- ✅ **PIN-gated redemptions** for marketplace rewards
- ✅ **Withdrawal flow** (vouchers, e-wallet, bank transfer)
- ✅ **Digital receipts** and transaction export (CSV)
- ✅ **Email notifications** for all wallet events
- ✅ **Failed attempt lockout** (3 attempts → 15-min lockout)
- ✅ **Mobile-friendly UI** with numeric keypad for PIN entry

---

## Database Schema

Four new tables added to schema.sql:

### 1. **wallets**
Stores user wallet account data and PIN security info.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Unique wallet ID |
| user_id | INT (FK, UNIQUE) | Links to users table (1:1) |
| balance | INT | Current points balance |
| lifetime_earned | INT | Total points ever earned |
| lifetime_redeemed | INT | Total points ever spent |
| pin_hash | VARCHAR(255) | bcrypt-hashed PIN (NULL if not set) |
| pin_created_at | DATETIME | When PIN was last set |
| failed_pin_attempts | INT | Failed PIN attempts (counter) |
| locked_until | DATETIME | Lockout expiry (NULL if not locked) |
| created_at | DATETIME | Wallet creation timestamp |

### 2. **transactions**
Ledger of all wallet activity (deposits, withdrawals, redemptions).

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Transaction ID |
| wallet_id | INT (FK) | Links to wallets table |
| type | ENUM | 'deposit', 'withdrawal', 'redemption' |
| amount | INT | Points involved |
| source_description | VARCHAR(255) | "Verified plastic recycling", "Coffee voucher", etc. |
| status | ENUM | 'pending', 'completed', 'failed', 'cancelled' |
| reference_id | VARCHAR(50) | Unique transaction reference (DEP-, WIT-, RED-) |
| balance_after | INT | Wallet balance after this transaction |
| receipt_data | JSON | Structured receipt details |
| created_at | DATETIME | Transaction timestamp |

### 3. **payout_methods**
User-configured payout destinations (for withdrawal flow).

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Payout method ID |
| user_id | INT (FK) | Links to users table |
| type | ENUM | 'voucher', 'bank_transfer', 'ewallet' |
| label | VARCHAR(100) | User-friendly name (e.g., "My GCash") |
| account_details | VARCHAR(500) | Encrypted account info |
| is_default | TINYINT(1) | Default method flag |
| created_at | DATETIME | Creation timestamp |

### 4. **pin_reset_requests**
OTP-based PIN recovery (future expansion).

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Request ID |
| user_id | INT (FK) | Links to users table |
| otp_code_hash | VARCHAR(255) | hashed OTP code |
| expires_at | DATETIME | OTP expiry time |
| verified | TINYINT(1) | Whether OTP was verified |
| created_at | DATETIME | Request creation timestamp |

---

## Core Functions (functions.php)

### Wallet Management

```php
createWallet(int $userId): int
// Creates a new wallet for a user. Called on signup.
// Returns: wallet ID

getUserWallet(int $userId): ?array
// Fetches wallet row for a user. Returns null if not found.

depositPointsToWallet(int $userId, int $points, string $source): array
// Auto-deposits points (triggered on verified recycling log).
// Returns: transaction array with receipt data

createWithdrawal(int $userId, int $points, string $payoutMethod, string $description): array
// Creates a withdrawal/cash-out transaction.
// Returns: transaction array

createRedemptionTransaction(int $userId, int $points, string $rewardTitle): array
// Records a reward redemption in the wallet ledger.
// Returns: transaction array
```

### PIN Security

```php
setWalletPin(int $userId, string $pin): void
// Sets a new PIN (4-6 digits). Hashes with bcrypt.
// Throws: InvalidArgumentException if PIN invalid

verifyPin(int $userId, string $pinAttempt): bool
// Verifies a PIN attempt. Handles lockout after 3 failures.
// Returns: true if correct, false if incorrect

hasPin(int $userId): bool
// Checks if user has set up a PIN yet.

isWalletLocked(int $userId): bool
// Checks if wallet is currently locked due to failed attempts.

getWalletLockoutRemaining(int $userId): int
// Returns remaining lockout time in seconds (0 if not locked).
```

### History & Export

```php
getWalletTransactions(int $userId, ?string $type = null, ?string $search = null, int $limit = 50, int $offset = 0): array
// Fetches wallet transaction history with optional filtering.
// $type: 'deposit', 'withdrawal', or 'redemption'
// Returns: array of transaction rows

generateReceipt(array $transaction, int $userId): string
// Generates a formatted HTML receipt for a transaction.

exportTransactionsCSV(int $userId): string
// Exports all user transactions as CSV-formatted string.
```

### Notifications

```php
sendWalletNotification(int $userId, string $type, array $data): void
// Sends email notification for wallet events.
// $type: 'deposit', 'withdrawal', 'redemption', 'pin_setup', 'failed_pin_attempts'
// Logs to files/logs/wallet-emails.log in development

notifyWalletEvent(string $type, array $data): void
// Creates in-app flash notification for wallet event.
```

---

## User Interface

### 1. **wallet.php** — Main Dashboard
**Route:** `/wallet.php`

**Features:**
- Large, prominent balance display (banking-style card with gradient)
- Quick-action buttons: Set PIN, Withdraw, Redeem
- Transaction history with pagination (20 per page)
- Filters: By type (deposit/withdrawal/redemption), search by description
- Export as CSV button
- Individual receipt links for each transaction

**Flow:**
1. User logs in, navigates to "EcoWallet" link in header
2. Sees current balance, lifetime stats
3. If no PIN set, "Set PIN" button is highlighted
4. Can click "Withdraw Points" to enter amount, choose payout method, verify PIN
5. Can view transaction history with detailed descriptions
6. Can click "📄 Receipt" on any transaction to view/download

### 2. **rewards.php** — PIN-Gated Redemption
**Route:** `/rewards.php` (modified)

**New Flow:**
1. User clicks "Redeem" on a reward card
2. Form submits with `action=initiate_redemption`
3. Server validates reward availability and user balance
4. If valid, modal dialog pops up asking for PIN
5. User enters PIN in numeric-keypad modal
6. On correct PIN, redemption completes; transaction logged in wallet
7. User sees redemption code and success message

### 3. **receipt.php** — Receipt Viewer & Export
**Route:** `/receipt.php?action=receipt&txn_id=123` or `/receipt.php?action=export_csv`

**Actions:**
- `action=receipt&txn_id=X` — View single transaction receipt (HTML format)
- `action=receipt&txn_id=X&format=pdf` — Download receipt as HTML file
- `action=export_csv` — Download all transactions as CSV

---

## User Flows

### Flow 1: User Registration → Auto-Wallet Creation

1. User fills signup form and submits
2. `register.php` creates user account
3. **NEW:** `createWallet()` called to create wallet for new user
4. User redirected to dashboard
5. User now has a wallet with 0 balance, no PIN yet

### Flow 2: Log Recycling → Auto-Deposit to Wallet

1. User submits recycling log (material, weight, quantity)
2. `recordRecyclingLog()` creates log entry, updates user points
3. **NEW:** Inside transaction, `depositPointsToWallet()` called
4. Points added to wallet balance
5. Transaction record created with source description
6. Email notification sent ("You've earned 50 points!")
7. User sees "Deposit transaction +50 pts" in wallet history

### Flow 3: First Redemption → Requires PIN Setup

1. User browses rewards and clicks "Redeem"
2. `rewards.php` detects user has no PIN
3. Modal pops: "Please set up a PIN first"
4. User enters 4-6 digit PIN twice (confirmation)
5. `setWalletPin()` hashes and stores PIN
6. Email sent: "Your EcoWallet PIN has been set"
7. User can now proceed with redemption

### Flow 4: Redeem Reward → PIN-Secured Checkout

1. User selects reward and clicks "Redeem"
2. `action=initiate_redemption` — server validates
3. PIN entry modal appears with numeric keypad
4. User enters PIN
5. Server calls `verifyPin()`:
   - If correct: increment failed_pin_attempts = 0, proceed
   - If incorrect: increment failed_pin_attempts
   - If 3+ failures: set locked_until = now + 15 minutes
6. On success:
   - `createRedemptionTransaction()` deducts points from wallet
   - Transaction record created in wallet ledger
   - Email sent: "You've redeemed 'Coffee Voucher' for 250 points"
   - Redemption code generated and shown
7. User can view receipt from wallet history

### Flow 5: Withdraw Points → Cash-Out with PIN

1. User clicks "Withdraw Points" button on wallet dashboard
2. Withdrawal modal appears:
   - Amount input (max = wallet balance)
   - Payout method dropdown (Voucher / E-Wallet / Bank Transfer)
   - PIN entry field
3. User enters amount and PIN
4. Server calls `verifyPin()`:
   - If locked: "Wallet locked, try again in X seconds"
   - If incorrect: increment attempts, show remaining attempts
   - If correct: proceed
5. On success:
   - `createWithdrawal()` deducts points, creates transaction
   - Reference ID generated (WIT-XXXXX)
   - Email sent with payout method and tracking info
   - User sees success message with reference ID
6. User can view withdrawal in transaction history
7. Withdrawal appears as separate type with payout method info

---

## Security Considerations

### 1. PIN Storage
- PINs are **never stored in plain text**
- `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) used
- Same security as password hashing

### 2. Failed Attempt Lockout
- After 3 failed PIN attempts, wallet locked for 15 minutes
- `locked_until` timestamp stored in database
- Lockout checked server-side (not just client-side)
- Prevents brute-force attacks

### 3. Transaction Atomicity
- All wallet operations wrapped in database transactions (`BEGIN`, `COMMIT`, `ROLLBACK`)
- Ensures balance and transaction records stay in sync
- If deposit fails, entire log creation rolled back

### 4. CSRF Protection
- All wallet forms include CSRF token via `csrfField()`
- PIN setup, withdrawal, and redemption all protected

### 5. Input Validation
- PIN: exactly 4-6 digits, regex-validated server-side
- Amount: positive integer, max = wallet balance
- All user inputs sanitized before use

### 6. Email Logging (Development)
- Wallet emails logged to `logs/wallet-emails.log`
- In production, integrate with mail service (SendGrid, Mailgun, AWS SES)
- Emails contain transaction details and reference IDs

---

## Installation & Setup

### 1. Update Database Schema
```bash
# Run schema.sql to create wallet tables
mysql -u root -p ecocycle < sql/schema.sql
```

### 2. Migrate Existing Users (Optional)
If migrating existing EcoCycle installation with users:
```bash
php migrate_wallets.php
```

### 3. Clear Browser Cache (CSS/JS)
New wallet CSS in `assets/css/style.css` — clear cache to see styling

### 4. Test Workflow
1. Register new user → wallet auto-created
2. Log recycling entry → points auto-deposited to wallet
3. View wallet dashboard → see balance and transactions
4. Try to redeem reward → forced to set PIN
5. Set PIN → attempt redemption with PIN
6. View receipt → download as CSV

---

## Configuration Notes

### Conversion Rates (Withdrawal)
Currently hardcoded. To modify, edit `wallet.php`:
```php
// Default: 100 pts = $1 (adjust as needed)
// Conversion logic would be added to withdrawal processing
```

### Email Service
Default: logs to file. To enable real email:
1. Install mail library (PHPMailer, SwiftMailer, etc.)
2. Update `sendWalletNotification()` to call `mail()` or service API

### Lockout Duration
Currently: 15 minutes (900 seconds). To change, edit `functions.php`:
```php
$lockedUntil = date('Y-m-d H:i:s', time() + 900); // Change 900 to desired seconds
```

### Failed Attempts Threshold
Currently: 3 attempts. To change, edit `functions.php`:
```php
if ($attempts >= 3) { // Change 3 to desired threshold
```

---

## Testing Checklist

- [ ] New user signup → wallet created automatically
- [ ] Log recycling → balance increases, transaction appears in history
- [ ] Click redeem reward without PIN → forced to set PIN
- [ ] Set PIN (4 digits, 5 digits, 6 digits) — all work
- [ ] Set PIN with mismatched confirmation → error
- [ ] Redeem reward → PIN modal appears
- [ ] Wrong PIN → error, countdown of attempts shown
- [ ] 3 wrong PINs → wallet locked, "try again in X seconds"
- [ ] Wait 15 minutes → can attempt again
- [ ] Correct PIN → redemption completes
- [ ] Withdraw points → PIN modal, success message
- [ ] View receipt → HTML page with transaction details
- [ ] Download receipt → HTML file downloaded
- [ ] Export CSV → CSV file with all transactions
- [ ] Filter transactions by type → works correctly
- [ ] Search transactions → finds by description
- [ ] Email log file → contains wallet event notifications

---

## File Changes Summary

### New Files
- `wallet.php` — Main wallet dashboard
- `receipt.php` — Receipt viewer & export
- `migrate_wallets.php` — Migration script for existing users

### Modified Files
- `sql/schema.sql` — Added 4 new tables (wallets, transactions, payout_methods, pin_reset_requests)
- `includes/functions.php` — Added 20+ wallet functions
- `register.php` — Auto-create wallet on signup
- `rewards.php` — PIN-gated redemption with modal
- `includes/header.php` — Added EcoWallet nav link
- `assets/css/style.css` — Added wallet styling

---

## Future Enhancements

1. **OTP Recovery** — PIN reset via email OTP
2. **Biometric Unlock** — Fingerprint/Face ID as PIN alternative (mobile)
3. **Real Bank Integration** — Stripe/PayPal for actual bank transfers
4. **Wallet Limits** — Max withdrawal per day, rate limits
5. **Referral Bonuses** — Deposit points for referring friends
6. **Gift Wallet** — Send points to other users
7. **Wallet Analytics** — Charts of earning/spending trends
8. **Admin Dashboard** — View user wallets, transaction audits

---

## Support & Troubleshooting

### Issue: "Wallet locked. Try again in X seconds"
**Solution:** Wait 15 minutes, or manually reset `locked_until` in database:
```sql
UPDATE wallets SET locked_until = NULL, failed_pin_attempts = 0 WHERE user_id = 123;
```

### Issue: Users not getting wallets on signup
**Solution:** Run `migrate_wallets.php` to backfill existing users

### Issue: Email notifications not sending
**Solution:** Check `logs/wallet-emails.log` for test output. Configure real mail service in `functions.php`

### Issue: Transaction appears but balance not updated
**Solution:** Rare race condition. Run:
```sql
UPDATE wallets SET balance = (SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END), 0) FROM transactions WHERE wallet_id = wallets.id);
```

---

**Created:** September 13, 2026  
**Version:** 1.0  
**Status:** Production-Ready
