// src/pages/OrderPlanPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const OrderPlanPage = () => {
    const [orderPlans, setOrderPlans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        side: '',
        status: '',
        dateFrom: '',
        dateTo: ''
    });
    const [pagination, setPagination] = useState({
        page: 1,
        limit: 50,
        total: 0
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'planTime',
        direction: 'desc'
    });

    const isFetchingRef = useRef(false);

    useEffect(() => {
        fetchOrderPlans();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchOrderPlans = async () => {
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

            const response = await api.getOrderPlans(params);
            setOrderPlans(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des plans de commandes:', err);
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
            side: '',
            status: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
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

    const getStatusBadgeClass = (status) => {
        switch (status?.toLowerCase()) {
            case 'planned':
                return 'badge-info';
            case 'executed':
                return 'badge-success';
            case 'cancelled':
                return 'badge-danger';
            case 'failed':
                return 'badge-warning';
            default:
                return 'badge-secondary';
        }
    };

    const formatJsonData = (jsonData) => {
        if (!jsonData || Object.keys(jsonData).length === 0) return null;
        return JSON.stringify(jsonData, null, 2);
    };

    return (
        <div className="order-plan-page">
            <div className="page-header">
                <h1>Plans de Commandes</h1>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchOrderPlans}
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
                        <label>Statut</label>
                        <select
                            value={filters.status}
                            onChange={(e) => handleFilterChange('status', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="PLANNED">Planifié</option>
                            <option value="EXECUTED">Exécuté</option>
                            <option value="CANCELLED">Annulé</option>
                            <option value="FAILED">Échoué</option>
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

            {/* Tableau des plans de commandes */}
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
                                onClick={() => handleSort('planTime')}
                            >
                                Date Plan {sortConfig.key === 'planTime' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('side')}
                            >
                                Côté {sortConfig.key === 'side' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('status')}
                            >
                                Statut {sortConfig.key === 'status' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Risk JSON</th>
                            <th>Context JSON</th>
                            <th>Exec JSON</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="8" className="text-center">
                                    <div className="loading">Chargement des plans de commandes...</div>
                                </td>
                            </tr>
                        ) : orderPlans.length === 0 ? (
                            <tr>
                                <td colSpan="8" className="text-center">
                                    <div className="no-data">Aucun plan de commande trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            orderPlans.map((plan) => (
                                <tr key={plan.id}>
                                    <td>{plan.id}</td>
                                    <td>
                                        <span className="symbol-badge">{plan.symbol}</span>
                                    </td>
                                    <td>{formatDate(plan.planTime)}</td>
                                    <td>
                                        <span className={`badge ${getSideBadgeClass(plan.side)}`}>
                                            {plan.side}
                                        </span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getStatusBadgeClass(plan.status)}`}>
                                            {plan.status}
                                        </span>
                                    </td>
                                    <td>
                                        {formatJsonData(plan.riskJson) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(plan.riskJson)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>
                                        {formatJsonData(plan.contextJson) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(plan.contextJson)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>
                                        {formatJsonData(plan.execJson) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(plan.execJson)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
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
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} plans
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
            {orderPlans.length > 0 && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{orderPlans.length}</div>
                            <div className="stat-label">Total Plans</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.status === 'PLANNED').length}
                            </div>
                            <div className="stat-label">Planifiés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.status === 'EXECUTED').length}
                            </div>
                            <div className="stat-label">Exécutés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.status === 'CANCELLED').length}
                            </div>
                            <div className="stat-label">Annulés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.status === 'FAILED').length}
                            </div>
                            <div className="stat-label">Échoués</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.side === 'long').length}
                            </div>
                            <div className="stat-label">Long</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {orderPlans.filter(p => p.side === 'short').length}
                            </div>
                            <div className="stat-label">Short</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default OrderPlanPage;
