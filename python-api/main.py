from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import importlib
from pathlib import Path

app = FastAPI(
    title="Technical Indicator API",
    version="1.0.0"
)

# Middleware CORS (optionnel mais utile en dev)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Import dynamique des routes d’indicateurs dans technical_indicator/api
api_dir = Path(__file__).parent / "technical_indicator" / "api"
for pyfile in api_dir.glob("*.py"):
    if not pyfile.name.startswith("__"):
        module_name = f"technical_indicator.api.{pyfile.stem}"
        module = importlib.import_module(module_name)
        if hasattr(module, "router"):
            app.include_router(module.router)

# Root simple
@app.get("/")
def read_root():
    return {"message": "Bienvenue sur l'API des indicateurs techniques 🚀"}
