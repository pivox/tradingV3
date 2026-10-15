"""H2 Gate 1: does a large coin-specific 24 h move with a calm market partly reverse over 1-5 days?

Effect study pre-registered in docs/handbook/research/PREREGISTRATION-H2-GATE1.md. Order enforced by the code:
claim -> events (event list, exclusions and control hours, written with their sha256) -> claim -> outcomes (refuses
to run without that hash) -> Gate 1 evaluation. Each stage reads its data once.
"""
