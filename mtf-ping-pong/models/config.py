"""
Modèles de configuration pour le workflow MTF Ping-Pong
"""
from dataclasses import dataclass, field
from typing import List, Optional
from datetime import datetime


@dataclass
class MtfPingPongConfig:
    """Configuration pour le workflow ping-pong MTF"""
    
    # URL de l'endpoint MTF run
    mtf_url: str = "http://trading-app-nginx:80/api/mtf/run"
    
    # Symboles à traiter
    symbols: List[str] = field(default_factory=lambda: [
        "BTCUSDT", "ETHUSDT", "ADAUSDT", "SOLUSDT", "DOTUSDT"
    ])
    
    # Mode dry-run (pas de création d'order plans)
    dry_run: bool = True
    
    # Forcer l'exécution même si les kill switches sont OFF
    force_run: bool = False
    
    # Temps maximum d'exécution en secondes (7 minutes par défaut)
    max_execution_time: int = 420
    
    # Intervalle entre les pings en secondes
    ping_interval: int = 30
    
    # Timeout pour les appels HTTP en secondes
    http_timeout: int = 120
    
    # Nombre maximum de tentatives pour les activités
    max_retries: int = 3
    
    # Intervalle initial pour les retries en secondes
    retry_initial_interval: int = 5
    
    # Intervalle maximum pour les retries en secondes
    retry_max_interval: int = 30
    
    # Coefficient de backoff pour les retries
    retry_backoff_coefficient: float = 2.0
    
    def __post_init__(self):
        """Validation de la configuration après initialisation"""
        if self.max_execution_time <= 0:
            raise ValueError("max_execution_time doit être positif")
        
        if self.ping_interval <= 0:
            raise ValueError("ping_interval doit être positif")
        
        if self.http_timeout <= 0:
            raise ValueError("http_timeout doit être positif")
        
        if not self.symbols:
            raise ValueError("Au moins un symbole doit être spécifié")
        
        if not self.mtf_url:
            raise ValueError("mtf_url ne peut pas être vide")


@dataclass
class WorkflowStatus:
    """Statut du workflow"""
    
    is_running: bool = False
    current_iteration: int = 0
    mtf_completed: bool = False
    temporal_notified: bool = False
    workflow_id: Optional[str] = None
    run_id: Optional[str] = None
    start_time: Optional[datetime] = None
    last_ping_time: Optional[datetime] = None
    error_count: int = 0
    success_count: int = 0
    
    def to_dict(self) -> dict:
        """Convertit le statut en dictionnaire"""
        return {
            "is_running": self.is_running,
            "current_iteration": self.current_iteration,
            "mtf_completed": self.mtf_completed,
            "temporal_notified": self.temporal_notified,
            "workflow_id": self.workflow_id,
            "run_id": self.run_id,
            "start_time": self.start_time.isoformat() if self.start_time else None,
            "last_ping_time": self.last_ping_time.isoformat() if self.last_ping_time else None,
            "error_count": self.error_count,
            "success_count": self.success_count
        }


@dataclass
class MtfRunResult:
    """Résultat d'un appel MTF run"""
    
    success: bool
    status_code: Optional[int] = None
    data: Optional[dict] = None
    error: Optional[str] = None
    timestamp: Optional[datetime] = None
    execution_time: Optional[float] = None
    
    def to_dict(self) -> dict:
        """Convertit le résultat en dictionnaire"""
        return {
            "success": self.success,
            "status_code": self.status_code,
            "data": self.data,
            "error": self.error,
            "timestamp": self.timestamp.isoformat() if self.timestamp else None,
            "execution_time": self.execution_time
        }


@dataclass
class NotificationData:
    """Données de notification à Temporal"""
    
    workflow_id: str
    iteration: int
    mtf_completed: bool
    timestamp: datetime
    config: dict
    result: Optional[MtfRunResult] = None
    
    def to_dict(self) -> dict:
        """Convertit les données de notification en dictionnaire"""
        return {
            "workflow_id": self.workflow_id,
            "iteration": self.iteration,
            "mtf_completed": self.mtf_completed,
            "timestamp": self.timestamp.isoformat(),
            "config": self.config,
            "result": self.result.to_dict() if self.result else None
        }








