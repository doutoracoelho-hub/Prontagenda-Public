<div align="center">

# Prontagenda AI Agent

### Autonomous healthcare scheduling workflows powered by Gemini and Google Cloud

**All Things Agentic Hackathon 2026**

[Prontagenda](https://www.prontagenda.com.br)

</div>

---

## About

**Prontagenda AI Agent** is an autonomous AI agent for healthcare scheduling and clinic workflow automation.

It extends the existing Prontagenda practice-management platform with an agentic layer capable of understanding natural-language requests from patients, deciding which workflow is required, calling controlled application tools, completing real actions in the scheduling system, and escalating requests to human staff when necessary.

The objective is not to build another chatbot.

The objective is to build an agent that can:

**understand → decide → act → record → escalate when needed**

The agent was built for the **All Things Agentic Hackathon 2026**.

---

## The problem

Healthcare clinics receive repetitive operational requests every day:

- appointment booking;
- availability questions;
- confirmations;
- cancellations;
- patient identification;
- scheduling preferences;
- reminders;
- requests that require human intervention.

Most of these requests are simple individually, but together they create constant interruptions for clinic staff.

Traditional chatbots can answer questions, but they often stop at generating text.

Prontagenda AI Agent goes further by connecting conversation to real clinic workflows.

---

## What the agent does

A patient can contact the clinic through WhatsApp using natural language.

For example:

> “I would like an appointment after 5 PM.”

The agent can:

1. identify that the patient wants to schedule an appointment;
2. identify the requested professional;
3. check the professional's working hours;
4. query real schedule availability;
5. offer valid available times;
6. understand follow-up preferences such as another day;
7. identify whether the appointment is for the WhatsApp contact or another patient;
8. collect the required patient information;
9. ask for confirmation;
10. create the appointment in Prontagenda.

The final result is not just a generated response.

The appointment is actually created in the clinic schedule.

---

## Human escalation

Not every workflow should be fully automated.

When a request is too complex or requires human judgment, the agent does not attempt to force an autonomous action.

Examples include:

- complex appointment changes;
- requests outside the allowed agent workflow;
- failures during tool execution;
- situations requiring staff judgment.

These conversations are routed to the **human support queue**.

This creates a controlled hybrid workflow:

**Autonomy when appropriate. Human oversight when necessary.**

---

## Secure agentic architecture

The architecture deliberately separates AI reasoning from direct application data access.

The patient does **not** communicate directly with Gemini.

Gemini does **not** access the MySQL database directly.

The communication flow is:

```text
Patient ↔ WhatsApp ↔ Prontagenda PHP
                         │
                         │ Signed context + Bearer token
                         ▼
              Cloud Run — Google ADK Agent
                         │
                         ▼
          Vertex AI — Gemini 3.5 Flash-Lite
                         │
                         ▼
              Secure Prontagenda Tools
                         │
                         ▼
                  Schedule + MySQL

             Failure or human intervention
                         ▼
                 Human support queue
