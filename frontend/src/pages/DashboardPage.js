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
    const [contractsLoading, setContractsLoading] = useState(true);
    const [chartLoading, setChartLoading] = useState(false);
    const [contractsError, setContractsError] = useState(null);
    const [chartError, setChartError] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');

    // Charger les contrats
    useEffect(() => {
        const fetchContracts = async () => {
            try {
                setContractsLoading(true);
                const data = await api.getContracts(searchTerm);
                setContracts(data);
                setContractsError(null);

                if (!selectedContract && data.length > 0) {
                    setSelectedContract(data[0]);
                }
            } catch (err) {
                setContractsError(`Erreur de chargement des contrats: ${err.message}`);
                console.error(err);
            } finally {
                setContractsLoading(false);
            }
        };

        fetchContracts();
    }, [searchTerm]);

    // Charger les données du graphique quand un contrat est sélectionné
    useEffect(() => {
        const contractId = selectedContract ? (selectedContract.id ?? selectedContract.symbol) : null;

        if (contractId) {
            setChartLoading(true);
            setChartError(null);
            setChartData([]);

            api.getKlines(contractId, timeframe)
                .then(data => {
                    setChartData(data);
                    setChartError(null);
                })
                .catch(err => {
                    setChartError(`Erreur de chargement des données du graphique: ${err.message}`);
                    console.error(err);
                })
                .finally(() => setChartLoading(false));
        } else {
            setChartData([]);
            setChartLoading(false);
            setChartError(null);
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

            {contractsError && <div className="error">{contractsError}</div>}

            <div className="contract-grid">
                <div className="contracts-panel">
                    <h2>Contrats</h2>
                    {contractsLoading && !selectedContract ? (
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
                            {chartError && <div className="error">{chartError}</div>}
                            <CandleChart
                                contractId={selectedContract.id ?? selectedContract.symbol}
                                data={chartData}
                                timeframe={timeframe}
                                onTimeframeChange={setTimeframe}
                                loading={chartLoading}
                                emptyMessage="Aucune donnée disponible"
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
