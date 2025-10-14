// src/components/ContractList.js
import React, { useState, useEffect } from 'react';

const safeValue = (v, def = '') => (v === null || v === undefined ? def : v);
const compareValues = (a, b, key, direction) => {
    const va = safeValue(a[key]);
    const vb = safeValue(b[key]);
    let cmp;
    if (typeof va === 'string' || typeof vb === 'string') {
        cmp = String(va).localeCompare(String(vb));
    } else {
        const na = Number(va);
        const nb = Number(vb);
        if (Number.isFinite(na) && Number.isFinite(nb)) {
            cmp = na === nb ? 0 : (na < nb ? -1 : 1);
        } else {
            cmp = String(va).localeCompare(String(vb));
        }
    }
    return direction === 'ascending' ? cmp : -cmp;
};

const formatCompact = (n) => {
    if (!Number.isFinite(Number(n))) return '-';
    try {
        return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 2 }).format(Number(n));
    } catch (_) {
        return String(n);
    }
};

const formatNumber = (n, max = 6) => {
    if (!Number.isFinite(Number(n))) return '-';
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: max }).format(Number(n));
};

const formatChangePct = (v) => {
    if (!Number.isFinite(Number(v))) return '-';
    const num = Number(v);
    const sign = num > 0 ? '+' : '';
    return `${sign}${num.toFixed(2)}%`;
};

const ContractList = ({ contracts, onSelect, selectedContract }) => {
    const [sortedContracts, setSortedContracts] = useState([]);
    const [sortConfig, setSortConfig] = useState({
        key: 'symbol',
        direction: 'ascending'
    });

    useEffect(() => {
        let contractsCopy = [...contracts];
        const key = sortConfig.key;
        const direction = sortConfig.direction;

        if (key) {
            contractsCopy.sort((a, b) => compareValues(a, b, key, direction));
        }

        setSortedContracts(contractsCopy);
    }, [contracts, sortConfig]);

    const requestSort = key => {
        const nextDirection = (sortConfig.key === key && sortConfig.direction === 'ascending')
            ? 'descending'
            : 'ascending';
        setSortConfig({ key, direction: nextDirection });
    };

    return (
        <div>
            <div className="sort-header">
                <button
                    onClick={() => requestSort('symbol')}
                    className={sortConfig.key === 'symbol' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Symbole {sortConfig.key === 'symbol' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
                <button
                    onClick={() => requestSort('lastPrice')}
                    className={sortConfig.key === 'lastPrice' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Prix {sortConfig.key === 'lastPrice' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
                <button
                    onClick={() => requestSort('volume24h')}
                    className={sortConfig.key === 'volume24h' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Vol 24h {sortConfig.key === 'volume24h' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
                <button
                    onClick={() => requestSort('change24h')}
                    className={sortConfig.key === 'change24h' ? `sorted-${sortConfig.direction}` : ''}
                >
                    Var 24h {sortConfig.key === 'change24h' ? (sortConfig.direction === 'ascending' ? '↑' : '↓') : ''}
                </button>
            </div>
            <ul className="contract-list">
                {sortedContracts.map(contract => {
                    const contractKey = contract.symbol; // identifiant côté API Platform
                    const isSelected = selectedContract
                        ? (selectedContract.symbol ?? selectedContract.id) === contractKey
                        : false;

                    const symbol = safeValue(contract.symbol, contract.id);
                    const lastPrice = safeValue(contract.lastPrice, contract.indexPrice);
                    const volume24h = safeValue(contract.volume24h, contract.volume);
                    const change24h = safeValue(contract.change24h, null);

                    const changeClass = Number(change24h) > 0 ? 'pos' : Number(change24h) < 0 ? 'neg' : '';

                    return (
                        <li
                            key={contractKey}
                            onClick={() => onSelect(contract)}
                            className={isSelected ? 'selected' : ''}
                        >
                            <div className="contract-item">
                                <span className="contract-symbol">{symbol}</span>
                                <span className="contract-details">
                                    <span className="contract-price">{formatNumber(lastPrice, 6)}</span>
                                    <span className="contract-volume">Vol: {formatCompact(volume24h)}</span>
                                    <span className={`contract-change ${changeClass}`}>{formatChangePct(change24h)}</span>
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
};

export default ContractList;
