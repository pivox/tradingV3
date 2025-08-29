# scripts/create_schedule_1m.py
import os
import asyncio
from temporalio.client import Client, Schedule, ScheduleSpec, ScheduleActionStartWorkflow

TEMPORAL_ADDRESS   = os.getenv("TEMPORAL_ADDRESS", "temporal:7233")
TEMPORAL_NAMESPACE = os.getenv("TEMPORAL_NAMESPACE", "default")

# ➜ réutilise la même task queue que ton worker existant
TASK_QUEUE    = os.getenv("TASK_QUEUE_NAME", "cron_symfony_refresh")
WORKFLOW_TYPE = "CronSymfony1mWorkflow"            # doit matcher @workflow.defn(name=...)
WORKFLOW_ID   = "cron-symfony-1m-runner"

# L’endpoint Symfony appelé par le workflow (tu l’as déjà ajouté)
SYM_CRON_URL  = os.getenv("SYM_CRON_URL", "http://nginx/api/bitmart/cron/refresh-1m")

SCHEDULE_ID   = "cron-symfony-1m"
CRON_EXPR     = "*/1 * * * *"                      # chaque minute
TIME_ZONE     = os.getenv("TZ", "Africa/Tunis")     # optionnel (sinon UTC)

async def main():
    client = await Client.connect(TEMPORAL_ADDRESS, namespace=TEMPORAL_NAMESPACE)

    schedule = Schedule(
        action=ScheduleActionStartWorkflow(
            WORKFLOW_TYPE,
            args=[SYM_CRON_URL],
            task_queue=TASK_QUEUE,
            id=WORKFLOW_ID,
        ),
        spec=ScheduleSpec(
            cron_expressions=[CRON_EXPR],
            time_zone_name=TIME_ZONE,
        ),
    )

    # crée (ou remplace selon version serveur) la schedule
    await client.create_schedule(SCHEDULE_ID, schedule)
    print(f"✅ Schedule '{SCHEDULE_ID}' créée: {CRON_EXPR} ({TIME_ZONE}) → queue='{TASK_QUEUE}'")

if __name__ == "__main__":
    asyncio.run(main())
