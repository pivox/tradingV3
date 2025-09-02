// src/components/ContractList.js
import React, { useState, useEffect } from 'react';

const ContractList = ({ contracts, onSelect, selectedContract }) => {
    const [sortedContracts, setSortedContracts] = useState([]);
    const [sortConfig, setSortConfig] = useState({
        key: 'name',
        direction: 'ascending'
    });

    useEffect(() => {
        let contractsCopy = [...contracts];

        if (sortConfig.key) {
            contractsCopy.sort((a, b) => {
                if (a[sortConfig.key] < b[sortConfig.key]) {
                    return sortConfig.direction === 'ascending' ? -1 : 1;
                }
                if (a[sortConfig.key] > b[sortConfig.key]) {
                    return sortConfig.direction === 'ascending' ? 1 : -1;
                }
                return 0;
            });
        }

        setSortedContracts(contractsCopy);
    }, [contracts, sortConfig]);

    const requestSort = key => {
        let direction = 'ascending';
        if (sortConfig.key === key && sortConfig.direction === 'ascending') {
            direction = 'descending';
        }
        setSortConfig({ key, direction });
    };

    return (
        <div>
            <div className="sort-header">
                <button
                    onClick={() => requestSort('symbol')}
                    className={sortConfig.key === 'symbol' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Nom {sortConfig.key === 'symbol' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
                <button
                    onClick={() => requestSort('volume')}
                    className={sortConfig.key === 'volume' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Volume {sortConfig.key === 'volume' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
                <button
                    onClick={() => requestSort('score')}
                    className={sortConfig.key === 'score' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Score {sortConfig.key === 'score' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
            </div>
            <ul className="contract-list">
                {sortedContracts.map(contract => (
                    <li
                        key={contract.id}
                        onClick={() => onSelect(contract)}
                        className={selectedContract && selectedContract.id === contract.id ? 'selected' : ''}
                    >
                        <div className="contract-item">
                            <span className="contract-symbol">{contract.symbol}</span>
                            <span className="contract-details">
                <span className="contract-volume">Vol: {contract.volume}</span>
                <span className="contract-score">Score: {contract.score}</span>
              </span>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
};

export default ContractList;