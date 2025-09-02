// src/pages/PositionsPage.js
import React, { useState, useEffect } from 'react';
import api from '../services/api';

const PositionsPage = () => {
    const [positions, setPositions] = useState([]);
    const [contracts, setContracts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filters, setFilters] = useState({
        contract: '',
        type: 'all',
        status: 'all'
    });

    // Charger tous les contrats pour le filtre
    useEffect(() => {
        api.getContracts()
            .then(data => setContracts(data))
            .catch(err => console.error('Erreur lors du chargement des contrats:', err));
    }, []);

    // Charger les positions avec filtres
    useEffect(() => {
        setLoading(true);
        api.getPositions(filters)
            .then(data => {
                setPositions(data);
                setError(null);
            })
            .catch(err => {
                setError(`Erreur de chargement des positions: ${err.message}`);
                console.error(err);
            })
            .finally(() => setLoading(false));
    }, [filters]);

    const handleFilterChange = (name, value) => {
        setFilters(prev => ({ ...prev, [name]: value }));
    };

    return (
        <div className="positions-page">
            <h1>Positions</h1>

            <div className="filters">
                <div className="filter-group">
                    <label htmlFor="contract-filter">Contrat:</label>
                    <select
                        id="contract-filter"
                        value={filters.contract}
                        onChange={e => handleFilterChange('contract', e.target.value)}
                    >
                        <option value="">Tous</option>
                        {contracts.map(contract => (
                            <option key={contract.id} value={contract.id}>
                                {contract.symbol}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="filter-group">
                    <label htmlFor="type-filter">Type:</label>
                    <select
                        id="type-filter"
                        value={filters.type}
                        onChange={e => handleFilterChange('type', e.target.value)}
                    >
                        <option value="all">Tous</option>
                        <option value="long">Long</option>
                        <option value="short">Short</option>
                    </select>
                </div>

                <div className="filter-group">
                    <label htmlFor="status-filter">Statut:</label>
                    <select
                        id="status-filter"
                        value={filters.status}
                        onChange={e => handleFilterChange('status', e.target.value)}
                    >
                        <option value="all">Tous</option>
                        <option value="open">Ouverte</option>
                        <option value="closed">Fermée</option>
                    </select>
                </div>
            </div>

            {error && <div className="error">{error}</div>}

            {loading ? (
                <div className="loading">Chargement des positions...</div>
            ) : positions.length > 0 ? (
                <table className="positions-table">
                    <thead>
                    <tr>
                        <th>Contrat</th>
                        <th>Type</th>
                        <th>Prix d'entrée</th>
                        <th>Prix de sortie</th>
                        <th>Date d'ouverture</th>
                        <th>Date de fermeture</th>
                        <th>Statut</th>
                        <th>P&L</th>
                    </tr>
                    </thead>
                    <tbody>
                    {positions.map(position => (
                        <tr
                            key={position.id}
                            className={position.type === 'long' ? 'long' : 'short'}
                        >
                            <td>{position.contract.symbol}</td>
                            <td>{position.type === 'long' ? 'LONG' : 'SHORT'}</td>
                            <td>{position.entry_price}</td>
                            <td>{position.exit_price || '-'}</td>
                            <td>{new Date(position.open_date).toLocaleString()}</td>
                            <td>{position.close_date ? new Date(position.close_date).toLocaleString() : '-'}</td>
                            <td className={`status-${position.status}`}>
                                {position.status === 'open' ? 'Ouverte' : 'Fermée'}
                            </td>
                            <td className={position.pnl > 0 ? 'profit' : position.pnl < 0 ? 'loss' : ''}>
                                {position.pnl ? `${position.pnl.toFixed(2)}%` : '-'}
                            </td>
                        </tr>
                    ))}
                    </tbody>
                </table>
            ) : (
                <div className="no-data">Aucune position trouvée</div>
            )}
        </div>
    );
};

export default PositionsPage;