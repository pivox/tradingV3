// src/components/Chart.js
import React, { useState, useEffect } from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const Chart = ({ contractId }) => {
    const [chartData, setChartData] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!contractId) return;

        setLoading(true);
        setError(null);

        fetch(`/api/chart-data/${contractId}?timeframe=${timeframe}`)
            .then(res => {
                if (!res.ok) throw new Error(`Erreur: ${res.status}`);
                return res.json();
            })
            .then(data => {
                setChartData(data);
                setLoading(false);
            })
            .catch(err => {
                console.error('Erreur de chargement des données du graphique:', err);
                setError(err.message);
                setLoading(false);
            });
    }, [contractId, timeframe]);

    if (!contractId) return <div className="chart-placeholder">Sélectionnez un contrat pour afficher le graphique</div>;
    if (loading) return <div className="chart-loading">Chargement du graphique...</div>;
    if (error) return <div className="chart-error">Erreur: {error}</div>;

    return (
        <div className="chart-container">
            <div className="timeframe-selector">
                {['5m', '15m', '30m', '1h', '4h', '1d'].map(tf => (
                    <button
                        key={tf}
                        onClick={() => setTimeframe(tf)}
                        className={timeframe === tf ? 'active' : ''}
                    >
                        {tf}
                    </button>
                ))}
            </div>

            <ResponsiveContainer width="100%" height={400}>
                <LineChart data={chartData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis
                        dataKey="timestamp"
                        tickFormatter={(timestamp) => {
                            const date = new Date(timestamp);
                            return `${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
                        }}
                    />
                    <YAxis domain={['auto', 'auto']} />
                    <Tooltip
                        labelFormatter={(timestamp) => new Date(timestamp).toLocaleString()}
                        formatter={(value) => [parseFloat(value).toFixed(2), '']}
                    />
                    <Legend />
                    <Line type="monotone" dataKey="close" stroke="#8884d8" dot={false} name="Prix" />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default Chart;