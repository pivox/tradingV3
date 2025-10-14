// src/pages/KlinesPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const KlinesPage = () => {
    const [klines, setKlines] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        timeframe: '',
        dateFrom: '',
        dateTo: '',
        source: ''
    });
    const [pagination, setPagination] = useState({
        page: 1,
        limit: 100,
        total: 0
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'openTime',
        direction: 'desc'
    });

    const isFetchingRef = useRef(false);

    useEffect(() => {
        fetchKlines();
    }, [filters, pagination.page, pagination.limit, sortConfig]);

    const fetchKlines = async () => {
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

            const response = await api.getKlines(params);
            setKlines(response.data || response);
            
            if (response.total !== undefined) {
                setPagination(prev => ({ ...prev, total: response.total }));
            }
        } catch (err) {
            console.error('Erreur lors du chargement des klines:', err);
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
            dateFrom: '',
            dateTo: '',
            source: ''
        });
        setPagination(prev => ({ ...prev, page: 1 }));
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const formatPrice = (price) => {
        if (!price) return '-';
        return parseFloat(price).toFixed(8);
    };

    const formatVolume = (volume) => {
        if (!volume) return '-';
        return parseFloat(volume).toFixed(2);
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

    const getSourceBadgeClass = (source) => {
        switch (source?.toLowerCase()) {
            case 'rest': return 'badge-info';
            case 'ws': return 'badge-success';
            case 'websocket': return 'badge-success';
            default: return 'badge-secondary';
        }
    };

    const calculatePriceChange = (open, close) => {
        if (!open || !close) return null;
        const openPrice = parseFloat(open);
        const closePrice = parseFloat(close);
        const change = ((closePrice - openPrice) / openPrice) * 100;
        return change;
    };

    const getPriceChangeClass = (change) => {
        if (change === null) return '';
        return change >= 0 ? 'price-up' : 'price-down';
    };

    return (
        <div className="klines-page">
            <div className="page-header">
                <h1>Données de Chandeliers (Klines)</h1>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchKlines}
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
                        <label>Source</label>
                        <select
                            value={filters.source}
                            onChange={(e) => handleFilterChange('source', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Toutes</option>
                            <option value="REST">REST</option>
                            <option value="WS">WebSocket</option>
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

            {/* Tableau des klines */}
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
                                TF {sortConfig.key === 'timeframe' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('openTime')}
                            >
                                Date Ouverture {sortConfig.key === 'openTime' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('openPrice')}
                            >
                                Ouverture {sortConfig.key === 'openPrice' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('highPrice')}
                            >
                                Plus Haut {sortConfig.key === 'highPrice' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('lowPrice')}
                            >
                                Plus Bas {sortConfig.key === 'lowPrice' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('closePrice')}
                            >
                                Fermeture {sortConfig.key === 'closePrice' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th>Variation</th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('volume')}
                            >
                                Volume {sortConfig.key === 'volume' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('source')}
                            >
                                Source {sortConfig.key === 'source' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                            <th 
                                className="sortable"
                                onClick={() => handleSort('insertedAt')}
                            >
                                Créé le {sortConfig.key === 'insertedAt' && (sortConfig.direction === 'asc' ? '↑' : '↓')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="12" className="text-center">
                                    <div className="loading">Chargement des klines...</div>
                                </td>
                            </tr>
                        ) : klines.length === 0 ? (
                            <tr>
                                <td colSpan="12" className="text-center">
                                    <div className="no-data">Aucune kline trouvée</div>
                                </td>
                            </tr>
                        ) : (
                            klines.map((kline) => {
                                const priceChange = calculatePriceChange(kline.openPrice, kline.closePrice);
                                return (
                                    <tr key={kline.id}>
                                        <td>{kline.id}</td>
                                        <td>
                                            <span className="symbol-badge">{kline.symbol}</span>
                                        </td>
                                        <td>
                                            <span className={`badge ${getTimeframeBadgeClass(kline.timeframe)}`}>
                                                {kline.timeframe}
                                            </span>
                                        </td>
                                        <td>{formatDate(kline.openTime)}</td>
                                        <td className="price-cell">{formatPrice(kline.openPrice)}</td>
                                        <td className="price-cell">{formatPrice(kline.highPrice)}</td>
                                        <td className="price-cell">{formatPrice(kline.lowPrice)}</td>
                                        <td className="price-cell">{formatPrice(kline.closePrice)}</td>
                                        <td className={`price-change ${getPriceChangeClass(priceChange)}`}>
                                            {priceChange !== null ? `${priceChange >= 0 ? '+' : ''}${priceChange.toFixed(2)}%` : '-'}
                                        </td>
                                        <td className="volume-cell">{formatVolume(kline.volume)}</td>
                                        <td>
                                            <span className={`badge ${getSourceBadgeClass(kline.source)}`}>
                                                {kline.source}
                                            </span>
                                        </td>
                                        <td>{formatDate(kline.insertedAt)}</td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {pagination.total > pagination.limit && (
                <div className="pagination-container">
                    <div className="pagination-info">
                        Affichage de {((pagination.page - 1) * pagination.limit) + 1} à {Math.min(pagination.page * pagination.limit, pagination.total)} sur {pagination.total} klines
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

export default KlinesPage;
