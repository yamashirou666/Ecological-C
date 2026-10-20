# ♻️ EcoCycle — Community Recycling Rewards Platform

> Turn everyday recycling into a rewarding community habit.
> A web platform supporting **UN SDG 12 — Responsible Consumption and Production**.

EcoCycle lets residents log their recycling, earn points for every kilogram diverted
from landfill, redeem those points for real local rewards, and watch their
neighborhood's collective environmental impact grow on a live dashboard.

---

## ✨ Features (MVP — Phase 1)

- **Landing page** explaining the mission, SDG 12 alignment (Targets 12.5 & 12.8), and live community impact stats.
- **Accounts** — secure sign up / log in (hashed passwords, CSRF-protected forms, session-based auth) and an editable profile.
- **Recycling log** — record entries by material (plastic, glass, paper, metal, e-waste, organic) with a live points preview and a weight estimator.
- **Points, levels & streaks** — points scale by material; users climb from 🌱 Sprout → 🌿 Sapling → 🌳 Green Guardian → 🏆 Eco Champion. Daily streaks and achievement badges included.
- **Impact dashboard** — personal stats (kg diverted, CO₂ saved, tree-years equivalent), a level-progress bar, and Chart.js visualisations (7-day CO₂ trend + material breakdown).
- **Rewards marketplace** — browse offers from 5 mock partners across discounts, eco-products, and donations; redeem points for a unique code (transaction-safe, stock-aware).
- **Community leaderboard** — top recyclers and top neighborhoods.
- **Educational hub** — per-material sorting guides, contamination-myth busting, and a seasonal challenge.
- **Owner / Admin dashboard** — city-wide analytics (members, participation %, waste diverted, CO₂ saved, tree-years, points issued, redemptions), a per-neighborhood district heatmap, a live activity feed, member role management (grant/revoke admin), and an exportable **sustainability impact report (CSV)** for grant & CSR reporting.

---

## 🧱 Tech Stack

| Layer     | Technology                          |
|-----------|-------------------------------------|
| Backend   | PHP 8.2 (PDO, prepared statements)  |
| Database  | MariaDB / MySQL (utf8mb4)           |
| Frontend  | Server-rendered PHP + Tailwind CSS (CDN) |
| Charts    | Chart.js (CDN)                      |
| Server    | Apache (XAMPP)                      |

No build step or package manager required — it runs directly under XAMPP's `htdocs`.

---

## 🚀 Getting Started

### 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) with **Apache** and **MySQL/MariaDB** running.
- This project placed in `c:\xampp\htdocs\Project EchoCycle` (already the case).

### 2. Start services
Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### 3. Create the database
Visit the setup script once in your browser:

```
http://localhost/Project%20EchoCycle/setup.php
```

This creates the `ecocycle` database, all tables, and seeds partners, rewards, and badges.

### 4. (Optional) Load demo data
To populate the leaderboard and community stats with sample residents:

```
http://localhost/Project%20EchoCycle/seed_demo.php
```

Demo logins: any of `maya@example.com`, `leo@example.com`, `aisha@example.com`,
`tom@example.com`, `sofia@example.com`, `noah@example.com` — all with password **`password123`**.

### 5. Open the app

```
http://localhost/Project%20EchoCycle/
```

Create an account, log your first item, and watch your impact grow. 🌍

### 6. Run EcoCycle as a desktop app with Electron

Electron wraps the existing PHP site in a desktop window. Apache and MySQL must
still be running because PHP and the database continue to run through XAMPP.

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open PowerShell in this project folder.
3. Install dependencies once:

```powershell
& 'C:\Program Files\nodejs\npm.cmd' install
```

4. Launch the desktop app:

```powershell
& 'C:\Program Files\nodejs\npm.cmd' start
```

The Electron entry point is `electron/main.js`, and it loads the same local
EcoCycle URL used by the browser version. If PowerShell allows `npm` scripts,
you can use `npm install` and `npm start` instead.

To stop the app, close its window. To run the browser version, visit the local
URL above as usual.

### 7. Owner / Admin access

The fresh schema seeds an **owner account** (Zyk Granada). If you are upgrading an
existing database, run the idempotent migration once to add the admin column and
create the owner account without touching your data:

```
http://localhost/Project%20EchoCycle/migrate_admin.php
```

Then log in and an **Admin** link appears in the top nav:

- **Email:** `zyk.granada@ecocycle.local`
- **Password:** `passw0rd!` — please change it on your profile after first login.

Admins can grant/revoke admin access to other members from the dashboard (you cannot
change your own owner status).

---

## 🔧 Configuration

Database credentials live in [`config/config.php`](config/config.php). The defaults match a
stock XAMPP install (`root` / empty password on `127.0.0.1:3306`). Adjust if yours differ.

---

## 📁 Project Structure

```
Project EchoCycle/
├── config/
│   └── config.php          # DB credentials + PDO connection
├── includes/
│   ├── functions.php       # Points/levels/impact engine, auth, CSRF, helpers
│   ├── header.php          # Shared <head>, nav, Tailwind theme, flash messages
│   └── footer.php          # Shared footer + scripts
├── assets/css/style.css    # Small custom styles on top of Tailwind
├── sql/schema.sql          # Schema + seed data
├── setup.php               # One-time DB installer
├── migrate_admin.php       # Idempotent upgrade: adds is_admin + owner account
├── seed_demo.php           # Optional demo-data seeder
├── index.php               # Landing page
├── register.php / login.php / logout.php
├── profile.php             # Profile, badges, redemption history
├── log.php                 # Recycling log form + history
├── dashboard.php           # Personal impact dashboard (charts)
├── rewards.php             # Marketplace + redemption flow
├── leaderboard.php         # Community leaderboard
├── admin.php               # Owner/admin analytics + member management
└── learn.php               # Educational hub
```

---

## 📊 How Points & Impact Are Calculated

Each material has a **points-per-kg** rate and a **CO₂-saved-per-kg** factor
(educational estimates), defined in `materials()` in `includes/functions.php`:

| Material  | Points/kg | CO₂ saved (kg/kg) |
|-----------|-----------|-------------------|
| E-waste   | 15        | 2.0               |
| Metal     | 12        | 4.0               |
| Plastic   | 10        | 1.5               |
| Glass     | 6         | 0.3               |
| Paper     | 5         | 0.9               |
| Organic   | 4         | 0.5               |

Tree-years equivalent assumes a mature tree absorbs ~21 kg CO₂/year.

---

## ♿ Accessibility & Responsiveness

- Mobile-first layout with a collapsible nav.
- Semantic landmarks, a skip-to-content link, ARIA labels on charts and icons.
- Visible focus rings and a `prefers-reduced-motion` fallback.

---

## 🛣️ Roadmap (Phase 2+)

QR / smart-bin check-in, business partner self-service portal, log verification queue,
social challenges, a map of recycling centers, and AI-based waste-sorting
image recognition.

---

*Impact figures are educational estimates intended to motivate participation, not audited measurements.*
