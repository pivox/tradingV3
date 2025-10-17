// src/pages/DashboardPage.js
import React, { useState, useEffect, useRef } from 'react';
import ContractList from '../components/ContractList';
import CandleChart from '../components/CandleChart';
import api from '../services/api';

const SEARCH_DEBOUNCE_MS = 300;

const DashboardPage = () => {
    const [contracts, setContracts] = useState([]);
    const [selectedContract, setSelectedContract] = useState(null);
    const [chartData, setChartData] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');
    const [contractsLoading, setContractsLoading] = useState(true);
    const [chartLoading, setChartLoading] = useState(false);
    const [contractsError, setContractsError] = useState(null);
    const [chartError, setChartError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');

    const searchTimerRef = useRef(null);
    const abortRef = useRef(null);

    // Charger les contrats (avec debounce + annulation)
    useEffect(() => {
        if (searchTimerRef.current) {
            clearTimeout(searchTimerRef.current);
            searchTimerRef.current = null;
        }

        searchTimerRef.current = setTimeout(async () => {
            if (abortRef.current) {
                abortRef.current.abort();
            }
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                setContractsLoading(true);
                const data = await api.getContracts(searchTerm.trim(), { signal: controller.signal });
                setContracts(data);
                setContractsError(null);

                // Ajuster la sélection si nécessaire
                if (!selectedContract) {
                    if (data.length > 0) {
                        setSelectedContract(data[0]);
                    }
                } else {
                    const key = selectedContract.id ?? selectedContract.symbol;
                    const stillThere = data.some((c) => (c.id ?? c.symbol) === key);
                    if (!stillThere && data.length > 0) {
                        setSelectedContract(data[0]);
                    }
                }
            } catch (err) {
                if (err?.name === 'AbortError') {
                    return; // requête annulée, ne rien faire
                }
                setContractsError(`Erreur de chargement des contrats: ${err.message}`);
                console.error(err);
            } finally {
                setContractsLoading(false);
            }
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
                searchTimerRef.current = null;
            }
            // on n'abort pas ici pour éviter d'annuler une req qui se termine juste; la prochaine itération gère l'annulation
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchTerm]);

    // Charger les données du graphique quand un contrat est sélectionné
    useEffect(() => {
        const symbol = selectedContract ? (selectedContract.symbol ?? selectedContract.id) : null;

        if (symbol) {
            setChartLoading(true);
            setChartError(null);
            setChartData([]);

            api.getKlines(symbol, timeframe, 200)
                .then(async data => {
                    let normalized = Array.isArray(data) ? data.map(d => {
                        let ts = d.timestamp;
                        if (typeof ts === 'string') {
                            // Convertit 'YYYY-MM-DD HH:mm:ss' en epoch ms (UTC) pour compatibilité Safari
                            const iso = ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z';
                            const ms = Date.parse(iso);
                            ts = Number.isFinite(ms) ? ms : new Date(ts).getTime();
                        }
                        return {
                            ...d,
                            timestamp: ts,
                        };
                    }) : [];

                    // Si aucune donnée, tenter de télécharger depuis Bitmart
                    if (normalized.length === 0) {
                        try {
                            await api.fetchKlinesFromBitmart(symbol, timeframe, 200);
                            // Recharger les klines après téléchargement
                            const retry = await api.getKlines(symbol, timeframe, 200);
                            normalized = Array.isArray(retry) ? retry.map(d => {
                                let ts = d.timestamp;
                                if (typeof ts === 'string') {
                                    const iso = ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z';
                                    const ms = Date.parse(iso);
                                    ts = Number.isFinite(ms) ? ms : new Date(ts).getTime();
                                }
                                return {
                                    ...d,
                                    timestamp: ts,
                                };
                            }) : [];
                        } catch (err) {
                            setChartError('Impossible de télécharger les klines depuis Bitmart.');
                        }
                    }
                    setChartData(normalized);
                    setChartError(null);
                })
                .catch(err => {
                    setChartError('Erreur de chargement des données du graphique.');
                    setChartData([]);
                })
                .finally(() => {
                    setChartLoading(false);
                });
        } else {
            setChartData([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedContract, timeframe]);

    return (
        <div className="dashboard-container">
            <h1>Dashboard</h1>

            <div className="search-container">
                <input
                    type="text"
                    placeholder="Rechercher un contrat (ex: BTCUSDT)"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="search-input"
                />
            </div>

            {contractsError && <div className="error">{contractsError}</div>}

            <div className="contract-grid">
                <div className="contracts-panel">
                    <h2>Contrats</h2>
                    {contractsLoading && !selectedContract ? (
                        <div className="loading">Chargement des contrats...</div>
                    ) : contracts.length === 0 ? (
                        <div className="no-data">Aucun contrat</div>
                    ) : (
                        <ContractList
                            contracts={contracts}
                            selectedContract={selectedContract}
                            onSelect={setSelectedContract}
                        />
                    )}
                </div>

                <div className="chart-detail-panel">
                    <h2>Graphique</h2>

                    {selectedContract ? (
                        <>
                            {chartError && <div className="error">{chartError}</div>}
                            <CandleChart
                                contractId={selectedContract.symbol ?? selectedContract.id}
                                data={chartData}
                                timeframe={timeframe}
                                onTimeframeChange={setTimeframe}
                                loading={chartLoading}
                                emptyMessage="Aucune donnée disponible"
                                utcOffsetMinutes={120}
                            />
                        </>
                    ) : (
                        <div className="select-prompt">Sélectionnez un contrat pour afficher le graphique</div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default DashboardPage;
