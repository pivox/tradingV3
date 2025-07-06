import React, { useEffect, useState } from 'react';
import Chart from 'react-apexcharts';
import axios from 'axios';

const CandleChart = ({ symbol, interval, start, end }) => {
    const [ohlcData, setOhlcData] = useState([]);

    useEffect(() => {
        if (!symbol || !interval || isNaN(new Date(start).getTime()) || isNaN(new Date(end).getTime())) return;

        const fetchData = async () => {
            try {
                const startTimestamp = Math.floor(new Date(start).getTime() / 1000);
                const endTimestamp = Math.floor(new Date(end).getTime() / 1000);

                const response = await axios.get(`http://localhost:8080/api/klines`, {
                    params: { symbol, interval, start: startTimestamp, end: endTimestamp }
                });

                const transformedData = response.data.map(item => ({
                    x: new Date(item.openTime),  // ApexCharts accepte Date ou timestamp
                    y: [
                        parseFloat(item.open),
                        parseFloat(item.high),
                        parseFloat(item.low),
                        parseFloat(item.close)
                    ]
                }));

                setOhlcData(transformedData);
            } catch (error) {
                console.error("Erreur chargement Klines:", error);
            }
        };

        fetchData();
    }, [symbol, interval, start, end]);

    const options = {
        chart: { type: 'candlestick', height: 350 },
        title: { text: `${symbol} Chart`, align: 'left' },  // toujours une chaîne !
        xaxis: { type: 'datetime' },
        yaxis: { tooltip: { enabled: true } }
    };

    const series = [{ data: ohlcData }];

    return <Chart options={options} series={series} type="candlestick" height={350} />;
};

export default CandleChart;
