// src/pages/MtfAuditPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const MtfAuditPage = () => {
    const [audits, setAudits] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        runId: '',
        symbol: '',
        step: '',
        verdict: '',
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
        fetchAudits();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchAudits = async () => {
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

            const response = await api.getMtfAudits(params);
            setAudits(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des audits MTF:', err);
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
            runId: '',
            symbol: '',
            step: '',
            verdict: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getVerdictBadgeClass = (verdict) => {
        switch (verdict?.toLowerCase()) {
            case 'passed':
            case 'success':
            case 'valid':
                return 'badge-success';
            case 'warning':
            case 'partial':
                return 'badge-warning';
            case 'failed':
            case 'error':
            case 'invalid':
                return 'badge-danger';
            case 'skipped':
            case 'pending':
                return 'badge-info';
            default:
                return 'badge-secondary';
        }
    };

    const getStepBadgeClass = (step) => {
        switch (step?.toLowerCase()) {
            case 'validation':
            case 'validate':
                return 'badge-primary';
            case 'signal':
            case 'signals':
                return 'badge-info';
            case 'indicator':
            case 'indicators':
                return 'badge-warning';
            case 'decision':
            case 'decision-making':
                return 'badge-success';
            case 'execution':
            case 'execute':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const formatJsonData = (jsonData) => {
        if (!jsonData || Object.keys(jsonData).length === 0) return null;
        return JSON.stringify(jsonData, null, 2);
    };

    return (
        <div className="mtf-audit-page">
            <div className="page-header">
                <h1>Journal MTF (Audit)</h1>
                <p className="page-subtitle">Visualiser un journal des étapes MTF (validation séquentielle, causes d'échec)</p>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchAudits}
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
                        <label>Run ID</label>
                        <input
                            type="text"
                            placeholder="Ex: run_123456"
                            value={filters.runId}
                            onChange={(e) => handleFilterChange('runId', e.target.value)}
                            className="form-control"
                        />
                    </div>

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
                        <label>Étape</label>
                        <select
                            value={filters.step}
                            onChange={(e) => handleFilterChange('step', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Toutes</option>
                            <option value="validation">Validation</option>
                            <option value="signal">Signal</option>
                            <option value="indicator">Indicateur</option>
                            <option value="decision">Décision</option>
                            <option value="execution">Exécution</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label>Verdict</label>
                        <select
                            value={filters.verdict}
                            onChange={(e) => handleFilterChange('verdict', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="passed">Réussi</option>
                            <option value="failed">Échoué</option>
                            <option value="warning">Avertissement</option>
                            <option value="skipped">Ignoré</option>
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

            {/* Tableau des audits */}
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
                                onClick={() => handleSort('runId')}
                            >
                                Run ID {sortConfig.key === 'runId' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('symbol')}
                            >
                                Symbole {sortConfig.key === 'symbol' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('step')}
                            >
                                Étape {sortConfig.key === 'step' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('verdict')}
                            >
                                Verdict/Cause {sortConfig.key === 'verdict' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Payload JSON</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('timestamp')}
                            >
                                Horodatage {sortConfig.key === 'timestamp' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="loading">Chargement des audits...</div>
                                </td>
                            </tr>
                        ) : audits.length === 0 ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="no-data">Aucun audit trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            audits.map((audit) => (
                                <tr key={audit.id}>
                                    <td>{audit.id}</td>
                                    <td>
                                        <span className="run-id-badge">{audit.runId || '-'}</span>
                                    </td>
                                    <td>
                                        <span className="symbol-badge">{audit.symbol}</span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getStepBadgeClass(audit.step)}`}>
                                            {audit.step}
                                        </span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getVerdictBadgeClass(audit.verdict)}`}>
                                            {audit.verdict}
                                        </span>
                                        {audit.cause && (
                                            <div className="cause-text">
                                                <small>{audit.cause}</small>
                                            </div>
                                        )}
                                    </td>
                                    <td>
                                        {formatJsonData(audit.payload) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(audit.payload)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>{formatDate(audit.timestamp)}</td>
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
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} audits
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
        </div>
    );
};

export default MtfAuditPage;

