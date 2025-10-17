from datetime import timedelta
from temporalio import workflow
from temporalio.common import RetryPolicy
from activities.log_activities import write_log_to_file


@workflow.defn
class LogProcessingWorkflow:
    """Workflow pour traiter les logs de manière asynchrone"""
    
    @workflow.run
    async def run(self, log_data, is_batch=False):
        """Point d'entrée principal du workflow"""
        if is_batch:
            # Traitement par batch
            for log_entry in log_data:
                await workflow.execute_activity(
                    write_log_to_file,
                    log_entry,
                    start_to_close_timeout=timedelta(seconds=30),
                    retry_policy=RetryPolicy(
                        initial_interval=timedelta(seconds=1),
                        maximum_interval=timedelta(seconds=10),
                        maximum_attempts=3,
                    )
                )
        else:
            # Traitement d'un seul log
            await workflow.execute_activity(
                write_log_to_file,
                log_data,
                start_to_close_timeout=timedelta(seconds=30),
                retry_policy=RetryPolicy(
                    initial_interval=timedelta(seconds=1),
                    maximum_interval=timedelta(seconds=10),
                    maximum_attempts=3,
                )
            )


