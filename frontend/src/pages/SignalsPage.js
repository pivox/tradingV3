// src/pages/SignalsPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const SignalsPage = () => {
    const [signals, setSignals] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedSignal, setSelectedSignal] = useState(null);
    const [signalDetail, setSignalDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [filters, setFilters] = useState({
        symbol: '',
        timeframe: '',
        side: '',
        dateFrom: '',
        dateTo: ''
    });
    const [pagination, setPagination] = useState({
        page: 1,
        limit: 50,
        total: 0
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'klineTime',
        direction: 'desc'
    });

    const isFetchingRef = useRef(false);

    useEffect(() => {
        fetchSignals();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchSignals = async () => {
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

            const response = await api.getSignals(params);
            setSignals(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des signaux:', err);
            setError(`Erreur de chargement: ${err.message}`);
        } finally {
            setLoading(false);
            isFetchingRef.current = false;
        }
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
        setPagination(prev => ({ ...prev, page: 1 })); // Reset à la première page
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
            side: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const fetchSignalDetail = async (signal) => {
        setDetailLoading(true);
        setSelectedSignal(signal);
        setSignalDetail(null);

        try {
            // Construire l'URL selon l'US-002: GET /signals/{symbol}/{tf}/{klineTime}
            const klineTime = new Date(signal.klineTime).toISOString();
            const detailUrl = `/api/signals/${signal.symbol}/${signal.timeframe}/${klineTime}`;
            
            // Pour l'instant, utiliser l'API existante avec l'ID
            const response = await api.getSignalDetail(signal.id);
            setSignalDetail(response);
        } catch (err) {
            console.error('Erreur lors du chargement du détail du signal:', err);
            setError(`Erreur de chargement du détail: ${err.message}`);
        } finally {
            setDetailLoading(false);
        }
    };

    const closeSignalDetail = () => {
        setSelectedSignal(null);
        setSignalDetail(null);
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getSideBadgeClass = (side) => {
        switch (side?.toLowerCase()) {
            case 'long':
            case 'buy':
                return 'badge-success';
            case 'short':
            case 'sell':
                return 'badge-danger';
            default:
                return 'badge-secondary';
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
        <div className="signals-page">
            <div className="page-header">
                <h1>Signaux</h1>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchSignals}
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
                        <label>Côté</label>
                        <select
                            value={filters.side}
                            onChange={(e) => handleFilterChange('side', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="long">Long</option>
                            <option value="short">Short</option>
                        </select>
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

            {/* Tableau des signaux */}
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
                                onClick={() => handleSort('klineTime')}
                            >
                                Date Kline {sortConfig.key === 'klineTime' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('side')}
                            >
                                Côté {sortConfig.key === 'side' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('score')}
                            >
                                Score {sortConfig.key === 'score' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Méta</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('insertedAt')}
                            >
                                Créé le {sortConfig.key === 'insertedAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="9" className="text-center">
                                    <div className="loading">Chargement des signaux...</div>
                                </td>
                            </tr>
                        ) : signals.length === 0 ? (
                            <tr>
                                <td colSpan="9" className="text-center">
                                    <div className="no-data">Aucun signal trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            signals.map((signal) => (
                                <tr key={signal.id}>
                                    <td>{signal.id}</td>
                                    <td>
                                        <span className="symbol-badge">{signal.symbol}</span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getTimeframeBadgeClass(signal.timeframe)}`}>
                                            {signal.timeframe}
                                        </span>
                                    </td>
                                    <td>{formatDate(signal.klineTime)}</td>
                                    <td>
                                        <span className={`badge ${getSideBadgeClass(signal.side)}`}>
                                            {signal.side}
                                        </span>
                                    </td>
                                    <td>
                                        {signal.score !== null ? (
                                            <span className="score-value">
                                                {typeof signal.score === 'number' 
                                                    ? signal.score.toFixed(2) 
                                                    : signal.score
                                                }
                                            </span>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>
                                        {signal.meta && Object.keys(signal.meta).length > 0 ? (
                                            <details className="meta-details">
                                                <summary>Voir</summary>
                                                <pre className="meta-content">
                                                    {JSON.stringify(signal.meta, null, 2)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>{formatDate(signal.insertedAt)}</td>
                                    <td>
                                        <button 
                                            className="btn btn-sm btn-outline-primary"
                                            onClick={() => fetchSignalDetail(signal)}
                                        >
                                            Détail
                                        </button>
                                    </td>
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
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} signaux
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

            {/* Modal de détail du signal */}
            {selectedSignal && (
                <div className="modal-overlay" onClick={closeSignalDetail}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()}>
                        <div className="modal-header">
                            <h3>Détail du Signal</h3>
                            <button className="modal-close" onClick={closeSignalDetail}>×</button>
                        </div>
                        <div className="modal-body">
                            {detailLoading ? (
                                <div className="loading">Chargement du détail...</div>
                            ) : signalDetail ? (
                                <div className="signal-detail">
                                    <div className="detail-section">
                                        <h4>Informations de base</h4>
                                        <div className="detail-grid">
                                            <div className="detail-item">
                                                <label>ID:</label>
                                                <span>{signalDetail.id}</span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Symbole:</label>
                                                <span className="symbol-badge">{signalDetail.symbol}</span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Timeframe:</label>
                                                <span className={`badge ${getTimeframeBadgeClass(signalDetail.timeframe)}`}>
                                                    {signalDetail.timeframe}
                                                </span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Côté:</label>
                                                <span className={`badge ${getSideBadgeClass(signalDetail.side)}`}>
                                                    {signalDetail.side}
                                                </span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Score:</label>
                                                <span className="score-value">
                                                    {signalDetail.score !== null ? 
                                                        (typeof signalDetail.score === 'number' 
                                                            ? signalDetail.score.toFixed(2) 
                                                            : signalDetail.score
                                                        ) : '-'
                                                    }
                                                </span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Date Kline:</label>
                                                <span>{formatDate(signalDetail.klineTime)}</span>
                                            </div>
                                            <div className="detail-item">
                                                <label>Créé le:</label>
                                                <span>{formatDate(signalDetail.insertedAt)}</span>
                                            </div>
                                        </div>
                                    </div>

                                    {signalDetail.meta && Object.keys(signalDetail.meta).length > 0 && (
                                        <div className="detail-section">
                                            <h4>Métadonnées</h4>
                                            <pre className="meta-content">
                                                {JSON.stringify(signalDetail.meta, null, 2)}
                                            </pre>
                                        </div>
                                    )}

                                    {signalDetail.context && (
                                        <div className="detail-section">
                                            <h4>Contexte MTF</h4>
                                            <pre className="context-content">
                                                {JSON.stringify(signalDetail.context, null, 2)}
                                            </pre>
                                        </div>
                                    )}

                                    {signalDetail.indicators && (
                                        <div className="detail-section">
                                            <h4>Indicateurs</h4>
                                            <pre className="indicators-content">
                                                {JSON.stringify(signalDetail.indicators, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="error">Erreur lors du chargement du détail</div>
                            )}
                        </div>
                        <div className="modal-footer">
                            <button className="btn btn-secondary" onClick={closeSignalDetail}>
                                Fermer
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default SignalsPage;
