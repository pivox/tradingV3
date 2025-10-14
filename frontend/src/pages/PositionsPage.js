// src/pages/PositionsPage.js
import React, { useEffect, useMemo, useRef, useState } from 'react';
import api from '../services/api';

const POLL_INTERVAL_MS = 5000;

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
        maximumFractionDigits,
    }).format(Number(value));
};

const normalizePosition = (raw) => {
    if (!raw) return null;

    const contractSymbol = raw.contractSymbol || raw.contract_symbol || raw.symbol || raw.contract?.symbol || null;
    const qtyContract = raw.qtyContract ?? raw.qty_contract ?? raw.size ?? raw.position_volume ?? raw.position_amount ?? null;
    const entryPrice = raw.entryPrice ?? raw.entry_price ?? raw.avg_entry_price ?? raw.average_price ?? null;
    const leverage = raw.leverage ?? raw.position_leverage ?? null;
    const statusRaw = raw.status ?? raw.position_status ?? null;
    const status = typeof statusRaw === 'string' ? statusRaw.toUpperCase() : statusRaw;
    const pnlUsdt = raw.pnlUsdt ?? raw.pnl_usdt ?? raw.realized_pnl ?? raw.realised_pnl ?? null;
    const amountUsdt = raw.amountUsdt ?? raw.amount_usdt ?? raw.position_value ?? raw.mark_value ?? null;
    const lastSyncAt = raw.lastSyncAt ?? raw.last_sync_at ?? raw.updatedAt ?? raw.updated_at ?? raw.closedAt ?? raw.closed_at ?? null;
    const sideRaw = raw.side ?? raw.position_side ?? raw.hold_side ?? raw.holdSide ?? (raw.type ? String(raw.type).toUpperCase() : null);
    const side = typeof sideRaw === 'string' ? sideRaw.toUpperCase() : sideRaw;
    const openedAt = raw.openedAt ?? raw.open_date ?? raw.createdAt ?? raw.created_at ?? null;
    const createdAt = raw.createdAt ?? raw.created_at ?? raw.open_date ?? null;

    let isClosed = raw.isClosed;
    if (typeof isClosed !== 'boolean') {
        if (status === 'CLOSED') {
            isClosed = true;
        } else if (qtyContract !== null && qtyContract !== undefined) {
            const n = Number(qtyContract);
            isClosed = Number.isFinite(n) ? Math.abs(n) < 1e-12 : false;
        } else {
            isClosed = false;
        }
    }

    return {
        ...raw,
        contractSymbol,
        qtyContract,
        entryPrice,
        leverage,
        status,
        pnlUsdt,
        amountUsdt,
        lastSyncAt,
        side,
        isClosed,
        openedAt,
        createdAt,
    };
};

const enrichPosition = (position) => {
    if (!position) {
        return null;
    }
    const norm = normalizePosition(position);
    if (!norm) return null;
    const amount = norm.amountUsdt ?? 0;
    const pnl = norm.pnlUsdt ?? null;
    const roiPct = amount && pnl !== null ? (pnl / amount) * 100 : null;
    return {
        ...norm,
        roiPct,
    };
};

const getPositionKey = (p) => {
    return (
        p.key ||
        p.id ||
        `${p.contractSymbol || p.symbol || 'UNKNOWN'}-${p.side || 'NA'}-${p.openedAt || p.createdAt || ''}`
    );
};

const PositionsPage = () => {
    const [contracts, setContracts] = useState([]);
    const [filters, setFilters] = useState({
        contract: '',
        type: 'all',
        status: 'all',
    });
    const [positions, setPositions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [autoRefresh, setAutoRefresh] = useState(true);

    const pollTimerRef = useRef(null);

    useEffect(() => {
        api.getContracts()
            .then((data) => setContracts(data))
            .catch((err) => console.error('Erreur lors du chargement des contrats:', err));
    }, []);

    const fetchPositions = async () => {
        setError(null);
        try {
            const params = {};
            if (filters.contract) {
                params.contract = filters.contract.trim().toUpperCase();
            }
            if (filters.type && filters.type !== 'all') {
                params.type = filters.type; // 'long' | 'short'
            }
            if (filters.status && filters.status !== 'all') {
                params.status = filters.status; // 'open' | 'closed'
            }
            const res = await api.getPositions(params);
            const list = Array.isArray(res)
                ? res
                : (res?.positions || res?.items || res?.data || []);

            const mapped = list
                .map((item) => {
                    const enriched = enrichPosition(item);
                    if (!enriched) return null;
                    return { key: getPositionKey(enriched), ...enriched };
                })
                .filter(Boolean);

            setPositions(mapped);
        } catch (e) {
            console.error('Erreur de récupération des positions', e);
            setError("Impossible de récupérer les positions");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        setLoading(true);
        fetchPositions();
        if (pollTimerRef.current) {
            clearInterval(pollTimerRef.current);
            pollTimerRef.current = null;
        }
        if (autoRefresh) {
            pollTimerRef.current = setInterval(fetchPositions, POLL_INTERVAL_MS);
        }
        return () => {
            if (pollTimerRef.current) {
                clearInterval(pollTimerRef.current);
                pollTimerRef.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters.contract, filters.type, filters.status, autoRefresh]);

    const handleFilterChange = (name, value) => {
        setFilters((prev) => ({ ...prev, [name]: value }));
    };

    const filteredPositions = useMemo(() => {
        return positions
            .filter((position) => {
                if (filters.contract && position.contractSymbol !== filters.contract.trim().toUpperCase()) {
                    return false;
                }
                if (filters.type !== 'all') {
                    const targetSide = filters.type === 'long' ? 'LONG' : 'SHORT';
                    if (position.side !== targetSide) {
                        return false;
                    }
                }
                if (filters.status === 'open') {
                    const isClosed = position.isClosed !== undefined ? position.isClosed : (position.status === 'CLOSED');
                    if (isClosed) return false;
                }
                if (filters.status === 'closed') {
                    const isClosed = position.isClosed !== undefined ? position.isClosed : (position.status === 'CLOSED');
                    if (!isClosed) return false;
                }
                return true;
            })
            .sort((a, b) => (a.contractSymbol || '').localeCompare(b.contractSymbol || ''));
    }, [positions, filters]);

    const availableContracts = useMemo(() => {
        const fromPositions = Array.from(
            new Set(positions.map((position) => position.contractSymbol))
        ).map((symbol) => ({ symbol, id: symbol }));

        const combined = [...contracts];
        fromPositions.forEach((item) => {
            if (!combined.some((contract) => (contract.symbol || contract.id) === item.symbol)) {
                combined.push(item);
            }
        });
        return combined;
    }, [contracts, positions]);

    return (
        <div className="positions-page">
            <h1>Positions</h1>

            <div className="refresh-controls">
                <button type="button" onClick={() => { setLoading(true); fetchPositions(); }}>
                    Rafraîchir
                </button>
                <label style={{ marginLeft: 12 }}>
                    <input
                        type="checkbox"
                        checked={autoRefresh}
                        onChange={(e) => setAutoRefresh(e.target.checked)}
                    />
                    Actualisation automatique (toutes les {Math.round(POLL_INTERVAL_MS / 1000)}s)
                </label>
            </div>

            <div className="filters">
                <div className="filter-group">
                    <label htmlFor="contract-filter">Contrat:</label>
                    <select
                        id="contract-filter"
                        value={filters.contract}
                        onChange={(event) => handleFilterChange('contract', event.target.value)}
                    >
                        <option value="">Tous</option>
                        {availableContracts.map((contract) => {
                            const value = contract.symbol ?? contract.id;
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
                        onChange={(event) => handleFilterChange('type', event.target.value)}
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
                        onChange={(event) => handleFilterChange('status', event.target.value)}
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
            ) : filteredPositions.length > 0 ? (
                <table className="positions-table">
                    <thead>
                    <tr>
                        <th>Contrat</th>
                        <th>Type</th>
                        <th>Quantité</th>
                        <th>Prix d'entrée</th>
                        <th>Levier</th>
                        <th>Statut</th>
                        <th>PnL (USDT)</th>
                        <th>ROI %</th>
                        <th>Dernière MAJ</th>
                    </tr>
                    </thead>
                    <tbody>
                    {filteredPositions.map((position) => (
                        <tr
                            key={position.key}
                            className={position.side === 'LONG' ? 'long' : 'short'}
                        >
                            <td>{position.contractSymbol}</td>
                            <td>{position.side}</td>
                            <td>{formatNumber(position.qtyContract, { maximumFractionDigits: 4 })}</td>
                            <td>{formatNumber(position.entryPrice, { maximumFractionDigits: 4 })}</td>
                            <td>{formatNumber(position.leverage, { maximumFractionDigits: 2 })}</td>
                            <td className={`status-${position.status?.toLowerCase()}`}>
                                {position.status ?? '-'}
                            </td>
                            <td className={position.pnlUsdt > 0 ? 'profit' : position.pnlUsdt < 0 ? 'loss' : ''}>
                                {position.pnlUsdt !== null && position.pnlUsdt !== undefined
                                    ? `${formatNumber(position.pnlUsdt, { maximumFractionDigits: 2, minimumFractionDigits: 2 })} USDT`
                                    : '-'}
                            </td>
                            <td>
                                {position.roiPct !== null && position.roiPct !== undefined
                                    ? `${formatNumber(position.roiPct, { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`
                                    : '-'}
                            </td>
                            <td>
                                {position.lastSyncAt ? new Date(position.lastSyncAt).toLocaleString() : '-'}
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
}

export default PositionsPage;
