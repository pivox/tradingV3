# #132 certification matrix implementation plan

1. Add failing PHP tests for a deterministic certification-matrix builder and
   its console JSON export.
2. Implement the builder from exact canonical contracts plus a versioned scope
   specification; exclude every blocked/non-executable setup fail-closed.
3. Add failing Python tests for expected zero-count cells, malformed manifests,
   and unexpected certified rows.
4. Extend the baseline generator with `--expected-cells`, preserving the
   minimum-50 gate and existing behavior when no manifest is supplied.
5. Document the operator commands and verify targeted PHP/Python suites,
   container wiring, static analysis, formatting, and the full adjacent baseline
   regression suite.
6. Commit, push a ready PR, request review, resolve real feedback, merge when CI
   and review are clear, then continue the global chantier.
