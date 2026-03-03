# Security Criteria

Use this checklist for repository-level security audits.

## Scope

- Assess vulnerabilities in code, dependencies, and operational controls.
- Prioritize systems with sensitive data or internet exposure.

## Checklist

- Dependencies with known vulnerabilities are identified.
- Secrets are not hardcoded and sensitive config is externalized.
- Authentication and authorization controls match access needs.
- Session/token handling and expiration controls are reviewed.
- Inputs are validated/sanitized against injection classes.
- Error handling avoids leaking sensitive details.
- Logging is security-aware and avoids credential/data exposure.
- CI/CD controls include security-relevant checks where applicable.

## Scoring

- Score: 0 to 5.
- Any unmitigated critical vulnerability caps score at 1.

## Severity guidance

- Critical: Active exploitable issue with high confidentiality/integrity/availability impact.
- High: Significant weakness in critical flows or sensitive modules.
- Medium: Important but non-immediate security weakness.
- Low: Hardening opportunities and preventive controls.
