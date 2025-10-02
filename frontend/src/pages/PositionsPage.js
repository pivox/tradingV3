// src/pages/PositionsPage.js
import React, { useEffect, useMemo, useRef, useState } from 'react';
import api from '../services/api';
import config from '../config';

const INITIAL_DELAY = 2000;
const MAX_DELAY = 30000;
const CONNECTION_LABELS = {
    inactive: 'inactif',
    connecting: 'connexion en cours',
    connected: 'connecté',
    disconnected: 'déconnecté',
};

const buildWsUrl = (filters) => {
    const base = config.positionsRealtimeBaseUrl || config.pythonApiUrl || window.location.origin;
    const url = new URL('/ws/positions', base);
    url.protocol = url.protocol.replace('http', 'ws');

    if (filters.contract) {
        url.searchParams.set('symbol', filters.contract.trim().toUpperCase());
    }
    if (filters.type && filters.type !== 'all') {
        const side = filters.type === 'long' ? 'LONG' : 'SHORT';
        url.searchParams.set('side', side);
    }
    if (filters.status && filters.status !== 'all') {
        const status = filters.status === 'open' ? 'OPEN' : 'CLOSED';
        url.searchParams.set('status', status);
    }

    return url.toString();
};

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

const enrichPosition = (position) => {
    if (!position) {
        return null;
    }
    const amount = position.amountUsdt ?? 0;
    const pnl = position.pnlUsdt ?? null;
    const roiPct = amount && pnl !== null ? (pnl / amount) * 100 : null;
    return {
        ...position,
        roiPct,
    };
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
    const [connectionState, setConnectionState] = useState('inactive');
    const [isRealtimeEnabled, setRealtimeEnabled] = useState(false);

    const websocketRef = useRef(null);
    const reconnectTimeoutRef = useRef(null);
    const backoffRef = useRef(INITIAL_DELAY);
    const shouldReconnectRef = useRef(false);
    const positionsRef = useRef(new Map());
    const sequenceRef = useRef(0);

    useEffect(() => {
        api.getContracts()
            .then((data) => setContracts(data))
            .catch((err) => console.error('Erreur lors du chargement des contrats:', err));
    }, []);

    const stopRealtime = (resetStatus = false) => {
        shouldReconnectRef.current = false;
        if (reconnectTimeoutRef.current) {
            clearTimeout(reconnectTimeoutRef.current);
            reconnectTimeoutRef.current = null;
        }
        if (websocketRef.current && websocketRef.current.readyState <= 1) {
            websocketRef.current.close();
        }
        if (resetStatus) {
            setConnectionState('inactive');
            setLoading(false);
        }
    };

    useEffect(() => {
        if (!isRealtimeEnabled) {
            stopRealtime(true);
            return undefined;
        }

        shouldReconnectRef.current = true;
        connectWebsocket();

        return () => {
            stopRealtime(false);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isRealtimeEnabled, filters.contract, filters.type, filters.status]);

    const connectWebsocket = () => {
        const wsUrl = buildWsUrl(filters);
        if (positionsRef.current.size === 0) {
            setLoading(true);
        }
        setConnectionState('connecting');
        setError(null);
        sequenceRef.current = 0;

        try {
            if (websocketRef.current && websocketRef.current.readyState <= 1) {
                websocketRef.current.close();
            }
            const socket = new WebSocket(wsUrl);
            websocketRef.current = socket;

            socket.onopen = () => {
                setConnectionState('connected');
                backoffRef.current = INITIAL_DELAY;
            };

            socket.onmessage = (event) => {
            console.log('[WS] Message brut reçu:', event.data);
                try {
                    const payload = JSON.parse(event.data);
                    handleRealtimePayload(payload);
                } catch (err) {
                    console.error('Message temps réel invalide', err);
                }
            };

            socket.onerror = (event) => {
            console.error('[WS] Erreur WebSocket', event);
                setError('Erreur de communication temps réel');
            };

            socket.onclose = () => {
            console.log('[WS] Connexion fermée');
                const nextState = shouldReconnectRef.current ? 'disconnected' : 'inactive';
                setConnectionState(nextState);
                if (shouldReconnectRef.current) {
                    scheduleReconnect();
                }
            };
        } catch (err) {
        console.error('[WS] Impossible de créer la connexion WebSocket', err);
            setError("Impossible d'ouvrir la connexion temps réel");
            scheduleReconnect();
        }
    };

    const scheduleReconnect = () => {
        if (!shouldReconnectRef.current) {
            return;
        }
        const delay = backoffRef.current;
        if (reconnectTimeoutRef.current) {
            clearTimeout(reconnectTimeoutRef.current);
        }
        reconnectTimeoutRef.current = setTimeout(() => {
            connectWebsocket();
        }, delay);
        backoffRef.current = Math.min(backoffRef.current * 2, MAX_DELAY);
    };

    const handleRealtimePayload = (payload) => {
        if (!payload) {
                    console.log('[WS] Payload vide ignoré');

            return;
        }
        if (payload.type === 'snapshot') {
        console.log('[WS] Snapshot reçu avec', (payload.positions || []).length, 'positions');
            const map = new Map();
            (payload.positions || []).forEach((item) => {
                const enriched = enrichPosition(item);
                if (enriched) {
                    map.set(enriched.key, enriched);
                }
            });
            positionsRef.current = map;
            sequenceRef.current = payload.seq || 0;
            setPositions(Array.from(map.values()));
            setLoading(false);
            setError(null);
            return;
        }

        if (!payload.position) {
            return;
        }
        if (payload.seq && payload.seq <= sequenceRef.current) {
            return;
        }
        if (payload.seq) {
            sequenceRef.current = payload.seq;
        }

        const enriched = enrichPosition(payload.position);
        if (!enriched) {
            return;
        }

        const map = new Map(positionsRef.current);
        map.set(enriched.key, enriched);
        positionsRef.current = map;
        setPositions(Array.from(map.values()));
        setLoading(false);
    };

    const handleFilterChange = (name, value) => {
        setFilters((prev) => ({ ...prev, [name]: value }));
    };

    const handleToggleRealtime = () => {
        setRealtimeEnabled((prev) => {
            const next = !prev;
            if (next && positionsRef.current.size === 0) {
                setLoading(true);
            }
            if (!next) {
                stopRealtime(true);
                setError(null);
            }
            return next;
        });
    };

    const filteredPositions = useMemo(() => {
        return positions
            .filter((position) => {
                if (position.status == 'CLOSED') {
                    return false;
                }
                if (filters.contract && position.contractSymbol !== filters.contract.trim().toUpperCase()) {
                    return false;
                }
                if (filters.type !== 'all') {
                    const targetSide = filters.type === 'long' ? 'LONG' : 'SHORT';
                    if (position.side !== targetSide) {
                        return false;
                    }
                }
                if (filters.status === 'open' && position.isClosed) {
                    return false;
                }
                if (filters.status === 'closed' && !position.isClosed) {
                    return false;
                }
                return true;
            })
            .sort((a, b) => a.contractSymbol.localeCompare(b.contractSymbol));
    }, [positions, filters]);

    const availableContracts = useMemo(() => {
        const fromRealtime = Array.from(
            new Set(positions.map((position) => position.contractSymbol))
        ).map((symbol) => ({ symbol, id: symbol }));

        const combined = [...contracts];
        fromRealtime.forEach((item) => {
            if (!combined.some((contract) => (contract.symbol || contract.id) === item.symbol)) {
                combined.push(item);
            }
        });
        return combined;
    }, [contracts, positions]);

    return (
        <div className="positions-page">
            <h1>Positions</h1>
            <div className="realtime-controls">
                <button type="button" onClick={handleToggleRealtime}>
                    {isRealtimeEnabled ? 'Couper le flux temps réel' : 'Activer le flux temps réel'}
                </button>
                <div className={`connection-state state-${connectionState}`}>
                    Flux temps réel : {CONNECTION_LABELS[connectionState] ?? connectionState}
                </div>
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
                <div className="no-data">
                    {isRealtimeEnabled ? 'Aucune position trouvée' : 'Activez le flux temps réel pour afficher les positions'}
                </div>
            )}
        </div>
    );
};

export default PositionsPage;
