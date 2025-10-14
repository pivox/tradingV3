// src/pages/RuntimeHistoryPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const RuntimeHistoryPage = () => {
    const [historyData, setHistoryData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        cycleType: '',
        dateFrom: '',
        dateTo: ''
    });
    const [pagination, setPagination] = useState({
        page: 1,
        limit: 50,
        total: 0
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'startTime',
        direction: 'desc'
    });
    const [autoRefresh, setAutoRefresh] = useState(false);

    const isFetchingRef = useRef(false);
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchRuntimeHistory();

        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                fetchRuntimeHistory(false);
            }, 60000); // Actualisation toutes les minutes
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [filters, pagination.page, pagination.limit, sortConfig, autoRefresh]);

    const fetchRuntimeHistory = async (withSpinner = true) => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        if (withSpinner) {
            setLoading(true);
        }
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

            // Utiliser l'API selon l'US-013: GET /runtime/history?from=&to=
            const response = await api.getRuntimeHistory(params);
            setHistoryData(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement de l\'historique d\'exécution:', err);
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
            cycleType: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const formatDuration = (durationMs) => {
        if (!durationMs) return '-';
        
        const seconds = Math.floor(durationMs / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        
        if (hours > 0) {
            return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
        } else if (minutes > 0) {
            return `${minutes}m ${seconds % 60}s`;
        } else {
            return `${seconds}s`;
        }
    };

    const getCycleTypeBadgeClass = (cycleType) => {
        switch (cycleType?.toLowerCase()) {
            case 'mtf':
            case 'multi-timeframe':
                return 'badge-primary';
            case 'signal':
            case 'signal-processing':
                return 'badge-info';
            case 'backfill':
            case 'data-sync':
                return 'badge-warning';
            case 'validation':
            case 'post-validation':
                return 'badge-success';
            case 'error':
            case 'cleanup':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const getStatusBadgeClass = (status) => {
        switch (status?.toLowerCase()) {
            case 'completed':
            case 'success':
                return 'badge-success';
            case 'running':
            case 'in-progress':
                return 'badge-info';
            case 'warning':
            case 'partial':
                return 'badge-warning';
            case 'failed':
            case 'error':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const getPerformanceClass = (duration, symbolsProcessed) => {
        if (!duration || !symbolsProcessed) return 'badge-secondary';
        
        const avgTimePerSymbol = duration / symbolsProcessed;
        
        if (avgTimePerSymbol < 1000) return 'badge-success'; // < 1s par symbole
        if (avgTimePerSymbol < 5000) return 'badge-warning'; // < 5s par symbole
        return 'badge-danger'; // > 5s par symbole
    };

    const getTotalResults = () => {
        return historyData.length;
    };

    const getStatistics = () => {
        if (historyData.length === 0) return null;

        const totalCycles = historyData.length;
        const successfulCycles = historyData.filter(h => h.status === 'completed' || h.status === 'success').length;
        const failedCycles = historyData.filter(h => h.status === 'failed' || h.status === 'error').length;
        const totalSymbolsProcessed = historyData.reduce((sum, h) => sum + (h.symbolsProcessed || 0), 0);
        const totalDuration = historyData.reduce((sum, h) => sum + (h.duration || 0), 0);
        const avgDuration = totalDuration / totalCycles;

        return {
            totalCycles,
            successfulCycles,
            failedCycles,
            successRate: totalCycles > 0 ? (successfulCycles / totalCycles * 100).toFixed(1) : 0,
            totalSymbolsProcessed,
            avgDuration
        };
    };

    const stats = getStatistics();

    return (
        <div className="runtime-history-page">
            <div className="page-header">
                <h1>Historique d'Exécution (Runtime)</h1>
                <p className="page-subtitle">Consulter l'historique des cycles d'exécution (batchs, workers, durées)</p>
                <div className="page-actions">
                    <div className="auto-refresh-toggle">
                        <label>
                            <input
                                type="checkbox"
                                checked={autoRefresh}
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                            />
                            Actualisation auto (1min)
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
                        onClick={() => fetchRuntimeHistory(true)}
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
                        <label>Type de cycle</label>
                        <select
                            value={filters.cycleType}
                            onChange={(e) => handleFilterChange('cycleType', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="mtf">MTF</option>
                            <option value="signal">Signal Processing</option>
                            <option value="backfill">Backfill</option>
                            <option value="validation">Validation</option>
                            <option value="cleanup">Cleanup</option>
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

            {/* Statistiques */}
            {stats && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{stats.totalCycles}</div>
                            <div className="stat-label">Cycles Total</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{stats.successfulCycles}</div>
                            <div className="stat-label">Cycles Réussis</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{stats.failedCycles}</div>
                            <div className="stat-label">Cycles Échoués</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{stats.successRate}%</div>
                            <div className="stat-label">Taux de Réussite</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{stats.totalSymbolsProcessed}</div>
                            <div className="stat-label">Symboles Traités</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{formatDuration(stats.avgDuration)}</div>
                            <div className="stat-label">Durée Moyenne</div>
                        </div>
                    </div>
                </div>
            )}

            {/* Tableau de l'historique */}
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
                                onClick={() => handleSort('cycleType')}
                            >
                                Type de Cycle {sortConfig.key === 'cycleType' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('startTime')}
                            >
                                Début {sortConfig.key === 'startTime' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('duration')}
                            >
                                Durée {sortConfig.key === 'duration' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('symbolsProcessed')}
                            >
                                Symboles Traités {sortConfig.key === 'symbolsProcessed' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('status')}
                            >
                                Statut {sortConfig.key === 'status' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Issues Détectées</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="8" className="text-center">
                                    <div className="loading">Chargement de l'historique...</div>
                                </td>
                            </tr>
                        ) : historyData.length === 0 ? (
                            <tr>
                                <td colSpan="8" className="text-center">
                                    <div className="no-data">Aucun historique trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            historyData.map((history) => (
                                <tr key={history.id}>
                                    <td>{history.id}</td>
                                    <td>
                                        <span className={`badge ${getCycleTypeBadgeClass(history.cycleType)}`}>
                                            {history.cycleType}
                                        </span>
                                    </td>
                                    <td>{formatDate(history.startTime)}</td>
                                    <td>
                                        <span className="duration-value">
                                            {formatDuration(history.duration)}
                                        </span>
                                    </td>
                                    <td>
                                        <span className="symbols-count">
                                            {history.symbolsProcessed || 0}
                                        </span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getStatusBadgeClass(history.status)}`}>
                                            {history.status}
                                        </span>
                                    </td>
                                    <td>
                                        {history.issuesDetected && history.issuesDetected.length > 0 ? (
                                            <details className="issues-details">
                                                <summary>{history.issuesDetected.length} issue{history.issuesDetected.length > 1 ? 's' : ''}</summary>
                                                <div className="issues-content">
                                                    {history.issuesDetected.map((issue, index) => (
                                                        <div key={index} className="issue-item">
                                                            <span className="issue-type">{issue.type}:</span>
                                                            <span className="issue-message">{issue.message}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>
                                        <span className={`badge ${getPerformanceClass(history.duration, history.symbolsProcessed)}`}>
                                            {history.symbolsProcessed && history.duration ? 
                                                `${Math.round(history.duration / history.symbolsProcessed)}ms/symbole` : 
                                                '-'
                                            }
                                        </span>
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
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} cycles
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

            {/* Informations techniques */}
            <div className="technical-info">
                <h4>Informations techniques</h4>
                <ul>
                    <li><strong>API utilisée:</strong> GET /runtime/history?from=&to=</li>
                    <li><strong>Colonnes:</strong> Type de cycle, durée, nb. de symboles traités, issues détectées</li>
                    <li><strong>Performance:</strong> Temps moyen par symbole traité</li>
                    <li><strong>Issues:</strong> Problèmes détectés lors de l'exécution</li>
                </ul>
            </div>
        </div>
    );
};

export default RuntimeHistoryPage;
