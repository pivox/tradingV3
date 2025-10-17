from temporalio import activity
import os
import json
from datetime import datetime


@activity.defn
async def write_log_to_file(log_data):
    """Écrit un log sur le filesystem"""
    
    # Créer le répertoire de logs s'il n'existe pas
    log_dir = "/var/log/symfony"
    os.makedirs(log_dir, exist_ok=True)
    
    # Construire le chemin du fichier de log
    channel = log_data.get('channel', 'default')
    log_file = os.path.join(log_dir, f"{channel}.log")
    
    # Formater le log
    timestamp = log_data.get('timestamp', datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3])
    level = log_data.get('level', 'INFO')
    message = log_data.get('message', '')
    context = log_data.get('context', {})
    symbol = log_data.get('symbol', '')
    timeframe = log_data.get('timeframe', '')
    side = log_data.get('side', '')
    
    # Format: [timestamp] channel.LEVEL: message {context}
    context_str = f" {json.dumps(context)}" if context else ""
    formatted_log = f"[{timestamp}] {channel}.{level.upper()}: {message}{context_str}\n"
    
    # Écrire le log
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(formatted_log)
    
    return f"Log written to {log_file}"


