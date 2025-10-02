// src/pages/PositionsPage.js
import React, { useState, useEffect, useRef } from 'react';
import api from '../services/api';

const PositionsPage = () => {
    const [positions, setPositions] = useState([]);
    const [contracts, setContracts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        contract: '',
        type: 'all',
        status: 'all'
    });

    // Charger tous les contrats pour le filtre
    useEffect(() => {
        api.getContracts()
            .then(data => setContracts(data))
            .catch(err => console.error('Erreur lors du chargement des contrats:', err));
    }, []);

    const isFetchingRef = useRef(false);

    // Charger les positions avec rafraîchissement périodique
    useEffect(() => {
        let isCancelled = false;

        const fetchPositions = async (withSpinner = false) => {
            if (isFetchingRef.current) {
                return;
            }

            isFetchingRef.current = true;
            if (withSpinner) {
                setLoading(true);
            }

            try {
                const data = await api.getPositions(filters);
                if (!isCancelled) {
                    setPositions(data);
                    setError(null);
                }
            } catch (err) {
                console.error('Erreur de chargement des positions:', err);
                if (!isCancelled) {
                    setError(`Erreur de chargement des positions: ${err.message}`);
                }
            } finally {
                if (!isCancelled && withSpinner) {
                    setLoading(false);
                }
                isFetchingRef.current = false;
            }
        };

        fetchPositions(true);
        const intervalId = setInterval(() => fetchPositions(false), 4000);

        return () => {
            isCancelled = true;
            clearInterval(intervalId);
        };
    }, [filters]);

    const formatNumber = (value, options = {}) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '-';
        }
        const {
            maximumFractionDigits = 4,
            minimumFractionDigits = 0,
        } = options;
        return new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits,
            maximumFractionDigits
        }).format(Number(value));
    };

    const handleFilterChange = (name, value) => {
        setFilters(prev => ({ ...prev, [name]: value }));
    };

    return (
        <div className="positions-page">
            <h1>Positions</h1>

            <div className="filters">
                <div className="filter-group">
                    <label htmlFor="contract-filter">Contrat:</label>
                    <select
                        id="contract-filter"
                        value={filters.contract}
                        onChange={e => handleFilterChange('contract', e.target.value)}
                    >
                        <option value="">Tous</option>
                        {contracts.map(contract => {
                            const value = contract.id ?? contract.symbol;
                            return (
                                <option key={value} value={value}>
                                    {contract.symbol ?? value}
                                </option>
                            );
                        })}
                    </select>
                </div>

                <div className="filter-group">
                    <label htmlFor="type-filter">Type:</label>
                    <select
                        id="type-filter"
                        value={filters.type}
                        onChange={e => handleFilterChange('type', e.target.value)}
                    >
                        <option value="all">Tous</option>
                        <option value="long">Long</option>
                        <option value="short">Short</option>
                    </select>
                </div>

                <div className="filter-group">
                    <label htmlFor="status-filter">Statut:</label>
                    <select
                        id="status-filter"
                        value={filters.status}
                        onChange={e => handleFilterChange('status', e.target.value)}
                    >
                        <option value="all">Tous</option>
                        <option value="open">Ouverte</option>
                        <option value="closed">Fermée</option>
                    </select>
                </div>
            </div>

            {error && <div className="error">{error}</div>}

            {loading ? (
                <div className="loading">Chargement des positions...</div>
            ) : positions.length > 0 ? (
                <table className="positions-table">
                    <thead>
                    <tr>
                        <th>Contrat</th>
                        <th>Source</th>
                        <th>Type</th>
                        <th>Quantité</th>
                        <th>Prix d'entrée</th>
                        <th>Mark</th>
                        <th>Date d'ouverture</th>
                        <th>Statut</th>
                        <th>ROI</th>
                        <th>P&L</th>
                    </tr>
                    </thead>
                    <tbody>
                    {positions.map(position => (
                        <tr
                            key={position.id}
                            className={position.type === 'long' ? 'long' : 'short'}
                        >
                            <td>
                                <div className="contract-symbol">{position.contract.symbol}</div>
                                {position.pipeline && (
                                    <div className="contract-sub-info">
                                        TF: {position.pipeline.current_timeframe}
                                        {position.pipeline.order_id ? ` · Order ${position.pipeline.order_id}` : ''}
                                    </div>
                                )}
                            </td>
                            <td className={`badge badge-${position.source || 'database'}`}>
                                {position.source === 'exchange' ? 'Exchange' : 'Historique'}
                            </td>
                            <td>{position.type === 'long' ? 'LONG' : position.type === 'short' ? 'SHORT' : position.type}</td>
                            <td>
                                {formatNumber(position.qty_base ?? position.qty_contract, { maximumFractionDigits: 3 })}
                                {position.qty_contract && position.qty_base && position.qty_base !== position.qty_contract && (
                                    <span className="contract-sub-info"> ({formatNumber(position.qty_contract, { maximumFractionDigits: 3 })} cts)</span>
                                )}
                            </td>
                            <td>{formatNumber(position.entry_price)}</td>
                            <td>{formatNumber(position.mark_price)}</td>
                            <td>{position.open_date ? new Date(position.open_date).toLocaleString() : '-'}</td>
                            <td className={`status-${position.status}`}>
                                {position.status ? position.status.toUpperCase() : '-'}
                            </td>
                            <td title={position.price_change_pct !== undefined ? `Δ prix: ${formatNumber(position.price_change_pct, { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%` : undefined}>
                                {position.roi_pct !== null && position.roi_pct !== undefined
                                    ? `${formatNumber(position.roi_pct, { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`
                                    : '-'}
                            </td>
                            <td
                                className={position.pnl > 0 ? 'profit' : position.pnl < 0 ? 'loss' : ''}
                                title={position.pnl_usdt !== null && position.pnl_usdt !== undefined
                                    ? `${formatNumber(position.pnl_usdt, { maximumFractionDigits: 2, minimumFractionDigits: 2 })} USDT`
                                    : undefined}
                            >
                                {position.pnl !== null && position.pnl !== undefined
                                    ? `${formatNumber(position.pnl, { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`
                                    : '-'}
                            </td>
                        </tr>
                    ))}
                    </tbody>
                </table>
            ) : (
                <div className="no-data">Aucune position trouvée</div>
            )}
        </div>
    );
};

export default PositionsPage;
