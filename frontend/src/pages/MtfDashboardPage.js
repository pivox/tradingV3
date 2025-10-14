// src/pages/MtfDashboardPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const MtfDashboardPage = () => {
    const [dashboardData, setDashboardData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        freshness: 'all' // all, fresh, stale, missing
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'priority',
        direction: 'desc'
    });
    const [autoRefresh, setAutoRefresh] = useState(true);

    const isFetchingRef = useRef(false);
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchDashboardData();

        if (autoRefresh) {
            intervalRef.current = setInterval(() => {
                fetchDashboardData(false);
            }, 30000); // Actualisation toutes les 30 secondes
        }

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [filters, sortConfig, autoRefresh]);

    const fetchDashboardData = async (withSpinner = true) => {
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

            // Utiliser l'API MTF states existante et enrichir avec les données de priorité
            const response = await api.getMtfStates(params);
            const enrichedData = enrichWithPriorityData(response.data || response);
            setDashboardData(enrichedData);
        } catch (err) {
            console.error('Erreur lors du chargement du dashboard MTF:', err);
            setError(`Erreur de chargement: ${err.message}`);
        } finally {
            if (withSpinner) {
                setLoading(false);
            }
            isFetchingRef.current = false;
        }
    };

    const enrichWithPriorityData = (mtfStates) => {
        return mtfStates.map(state => {
            const timeframes = ['4h', '1h', '15m', '5m', '1m'];
            const timeframeData = timeframes.map(tf => {
                const timeKey = `k${tf}Time`;
                const timeValue = state[timeKey];
                const status = getTimeframeStatus(tf, timeValue);
                return {
                    timeframe: tf,
                    timestamp: timeValue,
                    status: status.status,
                    freshness: status.freshness,
                    age: status.age
                };
            });

            // Calculer la priorité basée sur la fraîcheur et l'importance des timeframes
            const priority = calculatePriority(timeframeData, state.sides);

            return {
                ...state,
                timeframes: timeframeData,
                priority,
                currentSide: getCurrentSide(state.sides),
                needsReview: priority > 0
            };
        });
    };

    const getTimeframeStatus = (timeframe, timeValue) => {
        if (!timeValue) {
            return { 
                status: 'missing', 
                freshness: 'missing',
                age: null,
                text: 'Manquant' 
            };
        }
        
        const now = new Date();
        const time = new Date(timeValue);
        const diffMinutes = (now - time) / (1000 * 60);
        
        let status, freshness;
        if (diffMinutes < 5) {
            status = 'fresh';
            freshness = 'fresh';
        } else if (diffMinutes < 15) {
            status = 'warning';
            freshness = 'recent';
        } else {
            status = 'stale';
            freshness = 'stale';
        }

        return { 
            status, 
            freshness,
            age: diffMinutes,
            text: status === 'fresh' ? 'À jour' : status === 'warning' ? 'Récent' : 'Ancien'
        };
    };

    const calculatePriority = (timeframeData, sides) => {
        let priority = 0;
        
        // Priorité basée sur la fraîcheur des timeframes (plus récent = plus important)
        const timeframeWeights = { '4h': 5, '1h': 4, '15m': 3, '5m': 2, '1m': 1 };
        
        timeframeData.forEach(tf => {
            if (tf.status === 'missing') {
                priority += timeframeWeights[tf.timeframe] * 2; // Manquant = haute priorité
            } else if (tf.status === 'stale') {
                priority += timeframeWeights[tf.timeframe] * 1.5; // Ancien = priorité moyenne
            } else if (tf.status === 'warning') {
                priority += timeframeWeights[tf.timeframe] * 0.5; // Récent = faible priorité
            }
        });

        // Bonus si le symbole a des sides actifs
        if (sides && Object.keys(sides).length > 0) {
            priority += 10;
        }

        return Math.round(priority);
    };

    const getCurrentSide = (sides) => {
        if (!sides || Object.keys(sides).length === 0) return null;
        
        // Retourner le side le plus récent ou le plus important
        const sortedSides = Object.entries(sides).sort((a, b) => {
            const tfOrder = ['1m', '5m', '15m', '1h', '4h'];
            return tfOrder.indexOf(a[0]) - tfOrder.indexOf(b[0]);
        });
        
        return sortedSides[0][1];
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
            freshness: 'all'
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
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

    const getPriorityBadgeClass = (priority) => {
        if (priority >= 20) return 'badge-danger';
        if (priority >= 10) return 'badge-warning';
        if (priority >= 5) return 'badge-info';
        return 'badge-success';
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

    // Filtrer les données selon les critères
    const filteredData = dashboardData.filter(item => {
        if (filters.symbol && !item.symbol.toLowerCase().includes(filters.symbol.toLowerCase())) {
            return false;
        }
        
        if (filters.freshness !== 'all') {
            const hasMatchingFreshness = item.timeframes.some(tf => tf.freshness === filters.freshness);
            if (!hasMatchingFreshness) return false;
        }
        
        return true;
    });

    return (
        <div className="mtf-dashboard-page">
            <div className="page-header">
                <h1>Tableau de Bord MTF</h1>
                <p className="page-subtitle">Priorisation des revues par état MTF (4h/1h/15m/5m/1m)</p>
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
                        onClick={() => fetchDashboardData(true)}
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
                        <label>Fraîcheur</label>
                        <select
                            value={filters.freshness}
                            onChange={(e) => handleFilterChange('freshness', e.target.value)}
                            className="form-control"
                        >
                            <option value="all">Tous</option>
                            <option value="fresh">À jour</option>
                            <option value="recent">Récent</option>
                            <option value="stale">Ancien</option>
                            <option value="missing">Manquant</option>
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

            {/* Statistiques rapides */}
            {dashboardData.length > 0 && (
                <div className="stats-section">
                    <div className="stats-grid">
                        <div className="stat-card">
                            <div className="stat-value">{dashboardData.length}</div>
                            <div className="stat-label">Symboles</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">{dashboardData.filter(s => s.needsReview).length}</div>
                            <div className="stat-label">Nécessitent une revue</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {dashboardData.filter(s => s.timeframes.some(tf => tf.status === 'missing')).length}
                            </div>
                            <div className="stat-label">Avec données manquantes</div>
                        </div>
                        <div className="stat-card">
                            <div className="stat-value">
                                {dashboardData.filter(s => s.currentSide).length}
                            </div>
                            <div className="stat-label">Avec sides actifs</div>
                        </div>
                    </div>
                </div>
            )}

            {/* Tableau principal */}
            <div className="table-container">
                <table className="table">
                    <thead>
                        <tr>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('priority')}
                            >
                                Priorité {sortConfig.key === 'priority' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('symbol')}
                            >
                                Symbole {sortConfig.key === 'symbol' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>État MTF</th>
                            <th>Side Actuel</th>
                            <th>Fraîcheur</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('updatedAt')}
                            >
                                Dernière MAJ {sortConfig.key === 'updatedAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="loading">Chargement du dashboard MTF...</div>
                                </td>
                            </tr>
                        ) : filteredData.length === 0 ? (
                            <tr>
                                <td colSpan="7" className="text-center">
                                    <div className="no-data">Aucun symbole trouvé</div>
                                </td>
                            </tr>
                        ) : (
                            filteredData.map((item) => (
                                <tr key={item.id} className={item.needsReview ? 'needs-review' : ''}>
                                    <td>
                                        <span className={`badge ${getPriorityBadgeClass(item.priority)}`}>
                                            {item.priority}
                                        </span>
                                    </td>
                                    <td>
                                        <span className="symbol-badge">{item.symbol}</span>
                                    </td>
                                    <td>
                                        <div className="timeframes-grid">
                                            {item.timeframes.map(tf => (
                                                <div key={tf.timeframe} className="timeframe-item">
                                                    <span className={`badge ${getTimeframeBadgeClass(tf.timeframe)}`}>
                                                        {tf.timeframe}
                                                    </span>
                                                    <span className={`badge ${getStatusBadgeClass(tf.status)}`}>
                                                        {tf.status === 'fresh' ? '✓' : tf.status === 'warning' ? '⚠' : tf.status === 'stale' ? '⚠' : '✗'}
                                                    </span>
                                                    {tf.age && (
                                                        <span className="age-info">
                                                            {Math.round(tf.age)}min
                                                        </span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </td>
                                    <td>
                                        {item.currentSide ? (
                                            <span className={`badge ${getSideBadgeClass(item.currentSide)}`}>
                                                {item.currentSide}
                                            </span>
                                        ) : (
                                            <span className="text-muted">-</span>
                                        )}
                                    </td>
                                    <td>
                                        <div className="freshness-summary">
                                            {item.timeframes.filter(tf => tf.status === 'fresh').length}/
                                            {item.timeframes.length} à jour
                                        </div>
                                    </td>
                                    <td>{formatDate(item.updatedAt)}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button 
                                                className="btn btn-sm btn-outline-primary"
                                                onClick={() => window.open(`/signals?symbol=${item.symbol}`, '_blank')}
                                            >
                                                Signaux
                                            </button>
                                            <button 
                                                className="btn btn-sm btn-outline-secondary"
                                                onClick={() => window.open(`/mtf-state?symbol=${item.symbol}`, '_blank')}
                                            >
                                                Détail
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Légende */}
            <div className="legend-section">
                <h4>Légende</h4>
                <div className="legend-grid">
                    <div className="legend-item">
                        <span className="badge badge-success">✓</span>
                        <span>À jour (&lt; 5min)</span>
                    </div>
                    <div className="legend-item">
                        <span className="badge badge-warning">⚠</span>
                        <span>Récent (5-15min)</span>
                    </div>
                    <div className="legend-item">
                        <span className="badge badge-danger">⚠</span>
                        <span>Ancien (&gt; 15min)</span>
                    </div>
                    <div className="legend-item">
                        <span className="badge badge-secondary">✗</span>
                        <span>Manquant</span>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default MtfDashboardPage;
