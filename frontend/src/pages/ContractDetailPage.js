// src/pages/ContractDetailPage.js
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import CandleChart from '../components/CandleChart';
import api from '../services/api';

const ContractDetailPage = () => {
    const { id } = useParams();
    const [contract, setContract] = useState(null);
    const [positions, setPositions] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [chartData, setChartData] = useState([]);
    const [chartLoading, setChartLoading] = useState(false);
    const [chartError, setChartError] = useState(null);

    useEffect(() => {
        let cancelled = false;

        const fetchDetails = async () => {
            setLoading(true);
            try {
                const [contractData, positionsData] = await Promise.all([
                    api.getContract(id),
                    api.getPositions({ contract: id })
                ]);

                if (cancelled) {
                    return;
                }

                setContract(contractData);
                setPositions(Array.isArray(positionsData) ? positionsData : []);
                setError(null);
            } catch (err) {
                if (!cancelled) {
                    setError(`Erreur de chargement des données: ${err.message}`);
                    console.error(err);
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };

        fetchDetails();

        return () => {
            cancelled = true;
        };
    }, [id]);

    useEffect(() => {
        const contractId = contract ? (contract.id ?? contract.symbol) : id;
        if (!contractId) {
            setChartData([]);
            setChartLoading(false);
            setChartError(null);
            return;
        }

        let cancelled = false;
        setChartLoading(true);
        setChartError(null);

        api.getKlines(contractId, timeframe)
            .then(data => {
                if (!cancelled) {
                    setChartData(Array.isArray(data) ? data : []);
                }
            })
            .catch(err => {
                if (!cancelled) {
                    console.error('Erreur lors du chargement des klines:', err);
                    setChartData([]);
                    setChartError('Impossible de charger les données du graphique');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setChartLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [id, contract, timeframe]);

    const formatNumber = (value, options = {}) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '-';
        }
        const {
            maximumFractionDigits = 4,
            minimumFractionDigits = 0
        } = options;
        return new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits,
            maximumFractionDigits
        }).format(Number(value));
    };

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
                    <strong>Prix:</strong> {formatNumber(contract.price, { maximumFractionDigits: 6 })}
                </div>
                <div>
                    <strong>Volume:</strong> {formatNumber(contract.volume)}
                </div>
                <div>
                    <strong>Score:</strong> {contract.score ?? '-'}
                </div>
                <div>
                    <strong>Exchange:</strong> {contract.exchange}
                </div>
            </div>

            <div className="chart-container">
                {chartError && <div className="error">{chartError}</div>}
                <CandleChart
                    contractId={contract.id ?? contract.symbol}
                    data={chartData}
                    timeframe={timeframe}
                    onTimeframeChange={setTimeframe}
                    loading={chartLoading}
                    emptyMessage="Pas de données disponibles pour ce graphique"
                />
            </div>

            <h2>Positions</h2>
            {positions.length > 0 ? (
                <table className="positions-table">
                    <thead>
                    <tr>
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
                            key={`${position.source || 'db'}-${position.id}`}
                            className={position.type === 'long' ? 'long' : position.type === 'short' ? 'short' : ''}
                        >
                            <td className={`badge badge-${position.source || 'database'}`}>
                                {position.source === 'exchange' ? 'Exchange' : 'Historique'}
                            </td>
                            <td>{position.type ? position.type.toUpperCase() : '-'}</td>
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
                <div className="no-data">Aucune position pour ce contrat</div>
            )}
        </div>
    );
};

export default ContractDetailPage;
