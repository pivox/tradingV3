# #196 Golden scenario 20 fresh-stack implementation plan

1. Promote catalog row 20 and the golden expected facts to the executable
   contract; run the focused PHPUnit tests to prove the missing runner is red.
2. Add a Python fresh-stack proof runner that initializes a new SQLite
   orchestration database, starts real Symfony and FastAPI HTTP processes,
   executes R12, and tears the stack down safely.
3. Execute two independent stacks, compare their normalized reports, and emit
   deterministic redacted facts; cover the CLI and failure boundaries in
   focused Python tests.
4. Invoke the proof from `FakePaperGoldenScenarioRunner`, freeze the exact facts,
   and run the PHP golden test twice to prove fresh-state determinism.
5. Update the #196 audit, Fake/Paper handbook and R12 runbook to 20 executable
   scenarios while preserving all remaining non-golden limitations.
6. Run focused and broad Python/PHP tests, scoped PHPStan, Symfony/YAML lints,
   MkDocs strict, JSON/redaction/network-safety checks and `git diff --check`;
   provision Symfony dependencies in the Python CI job so the real boundary is
   exercised there rather than skipped.
7. Open a ready PR, request Codex review, resolve actionable feedback, merge on
   green, update #196, then continue the global Paper #132 sequence.
