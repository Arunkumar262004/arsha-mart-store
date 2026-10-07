# Store Order & Inventory Mini-System

A retail-counter billing app. The counter records customer orders against a product catalog, and stock stays in sync, including when two orders arrive at the same moment.

- **Backend:** Laravel 13 (PHP 8.3) JSON API, PostgreSQL (hosted on [Neon](https://neon.tech)), database queue
- **Frontend:** React 19 + Vite 8 + Tailwind CSS 4, a separate app that talks to the API over CORS

```
order-inventory-system-mallow/
├── backend/    Laravel API (migrations, services, jobs, tests)
├── frontend/   React single-page app (billing screen, history, low stock)
└── prompts/    AI prompt screenshots (prompt log)
```

---

## 1. Prerequisites

| Tool | Version used | Check with |
|---|---|---|
| PHP | 8.3+ with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `mbstring`, `openssl` | `php -v`, `php -m` |
| Composer | 2.x | `composer -V` |
| Database | **MySQL 8** locally (e.g. via Laragon), or a **PostgreSQL** database on [Neon](https://console.neon.tech) (used in production) | `mysql --version` |
| Node.js / npm | Node 20.19+ (built on 24) / npm 10+ | `node -v`, `npm -v` |

## 2. Setup

### Backend

```bash
cd backend
composer install
cp .env.example .env          # then fill in the DB_* values (see below)
php artisan key:generate
```

Pick a database in `backend/.env`:

- **Local MySQL (fastest for development):** create an empty database, e.g. `CREATE DATABASE mallow_mart;`, then set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=mallow_mart` and your MySQL user and password.
- **Neon PostgreSQL (what production uses):** set `DB_CONNECTION=pgsql` and copy the other `DB_*` values from the Neon connection string. Use Neon's **direct** host (without `-pooler`); migrations fail through the pooler. Set `DB_SSLMODE=require`, and enable `extension=pdo_pgsql` in `php.ini`.

```bash
php artisan migrate --seed    # 10 products (some already low on stock) + 10 customers
```

### Frontend

```bash
cd frontend
npm install
cp .env.example .env          # VITE_API_URL=http://localhost:8000/api
```

## 3. Running

**One command, one terminal.** Run this from the repo root (the first time, run `npm install` there too):

```bash
npm run dev
```

It uses [concurrently](https://github.com/open-cli-tools/concurrently) to start all three processes with coloured, labelled output. Ctrl+C stops them all.

| Label | Process | URL / role |
|---|---|---|
| `api` | `php artisan serve` | http://localhost:8000 |
| `queue` | `php artisan queue:listen --tries=3` | sends the confirmation email + WhatsApp a few seconds after each bill |
| `web` | `npm --prefix frontend run dev` | http://localhost:5173 |

The worker is `queue:listen` rather than `queue:work` because it reloads the app for every job. Changes to code or `.env` apply without restarting anything, which is the right trade-off for development. In production use `queue:work` under a process manager (Supervisor, systemd, or NSSM on Windows) so it restarts on crash and at boot.

You can still run the three separately if you prefer: `npm run dev:api`, `npm run dev:queue` and `npm run dev:web`.

> Why not run the jobs inline (`QUEUE_CONNECTION=sync`)? Then no worker is needed, but every bill would wait for the email and WhatsApp APIs, and the brief asks for a *queued* job. The worker keeps billing instant, and failed sends are retried (3 attempts with backoff) and kept in `failed_jobs`.

> **If emails and WhatsApp messages aren't arriving, the queue worker isn't running.** A bill only *queues* its notifications; the `queue` process is what sends them. Run `npm run dev` from the root (not just `php artisan serve`), or start `php artisan queue:work` in `backend/`. Jobs that were queued while no worker ran are sent as soon as one starts.

Open **http://localhost:5173**. With the default `MAIL_MAILER=log`, the confirmation emails are written to `backend/storage/logs/laravel.log`.

**Sign in** with one of the seeded demo accounts. The password for all three is `password123`, and the login page has one-click buttons for them.

| Account | Role | Sees |
|---|---|---|
| `admin@store.com` | Admin | Everything, plus **Settings** (Users, Roles & Permissions, Password Reset) |
| `manager@store.com` | Store Manager | Dashboard, billing, orders, inventory (add/edit products, restock) |
| `cashier@store.com` | Cashier | Dashboard, billing and order history only |
| `branch@store.com` | Cashier, Anna Nagar Branch only | Same as Cashier, locked to the branch store |

The seed also has an **Accountant** role (purchases, returns, payments, expenses, ledgers and GST) that you can give to a user.

Seeded customers you can try: `arun@example.com` (mobile `9578777764`) and `priya@example.com` (mobile `9092276443`). Type either the mobile number or the email, and the other two fields fill in. Bills for these customers send real WhatsApp messages, so change the numbers in `CustomerSeeder` to your own before testing.

### Optional: real email and WhatsApp

Both notifications work without any keys: the email goes to the log, and WhatsApp is skipped with a log warning. To send them for real, set these in `backend/.env`:

```env
# Email via Brevo SMTP (free: 300 emails/day, https://app.brevo.com → SMTP & API)
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=2525                               # 587 also works locally; Render's free plan blocks 587
MAIL_USERNAME=xxxxxxx@smtp-brevo.com         # "Login" on Brevo's SMTP page
MAIL_PASSWORD=xsmtpsib-xxxxxxxx              # an SMTP key
MAIL_FROM_ADDRESS="you@yourdomain.com"       # must be a verified sender in Brevo

# WhatsApp via wasender.dev (https://wasender.dev)
WASENDER_API_TOKEN=wsk_xxxxxxxx
WASENDER_API_URL=https://api.wasender.dev/messages/text
DEFAULT_COUNTRY_CODE=91                       # prefixed to 10-digit local numbers
```

Then restart `php artisan queue:work` (a running worker keeps the old config).

## 4. Tests

```bash
cd backend
php artisan test
```

There are 76 tests. They cover login, logout, rate limiting and password changes; role-based access (a cashier gets 403 on inventory and settings, and even a role holding every permission can't reach settings); role and user management, admin password resets and self-lock-out protection; product create/edit, restocks and corrections (stock can never go negative), with every change in the stock log; the dashboard and notifications; order creation, totals and rounding, insufficient stock, all-or-nothing rollback, validation, customer reuse, mobile-number lookup and normalisation, payment and change, order history, low-stock thresholds, both queued jobs (the WhatsApp API is faked with `Http::fake`), CORS, and a real multi-process concurrency test. `phpunit.xml` uses the `array` mailer and blanks the WhatsApp token, so tests never send real messages.

> The tests run on an in-memory SQLite database (set in `phpunit.xml`), so they need nothing installed and never touch the Neon data. The concurrency test needs real row locks, which SQLite ignores, so it skips itself there. To run it, point `phpunit.xml` at a separate PostgreSQL test database (never the live one: tests wipe their tables).

---

## 5. API

Base URL: `http://localhost:8000/api`. Every response is JSON. A validation or stock failure returns `422` with Laravel's standard `{ message, errors }` shape.

**Authentication:** call `POST /login` with `{email, password}` to get a Sanctum token, then send `Authorization: Bearer <token>` on every other request. With no token or an expired one you get `401`; without the needed permission you get `403`. Tokens expire after 12 hours (`SANCTUM_EXPIRATION`, in minutes).

| Method | Endpoint | Permission | Purpose |
|---|---|---|---|
| `POST` | `/login` | none (5 attempts/min) | Get a token + the user's permissions |
| `GET` / `POST` | `/me` · `/logout` | signed in | Current user & permissions · revoke this token |
| `PUT` | `/me/password` | signed in | Change own password (other sessions are signed out) |
| `GET` | `/dashboard` | `dashboard.view` | Sales stats, 7-day chart data, recent bills, stock summary |
| `GET` | `/notifications` | signed in | Low/out-of-stock alerts (only for users with `products.view`) |
| `POST` | `/orders` | `billing.create` | Create an order |
| `GET` | `/customers/lookup?email=` or `?phone=` | `billing.create` | Find a customer by email **or** mobile number (billing auto-fill) |
| `GET` | `/orders/{id}` | `orders.view` | Show one order |
| `GET` | `/customers/{email}/orders?page=&per_page=` | `orders.view` | A customer's order history, newest first (paginated) |
| `GET` | `/products` | `products.view` or `billing.create` | Catalog (also feeds the bill's product dropdown) |
| `GET` | `/products/low-stock?threshold=` | `products.view` | Products with stock **below** the threshold |
| `POST` / `PUT` | `/products` · `/products/{id}` | `products.manage` | Add a product (with opening stock) · edit details |
| `POST` | `/products/{id}/stock` | `stock.adjust` | `{type: restock\|correction, quantity (signed), note}` |
| `GET` | `/products/{id}/movements` | `products.view` | Stock log for a product |
| `GET` | `/permissions` | Admin | Permission catalogue, grouped |
| `GET/POST/PUT/DELETE` | `/roles`, `/users` | Admin | Manage roles and users |
| `PUT` | `/users/{id}/password` | Admin | Reset a user's password (signs them out everywhere) |

### Create order

```http
POST /api/orders
Content-Type: application/json
Accept: application/json

{
  "customer_email": "arun@example.com",
  "customer_name": "Arun Kumar",      // required only for a new customer
  "customer_phone": "98765 43210",       // optional; enables the WhatsApp bill
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 2, "quantity": 5 }
  ],
  "amount_paid": 250,                     // optional
  "interstate": false                     // optional; true = customer outside Tamil Nadu (IGST)
}
```

`201 Created`:

```json
{
  "data": {
    "id": 1,
    "order_number": "ORD-20260925-IXBC2Z",
    "customer": { "id": 1, "name": "Arun Kumar", "email": "arun@example.com" },
    "items": [
      { "product_id": 1, "product_name": "Colgate Toothpaste 100g", "quantity": 2,
        "unit_price": "50.00", "tax_percent": "18.00",
        "line_subtotal": "100.00", "line_tax": "18.00", "line_total": "118.00",
        "cgst_percent": "9.00", "cgst_amount": "9.00", "sgst_percent": "9.00", "sgst_amount": "9.00",
        "igst_percent": "0.00", "igst_amount": "0.00" },
      { "product_id": 2, "product_name": "Parle-G Biscuit", "quantity": 5,
        "unit_price": "10.00", "tax_percent": "5.00",
        "line_subtotal": "50.00", "line_tax": "2.50", "line_total": "52.50",
        "cgst_percent": "2.50", "cgst_amount": "1.25", "sgst_percent": "2.50", "sgst_amount": "1.25",
        "igst_percent": "0.00", "igst_amount": "0.00" }
    ],
    "subtotal": "150.00", "tax_total": "20.50", "grand_total": "170.50",
    "is_interstate": false, "cgst_amount": "10.25", "sgst_amount": "10.25", "igst_amount": "0.00",
    "amount_paid": "250.00", "change_due": "79.50",
    "confirmation_sent_at": null,
    "created_at": "2026-09-25T17:56:47+00:00"
  }
}
```

Insufficient stock gives `422`, and the whole order is rejected:

```json
{
  "message": "Insufficient stock for one or more products.",
  "errors": { "items.0.quantity": ["Only 2 unit(s) of Eggs (12) in stock; 3 requested."] },
  "shortages": [{ "index": 0, "product_id": 5, "name": "Eggs (12)", "requested": 3, "available": 2 }]
}
```

---

## 6. How the frontend talks to the backend (CORS)

The React app (`localhost:5173`) and the API (`localhost:8000`) are on different origins, so the browser enforces CORS:

1. For a JSON `POST`, the browser first sends a preflight `OPTIONS /api/orders` with `Origin: http://localhost:5173`.
2. Laravel's built-in `HandleCors` middleware reads [backend/config/cors.php](backend/config/cors.php) and answers with `Access-Control-Allow-Origin: http://localhost:5173`, plus the allowed methods and headers.
3. The browser then sends the real request. React reads the response.

The configuration:

- `allowed_origins` comes from `FRONTEND_URL` in `backend/.env`. It accepts a comma-separated list, e.g. for a deployed frontend. Only that origin is allowed; there is no `*`.
- `supports_credentials` is `false`. The API is stateless, with no cookies or sessions: the login token travels in the `Authorization` header, which is in `allowed_headers`.
- The token is kept in `localStorage`, so a page refresh keeps you signed in. An axios interceptor attaches it to every request. On any `401` (token expired or revoked, e.g. after an admin password reset) the app signs out and shows the login page. Trade-off: `localStorage` can be read by injected scripts (XSS). HttpOnly cookies via Sanctum's SPA mode would avoid that, but they need same-site hosting and CSRF handling, which is more than a two-origin dev setup needs.
- On the frontend, [frontend/src/api/client.js](frontend/src/api/client.js) is a single axios instance with `baseURL = VITE_API_URL`. It sends `Accept: application/json`, so Laravel always returns validation errors as JSON 422 instead of redirecting.
- Vite runs with `strictPort: true`, so the dev server can't silently move to another port that CORS would then block.

---

## 7. Design decisions

### Schema

```
roles (id, name UNIQUE, description, is_admin)          role_permissions (role_id FK, permission) PK(both)
users (…, role_id FK, is_active, last_login_at)
stock_movements (id, product_id FK, user_id FK NULL, order_id FK NULL, type, quantity ±, stock_after, note)
customers (id, name, email UNIQUE, phone NULL UNIQUE)
products  (id, name, code UNIQUE, price, tax_percent, stock UNSIGNED, INDEX stock)
orders    (id, order_number UNIQUE, customer_id FK, subtotal, tax_total, grand_total,
           is_interstate, cgst_amount, sgst_amount, igst_amount,
           created_by FK users NULL, amount_paid NULL, change_due NULL, confirmation_sent_at NULL, whatsapp_sent_at NULL,
           INDEX(customer_id, created_at))
order_items (id, order_id FK cascade, product_id FK restrict, unit_price, tax_percent, quantity,
             line_subtotal, line_tax, line_total,
             cgst_percent, cgst_amount, sgst_percent, sgst_amount, igst_percent, igst_amount,
             UNIQUE(order_id, product_id))
```

- **`order_items` is the supporting table.** It holds the many-to-many link between orders and products, plus per-line data.
- **Prices and tax are copied onto each line.** Changing a product's price or tax later must not rewrite old bills. A test covers this.
- **Totals are stored on `orders`.** They could be recomputed from the lines, but a bill is a financial record. Storing what was charged makes history reads cheap and keeps the record fixed.
- **`stock` is `UNSIGNED`.** This is the last line of defence: even buggy code can't push stock below zero.
- **Money uses `DECIMAL`, and all arithmetic uses integer paise** ([backend/app/Support/Money.php](backend/app/Support/Money.php)). This avoids float drift such as `0.1 + 0.2`.
- Deleting a product that has been sold is blocked (`restrictOnDelete`), so history never loses its lines.

### Code organisation

- **Controllers are thin.** They validate (via a Form Request), call a service and return an API Resource.
- **`OrderService::placeOrder()`** holds all the order logic: resolve the customer, lock the products, check stock, compute totals, save, deduct stock, then dispatch the job.
- **`StoreOrderRequest`** handles input validation, including rejecting duplicate products and requiring a name only for a new customer.
- **`InsufficientStockException`** renders itself as a 422 response in the same shape as a validation error, so the UI can put the message next to the right line.
- **API Resources** control the JSON shape, so internal columns never leak.

### Concurrency: no overselling

The check-and-deduct step runs inside one `DB::transaction()`:

```php
$products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get();  // SELECT ... FOR UPDATE
// check stock -> create order + lines -> decrement stock
```

- `lockForUpdate()` takes an exclusive row lock. A second order for the same product blocks at this line until the first commits. It then reads the already-reduced stock and fails with a clean 422.
- Locks are taken in primary-key order, so two orders for overlapping products can't deadlock each other. `DB::transaction(..., attempts: 3)` retries if the database reports a deadlock anyway.
- Customers are created with `createOrFirst()`, so two first-time orders for the same email can't create duplicate customers.

**How it's proven:** `OrderConcurrencyTest` starts real, separate PHP processes (`php artisan orders:place …`). Each one opens its own database connection and goes through the same `OrderService` the API uses. To force the requests to overlap, rather than run one after another by chance, the test first holds the product's row lock itself. It starts all the processes, waits until they are queued behind that lock, then releases it so they compete at the same instant.

- Stock 1 with 2 buyers: exactly 1 order is placed and 1 is rejected.
- Stock 3 with 6 buyers: exactly 3 are placed, 3 are rejected, and stock ends at 0.

I checked that the test really catches the bug: with `lockForUpdate()` removed, the 6-buyer test fails. The losing requests get past the stock check and are only stopped by the unsigned column, as a 500 error rather than a clean rejection.

*Alternative considered:* a conditional atomic update, `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?`, checking the number of affected rows. It is lock-free and also correct. I chose pessimistic locking because a multi-line order needs to check every line before committing to any of them, and the lock makes that all-or-nothing logic easy to follow.

### Queued jobs (email + WhatsApp)

- **`SendOrderConfirmation`** (email) and **`SendOrderWhatsAppConfirmation`** both implement `ShouldQueue` and run on the `database` queue.
- Both are dispatched **after the transaction commits**, so an order that was rolled back never notifies anyone. The WhatsApp job is dispatched only when the customer has a mobile number.
- **They are two separate jobs on purpose.** If WhatsApp is down, its retries never re-send the email, and the other way round.
- **Email:** a real Mailable (`OrderConfirmationMail`, a Markdown template) sent through the configured mailer: `log` by default, Brevo SMTP once the `MAIL_*` settings are filled in.
- **WhatsApp:** [`WasenderClient`](backend/app/Services/WhatsApp/WasenderClient.php) posts the PDF bill to wasender.dev's `/messages/document` endpoint as a base64 data URI (number without the `+`, Bearer token), with the itemised order summary as the caption. A 2xx means WhatsApp accepted it. A non-2xx response throws, so the queue retries. With no token set, the job logs a warning and skips.
- **The PDF bill** is an 80 mm thermal receipt rendered by [`ReceiptPdf`](backend/app/Services/ReceiptPdf.php) (dompdf). The email attaches it and the WhatsApp job sends it.
- Both use `tries = 3` with backoff and are **idempotent**. Each sets its own timestamp (`confirmation_sent_at` / `whatsapp_sent_at`) and skips if it is already set, so a retry never sends twice.

### Mobile numbers

- **Stored in E.164** ([backend/app/Support/Phone.php](backend/app/Support/Phone.php)). `98765 43210`, `09876543210`, `919876543210` and `+91-98765-43210` all become `+919876543210`. That makes lookups match however the number was typed, and it is the format WhatsApp needs. Ten-digit numbers get `DEFAULT_COUNTRY_CODE` (91) added.
- **The phone is unique but optional.** Email stays the customer's identity, because the brief's order-history endpoint is keyed by email. The phone is a second way to find the same customer.

---

## 8. Assumptions

1. **Customer identity is the email** (lower-cased and trimmed, so `Arun@Example.com` is the same customer). For an existing customer the submitted name is ignored. The saved name wins, and the UI makes the name field read-only once the email is recognised. A name is required only for a new customer.
2. **Tax is calculated per line and rounded half-up to the paisa**, then summed. For example, 3 × ₹9.99 at 5% gives tax ₹1.4985, which rounds to ₹1.50.
3. **An order is all-or-nothing.** If any line is short on stock, the whole order is rejected and nothing is deducted. A partial bill at a counter would confuse the customer.
4. **Each product may appear only once per order.** A duplicate `product_id` is a validation error; the client should raise the quantity instead. Quantity must be between 1 and 10,000, with at most 50 lines.
5. **"Below the threshold" is strict** (`stock < threshold`). The default is `LOW_STOCK_THRESHOLD=10` in `.env` (read through `config/inventory.php`), and it can be overridden per request with `?threshold=`.
6. **"Amount given by customer" and "Balance to return"** from the wireframe are optional. If `amount_paid` is sent, the server checks it covers the grand total (otherwise 422) and stores the change. The note/coin breakdown (e.g. `1×₹20 + 1×₹2 + 80 paise`) is display-only and calculated in the browser.
7. **The wireframe's "emails PDF to customer" is implemented.** The queued confirmation email attaches the bill as an 80 mm thermal-receipt PDF (`App\Services\ReceiptPdf`, rendered with dompdf), and the WhatsApp job sends the same PDF as a document with the order summary as its caption. The PDF goes to WhatsApp inline as base64, so it needs no public file URL.
    - **GST is split the Indian way.** A sale inside the store's state (`STORE_STATE`, default Tamil Nadu) is taxed as **CGST + SGST**, each at half the product's rate. A sale to a customer in another state (`"interstate": true`, a checkbox on the billing screen) is taxed as **IGST** at the full rate.
    - **Rates are stored per line, amounts per line and per order.** One bill can mix 5%, 12% and 18% items, so a single order-level percentage would be wrong; the order keeps the CGST/SGST/IGST totals and each line keeps its own rates and amounts. CGST and SGST are each rounded separately, as on a GST invoice.
8. **Every endpoint except `/login` requires sign-in,** including the brief's three order endpoints. A billing counter handles money and customer data, so anonymous access isn't reasonable.
    - **Access is permission-based:** roles are bundles of permissions from [backend/config/permissions.php](backend/config/permissions.php), each registered as a Gate ability and enforced by `can:` route middleware. The frontend hides menus the user can't use, but the server is the real gate.
    - **Settings is Admin-only by design:** it isn't an assignable permission, so no custom role can ever manage users or roles. The Admin role can't be edited or deleted, and admins can't deactivate or demote themselves.
    - **Password reset is admin-driven:** an admin sets a new password for the user from Settings, which signs them out everywhere, and users change their own from their profile. There is no self-service "forgot password" email, because the store has an admin on hand and email delivery (Brevo) is optional in this setup.
    - **Deactivated users** are signed out immediately and can't sign in.
9. **Currency is INR (₹),** following the wireframe.
10. **Mobile number:** optional on an order and never a replacement for email.
    - If a new customer gives one, it is saved. If an existing customer gives a new or different one, their record is updated.
    - A number is never silently moved between customers, and billing is never blocked by a clash. If the form differs from the matched saved customer (e.g. Arun's mobile with a new email), **Generate bill** opens a dialog showing saved vs new details. The choices are *Update this customer & bill* (recommended; sends `customer_id` + `update_customer: true`, which saves the new email/name/mobile on that record, with uniqueness still enforced), or *Bill the new email without this mobile* / *Bill with saved details*. If the server spots a clash the form missed, its 422 names the owner (`conflict.customer`) and the same dialog appears.
    - On the billing screen, a known mobile fills in email and name, and a known email fills in mobile and name. Editing the field that matched clears the auto-filled values, so a typo can't bill the wrong person.
11. **The frontend shows a live total preview** using the same integer maths as the server. The server's figures on the returned bill are the authoritative ones.
12. **Stock changes only through audited paths.** Editing a product can't change its stock. Stock moves only through a sale, a restock (always +), a correction (+ or −, reason required) or the opening stock on create. Each change writes a `stock_movements` row with who made it, the signed quantity and the resulting level. Restocks use the same row lock as sales, so they can't race each other.
13. **Notifications are derived, not stored.** The bell shows current conditions: low or out-of-stock products. So an alert disappears by itself once the product is restocked. "Seen" state is kept per browser, only to clear the red badge.
14. **Times use `APP_TIMEZONE=Asia/Kolkata`,** so "today's sales" and the 7-day chart follow the store's calendar day.

---

## 9. Frontend overview

| Screen | What it does |
|---|---|
Layout: a dark sidebar that collapses to icons on desktop (the choice is remembered) and slides in as a drawer on mobile. The top bar has the page title, the notification bell and the user menu (change password, sign out). Only the menu items your role allows are shown.

| Screen | What it does |
|---|---|
| **Login** (`/login`) | Email + password, show/hide password, demo-account shortcuts |
| **Dashboard** (`/dashboard`) | Today's and this month's sales, product and restock counts, 7-day sales chart with hover details, recent bills, stock alerts with one-click restock |
| **New Bill** (`/billing`) | Mobile / email / name, with two-way auto-fill for returning customers, product rows with stock-aware dropdowns, live subtotal/tax/total, amount given → change with note breakdown, Generate Bill → printable bill. Server errors appear on the matching row. The Low Stock panel shows only for roles with inventory access. |
| **Receipt** (after Generate bill) | Thermal till receipt preview (store header, GSTIN, bill no, cashier, items, CGST/SGST summary, cash/change, QR of the bill number) with an **80 mm / 58 mm** switch (remembered) and **Print receipt**. Printing uses [react-to-print](https://github.com/MatthewHerbst/react-to-print) with `@page { size: 80mm auto; margin: 0 }`, so a thermal printer or "Save as PDF" gets a till slip, not an A4 sheet. Store details come from `VITE_STORE_*` in `frontend/.env`. |
| **Order History** (`/orders`) | Search by email, expandable orders with line items, pagination, email/WhatsApp status, and a **Receipt** button to preview and reprint any past bill |
| **Inventory** (`/inventory`) | All / Low-stock tabs (threshold can be changed), search, **Add product**, **Edit**, **Stock** (restock or correction with a live before → after preview), and a per-product **stock history** |
| **Settings → Users** | Add users with a role, edit, deactivate, delete (admin only) |
| **Settings → Roles & Permissions** | Create or edit roles by ticking grouped permissions; Admin is locked |
| **Settings → Password Reset** | Set or generate a new password for any user |
| **My profile** (`/profile`) | Account details, permissions, change own password |

After each bill or stock change, the affected lists and the notification bell refresh straight away. The bell also re-checks every 60 seconds.

### Departmental store modules

**Multiple stores**
- Every bill, stock level, document and accounting entry belongs to a store. Stock is kept per store in `product_stocks`.
- Pick the store from the header's store switcher. Admins, and users with no store assigned, can also pick **All stores** to read combined figures. Nothing can be created in that mode.
- A user assigned to a store (Employees page) is always locked to it.
- Manage stores under **Settings → Stores**: name, code, GSTIN, address, state and GST state code.

**Document numbers**
- Numbers are gap-free per store, document type and financial year (April–March), e.g. `MAIN/INV/26-27/00001`.
- The prefixes are in `backend/config/documents.php`.

| Menu | Screens |
|---|---|
| **Sales** | **Tax Invoices**: every bill as an A4 GST invoice with HSN summary and amount in words, plus PDF. **Quotations**: convert to a bill at the quoted prices. **Delivery Challans**: goods leave before invoicing; one or more challans become one bill. **Sales Returns**: credit notes against a bill, with stock back and refund in cash, bank or credit. |
| **New Bill** | Payment mode: Cash, Card, UPI or Credit (pay later, owed on the customer's ledger). An optional business-customer section (GSTIN, billing address, place of supply) switches to IGST when the place of supply is in another state. |
| **Inventory** | Products have HSN code, category (department), unit and cost price. **Stock by store** view. **Stock Transfers**: dispatch from one store, receive at the other, with an in-transit status. |
| **Purchases** | **Suppliers** with outstanding balance. **Purchases**: goods received with input GST, freight and round-off; this updates cost price and stock. **Purchase Returns**: debit notes. |
| **Accounts** | **Receipts** (from customers), **Payments** (to suppliers), **Expenses**, **Day Book & Vouchers** (with journal and contra entry), **Ledgers** (running balance; cash and bank books), **Chart of Accounts** (with opening balances), **Outstanding** (receivables and payables with 0–30/31–60/61–90/90+ ageing), **Day Closing** (collections per cashier and payment mode, counted cash vs expected), **Financial Statements** (trial balance, profit and loss with stock valuation, balance sheet; PDF), **GST Reports** (GSTR-1 sections with Excel export, GSTR-3B with PDF). |
| **Reports → Sales Analysis** | Sales and margin by category, payment mode, store, day and hour, and top products. |

**Accounting**
- It is double-entry: `accounts`, `vouchers` and `voucher_entries`. Every module posts through `App\Services\AccountingService`, so the ledgers and statements always come from one balanced set of entries.
- Each bill posts a sales voucher automatically.
- Cancelling a purchase, receipt, payment or expense keeps the document and posts a reversing journal entry.

---

## 10. AI-assisted development

This project was built with **Claude Code** (Anthropic) in VS Code. Screenshots of the prompts used, and a write-up of what I did myself versus with AI, are in [`/prompts`](prompts/) (see [`prompts/HOW-I-BUILT-THIS.md`](prompts/HOW-I-BUILT-THIS.md)).

Summary of how it was used:
1. Shared the task PDF and asked for a full plan first: prerequisites, every install command, and how the frontend connects to the backend (CORS). I reviewed the plan before any code was written.
2. Supplied the database credentials, which confirmed the plan. The AI then scaffolded both apps and wrote the code and tests.
3. Verification done during the session:
   - ran the full test suite;
   - mutation-checked the concurrency test by removing the lock and confirming the test fails;
   - curl-tested the CORS preflight and each endpoint;
   - ran the queue worker and confirmed the email in the log;
   - built and linted the frontend.
