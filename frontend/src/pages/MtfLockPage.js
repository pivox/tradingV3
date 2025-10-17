// src/pages/MtfLockPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const MtfLockPage = () => {
    const [locks, setLocks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        lockType: '',
        status: ''
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'createdAt',
        direction: 'desc'
    });
    const [autoRefresh, setAutoRefresh] = useState(true);

    const isFetchingRef = useRef(false);
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchLocks();

        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                fetchLocks(false);
            }, 10000); // Actualisation toutes les 10 secondes
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [filters, sortConfig, autoRefresh]);

    const fetchLocks = async (withSpinner = true) => {
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

            const response = await api.getMtfLocks(params);
            setLocks(response.data || response);
        } catch (err) {
            console.error('Erreur lors du chargement des verrous MTF:', err);
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
            symbol: '',
            lockType: '',
            status: ''
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getLockTypeBadgeClass = (lockType) => {
        switch (lockType?.toLowerCase()) {
            case 'read':
                return 'badge-info';
            case 'write':
                return 'badge-warning';
            case 'exclusive':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const getStatusBadgeClass = (status) => {
        switch (status?.toLowerCase()) {
            case 'active':
            case 'locked':
                return 'badge-danger';
            case 'released':
            case 'unlocked':
                return 'badge-success';
            case 'expired':
                return 'badge-warning';
            default:
                return 'badge-secondary';
        }
    };

    const isLockExpired = (expiresAt) => {
        if (!expiresAt) return false;
        return new Date(expiresAt) < new Date();
    };

    const getLockDuration = (createdAt, expiresAt) => {
        if (!createdAt || !expiresAt) return '-';
        const created = new Date(createdAt);
        const expires = new Date(expiresAt);
        const duration = expires - created;
        return Math.round(duration / 1000) + 's';
    };

    return (
        <div className="mtf-lock-page">
            <div className="page-header">
                <h1>Verrous MTF</h1>
                <div className="page-actions">
                    <div className="auto-refresh-toggle">
                        <label>
                            <input
                                type="checkbox"
                                checked={autoRefresh}
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                            />
                            Actualisation auto (10s)
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
                        onClick={() => fetchLocks(true)}
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
                        <label>Type de verrou</label>
                        <select
                            value={filters.lockType}
                            onChange={(e) => handleFilterChange('lockType', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="read">Lecture</option>
                            <option value="write">Écriture</option>
                            <option value="exclusive">Exclusif</option>
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
                            <option value="active">Actif</option>
                            <option value="released">Libéré</option>
                            <option value="expired">Expiré</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Erreur */}
            {error && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* Tableau des verrous */}
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
                                onClick={() => handleSort('lockType')}
                            >
                                Type {sortConfig.key === 'lockType' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('status')}
                            >
                                Statut {sortConfig.key === 'status' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Durée</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('createdAt')}
                            >
                                Créé le {sortConfig.key === 'createdAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('expiresAt')}
                            >
                                Expire le {sortConfig.key === 'expiresAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="loading">Chargement des verrous...</div>
                                </td>
                            </tr>
                        ) : locks.length === 0 ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="no-data">Aucun verrou trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            locks.map((lock) => {
                                const expired = isLockExpired(lock.expiresAt);
                                const duration = getLockDuration(lock.createdAt, lock.expiresAt);
                                
                                return (
                                    <tr key={lock.id} className={expired ? 'expired-row' : ''}>
                                        <td>{lock.id}</td>
                                        <td>
                                            <span className="symbol-badge">{lock.symbol}</span>
                                        </td>
                                        <td>
                                            <span className={`badge ${getLockTypeBadgeClass(lock.lockType)}`}>
                                                {lock.lockType}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`badge ${getStatusBadgeClass(lock.status)}`}>
                                                {lock.status}
                                            </span>
                                            {expired && (
                                                <span className="badge badge-warning ml-1">
                                                    Expiré
                                                </span>
                                            )}
                                        </td>
                                        <td>{duration}</td>
                                        <td>{formatDate(lock.createdAt)}</td>
                                        <td>
                                            {lock.expiresAt ? (
                                                <span className={expired ? 'text-danger' : ''}>
                                                    {formatDate(lock.expiresAt)}
                                                </span>
                                            ) : (
                                                <span className="text-muted">-</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Statistiques */}
            {locks.length > 0 && (
                <div className="stats-section">
                    <h3>Statistiques</h3>
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{locks.length}</div>
                            <div className="stat-label">Total Verrous</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => l.status === 'active' || l.status === 'locked').length}
                            </div>
                            <div className="stat-label">Actifs</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => l.status === 'released' || l.status === 'unlocked').length}
                            </div>
                            <div className="stat-label">Libérés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => isLockExpired(l.expiresAt)).length}
                            </div>
                            <div className="stat-label">Expirés</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => l.lockType === 'read').length}
                            </div>
                            <div className="stat-label">Lecture</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => l.lockType === 'write').length}
                            </div>
                            <div className="stat-label">Écriture</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {locks.filter(l => l.lockType === 'exclusive').length}
                            </div>
                            <div className="stat-label">Exclusifs</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default MtfLockPage;
