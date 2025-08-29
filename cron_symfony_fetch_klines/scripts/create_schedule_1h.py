# scripts/create_schedule_1h.py
import asyncio
from temporalio.client import Client, Schedule, ScheduleSpec, ScheduleActionStartWorkflow

async def main():
    client = await Client.connect("temporal:7233")
    await client.create_schedule(
        "symfony-cron-1h",
        Schedule(
            action=ScheduleActionStartWorkflow(
                "CronSymfony1hWorkflow",
                args=["http://nginx/api/cron/bitmart/refresh-1h"],
                task_queue="cron_symfony_refresh",
                id="symfony-cron-1h-runner",
            ),
            spec=ScheduleSpec(cron_expressions=["0 */1 * * *"], time_zone_name="Africa/Tunis"),
        ),
    )

if __name__ == "__main__":
    asyncio.run(main())
