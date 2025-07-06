from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import importlib
import os

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

# Import dynamique des routes d'indicateurs
api_path = "api"
for filename in os.listdir(api_path):
    if filename.endswith(".py") and not filename.startswith("__"):
        module_name = f"{api_path}.{filename[:-3]}"
        module = importlib.import_module(module_name)
        if hasattr(module, "router"):
            app.include_router(module.router)

# Root simple
@app.get("/")
def read_root():
    return {"message": "Bienvenue sur l'API des indicateurs techniques 🚀"}
