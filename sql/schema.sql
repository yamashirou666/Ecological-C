-- EcoCycle database schema + seed data
-- MariaDB / MySQL. Charset utf8mb4.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS pin_reset_requests;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS withdrawal_audit_log;
DROP TABLE IF EXISTS withdrawal_requests;
DROP TABLE IF EXISTS saved_payout_methods;
DROP TABLE IF EXISTS neighborhoods;
DROP TABLE IF EXISTS payout_methods;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS wallets;
DROP TABLE IF EXISTS user_badges;
DROP TABLE IF EXISTS redemptions;
DROP TABLE IF EXISTS recycling_logs;
DROP TABLE IF EXISTS rewards;
DROP TABLE IF EXISTS partners;
DROP TABLE IF EXISTS badges;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(12) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name           VARCHAR(120)  NOT NULL,
    email          VARCHAR(190)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255)  NOT NULL,
    birthdate      DATE          NULL,
    neighborhood   VARCHAR(120)  NOT NULL DEFAULT 'Greendale',
    auth_provider  VARCHAR(20)   NOT NULL DEFAULT 'email',
    email_verified_at DATETIME   NULL,
    avatar_id      VARCHAR(40)   NULL,
    interests      VARCHAR(255)  NULL,
    referral_code_used VARCHAR(50) NULL,
    referred_by_user_id INT       NULL,
    newsletter_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    theme_preference VARCHAR(20) NOT NULL DEFAULT 'system',
    points_balance INT           NOT NULL DEFAULT 0,
    total_points   INT           NOT NULL DEFAULT 0,
    streak_count   INT           NOT NULL DEFAULT 0,
    last_log_date  DATE          NULL,
    is_admin       TINYINT(1)    NOT NULL DEFAULT 0,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    auth_session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_password_reset_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    target_user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_password_reset_admin (admin_user_id, created_at),
    INDEX idx_admin_password_reset_target (target_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Authentication attempts and single-use account tokens
-- ---------------------------------------------------------------------------
CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NULL,
    was_successful  TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempt_identifier_time (identifier_hash, created_at),
    INDEX idx_login_attempt_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_verifications (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_verification_token (token_hash),
    INDEX idx_email_verification_user (user_id, created_at),
    CONSTRAINT fk_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_token (token_hash),
    INDEX idx_password_reset_user (user_id, created_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Verified neighborhood reference data
-- ---------------------------------------------------------------------------
CREATE TABLE neighborhoods (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO neighborhoods (name) VALUES
    ('Greendale'), ('Maple Ridge'), ('Cedar Heights'), ('Oakview'),
    ('South Park'), ('Riverbend'), ('Northfield'), ('Lakeside');

-- ---------------------------------------------------------------------------
-- Wallets (EcoWallet personal banking system)
-- ---------------------------------------------------------------------------
CREATE TABLE wallets (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT           NOT NULL UNIQUE,
    balance             INT           NOT NULL DEFAULT 0,
    lifetime_earned     INT           NOT NULL DEFAULT 0,
    lifetime_redeemed   INT           NOT NULL DEFAULT 0,
    pin_hash            VARCHAR(255)  NULL,
    pin_created_at      DATETIME      NULL,
    pin_reset_at        DATETIME      NULL,
    failed_pin_attempts INT           NOT NULL DEFAULT 0,
    locked_until        DATETIME      NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Transactions (wallet ledger: deposits, withdrawals, redemptions)
-- ---------------------------------------------------------------------------
CREATE TABLE transactions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id          INT          NOT NULL,
    type               ENUM('deposit','withdrawal','redemption','adjustment') NOT NULL,
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

-- ---------------------------------------------------------------------------
-- Payout Methods (bank transfer, e-wallet, voucher options)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- PIN Reset Requests (OTP-based PIN recovery)
-- ---------------------------------------------------------------------------
CREATE TABLE pin_reset_requests (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT          NOT NULL,
    otp_code_hash  VARCHAR(255) NOT NULL,
    expires_at     DATETIME     NOT NULL,
    verified       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Saved payout methods and withdrawal audit trail
-- ---------------------------------------------------------------------------
CREATE TABLE saved_payout_methods (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    method          ENUM('bank_transfer','ewallet') NOT NULL,
    provider        VARCHAR(40) NOT NULL,
    account_label   VARCHAR(100) NOT NULL,
    account_last4   VARCHAR(4) NOT NULL,
    account_hash    VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_saved_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_saved_payout_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE withdrawal_audit_log (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    transaction_id  INT NULL,
    action          VARCHAR(40) NOT NULL,
    amount          INT NULL,
    payout_method   VARCHAR(40) NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    details         JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_withdrawal_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_withdrawal_audit_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE withdrawal_requests (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    wallet_id       INT NOT NULL,
    reference_id    VARCHAR(50) NOT NULL,
    token_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    authorization_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    authorization_token_used_at DATETIME NULL,
    pin_verified_at DATETIME NULL,
    status          ENUM('pending','authorized','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
    points_amount   INT NOT NULL,
    points_reserved INT NOT NULL DEFAULT 0,
    gross_amount    DECIMAL(10,2) NOT NULL,
    fee             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_amount      DECIMAL(10,2) NOT NULL,
    payout_method   ENUM('voucher','bank_transfer','ewallet') NOT NULL,
    provider        VARCHAR(40) NOT NULL,
    payout_label    VARCHAR(100) NULL,
    account_holder  VARCHAR(100) NULL,
    account_last4   VARCHAR(4) NULL,
    voucher_code    VARCHAR(40) NULL,
    transaction_id  INT NULL,
    expires_at      DATETIME NOT NULL,
    authorized_at   DATETIME NULL,
    completed_at    DATETIME NULL,
    expired_at      DATETIME NULL,
    cancelled_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_withdrawal_request_reference (reference_id),
    UNIQUE KEY uq_withdrawal_request_token (token_hash),
    UNIQUE KEY uq_withdrawal_request_idempotency (idempotency_key_hash),
    INDEX idx_withdrawal_request_user_created (user_id, created_at),
    INDEX idx_withdrawal_request_status_expiry (status, expires_at),
    INDEX idx_withdrawal_request_transaction (transaction_id),
    CONSTRAINT fk_withdrawal_request_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_withdrawal_request_wallet
        FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_withdrawal_request_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Recycling logs
-- ---------------------------------------------------------------------------
CREATE TABLE recycling_logs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT           NOT NULL,
    material_type       VARCHAR(30)   NOT NULL,
    quantity            INT           NOT NULL DEFAULT 1,
    weight_kg           DECIMAL(8,2)  NOT NULL DEFAULT 0,
    points_awarded      INT           NOT NULL DEFAULT 0,
    co2_saved_kg        DECIMAL(8,2)  NOT NULL DEFAULT 0,
    verification_status ENUM('verified','pending') NOT NULL DEFAULT 'verified',
    note                VARCHAR(255)  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Partners (local businesses / causes)
-- ---------------------------------------------------------------------------
CREATE TABLE partners (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(150) NOT NULL,
    category      VARCHAR(60)  NOT NULL,
    contact_info  VARCHAR(190) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Rewards (marketplace offers)
-- ---------------------------------------------------------------------------
CREATE TABLE rewards (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    partner_id         INT          NOT NULL,
    title              VARCHAR(150) NOT NULL,
    description        VARCHAR(400) NOT NULL,
    category           VARCHAR(60)  NOT NULL DEFAULT 'discount',
    points_cost        INT          NOT NULL,
    quantity_available INT          NOT NULL DEFAULT 100,
    expiry_date        DATE         NULL,
    icon               VARCHAR(10)  NOT NULL DEFAULT '🎁',
    CONSTRAINT fk_reward_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Redemptions
-- ---------------------------------------------------------------------------
CREATE TABLE redemptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    reward_id       INT          NOT NULL,
    redemption_code VARCHAR(20)  NOT NULL UNIQUE,
    points_spent    INT          NOT NULL,
    status          ENUM('active','used','expired') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_redeem_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_redeem_reward FOREIGN KEY (reward_id) REFERENCES rewards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Badges / achievements
-- ---------------------------------------------------------------------------
CREATE TABLE badges (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(40)  NOT NULL UNIQUE,
    name           VARCHAR(80)  NOT NULL,
    description    VARCHAR(200) NOT NULL,
    icon           VARCHAR(10)  NOT NULL DEFAULT '🏅'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_badges (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT      NOT NULL,
    badge_id   INT      NOT NULL,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_badge (user_id, badge_id),
    CONSTRAINT fk_ub_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_ub_badge FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------
INSERT INTO partners (business_name, category, contact_info) VALUES
('Bean & Leaf Café',      'Food & Drink', 'hello@beanandleaf.example'),
('Verde Grocery Co-op',   'Grocery',      'support@verdegrocery.example'),
('ReWear Thrift',         'Retail',       'contact@rewear.example'),
('SolarWorks',            'Eco Products', 'info@solarworks.example'),
('City Tree Fund',        'Donation',     'give@citytreefund.example');

INSERT INTO rewards (partner_id, title, description, category, points_cost, quantity_available, expiry_date, icon) VALUES
(1, 'Free Regular Coffee',        'Redeem for one free regular coffee at Bean & Leaf Café.',                'discount', 250,  200, '2027-12-31', '☕'),
(1, '20% Off Any Pastry',         'Enjoy 20% off any freshly baked pastry.',                                'discount', 150,  300, '2027-12-31', '🥐'),
(2, '$5 Off Groceries',           'Take $5 off a grocery order of $30 or more at Verde Grocery Co-op.',     'discount', 400,  150, '2027-12-31', '🛒'),
(2, 'Reusable Produce Bag Set',   'A set of 3 organic-cotton mesh produce bags.',                           'eco-product', 600, 80, '2027-12-31', '🥬'),
(3, '15% Off Second-Hand Finds',  'Save 15% on your next thrift haul at ReWear.',                           'discount', 300,  120, '2027-12-31', '👕'),
(4, 'Solar Phone Charger',        'Compact solar-powered phone charger from SolarWorks.',                   'eco-product', 1500, 40, '2027-12-31', '🔋'),
(5, 'Plant a Tree (Donate)',      'Convert 500 points into a real tree planted by the City Tree Fund.',     'donation', 500, 9999, NULL,          '🌳'),
(5, 'Fund a Community Compost',   'Donate points toward a neighborhood composting station.',                'donation', 800, 9999, NULL,          '♻️');

INSERT INTO badges (code, name, description, icon) VALUES
('first_log',    'First Steps',     'Logged your very first recycling entry.',            '🌱'),
('streak_7',     'Week Warrior',    'Maintained a 7-day recycling streak.',               '🔥'),
('kg_100',       'Century Diverter','Diverted 100 kg of material from landfill.',         '🏆'),
('points_1000',  'Point Collector', 'Earned 1,000 lifetime EcoPoints.',                   '⭐'),
('all_materials','Sorting Master',  'Recycled at least one item of every material type.', '🧭');

-- Create the administrator separately with migrate_admin.php and a securely
-- configured ECOCYCLE_ADMIN_PASSWORD; this schema does not seed a known password.
