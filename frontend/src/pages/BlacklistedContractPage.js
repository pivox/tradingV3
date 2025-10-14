// src/pages/BlacklistedContractPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const BlacklistedContractPage = () => {
    const [blacklistedContracts, setBlacklistedContracts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        reason: '',
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
        fetchBlacklistedContracts();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchBlacklistedContracts = async () => {
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

            const response = await api.getBlacklistedContracts(params);
            setBlacklistedContracts(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des contrats blacklistés:', err);
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
            reason: '',
            dateFrom: '',
            dateTo: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getReasonBadgeClass = (reason) => {
        switch (reason?.toLowerCase()) {
            case 'low_volume':
                return 'badge-warning';
            case 'high_spread':
                return 'badge-danger';
            case 'manipulation':
                return 'badge-danger';
            case 'maintenance':
                return 'badge-info';
            case 'delisting':
                return 'badge-secondary';
            default:
                return 'badge-secondary';
        }
    };

    const formatJsonData = (jsonData) => {
        if (!jsonData || Object.keys(jsonData).length === 0) return null;
        return JSON.stringify(jsonData, null, 2);
    };

    return (
        <div className="blacklisted-contract-page">
            <div className="page-header">
                <h1>Gestion de la Blacklist</h1>
                <p className="page-subtitle">Gérer une blacklist de symboles avec raison et expiration</p>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchBlacklistedContracts}
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
                        <label>Raison</label>
                        <select
                            value={filters.reason}
                            onChange={(e) => handleFilterChange('reason', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Toutes</option>
                            <option value="low_volume">Volume faible</option>
                            <option value="high_spread">Spread élevé</option>
                            <option value="manipulation">Manipulation</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="delisting">Délitement</option>
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

            {/* Tableau des contrats blacklistés */}
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
                                onClick={() => handleSort('reason')}
                            >
                                Raison {sortConfig.key === 'reason' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Détails</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('createdAt')}
                            >
                                Blacklisté le {sortConfig.key === 'createdAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="5" className="text-center">
                                    <div className="loading">Chargement des contrats blacklistés...</div>
                                </td>
                            </tr>
                        ) : blacklistedContracts.length === 0 ? (
                            <tr>
                                <td colSpan="5" className="text-center">
                                    <div className="no-data">Aucun contrat blacklisté trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            blacklistedContracts.map((contract) => (
                                <tr key={contract.id}>
                                    <td>{contract.id}</td>
                                    <td>
                                        <span className="symbol-badge blacklisted">{contract.symbol}</span>
                                    </td>
                                    <td>
                                        <span className={`badge ${getReasonBadgeClass(contract.reason)}`}>
                                            {contract.reason}
                                        </span>
                                    </td>
                                    <td>
                                        {formatJsonData(contract.details) ? (
                                            <details className="json-details">
                                                <summary>Voir</summary>
                                                <pre className="json-content">
                                                    {formatJsonData(contract.details)}
                                                </pre>
                                            </details>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>{formatDate(contract.createdAt)}</td>
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
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} contrats
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
            {blacklistedContracts.length > 0 && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{blacklistedContracts.length}</div>
                            <div className="stat-label">Total Blacklistés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {blacklistedContracts.filter(c => c.reason === 'low_volume').length}
                            </div>
                            <div className="stat-label">Volume Faible</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {blacklistedContracts.filter(c => c.reason === 'high_spread').length}
                            </div>
                            <div className="stat-label">Spread Élevé</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {blacklistedContracts.filter(c => c.reason === 'manipulation').length}
                            </div>
                            <div className="stat-label">Manipulation</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {blacklistedContracts.filter(c => c.reason === 'maintenance').length}
                            </div>
                            <div className="stat-label">Maintenance</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {blacklistedContracts.filter(c => c.reason === 'delisting').length}
                            </div>
                            <div className="stat-label">Délitement</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default BlacklistedContractPage;
