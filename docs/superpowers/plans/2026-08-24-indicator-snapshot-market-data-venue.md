# Indicator snapshot market-data venue implementation plan

1. Extend the entity contract test to require venue mapping on
   `IndicatorSnapshot`.
2. Add a PostgreSQL integration test for a corrective migration ordered before
   `Version20260811120000`.
3. Add the migration and entity field using the existing strict venue contract.
4. Run targeted tests, static analysis and the full migration chain on a fresh
   dedicated PostgreSQL database.
5. Run the analysis-view suite and open an atomic PR for review.
