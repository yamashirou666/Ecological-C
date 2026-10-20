# EcoWallet Deployment Checklist

## Pre-Deployment

### 1. Database Backup
```bash
mysqldump -u root -p ecocycle > backup_ecocycle_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Review Changes
Review all modified files:
- [x] `sql/schema.sql` — 4 new tables added
- [x] `includes/functions.php` — 20+ wallet functions
- [x] `wallet.php` — New dashboard
- [x] `receipt.php` — Receipt viewer
- [x] `register.php` — Auto-wallet on signup
- [x] `rewards.php` — PIN-gated redemption
- [x] `includes/header.php` — Navigation link
- [x] `assets/css/style.css` — Wallet styling

---

## Deployment Steps

### Step 1: Update Database Schema
```bash
# Run the updated schema
mysql -u root -p ecocycle < sql/schema.sql

# Or, run individual table creation:
mysql -u root -p ecocycle << EOF
CREATE TABLE wallets (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT           NOT NULL UNIQUE,
    balance             INT           NOT NULL DEFAULT 0,
    lifetime_earned     INT           NOT NULL DEFAULT 0,
    lifetime_redeemed   INT           NOT NULL DEFAULT 0,
    pin_hash            VARCHAR(255)  NULL,
    pin_created_at      DATETIME      NULL,
    failed_pin_attempts INT           NOT NULL DEFAULT 0,
    locked_until        DATETIME      NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id          INT          NOT NULL,
    type               ENUM('deposit','withdrawal','redemption') NOT NULL,
    amount             INT          NOT NULL,
    source_description VARCHAR(255) NOT NULL,
    status             ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    reference_id       VARCHAR(50)  NOT NULL UNIQUE,
    balance_after      INT          NOT NULL,
    receipt_data       JSON         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_txn_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    INDEX idx_wallet_created (wallet_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payout_methods (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    type            ENUM('voucher','bank_transfer','ewallet') NOT NULL,
    label           VARCHAR(100) NOT NULL,
    account_details VARCHAR(500) NULL,
    is_default      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pin_reset_requests (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT          NOT NULL,
    otp_code_hash  VARCHAR(255) NOT NULL,
    expires_at     DATETIME     NOT NULL,
    verified       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF
```

### Step 2: Migrate Existing Users (if applicable)
If you have existing users, create wallets for them:
```bash
php migrate_wallets.php
```

Expected output:
```
EcoCycle Wallet Migration
=========================

Found X user(s) without wallets.
Creating wallets...

✓ Created wallet for: Zyk Granada (ID: 1)
...
✓ Migration complete! Created X wallet(s).
```

### Step 3: Create Logs Directory
```bash
mkdir -p logs
chmod 755 logs
```

Wallet email notifications will be logged to `logs/wallet-emails.log`

### Step 4: Test New Features

#### Test 1: Register a new user
1. Go to `/register.php`
2. Create a test account
3. Verify in database that wallet was auto-created:
   ```sql
   SELECT * FROM wallets WHERE user_id = (SELECT MAX(id) FROM users);
   ```

#### Test 2: Log recycling & auto-deposit
1. Log in as test user
2. Go to `/log.php`
3. Submit a recycling log (e.g., 5 kg plastic)
4. Check wallet history at `/wallet.php`
5. Verify transaction appears

#### Test 3: PIN setup & redemption
1. On wallet dashboard, click "🔐 Set PIN"
2. Enter a 4-6 digit PIN twice
3. Go to `/rewards.php`
4. Click "Redeem" on any reward
5. PIN modal should appear
6. Enter correct PIN — redemption should complete
7. Check wallet — balance decreased, transaction logged

#### Test 4: Withdrawal
1. On wallet dashboard, click "💰 Withdraw Points"
2. Enter amount, select payout method, enter PIN
3. Should see success message with reference ID
4. Check transaction history

### Step 5: Verify Email Logging
```bash
# Check if wallet-emails.log exists and has content
tail -20 logs/wallet-emails.log
```

Should show entries like:
```
[2026-09-13 14:30:45] TO: user@example.com | SUBJECT: 💰 50 points deposited to your EcoWallet
...
```

### Step 6: Clear Browser Cache
- Hard refresh (`Ctrl+Shift+R` or `Cmd+Shift+R`)
- Or clear browser cache manually to load new CSS

### Step 7: Update Navigation
Verify "EcoWallet 🏦" link appears in header navigation for logged-in users

---

## Post-Deployment Verification

### Database Integrity
```sql
-- Check all wallets exist
SELECT COUNT(*) AS total_users, COUNT(w.id) AS users_with_wallets
FROM users u LEFT JOIN wallets w ON u.id = w.user_id;

-- Should show: total_users = users_with_wallets

-- Check transactions table is populated
SELECT COUNT(*) as transaction_count FROM transactions;

-- Check for any failed transactions
SELECT * FROM transactions WHERE status != 'completed';
```

### Feature Checklist
- [ ] New users auto-get wallets on signup
- [ ] Recycling logs auto-deposit to wallet
- [ ] Wallet dashboard displays balance, history
- [ ] PIN setup works (4-6 digits, confirmation required)
- [ ] PIN verification works (3 attempts → 15-min lockout)
- [ ] Reward redemption requires PIN
- [ ] Withdrawal creates transaction record
- [ ] Receipts can be viewed and downloaded
- [ ] CSV export works
- [ ] Email notifications logged
- [ ] Navigation link appears

---

## Configuration (Optional)

### Real Email Service
To send actual emails instead of logging, edit `includes/functions.php`:

Replace this section in `sendWalletNotification()`:
```php
// For production, use mail service like SendGrid
$mailgun_api_key = 'YOUR_MAILGUN_API_KEY';
$mailgun_domain = 'YOUR_MAILGUN_DOMAIN';

// Using mail() function (requires server mail config):
mail($user['email'], $subject, $body, "From: noreply@ecocycle.local\r\nContent-Type: text/plain; charset=UTF-8");

// Or use a library like PHPMailer/SwiftMailer
```

### PIN Configuration
To change PIN requirements, edit `includes/functions.php`:

```php
// PIN length validation (currently 4-6)
if (!preg_match('/^\d{4,6}$/', $pin)) { // Change to /^\d{6}$/ for 6-digit only

// Lockout duration (currently 15 minutes = 900 seconds)
$lockedUntil = date('Y-m-d H:i:s', time() + 900); // Change 900 to desired seconds

// Failed attempts before lockout (currently 3)
if ($attempts >= 3) { // Change 3 to desired threshold
```

---

## Rollback (If Needed)

### Quick Rollback
1. Restore database from backup:
   ```bash
   mysql -u root -p ecocycle < backup_ecocycle_YYYYMMDD_HHMMSS.sql
   ```

2. Revert code changes (git):
   ```bash
   git revert HEAD
   ```

### Data Cleanup (If Rollback Mid-Deployment)
```sql
-- Drop wallet tables if needed
DROP TABLE IF EXISTS pin_reset_requests;
DROP TABLE IF EXISTS payout_methods;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS wallets;
```

---

## Monitoring

### Check Wallet Health
```sql
-- Users with unusual balances
SELECT u.name, w.balance, w.lifetime_earned, w.lifetime_redeemed 
FROM users u 
JOIN wallets w ON u.id = w.user_id 
WHERE w.balance < 0 OR w.lifetime_earned < w.lifetime_redeemed;

-- Users with locked wallets
SELECT u.name, w.locked_until 
FROM wallets w 
JOIN users u ON w.user_id = u.id 
WHERE w.locked_until IS NOT NULL;

-- Recent transactions
SELECT t.id, t.type, t.amount, t.status, u.name, t.created_at
FROM transactions t
JOIN wallets w ON t.wallet_id = w.id
JOIN users u ON w.user_id = u.id
ORDER BY t.created_at DESC LIMIT 20;
```

### Monitor Error Logs
```bash
# PHP errors
tail -f /var/log/php-fpm/error.log

# Email notifications
tail -f logs/wallet-emails.log

# Database queries (if enabled)
tail -f /var/log/mysql/query.log
```

---

## Support Contact

For issues, consult:
1. `ECOCYCLE_WALLET_GUIDE.md` — Feature documentation
2. Code comments in `includes/functions.php` — Function details
3. Database schema in `sql/schema.sql` — Data model

---

**Deployment Date:** __________________  
**Deployed By:** __________________  
**Status:** ☐ Testing  ☐ Production

---

## Quick Reference

| Action | File | Function |
|--------|------|----------|
| Create wallet | `register.php` | `createWallet()` |
| Deposit points | `log.php` | `depositPointsToWallet()` |
| Set PIN | `wallet.php` | `setWalletPin()` |
| Verify PIN | `wallet.php` | `verifyPin()` |
| Redeem reward | `rewards.php` | `createRedemptionTransaction()` |
| Withdraw | `wallet.php` | `createWithdrawal()` |
| View receipt | `receipt.php` | `generateReceipt()` |
| Export CSV | `receipt.php` | `exportTransactionsCSV()` |
