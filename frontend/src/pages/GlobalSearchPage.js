// src/pages/GlobalSearchPage.js
import React, { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import api from '../services/api';

const SEARCH_DEBOUNCE_MS = 500;

const GlobalSearchPage = () => {
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState({
        contracts: [],
        signals: [],
        audits: []
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [hasSearched, setHasSearched] = useState(false);

    const searchTimerRef = useRef(null);
    const isFetchingRef = useRef(false);

    useEffect(() => {
        if (searchTimerRef.current) {
            clearTimeout(searchTimerRef.current);
            searchTimerRef.current = null;
        }

        if (searchQuery.trim().length >= 2) {
            searchTimerRef.current = setTimeout(() => {
                performGlobalSearch(searchQuery.trim());
            }, SEARCH_DEBOUNCE_MS);
        } else if (searchQuery.trim().length === 0) {
            setSearchResults({
                contracts: [],
                signals: [],
                audits: []
            });
            setHasSearched(false);
        }

        return () => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
                searchTimerRef.current = null;
            }
        };
    }, [searchQuery]);

    const performGlobalSearch = async (query) => {
        if (isFetchingRef.current) return;
        
        isFetchingRef.current = true;
        setLoading(true);
        setError(null);
        setHasSearched(true);

        try {
            // Recherche globale selon l'US-011: GET /search?q=...
            const response = await api.globalSearch(query);
            
            // Organiser les résultats par catégorie
            const organizedResults = {
                contracts: response.contracts || [],
                signals: response.signals || [],
                audits: response.audits || []
            };
            
            setSearchResults(organizedResults);
        } catch (err) {
            console.error('Erreur lors de la recherche globale:', err);
            setError(`Erreur de recherche: ${err.message}`);
            setSearchResults({
                contracts: [],
                signals: [],
                audits: []
            });
        } finally {
            setLoading(false);
            isFetchingRef.current = false;
        }
    };

    const clearSearch = () => {
        setSearchQuery('');
        setSearchResults({
            contracts: [],
            signals: [],
            audits: []
        });
        setHasSearched(false);
        setError(null);
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString('fr-FR');
    };

    const getTimeframeBadgeClass = (timeframe) => {
        switch (timeframe) {
            case '1m': return 'badge-primary';
            case '5m': return 'badge-info';
            case '15m': return 'badge-warning';
            case '1h': return 'badge-success';
            case '4h': return 'badge-danger';
            default: return 'badge-secondary';
        }
    };

    const getSideBadgeClass = (side) => {
        switch (side?.toLowerCase()) {
            case 'long':
            case 'buy':
                return 'badge-success';
            case 'short':
            case 'sell':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const getStatusBadgeClass = (status) => {
        switch (status?.toLowerCase()) {
            case 'active':
            case 'success':
                return 'badge-success';
            case 'pending':
            case 'warning':
                return 'badge-warning';
            case 'error':
            case 'failed':
                return 'badge-danger';
            default:
                return 'badge-secondary';
        }
    };

    const highlightText = (text, query) => {
        if (!query || !text) return text;
        
        const regex = new RegExp(`(${query})`, 'gi');
        const parts = text.split(regex);
        
        return parts.map((part, index) => 
            regex.test(part) ? (
                <mark key={index} className="search-highlight">{part}</mark>
            ) : part
        );
    };

    const getTotalResults = () => {
        return searchResults.contracts.length + 
               searchResults.signals.length + 
               searchResults.audits.length;
    };

    return (
        <div className="global-search-page">
            <div className="page-header">
                <h1>Recherche Globale</h1>
                <p className="page-subtitle">Rechercher rapidement un symbole, un signal ou un audit depuis une barre de recherche</p>
            </div>

            {/* Barre de recherche */}
            <div className="search-section">
                <div className="search-container">
                    <div className="search-input-group">
                        <input
                            type="text"
                            placeholder="Rechercher un symbole, signal ou audit..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="search-input"
                            autoFocus
                        />
                        {searchQuery && (
                            <button 
                                className="search-clear"
                                onClick={clearSearch}
                                title="Effacer la recherche"
                            >
                                ×
                            </button>
                        )}
                    </div>
                    {loading && (
                        <div className="search-loading">
                            <span>Recherche en cours...</span>
                        </div>
                    )}
                </div>
            </div>

            {/* Erreur */}
            {error && (
                <div className="alert alert-danger">
                    {error}
                </div>
            )}

            {/* Résultats de recherche */}
            {hasSearched && (
                <div className="search-results">
                    <div className="results-header">
                        <h3>
                            Résultats de recherche pour "{searchQuery}"
                            {getTotalResults() > 0 && (
                                <span className="results-count">({getTotalResults()} résultat{getTotalResults() > 1 ? 's' : ''})</span>
                            )}
                        </h3>
                    </div>

                    {getTotalResults() === 0 ? (
                        <div className="no-results">
                            <div className="no-results-icon">🔍</div>
                            <h4>Aucun résultat trouvé</h4>
                            <p>Essayez avec d'autres termes de recherche ou vérifiez l'orthographe.</p>
                        </div>
                    ) : (
                        <div className="results-grid">
                            {/* Contrats */}
                            {searchResults.contracts.length > 0 && (
                                <div className="results-category">
                                    <h4>
                                        <span className="category-icon">📊</span>
                                        Contrats ({searchResults.contracts.length})
                                    </h4>
                                    <div className="results-list">
                                        {searchResults.contracts.map((contract) => (
                                            <div key={contract.id} className="result-item contract-item">
                                                <div className="result-header">
                                                    <span className="symbol-badge">
                                                        {highlightText(contract.symbol, searchQuery)}
                                                    </span>
                                                    <span className={`badge ${getStatusBadgeClass(contract.status)}`}>
                                                        {contract.status}
                                                    </span>
                                                </div>
                                                <div className="result-details">
                                                    <div className="detail-item">
                                                        <span className="detail-label">Tick Size:</span>
                                                        <span>{contract.tickSize}</span>
                                                    </div>
                                                    <div className="detail-item">
                                                        <span className="detail-label">Taille Min/Max:</span>
                                                        <span>{contract.minSize} / {contract.maxSize}</span>
                                                    </div>
                                                    <div className="detail-item">
                                                        <span className="detail-label">Multiplicateur:</span>
                                                        <span>{contract.multiplier}</span>
                                                    </div>
                                                </div>
                                                <div className="result-actions">
                                                    <Link 
                                                        to={`/contracts/${contract.id}`}
                                                        className="btn btn-sm btn-outline-primary"
                                                    >
                                                        Voir le contrat
                                                    </Link>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Signaux */}
                            {searchResults.signals.length > 0 && (
                                <div className="results-category">
                                    <h4>
                                        <span className="category-icon">📡</span>
                                        Signaux ({searchResults.signals.length})
                                    </h4>
                                    <div className="results-list">
                                        {searchResults.signals.map((signal) => (
                                            <div key={signal.id} className="result-item signal-item">
                                                <div className="result-header">
                                                    <span className="symbol-badge">
                                                        {highlightText(signal.symbol, searchQuery)}
                                                    </span>
                                                    <span className={`badge ${getTimeframeBadgeClass(signal.timeframe)}`}>
                                                        {signal.timeframe}
                                                    </span>
                                                    <span className={`badge ${getSideBadgeClass(signal.side)}`}>
                                                        {signal.side}
                                                    </span>
                                                </div>
                                                <div className="result-details">
                                                    <div className="detail-item">
                                                        <span className="detail-label">Score:</span>
                                                        <span>{signal.score || '-'}</span>
                                                    </div>
                                                    <div className="detail-item">
                                                        <span className="detail-label">Date Kline:</span>
                                                        <span>{formatDate(signal.klineTime)}</span>
                                                    </div>
                                                </div>
                                                <div className="result-actions">
                                                    <Link 
                                                        to={`/signals?symbol=${signal.symbol}&timeframe=${signal.timeframe}`}
                                                        className="btn btn-sm btn-outline-primary"
                                                    >
                                                        Voir les signaux
                                                    </Link>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Plans d'ordres */}
                            {/* Audits */}
                            {searchResults.audits.length > 0 && (
                                <div className="results-category">
                                    <h4>
                                        <span className="category-icon">📝</span>
                                        Audits ({searchResults.audits.length})
                                    </h4>
                                    <div className="results-list">
                                        {searchResults.audits.map((audit) => (
                                            <div key={audit.id} className="result-item audit-item">
                                                <div className="result-header">
                                                    <span className="symbol-badge">
                                                        {highlightText(audit.symbol, searchQuery)}
                                                    </span>
                                                    <span className={`badge ${getTimeframeBadgeClass(audit.timeframe)}`}>
                                                        {audit.timeframe}
                                                    </span>
                                                    <span className={`badge ${getStatusBadgeClass(audit.verdict)}`}>
                                                        {audit.verdict}
                                                    </span>
                                                </div>
                                                <div className="result-details">
                                                    <div className="detail-item">
                                                        <span className="detail-label">Étape:</span>
                                                        <span>{audit.step}</span>
                                                    </div>
                                                    <div className="detail-item">
                                                        <span className="detail-label">Horodatage:</span>
                                                        <span>{formatDate(audit.timestamp)}</span>
                                                    </div>
                                                </div>
                                                <div className="result-actions">
                                                    <Link 
                                                        to={`/mtf-audit?symbol=${audit.symbol}&run_id=${audit.runId}`}
                                                        className="btn btn-sm btn-outline-primary"
                                                    >
                                                        Voir l'audit
                                                    </Link>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Suggestions de recherche */}
            {!hasSearched && (
                <div className="search-suggestions">
                    <h3>Suggestions de recherche</h3>
                    <div className="suggestions-grid">
                        <div className="suggestion-card">
                            <h4>Symboles</h4>
                            <p>Recherchez par nom de symbole (ex: BTCUSDT, ETHUSDT)</p>
                        </div>
                        <div className="suggestion-card">
                            <h4>Signaux</h4>
                            <p>Trouvez des signaux par symbole, timeframe ou côté</p>
                        </div>
                        <div className="suggestion-card">
                            <h4>Plans d'ordres</h4>
                            <p>Recherchez des plans d'ordres par symbole ou statut</p>
                        </div>
                        <div className="suggestion-card">
                            <h4>Audits</h4>
                            <p>Consultez les audits MTF par symbole ou run_id</p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default GlobalSearchPage;
