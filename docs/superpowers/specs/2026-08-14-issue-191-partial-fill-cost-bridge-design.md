# #191 Partial-fill cost bridge design

## Scope

Expose the PHP partial-fill cost authority to Python through a strict bounded
stdin/stdout bridge. This lot authenticates transport and result parity only;
it does not yet collapse staged fills into one synthetic entry.

The frozen request carries the canonical v2 plan, dataset identity,
visible-queue result/trace hashes, exact filled base quantity, terminal branch
and target id. The result model mirrors every ordered PHP field, verifies the
request/result hashes, decimal reconciliation, quantity conservation and
stop/target semantics.

The child process has fixed argv, timeout and stdout/stderr byte bounds. A
nonzero exit, duplicate JSON key, malformed UTF-8/JSON, identity substitution,
hash mismatch, timeout or oversized stream fails closed. No credential or
venue path exists.
