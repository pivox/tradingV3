// src/pages/MissingKlinesPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const MissingKlinesPage = () => {
    const [missingKlines, setMissingKlines] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        timeframe: '1h',
        start: '',
        end: ''
    });
    const [backfillStatus, setBackfillStatus] = useState(null);
    const [isBackfilling, setIsBackfilling] = useState(false);

    const isFetchingRef = useRef(false);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const clearFilters = () => {
        setFilters({
            symbol: '',
            timeframe: '1h',
            start: '',
            end: ''
        });
    };

    const fetchMissingKlines = async () => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        setLoading(true);
        setError(null);
        setMissingKlines([]);

        try {
            // Validation des paramètres requis
            if (!filters.symbol || !filters.start || !filters.end) {
                throw new Error('Symbole, date de début et date de fin sont requis');
            }

            const params = {
                symbol: filters.symbol,
                tf: filters.timeframe,
                start: filters.start,
                end: filters.end
            };

            // Utiliser l'API selon l'US-010: GET /klines/missing?symbol=&tf=&start=&end=
            const response = await api.getMissingKlines(params);
            setMissingKlines(response.data || response);
        } catch (err) {
            console.error('Erreur lors de la détection des bougies manquantes:', err);
            setError(`Erreur: ${err.message}`);
        } finally {
            setLoading(false);
            isFetchingRef.current = false;
        }
    };

    const triggerBackfill = async () => {
        if (missingKlines.length === 0) {
            setError('Aucune bougie manquante détectée pour déclencher un backfill');
            return;
        }

        setIsBackfilling(true);
        setBackfillStatus(null);
        setError(null);

        try {
            // Déclencher le backfill pour toutes les plages manquantes
            const backfillPromises = missingKlines.map(range => 
                api.triggerBackfill({
                    symbol: filters.symbol,
                    timeframe: filters.timeframe,
                    start: range.from,
                    end: range.to
                })
            );

            const results = await Promise.all(backfillPromises);
            setBackfillStatus({
                success: true,
                message: `Backfill déclenché pour ${results.length} plages manquantes`,
                results: results
            });
        } catch (err) {
            console.error('Erreur lors du déclenchement du backfill:', err);
            setError(`Erreur lors du backfill: ${err.message}`);
            setBackfillStatus({
                success: false,
                message: `Erreur lors du backfill: ${err.message}`
            });
        } finally {
            setIsBackfilling(false);
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

    const calculateMissingDuration = (from, to) => {
        const fromDate = new Date(from);
        const toDate = new Date(to);
        const diffMs = toDate - fromDate;
        const diffMinutes = Math.floor(diffMs / (1000 * 60));
        
        if (diffMinutes < 60) {
            return `${diffMinutes} minutes`;
        } else if (diffMinutes < 1440) {
            const hours = Math.floor(diffMinutes / 60);
            return `${hours} heure${hours > 1 ? 's' : ''}`;
        } else {
            const days = Math.floor(diffMinutes / 1440);
            return `${days} jour${days > 1 ? 's' : ''}`;
        }
    };

    const getExpectedGranularity = (timeframe) => {
        switch (timeframe) {
            case '1m': return '1 minute';
            case '5m': return '5 minutes';
            case '15m': return '15 minutes';
            case '1h': return '1 heure';
            case '4h': return '4 heures';
            default: return 'Inconnue';
        }
    };

    return (
        <div className="missing-klines-page">
            <div className="page-header">
                <h1>Détection des Bougies Manquantes</h1>
                <p className="page-subtitle">Détecter les bougies manquantes sur une plage pour déclencher un backfill</p>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchMissingKlines}
                        disabled={loading || !filters.symbol || !filters.start || !filters.end}
                    >
                        {loading ? 'Analyse...' : 'Détecter les manquantes'}
                    </button>
                    {missingKlines.length > 0 && (
                        <button 
                            className="btn btn-success"
                            onClick={triggerBackfill}
                            disabled={isBackfilling}
                        >
                            {isBackfilling ? 'Backfill en cours...' : 'Déclencher Backfill'}
                        </button>
                    )}
                </div>
            </div>

            {/* Filtres */}
            <div className="filters-section">
                <div className="filters-grid">
                    <div className="filter-group">
                        <label>Symbole *</label>
                        <input
                            type="text"
                            placeholder="Ex: BTCUSDT"
                            value={filters.symbol}
                            onChange={(e) => handleFilterChange('symbol', e.target.value.toUpperCase())}
                            className="form-control"
                            required
                        />
                    </div>

                    <div className="filter-group">
                        <label>Timeframe</label>
                        <select
                            value={filters.timeframe}
                            onChange={(e) => handleFilterChange('timeframe', e.target.value)}
                            className="form-control"
                        >
                            <option value="1m">1 minute</option>
                            <option value="5m">5 minutes</option>
                            <option value="15m">15 minutes</option>
                            <option value="1h">1 heure</option>
                            <option value="4h">4 heures</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label>Date de début *</label>
                        <input
                            type="datetime-local"
                            value={filters.start}
                            onChange={(e) => handleFilterChange('start', e.target.value)}
                            className="form-control"
                            required
                        />
                    </div>

                    <div className="filter-group">
                        <label>Date de fin *</label>
                        <input
                            type="datetime-local"
                            value={filters.end}
                            onChange={(e) => handleFilterChange('end', e.target.value)}
                            className="form-control"
                            required
                        />
                    </div>
                </div>
            </div>

            {/* Erreur */}
            {error && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* Statut du backfill */}
            {backfillStatus && (
                <div className={`alert ${backfillStatus.success ? 'alert-success' : 'alert-danger'}`}>
                    {backfillStatus.message}
                </div>
            )}

            {/* Résultats de la détection */}
            {missingKlines.length > 0 && (
                <div className="results-section">
                    <h3>Bougies Manquantes Détectées</h3>
                    <div className="summary-card">
                        <div className="summary-item">
                            <span className="summary-label">Symbole:</span>
                            <span className="symbol-badge">{filters.symbol}</span>
                        </div>
                        <div className="summary-item">
                            <span className="summary-label">Timeframe:</span>
                            <span className={`badge ${getTimeframeBadgeClass(filters.timeframe)}`}>
                                {filters.timeframe}
                            </span>
                        </div>
                        <div className="summary-item">
                            <span className="summary-label">Plages manquantes:</span>
                            <span className="summary-value">{missingKlines.length}</span>
                        </div>
                        <div className="summary-item">
                            <span className="summary-label">Granularité attendue:</span>
                            <span className="summary-value">{getExpectedGranularity(filters.timeframe)}</span>
                        </div>
                    </div>

                    <div className="table-container">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Début (from)</th>
                                    <th>Fin (to)</th>
                                    <th>Durée manquante</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {missingKlines.map((range, index) => (
                                    <tr key={index}>
                                        <td>{index + 1}</td>
                                        <td>{formatDate(range.from)}</td>
                                        <td>{formatDate(range.to)}</td>
                                        <td>
                                            <span className="duration-badge">
                                                {calculateMissingDuration(range.from, range.to)}
                                            </span>
                                        </td>
                                        <td>
                                            <button 
                                                className="btn btn-sm btn-outline-primary"
                                                onClick={() => {
                                                    // Action pour une plage spécifique
                                                    console.log('Backfill pour plage:', range);
                                                }}
                                            >
                                                Backfill
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Message si aucune bougie manquante */}
            {!loading && missingKlines.length === 0 && filters.symbol && filters.start && filters.end && (
                <div className="no-missing-section">
                    <div className="alert alert-success">
                        <h4>✅ Aucune bougie manquante détectée</h4>
                        <p>
                            Toutes les bougies sont présentes pour <strong>{filters.symbol}</strong> 
                            sur le timeframe <strong>{filters.timeframe}</strong> 
                            entre <strong>{formatDate(filters.start)}</strong> et <strong>{formatDate(filters.end)}</strong>.
                        </p>
                    </div>
                </div>
            )}

            {/* Instructions d'utilisation */}
            <div className="instructions-section">
                <h3>Instructions d'utilisation</h3>
                <div className="instructions-grid">
                    <div className="instruction-card">
                        <h4>1. Sélection des paramètres</h4>
                        <p>Choisissez le symbole, le timeframe et la plage de dates à analyser.</p>
                    </div>
                    <div className="instruction-card">
                        <h4>2. Détection automatique</h4>
                        <p>Le système analyse la plage et identifie les bougies manquantes.</p>
                    </div>
                    <div className="instruction-card">
                        <h4>3. Backfill automatique</h4>
                        <p>Déclenchez le backfill pour récupérer les données manquantes.</p>
                    </div>
                </div>
            </div>

            {/* Informations techniques */}
            <div className="technical-info">
                <h4>Informations techniques</h4>
                <ul>
                    <li><strong>API utilisée:</strong> GET /klines/missing?symbol=&tf=&start=&end=</li>
                    <li><strong>Retour:</strong> Ranges from/to et granularité attendue</li>
                    <li><strong>Backfill:</strong> Déclenchement automatique via l'API de backfill</li>
                </ul>
            </div>
        </div>
    );
};

export default MissingKlinesPage;
