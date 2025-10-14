// src/components/CandleChart.js
import React, { useState, useEffect, useMemo } from 'react';
import ReactApexChart from 'react-apexcharts';

const DEFAULT_TIMEFRAMES = ['5m', '15m', '1h', '4h', '1d'];

const CandleChart = ({
    contractId,
    data,
    timeframe: controlledTimeframe,
    onTimeframeChange,
    loading: externalLoading = false,
    timeframes = DEFAULT_TIMEFRAMES,
    height = 400,
    emptyMessage = 'Aucune donnée disponible',
    utcOffsetMinutes = 0,
}) => {
    const [internalTimeframe, setInternalTimeframe] = useState(controlledTimeframe || '1h');
    const [internalData, setInternalData] = useState([]);
    const [internalLoading, setInternalLoading] = useState(false);
    const [error, setError] = useState(null);

    const usingExternalData = Array.isArray(data);
    const timeframe = controlledTimeframe ?? internalTimeframe;
    const effectiveData = usingExternalData ? data : internalData;
    const loading = usingExternalData ? externalLoading : internalLoading;

    useEffect(() => {
        if (usingExternalData) {
            setInternalLoading(false);
            setError(null);
            return;
        }

        if (!contractId) {
            setInternalData([]);
            setInternalLoading(false);
            return;
        }

        let cancelled = false;
        setInternalLoading(true);
        setError(null);

        fetch(`/api/chart-data/${contractId}?timeframe=${timeframe}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error(`Erreur ${res.status}`);
                }
                return res.json();
            })
            .then(dataPoints => {
                if (!cancelled) {
                    setInternalData(Array.isArray(dataPoints) ? dataPoints : []);
                }
            })
            .catch(err => {
                if (!cancelled) {
                    console.error('Erreur de chargement des données du graphique:', err);
                    setError('Impossible de charger les données du graphique');
                    setInternalData([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setInternalLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [contractId, timeframe, usingExternalData]);

    const handleTimeframeClick = (tf) => {
        if (tf === timeframe) {
            return;
        }

        if (controlledTimeframe && onTimeframeChange) {
            onTimeframeChange(tf);
        } else {
            setInternalTimeframe(tf);
        }
    };

    const offsetMs = (Number(utcOffsetMinutes) || 0) * 60 * 1000;

    const series = useMemo(() => [{
        data: (effectiveData ?? []).map(point => {
            const timestamp = point.timestamp;
            const base = typeof timestamp === 'number'
                ? timestamp
                : new Date(timestamp).getTime();
            const x = base + offsetMs; // Affichage: UTC + offset
            return {
                x,
                y: [Number(point.open), Number(point.high), Number(point.low), Number(point.close)]
            };
        })
    }], [effectiveData, offsetMs]);

    const options = useMemo(() => ({
        chart: {
            type: 'candlestick',
            toolbar: { show: false }
        },
        plotOptions: {
            candlestick: {
                colors: {
                    upward: '#00b746',
                    downward: '#ef403c'
                }
            }
        },
        xaxis: {
            type: 'datetime',
            labels: { datetimeUTC: true } // force l’affichage en UTC; on décale déjà les timestamps
        },
        yaxis: {
            tooltip: { enabled: true }
        }
    }), []);

    return (
        <div className="candlestick-chart">
            <div className="timeframe-selector">
                {timeframes.map(tf => (
                    <button
                        key={tf}
                        onClick={() => handleTimeframeClick(tf)}
                        className={timeframe === tf ? 'active' : ''}
                    >
                        {tf}
                    </button>
                ))}
            </div>

            {error && <div className="chart-error">{error}</div>}

            {loading ? (
                <div className="chart-loading">Chargement du graphique...</div>
            ) : (effectiveData && effectiveData.length > 0) ? (
                <div className="chart-wrapper">
                    <ReactApexChart options={options} series={series} type="candlestick" height={height} />
                </div>
            ) : (
                <div className="no-data">{emptyMessage}</div>
            )}
        </div>
    );
};

export default CandleChart;
