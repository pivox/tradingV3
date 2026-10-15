"""Theoretical leverage > 2x study on the tick engine (PREREGISTRATION-leverage-gt2.md).

Exploratory, post hoc study on already-consumed data. The frozen engines of `research_exec`
are not modified: this package reuses their primitives (bars, features, signal masks, the
E1 entry and the B4 exit detection) and adds the leverage computation, the E0a census, the
E1 replay with an ex ante band admission filter, the statistics and a single-use claim.
"""
