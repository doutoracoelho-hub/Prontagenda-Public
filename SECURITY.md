# Security model

Prontagenda keeps authentication, authorization, multi-company isolation,
business rules, database access, schedule consistency and human escalation in
the PHP backend. The language model never receives database credentials and
cannot select a company, patient or sender identity.

The Cloud Run gateway requires a Bearer token. Each WhatsApp request also uses
a short-lived HMAC-signed context created by the trusted PHP router. The
backend validates that context again before returning data or committing an
action.

## Repository safety

This repository intentionally excludes:

- production credentials and service-account keys;
- `.env` files;
- ADK session databases and Python caches;
- patient data, uploads and production database dumps;
- the proprietary Prontagenda application core.

Only example values are committed. Never use a real patient conversation in a
demo or automated test.

## Reporting a vulnerability

Please do not open a public issue containing credentials, patient data or an
exploitable security report. Contact the project owner privately through the
Prontagenda website instead.

