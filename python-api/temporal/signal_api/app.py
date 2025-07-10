from fastapi import FastAPI, Request
from temporalio.client import Client
import logging
import asyncio

app = FastAPI()
logging.basicConfig(level=logging.INFO)

async def get_temporal_client():
    client = await Client.connect("temporal:7233")
    logging.info(client)  # Bien aligné ici
    return client

@app.post("/signal")
async def signal_workflow(request: Request):
    try:
        data = await request.json()
        workflow_id = "api-rate-limiter"
        signal_name = data.get("Name")
        signal_input = data.get("Input")

        # Sécurité minimale
        if not signal_name:
                return {"status": "Erreur", "message": "Le champ 'Name' est obligatoire."}

        client = await get_temporal_client()

        await client.signal_workflow(
            workflow_id=workflow_id,
            signal=signal_name,
            arg=signal_input
        )

        return {"status": "Signal envoyé avec succès"}

    except Exception as e:
        logging.exception("Erreur lors de l'envoi du signal Temporal")
        return {"status": "Erreur", "message": str(e)}
