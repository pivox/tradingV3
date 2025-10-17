// src/pages/ContractPage.js
import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import Chart from '../components/Chart';

const ContractPage = () => {
    const { contractId } = useParams();
    const navigate = useNavigate();
    const [contracts, setContracts] = useState([]);
    const [selectedContract, setSelectedContract] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        symbol: '',
        status: '',
        search: ''
    });
    const [sortConfig, setSortConfig] = useState({
        key: 'symbol',
        direction: 'asc'
    });

    useEffect(() => {
        fetchContracts();
    }, [filters, sortConfig, contractId]);

    const fetchContracts = async () => {
        setLoading(true);
        setError(null);
        
        try {
            const params = new URLSearchParams();
            if (filters.symbol) params.append('symbol', filters.symbol);
            if (filters.status) params.append('status', filters.status);
            if (filters.search) params.append('search', filters.search);
            if (sortConfig.key) params.append('sort', sortConfig.key);
            if (sortConfig.direction) params.append('order', sortConfig.direction);

            const response = await fetch(`/api/contracts?${params.toString()}`);
            const data = await response.json();
            
            setContracts(data);

            // Si contractId est fourni dans l'URL, sélectionnez ce contrat
            if (contractId) {
                const contract = data.find(c => c.id === contractId);
                if (contract) setSelectedContract(contract);
            }
        } catch (error) {
            console.error('Erreur lors du chargement des contrats:', error);
            setError('Erreur lors du chargement des contrats');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (selectedContract) {
            navigate(`/contracts/${selectedContract.id}`, { replace: true });
        }
    }, [selectedContract, navigate]);

    const handleSort = (key) => {
        setSortConfig(prev => ({
            key,
            direction: prev.key === key && prev.direction === 'asc' ? 'desc' : 'asc'
        }));
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const clearFilters = () => {
        setFilters({
            symbol: '',
            status: '',
            search: ''
        });
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
            <div className="page-header">
                <h1>Gestion des Contrats</h1>
                <p className="page-subtitle">Consulter et gérer les contrats disponibles</p>
                <div className="page-actions">
                    <button 
                        className="btn btn-secondary"
                        onClick={clearFilters}
                    >
                        Effacer les filtres
                    </button>
                    <button 
                        className="btn btn-primary"
                        onClick={fetchContracts}
                        disabled={loading}
                    >
                        {loading ? 'Chargement...' : 'Actualiser'}
                    </button>
                </div>
            </div>

            {/* Filtres */}
            <div className="filters-section">
                <div className="filters-grid">
                    <div className="filter-group">
                        <label>Symbole</label>
                        <input
                            type="text"
                            placeholder="Ex: BTCUSDT"
                            value={filters.symbol}
                            onChange={(e) => handleFilterChange('symbol', e.target.value)}
                            className="form-control"
                        />
                    </div>

                    <div className="filter-group">
                        <label>Statut</label>
                        <select
                            value={filters.status}
                            onChange={(e) => handleFilterChange('status', e.target.value)}
                            className="form-control"
                        >
                            <option value="">Tous</option>
                            <option value="active">Actif</option>
                            <option value="inactive">Inactif</option>
                            <option value="suspended">Suspendu</option>
                        </select>
                    </div>

                    <div className="filter-group">
                        <label>Recherche</label>
                        <input
                            type="text"
                            placeholder="Rechercher..."
                            value={filters.search}
                            onChange={(e) => handleFilterChange('search', e.target.value)}
                            className="form-control"
                        />
                    </div>
                </div>
            </div>

            {/* Erreur */}
            {error && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            <div className="contract-grid">
                <div className="contracts-panel">

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
                        {loading ? (
                            <li className="loading">Chargement des contrats...</li>
                        ) : sortedContracts.length === 0 ? (
                            <li className="no-data">Aucun contrat trouvé</li>
                        ) : (
                            sortedContracts.map(contract => (
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
                            ))
                        )}
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