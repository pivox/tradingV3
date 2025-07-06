import React, { useEffect, useState } from 'react';
import Chart from 'react-apexcharts';
import axios from 'axios';

const ChartsPage = () => {
    const [symbol, setSymbol] = useState('RESOLVUSDT');
    const [interval, setInterval] = useState('4h');
    const [start, setStart] = useState(() => {
        const date = new Date();
        date.setHours(date.getHours() - 240); // 60 intervals of 4h => 240h
        return date.toISOString().slice(0, 16);
    });
    const [end, setEnd] = useState(() => {
        return new Date().toISOString().slice(0, 16);
    });

    const [ohlcData, setOhlcData] = useState([]);

    useEffect(() => {
        const fetchData = async () => {
            if (!symbol || !interval || !start || !end) return;

            try {
                const response = await axios.get('http://localhost:8080/api/klines', {
                    params: {
                        symbol,
                        interval,
                        start,
                        end
                    }
                });

                const transformedData = response.data.map(item => ({
                    x: new Date(item.timestamp),
                    y: [
                        parseFloat(item.open),
                        parseFloat(item.high),
                        parseFloat(item.low),
                        parseFloat(item.close)
                    ]
                })).sort((a, b) => new Date(a.x) - new Date(b.x));

                setOhlcData(transformedData);
            } catch (error) {
                console.error('Erreur chargement Klines:', error);
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
            type: 'datetime'
        },
        yaxis: {
            tooltip: {
                enabled: true
            }
        }
    };

    const series = [{ data: ohlcData }];

    return (
        <div className="p-4">
            <h2 className="text-xl font-bold mb-4">Charts</h2>

            <div className="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                <input
                    type="text"
                    value={symbol}
                    onChange={e => setSymbol(e.target.value)}
                    placeholder="Symbole"
                    className="border p-2 rounded"
                />

                <select
                    value={interval}
                    onChange={e => setInterval(e.target.value)}
                    className="border p-2 rounded"
                >
                    <option value="1m">1m</option>
                    <option value="5m">5m</option>
                    <option value="15m">15m</option>
                    <option value="1h">1h</option>
                    <option value="4h">4h</option>
                </select>

                <input
                    type="datetime-local"
                    value={start}
                    onChange={e => setStart(e.target.value)}
                    className="border p-2 rounded"
                />

                <input
                    type="datetime-local"
                    value={end}
                    onChange={e => setEnd(e.target.value)}
                    className="border p-2 rounded"
                />

            </div>

            <Chart options={options} series={series} type="candlestick" height={350} />
        </div>
    );
};

export default ChartsPage;
