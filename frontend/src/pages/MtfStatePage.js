// src/pages/MtfStatePage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const MtfStatePage = () => {
    const [mtfStates, setMtfStates] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: ''
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'updatedAt',
        direction: 'desc'
    });
    const [autoRefresh, setAutoRefresh] = useState(true);

    const isFetchingRef = useRef(false);
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchMtfStates();

        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                fetchMtfStates(false);
            }, 30000); // Actualisation toutes les 30 secondes
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [filters, sortConfig, autoRefresh]);

    const fetchMtfStates = async (withSpinner = true) => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        if (withSpinner) {
            setLoading(true);
        }
        setError(null);

        try {
            const params = {
                sort: sortConfig.key,
                order: sortConfig.direction,
                ...filters
            };

            // Nettoyer les paramètres vides
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null || params[key] === undefined) {
                    delete params[key];
                }
            });

            const response = await api.getMtfStates(params);
            setMtfStates(response.data || response);
        } catch (err) {
            console.error('Erreur lors du chargement des états MTF:', err);
            setError(`Erreur de chargement: ${err.message}`);
        } finally {
            if (withSpinner) {
                setLoading(false);
            }
            isFetchingRef.current = false;
        }
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const handleSort = (key) => {
        setSortConfig(prev => ({
            key,
            direction: prev.key === key && prev.direction === 'asc' ? 'desc' : 'asc'
        }));
    };

    const clearFilters = () => {
        setFilters({
            symbol: ''
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getTimeframeStatus = (timeframe, timeValue) => {
        if (!timeValue) return { status: 'missing', text: 'Manquant' };
        
        const now = new Date();
        const time = new Date(timeValue);
        const diffMinutes = (now - time) / (1000 * 60);
        
        if (diffMinutes < 5) return { status: 'fresh', text: 'À jour' };
        if (diffMinutes < 15) return { status: 'warning', text: 'Récent' };
        return { status: 'stale', text: 'Ancien' };
    };

    const getStatusBadgeClass = (status) => {
        switch (status) {
            case 'fresh': return 'badge-success';
            case 'warning': return 'badge-warning';
            case 'stale': return 'badge-danger';
            case 'missing': return 'badge-secondary';
            default: return 'badge-secondary';
        }
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

    return (
        <div className="mtf-state-page">
            <div className="page-header">
                <h1>États MTF (Multi-Timeframe)</h1>
                <div className="page-actions">
                    <div className="auto-refresh-toggle">
                        <label>
                            <input
                                type="checkbox"
                                checked={autoRefresh}
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                            />
                            Actualisation auto (30s)
                        </label>
                    </div>
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={() => fetchMtfStates(true)}
                        disabled={loading}
                    >
                        {loading ? 'Chargement...' : 'Actualiser'}
                    </button>
                </div>
            </div>

            {/* Filtres */}
            <div className="filters-section">
                <div className="filters-grid">
                    <div className="filter-group">
                        <label>Symbole</label>
                        <input
                            type="text"
                            placeholder="Ex: BTCUSDT"
                            value={filters.symbol}
                            onChange={(e) => handleFilterChange('symbol', e.target.value)}
                            className="form-control"
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

            {/* Tableau des états MTF */}
            <div className="table-container">
                <table className="table">
                    <thead>
                        <tr>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('id')}
                            >
                                ID {sortConfig.key === 'id' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('symbol')}
                            >
                                Symbole {sortConfig.key === 'symbol' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>K4h</th>
                            <th>K1h</th>
                            <th>K15m</th>
                            <th>K5m</th>
                            <th>K1m</th>
                            <th>Sides</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('updatedAt')}
                            >
                                Mis à jour {sortConfig.key === 'updatedAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="9" className="text-center">
                                    <div className="loading">Chargement des états MTF...</div>
                                </td>
                            </tr>
                        ) : mtfStates.length === 0 ? (
                            <tr>
                                <td colSpan="9" className="text-center">
                                    <div className="no-data">Aucun état MTF trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            mtfStates.map((state) => {
                                const k4hStatus = getTimeframeStatus('4h', state.k4hTime);
                                const k1hStatus = getTimeframeStatus('1h', state.k1hTime);
                                const k15mStatus = getTimeframeStatus('15m', state.k15mTime);
                                const k5mStatus = getTimeframeStatus('5m', state.k5mTime);
                                const k1mStatus = getTimeframeStatus('1m', state.k1mTime);

                                return (
                                    <tr key={state.id}>
                                        <td>{state.id}</td>
                                        <td>
                                            <span className="symbol-badge">{state.symbol}</span>
                                        </td>
                                        <td>
                                            <div className="timeframe-cell">
                                                <span className={`badge ${getTimeframeBadgeClass('4h')}`}>4h</span>
                                                <span className={`badge ${getStatusBadgeClass(k4hStatus.status)}`}>
                                                    {k4hStatus.text}
                                                </span>
                                                <div className="time-info">
                                                    {formatDate(state.k4hTime)}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="timeframe-cell">
                                                <span className={`badge ${getTimeframeBadgeClass('1h')}`}>1h</span>
                                                <span className={`badge ${getStatusBadgeClass(k1hStatus.status)}`}>
                                                    {k1hStatus.text}
                                                </span>
                                                <div className="time-info">
                                                    {formatDate(state.k1hTime)}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="timeframe-cell">
                                                <span className={`badge ${getTimeframeBadgeClass('15m')}`}>15m</span>
                                                <span className={`badge ${getStatusBadgeClass(k15mStatus.status)}`}>
                                                    {k15mStatus.text}
                                                </span>
                                                <div className="time-info">
                                                    {formatDate(state.k15mTime)}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="timeframe-cell">
                                                <span className={`badge ${getTimeframeBadgeClass('5m')}`}>5m</span>
                                                <span className={`badge ${getStatusBadgeClass(k5mStatus.status)}`}>
                                                    {k5mStatus.text}
                                                </span>
                                                <div className="time-info">
                                                    {formatDate(state.k5mTime)}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="timeframe-cell">
                                                <span className={`badge ${getTimeframeBadgeClass('1m')}`}>1m</span>
                                                <span className={`badge ${getStatusBadgeClass(k1mStatus.status)}`}>
                                                    {k1mStatus.text}
                                                </span>
                                                <div className="time-info">
                                                    {formatDate(state.k1mTime)}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            {state.sides && Object.keys(state.sides).length > 0 ? (
                                                <details className="sides-details">
                                                    <summary>Sides</summary>
                                                    <div className="sides-content">
                                                        {Object.entries(state.sides).map(([timeframe, side]) => (
                                                            <div key={timeframe} className="side-item">
                                                                <span className={`badge ${getTimeframeBadgeClass(timeframe)}`}>
                                                                    {timeframe}
                                                                </span>
                                                                <span className={`badge ${side === 'long' ? 'badge-success' : 'badge-danger'}`}>
                                                                    {side}
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </details>
                                            ) : (
                                                <span className="text-muted">-</span>
                                            )}
                                        </td>
                                        <td>{formatDate(state.updatedAt)}</td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Statistiques */}
            {mtfStates.length > 0 && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{mtfStates.length}</div>
                            <div className="stat-label">États MTF</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {mtfStates.filter(s => s.k4hTime).length}
                            </div>
                            <div className="stat-label">Avec K4h</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {mtfStates.filter(s => s.k1hTime).length}
                            </div>
                            <div className="stat-label">Avec K1h</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {mtfStates.filter(s => s.k15mTime).length}
                            </div>
                            <div className="stat-label">Avec K15m</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {mtfStates.filter(s => s.k5mTime).length}
                            </div>
                            <div className="stat-label">Avec K5m</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {mtfStates.filter(s => s.k1mTime).length}
                            </div>
                            <div className="stat-label">Avec K1m</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default MtfStatePage;
