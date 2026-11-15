# RentalnServices

A web system where users book vehicles (cars, vans, trucks, with or without a driver) or request local services
(handyman, plumbing, cleaning, electrical, beauty, laundry, gardening, driver, body massage).
Each city or municipality has a manager who approves bookings and requests. Providers own the vehicles and do the services.

**Status:** the admin side, the manager side, the public landing page and the customer app (sign-up, browse, track, cancel, review, profile) are built. Placing a booking or request (with online payment) and the provider side are not. Booking and service requests (the customer forms) and the provider side are not.

## Setup (XAMPP)

1. Put this folder in `C:\xampp\htdocs\RentalnServices`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. In phpMyAdmin, create a database named `booking_and_services` (collation `utf8mb4_general_ci`) and import `booking_and_services.sql`.
   The file drops and recreates every table, so only import it into an empty database or one you can reset.
4. Create the first admin from the project folder:
   ```
   C:\xampp\php\php.exe create_admin.php "Your Name" you@example.com "a-strong-password"
   ```
5. Load the Philippine areas (all 42,010 barangays, takes about a second):
   ```
   C:\xampp\php\php.exe database\import_psgc.php
   ```
6. Open <http://localhost/RentalnServices/>. Everyone logs in on `login.php` (the **Log in** button in the top menu); each role lands on its own page. Customers use **Sign up** (`register.php`) to create an account.

Database settings default to `localhost`, port `3306`, user `root`, no password.
To change them, copy `config.example.php` to `config.local.php` and edit it. `config.local.php` is git-ignored.

## Customer app (project root)

A phone-first app with a bottom navigation bar (Home, Services, Vehicles, Bookings, Profile) that becomes a top menu on larger screens.

| Page | What it does |
| --- | --- |
| `home.php` | Search, categories, popular services in your city, and your upcoming bookings |
| `services.php` | Browse services in your city, with search and filters |
| `vehicles.php` | Browse vehicles to rent (your city or all cities), with search and type filters. Old `services.php?tab=vehicles` links forward here |
| `service.php`, `vehicle.php` | Details and recent reviews. On a vehicle, Book now opens `book.php` (service requests are not bookable yet) |
| `book.php`, `pay_return.php` | Pick dates, pay on PayMongo (GCash, Maya, card); the booking is created only after the payment is confirmed |
| `bookings.php`, `order.php` | Your bookings and requests; cancel a pending one (a paid online payment is marked refunded); review a completed one |
| `profile.php` | Edit your details, address and email, change your password, log out |

Rejecting a booking or request on the manager side also marks its paid online payment as refunded.

## Provider sign-up and approval

Providers sign up themselves at `register.php?as=provider` (the home page has an "Apply as a provider" button). They give their name,
business or trade name, email, phone, address, what they will offer and a password. The account is created **switched off and
"pending"**; nobody can sign in with it yet.

- The **manager of the provider's city** sees the application on `manager/applications.php`; an **admin** sees all of them on
  `admin/applications.php` (useful for cities that have no manager). Either one approves or rejects. Whoever acts first decides, and the
  second click is told it was already reviewed. A rejection needs a short reason.
- **Approve** switches the account on. **Reject** keeps it off and saves the reason.
- The applicant can try to log in at any time: they are told it is waiting for approval, or that it was not approved and why. Once approved,
  they sign in and land on the provider dashboard (see below).
- Pending and rejected applicants are not counted as providers anywhere (dashboards, Partnerships, the manager's provider list, the
  forms where a manager picks a provider). Providers an admin creates directly are approved straight away.
- Both sidebars show a count badge on **Applications**, and the bell includes waiting applications.

**Upgrading an older database:** run once `C:\xampp\php\php.exe database\migrate_provider_applications.php`. It adds the new columns to
`provider_profiles` and marks every existing provider as approved. Fresh installs do not need it.

## Accounts and roles

Every person who signs in is an account with one of four roles: **admin**, **manager**, **provider** or **customer**.

- **Customers** sign up themselves (`register.php`) and edit their own name, email, phone, address and password on `profile.php`.
- **Managers** edit their own name, email, phone, street and password on `manager/profile.php`. The city they cover is set by an admin.
- **Admins** edit their own details on `admin/profile.php`, and edit any customer, provider or manager (details, address, password reset)
  from **Accounts**. Admins also create manager and provider accounts and activate or deactivate managers and providers.
- Changing an email address always asks for the current password. Nobody can change their own role or active status.

**Upgrading an older database:** the role used to be called `user`. If you imported the database before this change, run once:
```
C:\xampp\php\php.exe database\migrate_customer_role.php
```
It renames the role in the table and in every existing account, and is safe to run again. Fresh installs do not need it.

## Philippine areas (PSGC)

`database/psgc_areas.csv` holds every barangay from the Philippine Statistics Authority's Philippine Standard Geographic Code
(PSGC), September 2026 publication (<https://psa.gov.ph/classification/psgc>): 83 provinces, 1,642 cities and municipalities,
42,010 barangays. `database/import_psgc.php` loads it into the `areas` table once, so the system never depends on an outside API
being online. The import is safe to run again: rows are matched by PSGC code or by name and nothing is duplicated.
`--prune` also removes hand-typed areas that nothing uses yet.

- "City of Ilagan" is stored as "Ilagan City", and the cities of the National Capital Region are filed under the province "Metro Manila".
- Forms pick an area with three dropdowns (province, then city or municipality, then barangay). Their options come from
  `api/areas.php` as you choose (it also searches cities by name), so no page ever carries the whole list. The script is `assets/areas.js`; forms use `area_picker()`
  from `includes/areas.php`.
- To update after a new PSA publication: convert the new workbook to the same CSV columns
  (`psgc_code,province,city_municipality,barangay`) and run the importer again.

## How areas work

There is one `areas` table with a row per barangay (province, city/municipality, barangay).
A city is every row that shares the same province and city name.
Accounts, vehicles, services, bookings and requests all point at a barangay.
A manager covers the whole city of their barangay, and a city can have only one active manager.
A barangay or city that is still in use cannot be removed. Areas typed in by hand on the Areas page have no PSGC code.

## Admin pages (`/admin`)

| Page | What it does |
| --- | --- |
| `dashboard.php` | Totals, approvals waiting, cities without a manager, recent activity |
| `accounts.php`, `account.php` | List, search, add managers and providers; edit details, reset password, activate or deactivate |
| `partners.php` | Managers and providers overview |
| `catalog.php` | Review every vehicle and service |
| `activity.php`, `booking.php`, `request.php` | Bookings, requests, payments and reviews, with detail pages |
| `reports.php`, `export.php` | Totals for a date range and city, plus CSV downloads |
| `areas.php` | Pick a city with the province/city dropdowns or the search box to see its manager, barangay count, providers and customers, and to assign a manager. Missing places can be added manually |
| `area.php` | Manage one city: rename it, and search, add, rename or remove its barangays |
| `logs.php` | Record of admin actions (passwords are never logged) |
| `profile.php` | Edit your own name, email and phone, and change your password |

## Manager pages (`/manager`)

A manager only ever sees and changes rows in their own city. Anything in another city is treated as not found.
Admins create manager and provider accounts (a manager needs a barangay, which decides the city they cover).

| Page | What it does |
| --- | --- |
| `dashboard.php` | Approvals waiting, what starts this week, vehicles/services/providers in the city |
| `approvals.php` | Queue of vehicle bookings and service requests, filtered by status |
| `booking.php`, `request.php` | Approve, reject (optional note goes to the admin log) or mark completed. Approving a booking checks for date clashes on the same vehicle and assigns a driver when one is needed |
| `vehicles.php` | Add and edit vehicles for providers in the city |
| `services.php` | Add and edit services for providers in the city |
| `providers.php` | Providers who live in the city, with their vehicle and service counts |
| `profile.php` | Edit your own name, email, phone and street, and change your password |

Rules enforced: a vehicle cannot be approved for overlapping dates; a vehicle or service cannot be marked unavailable while it
has pending or upcoming approved bookings or requests; a booking or request can only be completed after its start time.

## Provider pages (`/provider`)

An approved provider signs in on the home page like everyone else and lands on `provider/dashboard.php`. A provider only ever sees their
own vehicles, services and jobs. A deactivated provider, or one whose application is not approved, is locked out immediately.

| Page | What it does |
| --- | --- |
| `dashboard.php` | Jobs coming up, jobs waiting for a manager, vehicles and services available, payments this month, average rating, latest reviews |
| `jobs.php`, `job.php` | Bookings of your vehicles, bookings where you are the driver, and requests for your services. Read-only: a manager approves, rejects and completes them. The customer's name, phone and pickup address are shown only after approval |
| `vehicles.php`, `services.php` | Your listings with rating and open jobs. Switch one between available and unavailable (blocked while it has pending or upcoming approved jobs). The manager adds and edits the details |
| `earnings.php` | What customers paid for your vehicles and services: this month, last month, all time, refunded, the last six months, and every payment. Driver-only jobs are not counted, because nothing records the driver's share |
| `profile.php` | Your details, business name and description, and password. Your barangay is set by an admin or manager |

## Project layout

```
index.php            public landing page
register.php         customer sign-up (always creates a customer account)
home.php, services.php, service.php, vehicle.php, bookings.php, order.php, profile.php   the customer app
logout.php
create_admin.php     one-time command-line script
booking_and_services.sql   database
database/            psgc_areas.csv (PSA data), import_psgc.php (loads the areas) and migrate_customer_role.php (upgrade for older databases)
api/                 areas.php, read-only JSON for the area dropdowns
config.example.php
admin/               the admin side
manager/             the manager side
provider/            the provider side
includes/            db, auth, layout, areas, reports, profile, manager, provider, user (customer app), public site helpers
assets/              style.css (admin and manager), landing.css (public), app.css (customer app), areas.js (area dropdowns), area-search.js (city search box)
```

## Online payment (PayMongo)

Vehicle bookings are paid through PayMongo Checkout (GCash, Maya, GrabPay, cards). Flow: `book.php` saves a **checkout** (this holds the dates
for 45 minutes) and sends the customer to PayMongo. On return, `pay_return.php` asks PayMongo whether it was paid, and only then creates the
booking (`pending`, for the city manager) and a `paid` payment. `paymongo_webhook.php` does the same if the customer never comes back.
Cancelling a pending booking (`order.php`) or rejecting one (`manager/booking.php`) refunds the money through PayMongo.

1. Run `migrations/002_checkouts.sql` once (fresh installs of `booking_and_services.sql` already have the table).
2. Create a PayMongo account, copy the **test** secret key (`sk_test_...`) from Developers > API Keys, and put it in `config.local.php`
   (see `config.example.php`). Use the `sk_live_` key only after PayMongo activates your account.
3. Needs the PHP `curl` extension (on in XAMPP; remove `;` before `extension=curl` in php.ini if not).
4. Webhook (optional, needs a public HTTPS address): register `https://YOUR-SITE/paymongo_webhook.php` for `checkout_session.payment.paid`
   and set `PAYMONGO_WEBHOOK_SECRET`.
