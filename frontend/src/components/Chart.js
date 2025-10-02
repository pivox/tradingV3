// src/components/Chart.js
import React, { useState, useEffect, useMemo } from 'react';
import ReactApexChart from 'react-apexcharts';

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

    const series = useMemo(() => [{
        name: 'Prix',
        data: chartData.map(point => ({
            x: point.timestamp,
            y: Number(point.close)
        }))
    }], [chartData]);

    const options = useMemo(() => ({
        chart: {
            type: 'line',
            zoom: { enabled: false },
            toolbar: { show: false }
        },
        stroke: {
            curve: 'smooth',
            width: 2
        },
        dataLabels: { enabled: false },
        xaxis: {
            type: 'datetime',
            labels: { datetimeUTC: false }
        },
        yaxis: {
            decimalsInFloat: 2
        },
        tooltip: {
            x: { format: 'dd MMM HH:mm' },
            y: {
                formatter: (value) => Number(value).toFixed(2)
            }
        }
    }), []);

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

            <div className="chart-wrapper">
                <ReactApexChart options={options} series={series} type="line" height={400} />
            </div>
        </div>
    );
};

export default Chart;
