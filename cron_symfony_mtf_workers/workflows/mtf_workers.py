"""DEPRECATED (CLEAN-001) — workflow legacy multi-jobs.

``CronSymfonyMtfWorkersWorkflow`` normalise N ``MtfJob`` et boucle des appels
``mtf_api_call`` vers ``/api/mtf/run``. Ce chemin est **déprécié** : la cible est
le schedule orchestrateur unique (``scripts/manage_orchestrator_schedule.py`` →
``OrchestratorCronWorkflow`` → un seul ``POST /orchestrator/run``). Le workflow
reste enregistré et 100 % fonctionnel pendant la transition (la suppression est
un jalon ultérieur, hors CLEAN-001) ; il émet désormais un avertissement de
dépréciation via ``workflow.logger.warning`` au début de chaque run.

Ne pas étendre ce chemin : toute nouvelle logique d'orchestration vit dans
``python-orchestrator/`` (PY-005/PY-006).
"""

from __future__ import annotations

from datetime import timedelta
from typing import Any, Iterable, List

from temporalio import workflow

from models.mtf_job import MtfJob


def _normalize_jobs(raw_jobs: Iterable[Any]) -> List[MtfJob]:
    jobs: List[MtfJob] = []
    for item in raw_jobs:
        if isinstance(item, MtfJob):
            jobs.append(item)
        elif isinstance(item, dict):
            jobs.append(MtfJob.from_dict(item))
        else:
            jobs.append(MtfJob.from_dict({"url": str(item)}))
    return jobs


@workflow.defn(name="CronSymfonyMtfWorkersWorkflow")
class CronSymfonyMtfWorkersWorkflow:
    @workflow.run
    async def run(self, jobs: Iterable[Any]) -> None:
        # Avertissement de dépréciation (CLEAN-001) — déterministe : on reste sur
        # workflow.logger (aucune I/O, aucun datetime.now()). Le legacy continue
        # de s'exécuter normalement ; la cible est le schedule orchestrateur
        # unique (scripts/manage_orchestrator_schedule.py → /orchestrator/run).
        workflow.logger.warning(
            "[CronMtfWorkers] DEPRECATED (CLEAN-001): legacy multi-jobs workflow; "
            "migrate to the single orchestrator schedule "
            "(manage_orchestrator_schedule.py -> POST /orchestrator/run)."
        )
        normalized = _normalize_jobs(jobs)
        for job in normalized:
            payload = job.payload()
            payload.setdefault("workers", job.workers)
            timeout = timedelta(minutes=job.timeout_minutes)
            workflow.logger.info(
                "[CronMtfWorkers] calling %s with payload=%s (timeout=%d min)",
                job.url,
                payload,
                job.timeout_minutes,
            )
            try:
                result = await workflow.execute_activity(
                    "mtf_api_call",
                    args=[job.url, payload],
                    start_to_close_timeout=timeout,
                )
                # Log concise summary only (full response in result["full_response"])
                workflow.logger.info(
                    "[CronMtfWorkers] ✅ Result:\n%s", 
                    result.get("summary", "No summary available")
                )
            except Exception as exc:  # noqa: BLE001
                workflow.logger.error("[CronMtfWorkers] error for %s: %s", job.url, exc)
