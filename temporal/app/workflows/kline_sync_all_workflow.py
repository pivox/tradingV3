KlineSyncAllWorkflowfrom temporalio import workflow, activity
                    from typing import List, Optional
                    from datetime import timedelta

                    with workflow.defn:
                        class KlineSyncAllWorkflow:
                            @workflow.run
                            async def run(self, limit: Optional[int] = None, symbol: Optional[str] = None) -> str:
                                contracts = await workflow.execute_activity(
                                    "fetch_contracts_activity",
                                    start_to_close_timeout=timedelta(seconds=60),
                                )

                                # Filtrage éventuel
                                if symbol:
                                    contracts = [c for c in contracts if c == symbol]
                                elif limit:
                                    contracts = contracts[:limit]

                                total = len(contracts)
                                i = 1

                                for contract_symbol in contracts:
                                    workflow.logger.info(f"⏳ [{i}/{total}] Sync for {contract_symbol}")

                                    await workflow.execute_activity(
                                        "sync_symbol_activity",
                                        contract_symbol,
                                        start_to_close_timeout=timedelta(minutes=5),
                                    )

                                    # Sleep non-bloquant pour respecter 1s entre les appels
                                    await workflow.sleep(1)
                                    i += 1

                                return f"✅ Synchronisation complète : {total} symboles"
