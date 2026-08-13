# #191 Public execution tape implementation plan

1. Extend the PHP Paper backtest adapter with a strict normalized public-trade
   value object and deterministic encoder output.
2. Cover OKX and Hyperliquid payload/unit differences, provenance, canonical
   ordering, duplicates and forbidden strategy identity.
3. Add frozen Python record, artifact and verified-tape contracts bound to the
   normalized candle dataset and exact Paper source checksum.
4. Add cross-runtime fixtures, tamper/ordering/look-ahead tests and handbook
   reproduction notes.
5. Run targeted and full verification, open a ready PR, address concrete review
   findings, and merge only with green checks.
