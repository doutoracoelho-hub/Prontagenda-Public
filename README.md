<div align="center">
  <img src="docs/assets/prontagenda-banner.png" alt="ProntAgenda — clinical, administrative and financial management" width="100%">

  <br>
  <h1>ProntAgenda</h1>
  <p><strong>Cloud-based practice management software for dentists and dental clinics.</strong></p>

  <p>🇺🇸 English &nbsp;|&nbsp; <a href="README.pt-BR.md">🇧🇷 Português</a></p>

  <p>
    <img src="https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white" alt="PHP 8.x">
    <img src="https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white" alt="MySQL">
    <img src="https://img.shields.io/badge/JavaScript-ES6-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript ES6">
    <img src="https://img.shields.io/badge/License-All%20Rights%20Reserved-red" alt="All Rights Reserved">
    <img src="https://img.shields.io/badge/Status-Active-success" alt="Active">
  </p>

  <p>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/commits"><img src="https://img.shields.io/github/last-commit/doutoracoelho-hub/Prontagenda" alt="Last commit"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda"><img src="https://img.shields.io/github/commit-activity/m/doutoracoelho-hub/Prontagenda" alt="Commit activity"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/issues"><img src="https://img.shields.io/github/issues/doutoracoelho-hub/Prontagenda" alt="Open issues"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/releases"><img src="https://img.shields.io/github/v/release/doutoracoelho-hub/Prontagenda?display_name=tag&include_prereleases" alt="Latest release"></a>
    <img src="https://img.shields.io/github/languages/top/doutoracoelho-hub/Prontagenda" alt="Top language">
  </p>
</div>

## Contents

- [About](#about)
- [Main features](#main-features)
- [Demo](#demo)
- [Technology](#technology)
- [Architecture](#architecture)
- [Local setup](#local-setup)
- [Security](#security)
- [Roadmap](#roadmap)
- [Author](#author)
- [License](#license)

## About

**ProntAgenda** is a comprehensive cloud platform that centralizes clinical, administrative and financial workflows for dentists and dental clinics, allowing professionals to manage the entire patient journey—from appointment scheduling to treatment completion—within a single system.

Built for solo professionals and multi-clinic organizations, it provides multi-company operation, secure multi-user access and role-based permissions.

## Repository notice

ProntAgenda is proprietary software.

This public repository contains product documentation, screenshots and demonstration materials only. The application source code is maintained in a private repository and is not distributed publicly.

## Main features

| Area | Capabilities |
| --- | --- |
| **Appointments** | Interactive calendar, multiple professional schedules, recurring appointments, labels and secretarial support |
| **Patient records** | Registration, anamnesis, clinical notes, consultation history, images and attachments |
| **Dental chart** | Interactive odontogram, tooth surfaces, treatment synchronization and permanent/deciduous dentition |
| **Treatments** | Catalog, estimates, procedure tracking, progress history and dentist assignment |
| **Finance** | Receivables, payables, cash flow, cards, installments, commissions and dashboards |
| **Laboratory** | Prosthetic workflow, work orders, delivery stages and payment tracking |
| **Inventory** | Products, stock movements, material consumption, equipment and depreciation |
| **Documents** | Prescriptions, certificates, examination requests, contracts and custom printable documents |
| **Platform** | Multi-company architecture, user roles, auditing, secure sessions and subscription management |
| **Reports** | Financial reports, treatment history, printable documents and dashboards |

## Demo

A complete product walkthrough will be available soon.

The demonstration will cover:

- Appointment scheduling
- Patient records
- Interactive odontogram
- Financial management
- Treatment planning

<!-- Replace this block with: <img src="docs/assets/prontagenda-demo.gif" alt="ProntAgenda product walkthrough" width="100%"> -->

## Technology

- **Backend:** PHP 8+, PDO, MySQL/MariaDB and REST-like APIs
- **Frontend:** HTML5, CSS3, JavaScript ES6, AJAX and JSON
- **Libraries:** FullCalendar, Flatpickr and PHPMailer
- **Integrations:** Google OAuth, Google Contacts API, Mercado Pago and Memed API

## Architecture

```text
                 Browser
                    │
                    ▼
              public_html/
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
      Pages        API    Authentication
        │           │           │
        └───────────┼───────────┘
                    ▼
                  src/
        ┌───────────┼───────────┐
        ▼           ▼           ▼
      Config       Auth       Helpers
        │           │           │
        └───────────┼───────────┘
                    ▼
                  MySQL
```

Public pages and JavaScript clients communicate with REST-like PHP endpoints. Shared authentication, configuration and helper modules keep business rules and database access centralized.

## Local setup

### Requirements

- PHP 8.0+
- MySQL 5.7+, MySQL 8+ or a compatible MariaDB version
- PHP extensions: `pdo_mysql`, `mbstring`, `curl`, `json` and `openssl`
- Apache, Nginx or the PHP development server

### Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/doutoracoelho-hub/Prontagenda.git
   cd Prontagenda
   ```

2. Create a MySQL database and import the project's database structure.

3. Create `src/config/.env`:

   ```env
   APP_ENV=local
   APP_DEBUG=true

   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=prontagenda
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   DB_TZ=-03:00

   MP_ACCESS_TOKEN=
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_OAUTH_BASE_URL=http://localhost:8000
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback.php
   SMTP_USER=
   SMTP_PASS=
   MEMED_API_KEY=
   MEMED_SECRET_KEY=
   ```

4. Point the web server document root to `public_html`, or start the development server:

   ```bash
   php -S localhost:8000 -t public_html
   ```

5. Open `http://localhost:8000`.

> Never commit `.env`, database dumps, logs, user uploads or production credentials. Use `APP_ENV=production` and `APP_DEBUG=false` in production.

## Security

- Environment-based configuration
- PDO prepared statements
- Authentication middleware and session validation
- Role-based authorization
- Multi-company data isolation
- Password hashing with PHP's native password API
- CSRF tokens on protected forms
- SQL injection prevention through prepared statements
- Security headers and protected uploads

Because ProntAgenda processes personal and health information, production deployments should use HTTPS, encrypted backups, least-privilege database access, audit policies and controls compatible with Brazil's LGPD.

## Roadmap

- [x] Patient and appointment management
- [x] Financial management
- [x] Interactive odontogram
- [x] Treatment planning
- [x] Inventory and equipment management
- [x] Orthodontic and prosthetic laboratory modules
- [x] Google, Mercado Pago and Memed integrations
- [ ] Mobile application
- [ ] AI-powered clinical assistant
- [ ] Patient portal
- [ ] Online appointment booking

## Author

**Monica Simões Coelho** — Dentist and Software Developer

[github.com/doutoracoelho-hub](https://github.com/doutoracoelho-hub)

[www.prontagenda.com.br](https://www.prontagenda.com.br)

## License

This project is proprietary software. All rights reserved. The source code is provided for portfolio purposes only. Commercial use, redistribution or reproduction without authorization is prohibited.
