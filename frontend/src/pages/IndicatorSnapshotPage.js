// src/pages/IndicatorSnapshotPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const IndicatorSnapshotPage = () => {
    const [snapshots, setSnapshots] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        timeframe: '',
        indicatorName: '',
        dateFrom: '',
        dateTo: ''
    });
    const [pagination, setPagination] = useState({
        page: 1,
        limit: 50,
        total: 0
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'createdAt',
        direction: 'desc'
    });

    const isFetchingRef = useRef(false);

    useEffect(() => {
        fetchSnapshots();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchSnapshots = async () => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        setLoading(true);
        setError(null);

        try {
            const params = {
                page: pagination.page,
                limit: pagination.limit,
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

            const response = await api.getIndicatorSnapshots(params);
            setSnapshots(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des snapshots d\'indicateurs:', err);
            setError(`Erreur de chargement: ${err.message}`);
        } finally {
            setLoading(false);
            isFetchingRef.current = false;
        }
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const handleSort = (key) => {
        setSortConfig(prev => ({
            key,
            direction: prev.key === key && prev.direction === 'asc' ? 'desc' : 'asc'
        }));
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
    };

    const clearFilters = () => {
        setFilters({
            symbol: '',
            timeframe: '',
            indicatorName: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
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

    const formatJsonData = (jsonData) => {
        if (!jsonData || Object.keys(jsonData).length === 0) return null;
        return JSON.stringify(jsonData, null, 2);
    };

    return (
        <div className="indicator-snapshot-page">
            <div className="page-header">
                <h1>Snapshots d'Indicateurs</h1>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchSnapshots}
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

                    <div className="filter-group">
                        <label>Timeframe</label>
                        <select
                            value={filters.timeframe}
                            onChange={(e) => handleFilterChange('timeframe', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="1m">1 minute</option>
                            <option value="5m">5 minutes</option>
                            <option value="15m">15 minutes</option>
                            <option value="1h">1 heure</option>
                            <option value="4h">4 heures</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label>Nom de l'indicateur</label>
                        <input
                            type="text"
                            placeholder="Ex: RSI, MACD, EMA"
                            value={filters.indicatorName}
                            onChange={(e) => handleFilterChange('indicatorName', e.target.value)}
                            className="form-control"
                        />
                    </div>

                    <div className="filter-group">
                        <label>Date de début</label>
                        <input
                            type="datetime-local"
                            value={filters.dateFrom}
                            onChange={(e) => handleFilterChange('dateFrom', e.target.value)}
                            className="form-control"
                        />
                    </div>

                    <div className="filter-group">
                        <label>Date de fin</label>
                        <input
                            type="datetime-local"
                            value={filters.dateTo}
                            onChange={(e) => handleFilterChange('dateTo', e.target.value)}
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

            {/* Tableau des snapshots */}
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
                            <th 
                                className="sortable"
                                onClick={() => handleSort('timeframe')}
                            >
                                Timeframe {sortConfig.key === 'timeframe' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('indicatorName')}
                            >
                                Indicateur {sortConfig.key === 'indicatorName' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Valeurs</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('createdAt')}
                            >
                                Créé le {sortConfig.key === 'createdAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="6" className="text-center">
                                    <div className="loading">Chargement des snapshots...</div>
                                </td>
                            </tr>
                        ) : snapshots.length === 0 ? (
                            <tr>
                                <td colSpan="6" className="text-center">
                                    <div className="no-data">Aucun snapshot trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            snapshots.map((snapshot) => (
                                <tr key={snapshot.id}>
                                    <td>{snapshot.id}</td>
                                    <td>
                                        <span className="symbol-badge">{snapshot.symbol}</span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getTimeframeBadgeClass(snapshot.timeframe)}`}>
                                            {snapshot.timeframe}
                                        </span>
                                    </td>
                                    <td>
                                        <span className="indicator-name">{snapshot.indicatorName}</span>
                                    </td>
                                    <td>
                                        {formatJsonData(snapshot.values) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(snapshot.values)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>{formatDate(snapshot.createdAt)}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {pagination.total > pagination.limit && (
                <div className="pagination-container">
                    <div className="pagination-info">
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} snapshots
                    </div>
                    <div className="pagination-controls">
                        <button
                            className="btn btn-sm btn-outline-primary"
                            onClick={() => handlePageChange(pagination.page - 1)}
                            disabled={pagination.page <= 1}
                        >
                            Précédent
                        </button>
                        <span className="pagination-page">
                            Page {pagination.page} sur {Math.ceil(pagination.total / pagination.limit)}
                        </span>
                        <button
                            className="btn btn-sm btn-outline-primary"
                            onClick={() => handlePageChange(pagination.page + 1)}
                            disabled={pagination.page >= Math.ceil(pagination.total / pagination.limit)}
                        >
                            Suivant
                        </button>
                    </div>
                </div>
            )}

            {/* Statistiques */}
            {snapshots.length > 0 && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{snapshots.length}</div>
                            <div className="stat-label">Total Snapshots</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {new Set(snapshots.map(s => s.symbol)).size}
                            </div>
                            <div className="stat-label">Symboles Uniques</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {new Set(snapshots.map(s => s.indicatorName)).size}
                            </div>
                            <div className="stat-label">Indicateurs Uniques</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {new Set(snapshots.map(s => s.timeframe)).size}
                            </div>
                            <div className="stat-label">Timeframes Uniques</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default IndicatorSnapshotPage;
