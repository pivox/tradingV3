# Paper Strategy Profile CLI Design

## Goal

Make the Paper runtime-check and replay commands constructible inside the real
Symfony console application while retaining explicit legacy strategy selection.

## Context

Symfony FrameworkBundle globally reserves `--profile` for console profiling.
Both Paper commands currently declare another value-bearing option with that
name. Direct `CommandTester` tests pass because they do not merge the
application definition, while `bin/console` fails before Paper readiness runs.

## Decision

Rename only the Paper legacy selector to `--strategy-profile` in runtime-check
and replay. Modern mode/setup/version/side options remain byte-for-byte
unchanged. Mixing the renamed legacy selector with modern identity remains
fail-closed through `PaperReplayStrategySelection`.

Removing legacy selection would expand scope, while overriding Symfony's
global option would couple safety-critical commands to framework internals.
The previous unusable `--profile` spelling cannot be retained as an alias.

## Verification

A kernel-backed test will load both commands through the real FrameworkBundle
console application, merge global definitions, and prove that global
`--profile` and Paper `--strategy-profile` coexist. Existing command tests and
operator documentation will use the renamed selector. A real modern
runtime-check against immutable r11 must then reach the redacted readiness
contract rather than command-definition failure.
