import React, { useEffect, useState } from 'react';
import Chart from 'react-apexcharts';
import api from '../services/api';

const toLocalInput = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    const y = date.getFullYear();
    const m = pad(date.getMonth() + 1);
    const d = pad(date.getDate());
    const h = pad(date.getHours());
    const min = pad(date.getMinutes());
    return `${y}-${m}-${d}T${h}:${min}`;
};

const ChartsPage = () => {
    const [symbol, setSymbol] = useState('BTCUSDT');
    const [interval, setInterval] = useState('4h');
    const [start, setStart] = useState(() => {
        const now = new Date();
        const past = new Date(now.getTime());
        past.setHours(past.getHours() - 240); // 60 intervalles de 4h => 240h
        return toLocalInput(past);
    });
    const [end, setEnd] = useState(() => {
        return toLocalInput(new Date());
    });

    const [ohlcData, setOhlcData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchData = async () => {
            if (!symbol || !interval || !start || !end) return;
            setLoading(true);
            setError(null);
            try {
                const data = await api.getKlinesRange(symbol, interval, start, end);
                const offsetMs = 120 * 60 * 1000; // UTC+2 pour l'affichage
                const transformedData = (Array.isArray(data) ? data : [])
                    .map(item => {
                        let ts = item.timestamp;
                        if (typeof ts === 'string') {
                            const iso = ts.includes('T') ? ts : ts.replace(' ', 'T') + 'Z';
                            const ms = Date.parse(iso);
                            ts = Number.isFinite(ms) ? ms : new Date(ts).getTime();
                        }
                        return {
                            x: ts + offsetMs, // décalage affichage UTC+2
                            y: [
                                parseFloat(item.open),
                                parseFloat(item.high),
                                parseFloat(item.low),
                                parseFloat(item.close)
                            ]
                        };
                    })
                    .filter(p => Number.isFinite(p.x) && p.y.every(n => Number.isFinite(n)))
                    .sort((a, b) => a.x - b.x);

                setOhlcData(transformedData);
            } catch (err) {
                console.error('Erreur chargement Klines:', err);
                setError(`Erreur chargement Klines: ${err.message}`);
                setOhlcData([]);
            } finally {
                setLoading(false);
            }
        };

        fetchData();
    }, [symbol, interval, start, end]);

    const options = {
        chart: {
            type: 'candlestick',
            height: 350
        },
        title: {
            text: `${symbol} Chart`,
            align: 'left'
        },
        xaxis: {
            type: 'datetime',
            labels: { datetimeUTC: true } // afficher en UTC; timestamps déjà décalés
        },
        yaxis: {
            tooltip: {
                enabled: true
            }
        }
    };

    const series = [{ data: ohlcData }];

    const popularSymbols = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'ADAUSDT', 'SOLUSDT', 'XRPUSDT', 'DOTUSDT', 'DOGEUSDT'];

    return (
        <div className="p-4">
            <h2 className="text-2xl font-bold mb-6 text-gray-800">Graphiques de Trading</h2>

            {/* Contrôles principaux */}
            <div className="bg-white rounded-lg shadow-md p-6 mb-6">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    {/* Sélection du contrat */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Contrat
                        </label>
                        <input
                            type="text"
                            value={symbol}
                            onChange={e => setSymbol(e.target.value.toUpperCase())}
                            placeholder="Ex: BTCUSDT"
                            className="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>

                    {/* Sélection du timeframe */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Timeframe
                        </label>
                        <select
                            value={interval}
                            onChange={e => setInterval(e.target.value)}
                            className="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="1m">1 minute</option>
                            <option value="5m">5 minutes</option>
                            <option value="15m">15 minutes</option>
                            <option value="1h">1 heure</option>
                            <option value="4h">4 heures</option>
                        </select>
                    </div>

                    {/* Date de début */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Date de début
                        </label>
                        <input
                            type="datetime-local"
                            value={start}
                            onChange={e => setStart(e.target.value)}
                            className="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>

                    {/* Date de fin */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Date de fin
                        </label>
                        <input
                            type="datetime-local"
                            value={end}
                            onChange={e => setEnd(e.target.value)}
                            className="w-full border border-gray-300 p-3 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>
                </div>

                {/* Contrats populaires */}
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">
                        Contrats populaires
                    </label>
                    <div className="flex flex-wrap gap-2">
                        {popularSymbols.map(popSymbol => (
                            <button
                                key={popSymbol}
                                onClick={() => setSymbol(popSymbol)}
                                className={`px-3 py-1 rounded-full text-sm font-medium transition-colors ${
                                    symbol === popSymbol
                                        ? 'bg-blue-500 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                }`}
                            >
                                {popSymbol}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* Graphique */}
            <div className="bg-white rounded-lg shadow-md p-6">
                {error && (
                    <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                        {error}
                    </div>
                )}
                
                {loading ? (
                    <div className="flex items-center justify-center h-96">
                        <div className="text-center">
                            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
                            <p className="text-gray-600">Chargement du graphique...</p>
                        </div>
                    </div>
                ) : (
                    <Chart options={options} series={series} type="candlestick" height={500} />
                )}
            </div>
        </div>
    );
};

export default ChartsPage;
