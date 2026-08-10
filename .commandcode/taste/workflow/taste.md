# Workflow Preferences

- Prefers step-by-step incremental implementation — one step per prompt so each can be reviewed independently before proceeding. Confidence: 0.9
- Prefers verification/testing after each implementation step before moving on to the next step. Confidence: 0.85
- Prefers a clean database reset (drop & recreate) when schema conflicts exist, rather than using an alternate database name. Confidence: 0.7
- Prefers database schema changes documented as version-controlled SQL migration files (e.g., `database/migration_status.sql`) rather than ad-hoc ALTER TABLE commands. Confidence: 0.85
