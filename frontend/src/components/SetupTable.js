import React from 'react';

const SetupTable = () => {
    const fakeSetups = [
        { symbol: 'RESOLVUSDT', type: 'Long', timeframe: '5m', score: 78, time: '2025-07-03 10:12' },
        { symbol: 'BTCUSDT', type: 'Short', timeframe: '15m', score: 85, time: '2025-07-03 10:10' },
    ];

    return (
        <table className="border-collapse border w-full">
            <thead>
            <tr>
                <th className="border p-2">Symbole</th>
                <th className="border p-2">Type</th>
                <th className="border p-2">Timeframe</th>
                <th className="border p-2">Score</th>
                <th className="border p-2">Heure</th>
            </tr>
            </thead>
            <tbody>
            {fakeSetups.map((setup, idx) => (
                <tr key={idx}>
                    <td className="border p-2">{setup.symbol}</td>
                    <td className="border p-2">{setup.type}</td>
                    <td className="border p-2">{setup.timeframe}</td>
                    <td className="border p-2">{setup.score}%</td>
                    <td className="border p-2">{setup.time}</td>
                </tr>
            ))}
            </tbody>
        </table>
    );
};

export default SetupTable;
