// src/pages/HealthMonitoringPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const HealthMonitoringPage = () => {
    const [healthData, setHealthData] = useState({
        restFlux: null,
        wsFlux: null,
        latency: null,
        lastTimestamps: [],
        recentErrors: []
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [refreshInterval, setRefreshInterval] = useState(30); // secondes

    const isFetchingRef = useRef(false);
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchHealthData();

        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                fetchHealthData(false);
            }, refreshInterval * 1000);
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [autoRefresh, refreshInterval]);

    const fetchHealthData = async (withSpinner = true) => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        if (withSpinner) {
            setLoading(true);
        }
        setError(null);

        try {
            // Utiliser l'API selon l'US-012: GET /health/data
            const response = await api.getHealthData();
            setHealthData(response.data || response);
        } catch (err) {
            console.error('Erreur lors du chargement des données de santé:', err);
            setError(`Erreur de chargement: ${err.message}`);
        } finally {
            if (withSpinner) {
                setLoading(false);
            }
            isFetchingRef.current = false;
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getTimeframeBadgeClass = (timeframe) => {
        switch (timeframe) {
            case '1m': return 'badge-primary';
            case '5m': return 'badge-info';
            case '15m': return 'badge-warning';
            case '1h': return 'badge-success';
            case '4h': return 'badge-danger';
            default: return 'badge-secondary';
        }
    };

    const getHealthStatusClass = (status) => {
        switch (status?.toLowerCase()) {
            case 'healthy':
            case 'ok':
            case 'active':
                return 'badge-success';
            case 'warning':
            case 'degraded':
                return 'badge-warning';
            case 'error':
            case 'down':
            case 'inactive':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const getLatencyClass = (latency) => {
        if (latency < 100) return 'badge-success';
        if (latency < 500) return 'badge-warning';
        return 'badge-danger';
    };

    const calculateDataAge = (timestamp) => {
        if (!timestamp) return { age: null, status: 'unknown' };
        
        const now = new Date();
        const time = new Date(timestamp);
        const diffMinutes = (now - time) / (1000 * 60);
        
        if (diffMinutes < 1) return { age: '< 1min', status: 'fresh' };
        if (diffMinutes < 5) return { age: `${Math.round(diffMinutes)}min`, status: 'recent' };
        if (diffMinutes < 15) return { age: `${Math.round(diffMinutes)}min`, status: 'warning' };
        return { age: `${Math.round(diffMinutes)}min`, status: 'stale' };
    };

    const getDataAgeClass = (status) => {
        switch (status) {
            case 'fresh': return 'badge-success';
            case 'recent': return 'badge-info';
            case 'warning': return 'badge-warning';
            case 'stale': return 'badge-danger';
            default: return 'badge-secondary';
        }
    };

    const getOverallHealthStatus = () => {
        const { restFlux, wsFlux, latency, lastTimestamps, recentErrors } = healthData;
        
        // Vérifier les flux
        const restOk = restFlux?.status === 'healthy' || restFlux?.status === 'active';
        const wsOk = wsFlux?.status === 'healthy' || wsFlux?.status === 'active';
        
        // Vérifier la latence
        const latencyOk = latency?.average < 1000; // moins de 1 seconde
        
        // Vérifier les timestamps récents
        const recentData = lastTimestamps?.filter(ts => {
            const age = calculateDataAge(ts.timestamp);
            return age.status === 'fresh' || age.status === 'recent';
        }).length || 0;
        
        const totalSymbols = lastTimestamps?.length || 0;
        const dataFreshnessOk = totalSymbols > 0 && (recentData / totalSymbols) > 0.8; // 80% des données récentes
        
        // Vérifier les erreurs récentes
        const recentErrorsCount = recentErrors?.filter(err => {
            const errorTime = new Date(err.timestamp);
            const now = new Date();
            const diffMinutes = (now - errorTime) / (1000 * 60);
            return diffMinutes < 60; // erreurs de la dernière heure
        }).length || 0;
        
        const errorsOk = recentErrorsCount < 5; // moins de 5 erreurs récentes
        
        if (restOk && wsOk && latencyOk && dataFreshnessOk && errorsOk) {
            return { status: 'healthy', text: 'Système en bonne santé', class: 'badge-success' };
        } else if (restOk && wsOk && latencyOk && dataFreshnessOk) {
            return { status: 'warning', text: 'Quelques problèmes mineurs', class: 'badge-warning' };
        } else {
            return { status: 'critical', text: 'Problèmes critiques détectés', class: 'badge-danger' };
        }
    };

    return (
        <div className="health-monitoring-page">
            <div className="page-header">
                <h1>Santé & Fraîcheur des Données</h1>
                <p className="page-subtitle">État de santé des flux (REST/WS), latence, derniers timestamps par TF</p>
                <div className="page-actions">
                    <div className="auto-refresh-controls">
                        <label>
                            <input
                                type="checkbox"
                                checked={autoRefresh}
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                            />
                            Actualisation auto
                        </label>
                        <select
                            value={refreshInterval}
                            onChange={(e) => setRefreshInterval(Number(e.target.value))}
                            disabled={!autoRefresh}
                        >
                            <option value={10}>10s</option>
                            <option value={30}>30s</option>
                            <option value={60}>1min</option>
                            <option value={300}>5min</option>
                        </select>
                    </div>
                    <button 
                        className="btn btn-primary"
                        onClick={() => fetchHealthData(true)}
                        disabled={loading}
                    >
                        {loading ? 'Chargement...' : 'Actualiser'}
                    </button>
                </div>
            </div>

            {/* Erreur */}
            {error && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* Statut global */}
            <div className="overall-status-section">
                <div className="status-card">
                    <h3>Statut Global du Système</h3>
                    {(() => {
                        const overallStatus = getOverallHealthStatus();
                        return (
                            <div className="overall-status">
                                <span className={`badge ${overallStatus.class} status-badge-large`}>
                                    {overallStatus.text}
                                </span>
                            </div>
                        );
                    })()}
                </div>
            </div>

            {/* Flux REST/WS */}
            <div className="flux-section">
                <h3>État des Flux</h3>
                <div className="flux-grid">
                    <div className="flux-card">
                        <h4>Flux REST</h4>
                        <div className="flux-status">
                            <span className={`badge ${getHealthStatusClass(healthData.restFlux?.status)}`}>
                                {healthData.restFlux?.status || 'Inconnu'}
                            </span>
                        </div>
                        <div className="flux-details">
                            <div className="detail-item">
                                <span className="detail-label">URL:</span>
                                <span>{healthData.restFlux?.url || '-'}</span>
                            </div>
                            <div className="detail-item">
                                <span className="detail-label">Dernière requête:</span>
                                <span>{formatDate(healthData.restFlux?.lastRequest)}</span>
                            </div>
                            <div className="detail-item">
                                <span className="detail-label">Erreurs récentes:</span>
                                <span>{healthData.restFlux?.recentErrors || 0}</span>
                            </div>
                        </div>
                    </div>

                    <div className="flux-card">
                        <h4>Flux WebSocket</h4>
                        <div className="flux-status">
                            <span className={`badge ${getHealthStatusClass(healthData.wsFlux?.status)}`}>
                                {healthData.wsFlux?.status || 'Inconnu'}
                            </span>
                        </div>
                        <div className="flux-details">
                            <div className="detail-item">
                                <span className="detail-label">URL:</span>
                                <span>{healthData.wsFlux?.url || '-'}</span>
                            </div>
                            <div className="detail-item">
                                <span className="detail-label">Dernière connexion:</span>
                                <span>{formatDate(healthData.wsFlux?.lastConnection)}</span>
                            </div>
                            <div className="detail-item">
                                <span className="detail-label">Messages reçus:</span>
                                <span>{healthData.wsFlux?.messagesReceived || 0}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Latence */}
            {healthData.latency && (
                <div className="latency-section">
                    <h3>Latence</h3>
                    <div className="latency-grid">
                        <div className="latency-card">
                            <h4>Latence Moyenne</h4>
                            <div className="latency-value">
                                <span className={`badge ${getLatencyClass(healthData.latency.average)}`}>
                                    {healthData.latency.average}ms
                                </span>
                            </div>
                        </div>
                        <div className="latency-card">
                            <h4>Latence Min</h4>
                            <div className="latency-value">
                                <span className="badge badge-info">
                                    {healthData.latency.min}ms
                                </span>
                            </div>
                        </div>
                        <div className="latency-card">
                            <h4>Latence Max</h4>
                            <div className="latency-value">
                                <span className={`badge ${getLatencyClass(healthData.latency.max)}`}>
                                    {healthData.latency.max}ms
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Derniers timestamps par TF */}
            {healthData.lastTimestamps && healthData.lastTimestamps.length > 0 && (
                <div className="timestamps-section">
                    <h3>Derniers Timestamps par Timeframe</h3>
                    <div className="table-container">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Symbole</th>
                                    <th>Timeframe</th>
                                    <th>Dernier Timestamp</th>
                                    <th>Âge des Données</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {healthData.lastTimestamps.map((timestamp, index) => {
                                    const age = calculateDataAge(timestamp.timestamp);
                                    return (
                                        <tr key={index}>
                                            <td>
                                                <span className="symbol-badge">{timestamp.symbol}</span>
                                            </td>
                                            <td>
                                                <span className={`badge ${getTimeframeBadgeClass(timestamp.timeframe)}`}>
                                                    {timestamp.timeframe}
                                                </span>
                                            </td>
                                            <td>{formatDate(timestamp.timestamp)}</td>
                                            <td>
                                                <span className={`badge ${getDataAgeClass(age.status)}`}>
                                                    {age.age || 'Inconnu'}
                                                </span>
                                            </td>
                                            <td>
                                                <span className={`badge ${getDataAgeClass(age.status)}`}>
                                                    {age.status === 'fresh' ? 'Frais' : 
                                                     age.status === 'recent' ? 'Récent' :
                                                     age.status === 'warning' ? 'Attention' : 'Ancien'}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Erreurs récentes */}
            {healthData.recentErrors && healthData.recentErrors.length > 0 && (
                <div className="errors-section">
                    <h3>Erreurs Récentes</h3>
                    <div className="errors-list">
                        {healthData.recentErrors.slice(0, 10).map((error, index) => (
                            <div key={index} className="error-item">
                                <div className="error-header">
                                    <span className="error-timestamp">{formatDate(error.timestamp)}</span>
                                    <span className={`badge ${getHealthStatusClass(error.level)}`}>
                                        {error.level}
                                    </span>
                                </div>
                                <div className="error-message">{error.message}</div>
                                {error.context && (
                                    <div className="error-context">
                                        <details>
                                            <summary>Contexte</summary>
                                            <pre>{JSON.stringify(error.context, null, 2)}</pre>
                                        </details>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Statistiques */}
            <div className="stats-section">
                <h3>Statistiques</h3>
                <div className="stats-grid">
                    <div className="stat-card">
                        <div className="stat-value">
                            {healthData.lastTimestamps?.length || 0}
                        </div>
                        <div className="stat-label">Symboles Surveillés</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-value">
                            {healthData.lastTimestamps?.filter(ts => {
                                const age = calculateDataAge(ts.timestamp);
                                return age.status === 'fresh';
                            }).length || 0}
                        </div>
                        <div className="stat-label">Données Fraîches</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-value">
                            {healthData.recentErrors?.length || 0}
                        </div>
                        <div className="stat-label">Erreurs Récentes</div>
                    </div>
                    <div className="stat-card">
                        <div className="stat-value">
                            {healthData.latency?.average || 0}ms
                        </div>
                        <div className="stat-label">Latence Moyenne</div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default HealthMonitoringPage;
