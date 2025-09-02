// src/pages/ContractPage.js
import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import Chart from '../components/Chart';

const ContractPage = () => {
    const { contractId } = useParams();
    const navigate = useNavigate();
    const [contracts, setContracts] = useState([]);
    const [selectedContract, setSelectedContract] = useState(null);
    const [search, setSearch] = useState('');
    const [sortConfig, setSortConfig] = useState({
        key: 'symbol',
        direction: 'ascending'
    });

    useEffect(() => {
        fetch(`/api/contracts?search=${search}`)
            .then(res => res.json())
            .then(data => {
                setContracts(data);

                // Si contractId est fourni dans l'URL, sélectionnez ce contrat
                if (contractId) {
                    const contract = data.find(c => c.id === contractId);
                    if (contract) setSelectedContract(contract);
                }
            })
            .catch(error => console.error('Erreur lors du chargement des contrats:', error));
    }, [search, contractId]);

    useEffect(() => {
        if (selectedContract) {
            navigate(`/contracts/${selectedContract.id}`, { replace: true });
        }
    }, [selectedContract, navigate]);

    const handleSort = (key) => {
        let direction = 'ascending';
        if (sortConfig.key === key && sortConfig.direction === 'ascending') {
            direction = 'descending';
        }
        setSortConfig({ key, direction });
    };

    const sortedContracts = [...contracts].sort((a, b) => {
        if (a[sortConfig.key] < b[sortConfig.key]) {
            return sortConfig.direction === 'ascending' ? -1 : 1;
        }
        if (a[sortConfig.key] > b[sortConfig.key]) {
            return sortConfig.direction === 'ascending' ? 1 : -1;
        }
        return 0;
    });

    return (
        <div className="contract-page">
            <h1>Contrats</h1>

            <div className="contract-grid">
                <div className="contracts-panel">
                    <div className="search-container">
                        <input
                            type="text"
                            placeholder="Rechercher un contrat"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="search-input"
                        />
                    </div>

                    <div className="sort-header">
                        <button
                            onClick={() => handleSort('symbol')}
                            className={sortConfig.key === 'symbol' ? `sorted-${sortConfig.direction}` : ''}
                        >
                            Nom {sortConfig.key === 'symbol' && (sortConfig.direction === 'ascending' ? '↑' : '↓')}
                        </button>
                        <button
                            onClick={() => handleSort('volume')}
                            className={sortConfig.key === 'volume' ? `sorted-${sortConfig.direction}` : ''}
                        >
                            Volume {sortConfig.key === 'volume' && (sortConfig.direction === 'ascending' ? '↑' : '↓')}
                        </button>
                        <button
                            onClick={() => handleSort('score')}
                            className={sortConfig.key === 'score' ? `sorted-${sortConfig.direction}` : ''}
                        >
                            Score {sortConfig.key === 'score' && (sortConfig.direction === 'ascending' ? '↑' : '↓')}
                        </button>
                    </div>

                    <ul className="contract-list">
                        {sortedContracts.map(contract => (
                            <li
                                key={contract.id}
                                onClick={() => setSelectedContract(contract)}
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

                <div className="chart-detail-panel">
                    {selectedContract ? (
                        <>
                            <h2>{selectedContract.symbol} - {selectedContract.exchange}</h2>
                            <div className="contract-info">
                                <div>Volume: <strong>{selectedContract.volume}</strong></div>
                                <div>Score: <strong>{selectedContract.score}</strong></div>
                                <div>Exchange: <strong>{selectedContract.exchange}</strong></div>
                            </div>
                            <Chart contractId={selectedContract.id} />
                        </>
                    ) : (
                        <div className="select-prompt">Sélectionnez un contrat pour afficher les détails</div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default ContractPage;