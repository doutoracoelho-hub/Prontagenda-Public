# PHP integration reference

This directory contains the AI-facing endpoints, services, migrations and a
security test extracted from the existing Prontagenda application. It is
published so hackathon judges can inspect the real trust boundary between the
agent and the scheduling backend.

It is not a standalone copy of Prontagenda. The files depend on the proprietary
application bootstrap, database schema and supporting services. The runnable
hackathon artifact is the agent container at the repository root; configure it
to call an authorized Prontagenda test environment through
`PRONTAGENDA_API_BASE_URL`.

Important properties visible in this reference implementation:

- Bearer authentication for server-to-server calls;
- short-lived HMAC context bound to company, conversation and sender;
- backend ownership and authorization checks;
- availability and business-rule validation in PHP;
- transactional booking confirmation;
- explicit human escalation;
- no direct Gemini-to-MySQL access.

