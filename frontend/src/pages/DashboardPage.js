// src/pages/DashboardPage.js
import React, { useState, useEffect } from 'react';
import ContractList from '../components/ContractList';
import CandleChart from '../components/CandleChart';
import api from '../services/api';

const DashboardPage = () => {
    const [contracts, setContracts] = useState([]);
    const [selectedContract, setSelectedContract] = useState(null);
    const [chartData, setChartData] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');

    // Charger les contrats
    useEffect(() => {
        const fetchContracts = async () => {
            try {
                setLoading(true);
                const data = await api.getContracts(searchTerm);
                setContracts(data);
                setError(null);
            } catch (err) {
                setError(`Erreur de chargement des contrats: ${err.message}`);
                console.error(err);
            } finally {
                setLoading(false);
            }
        };

        fetchContracts();
    }, [searchTerm]);

    // Charger les données du graphique quand un contrat est sélectionné
    useEffect(() => {
        if (selectedContract) {
            setLoading(true);
            api.getKlines(selectedContract.id, timeframe)
                .then(data => {
                    setChartData(data);
                    setError(null);
                })
                .catch(err => {
                    setError(`Erreur de chargement des données du graphique: ${err.message}`);
                    console.error(err);
                })
                .finally(() => setLoading(false));
        }
    }, [selectedContract, timeframe]);

    return (
        <div className="dashboard-container">
            <h1>Dashboard</h1>

            <div className="search-container">
                <input
                    type="text"
                    placeholder="Rechercher un contrat"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="search-input"
                />
            </div>

            {error && <div className="error">{error}</div>}

            <div className="contract-grid">
                <div className="contracts-panel">
                    <h2>Contrats</h2>
                    {loading && !selectedContract ? (
                        <div className="loading">Chargement des contrats...</div>
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
                            <div className="timeframe-selector">
                                {['15m', '1h', '4h', '1d'].map(tf => (
                                    <button
                                        key={tf}
                                        className={timeframe === tf ? 'active' : ''}
                                        onClick={() => setTimeframe(tf)}
                                    >
                                        {tf}
                                    </button>
                                ))}
                            </div>

                            {loading ? (
                                <div className="chart-loading">Chargement du graphique...</div>
                            ) : chartData.length > 0 ? (
                                <CandleChart data={chartData} />
                            ) : (
                                <div className="no-data">Aucune donnée disponible</div>
                            )}
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