// src/pages/ContractDetailPage.js
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import CandleChart from '../components/CandleChart';
import api from '../services/api';

const ContractDetailPage = () => {
    const { id } = useParams();
    const [contract, setContract] = useState(null);
    const [positions, setPositions] = useState([]);
    const [chartData, setChartData] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');
    const [setups, setSetups] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // Charger les détails du contrat
    useEffect(() => {
        const fetchData = async () => {
            try {
                setLoading(true);
                const contractData = await api.getContract(id);
                setContract(contractData);

                // Charger données additionnelles
                const [positionsData, klinesData, setupsData] = await Promise.all([
                    api.getPositions({ contract: id }),
                    api.getKlines(id, timeframe),
                    api.getSetups(id)
                ]);

                setPositions(positionsData);
                setChartData(klinesData);
                setSetups(setupsData);
                setError(null);
            } catch (err) {
                setError(`Erreur de chargement des données: ${err.message}`);
                console.error(err);
            } finally {
                setLoading(false);
            }
        };

        fetchData();
    }, [id]);

    // Recharger les klines quand le timeframe change
    useEffect(() => {
        if (contract) {
            api.getKlines(id, timeframe)
                .then(data => setChartData(data))
                .catch(err => console.error('Erreur lors du chargement des klines:', err));
        }
    }, [timeframe, id, contract]);

    if (loading) {
        return <div className="loading">Chargement des données...</div>;
    }

    if (error) {
        return <div className="error">{error}</div>;
    }

    if (!contract) {
        return <div className="no-data">Contrat non trouvé</div>;
    }

    return (
        <div className="contract-detail-page">
            <h1>{contract.symbol}</h1>

            <div className="contract-info">
                <div>
                    <strong>Prix:</strong> {contract.price}
                </div>
                <div>
                    <strong>Volume:</strong> {contract.volume}
                </div>
                <div>
                    <strong>Score:</strong> {contract.score}
                </div>
                <div>
                    <strong>Exchange:</strong> {contract.exchange}
                </div>
            </div>

            <div className="chart-container">
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

                {chartData.length > 0 ? (
                    <CandleChart data={chartData} setups={setups} />
                ) : (
                    <div className="no-data">Pas de données disponibles pour ce graphique</div>
                )}
            </div>

            <h2>Positions</h2>
            {positions.length > 0 ? (
                <table className="positions-table">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Prix d'entrée</th>
                        <th>Prix de sortie</th>
                        <th>Date d'ouverture</th>
                        <th>Date de fermeture</th>
                        <th>Statut</th>
                        <th>P&L</th>
                    </tr>
                    </thead>
                    <tbody>
                    {positions.map(position => (
                        <tr
                            key={position.id}
                            className={position.type === 'long' ? 'long' : 'short'}
                        >
                            <td>{position.type === 'long' ? 'LONG' : 'SHORT'}</td>
                            <td>{position.entry_price}</td>
                            <td>{position.exit_price || '-'}</td>
                            <td>{new Date(position.open_date).toLocaleString()}</td>
                            <td>{position.close_date ? new Date(position.close_date).toLocaleString() : '-'}</td>
                            <td className={`status-${position.status}`}>
                                {position.status === 'open' ? 'Ouverte' : 'Fermée'}
                            </td>
                            <td className={position.pnl > 0 ? 'profit' : position.pnl < 0 ? 'loss' : ''}>
                                {position.pnl ? `${position.pnl.toFixed(2)}%` : '-'}
                            </td>
                        </tr>
                    ))}
                    </tbody>
                </table>
            ) : (
                <div className="no-data">Aucune position pour ce contrat</div>
            )}
        </div>
    );
};

export default ContractDetailPage;