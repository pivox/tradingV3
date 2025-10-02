// src/pages/PipelinePage.js
import React, { useMemo, useState, useEffect, useRef } from 'react';
import api from '../services/api';

const TIMEFRAME_PRIORITY = ['4h', '1h', '15m', '5m', '1m'];

const PipelinePage = () => {
    const [pipelines, setPipelines] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [activeFilter, setActiveFilter] = useState('all');
    const [viewMode, setViewMode] = useState('cards');
    const [lastUpdated, setLastUpdated] = useState(null);

    const isFetchingRef = useRef(false);
    const prevOrderRef = useRef({});
    const intervalRef = useRef(null);

    useEffect(() => {
        fetchPipelines(true);

        if (intervalRef.current) {
            clearInterval(intervalRef.current);
        }
        intervalRef.current = setInterval(() => {
            fetchPipelines(false);
        }, 60_000);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeFilter]);

    const fetchPipelines = async (withSpinner) => {
        if (isFetchingRef.current) {
            return;
        }
        isFetchingRef.current = true;
        if (withSpinner) {
            setLoading(true);
        }

        try {
            const data = await api.getPipeline(activeFilter);
            const sorted = sortPipelines(data ?? []);
            const enriched = addMovementFlag(sorted);
            setPipelines(enriched);
            setError(null);
            setLastUpdated(new Date());
        } catch (err) {
            console.error('Erreur de chargement des pipelines:', err);
            setError(`Erreur de chargement des pipelines: ${err.message}`);
        } finally {
            if (withSpinner) {
                setLoading(false);
            }
            isFetchingRef.current = false;
        }
    };

    const sortPipelines = (list) => {
        const priority = TIMEFRAME_PRIORITY.reduce((acc, tf, index) => {
            acc[tf] = index;
            return acc;
        }, {});

        return [...list].sort((a, b) => {
            const tfA = priority[a.current_timeframe] ?? TIMEFRAME_PRIORITY.length;
            const tfB = priority[b.current_timeframe] ?? TIMEFRAME_PRIORITY.length;
            if (tfA !== tfB) {
                return tfA - tfB;
            }
            const symbolA = a.contract?.symbol ?? '';
            const symbolB = b.contract?.symbol ?? '';
            return symbolA.localeCompare(symbolB);
        });
    };

    const addMovementFlag = (list) => {
        const previousOrder = prevOrderRef.current;
        const nextOrder = {};

        const enriched = list.map((item, index) => {
            const key = item.id ?? item.contract?.symbol ?? `pipeline-${index}`;
            const prevIndex = previousOrder[key];
            let movement = 'none';
            if (typeof prevIndex === 'number') {
                if (prevIndex > index) {
                    movement = 'up';
                } else if (prevIndex < index) {
                    movement = 'down';
                }
            }
            nextOrder[key] = index;
            return {
                ...item,
                _key: key,
                _movement: movement
            };
        });

        prevOrderRef.current = nextOrder;
        return enriched;
    };

    const pipelinesForDisplay = useMemo(() => pipelines, [pipelines]);

    return (
        <div className="pipeline-page">
            <h1>Pipelines de Traitement</h1>

            <div className="filters">
                <button
                    className={activeFilter === 'all' ? 'active' : ''}
                    onClick={() => setActiveFilter('all')}
                >
                    Tous
                </button>
                <button
                    className={activeFilter === 'completed' ? 'active' : ''}
                    onClick={() => setActiveFilter('completed')}
                >
                    Terminés
                </button>
                <button
                    className={activeFilter === 'in-progress' ? 'active' : ''}
                    onClick={() => setActiveFilter('in-progress')}
                >
                    En cours
                </button>
                <button
                    className={activeFilter === 'failed' ? 'active' : ''}
                    onClick={() => setActiveFilter('failed')}
                >
                    Échoués
                </button>
                <div className="view-toggle">
                    <button
                        className={viewMode === 'cards' ? 'active' : ''}
                        onClick={() => setViewMode('cards')}
                    >
                        Cartes
                    </button>
                    <button
                        className={viewMode === 'table' ? 'active' : ''}
                        onClick={() => setViewMode('table')}
                    >
                        Tableau
                    </button>
                </div>
            </div>

            {lastUpdated && (
                <div className="last-updated">
                    Dernière mise à jour : {lastUpdated.toLocaleTimeString()}
                </div>
            )}

            {error && <div className="error">{error}</div>}

            {loading ? (
                <div className="loading">Chargement des pipelines...</div>
            ) : pipelinesForDisplay.length > 0 ? (
                viewMode === 'table'
                    ? <PipelineTable pipelines={pipelinesForDisplay} />
                    : <PipelineCards pipelines={pipelinesForDisplay} />
            ) : (
                <div className="no-data">Aucun pipeline trouvé</div>
            )}
        </div>
    );
};

const PipelineCards = ({ pipelines }) => (
    <div className="pipeline-list">
        {pipelines.map(pipeline => (
            <div
                key={pipeline._key}
                className={`pipeline-card status-${pipeline.status}`}
            >
                <h3>{pipeline.contract.symbol}</h3>

                <div className="pipeline-stages">
                    {pipeline.stages.map((stage, index) => (
                        <div
                            key={`${pipeline._key}-stage-${index}`}
                            className={`pipeline-stage ${stage.status}`}
                        >
                            {stage.name}
                        </div>
                    ))}
                </div>

                <div className="pipeline-details">
                    <div className="detail-group">
                        <span>Timeframe actuel:</span>
                        <span>{pipeline.current_timeframe}</span>
                    </div>
                    <div className="detail-group">
                        <span>Statut:</span>
                        <span>{pipeline.status}</span>
                    </div>
                    <div className="detail-group">
                        <span>Retries:</span>
                        <span>{pipeline.retries}/{pipeline.max_retries}</span>
                    </div>
                    <div className="detail-group">
                        <span>Dernière mise à jour:</span>
                        <span>{pipeline.updated_at ? new Date(pipeline.updated_at).toLocaleString() : '-'}</span>
                    </div>
                </div>
            </div>
        ))}
    </div>
);

const PipelineTable = ({ pipelines }) => (
    <div className="pipeline-table-wrapper">
        <table className="pipeline-table">
            <thead>
            <tr>
                <th>Contrat</th>
                <th>Timeframe</th>
                <th>Statut</th>
                <th>Retries</th>
                <th>Order</th>
                <th>Étapes</th>
                <th>Dernière mise à jour</th>
            </tr>
            </thead>
            <tbody>
            {pipelines.map((pipeline, index) => (
                <tr
                    key={pipeline._key}
                    className={`pipeline-row movement-${pipeline._movement}`}
                    data-index={index}
                >
                    <td>{pipeline.contract.symbol}</td>
                    <td>{pipeline.current_timeframe}</td>
                    <td className={`status-${pipeline.status}`}>{pipeline.status}</td>
                    <td>{pipeline.retries}/{pipeline.max_retries}</td>
                    <td>{pipeline.order_id || '-'}</td>
                    <td>
                        <div className="pipeline-stages-inline">
                            {pipeline.stages.map((stage, idx) => (
                                <span
                                    key={`${pipeline._key}-inline-${idx}`}
                                    className={`stage-pill ${stage.status}`}
                                >
                                    {stage.timeframe || stage.name}
                                </span>
                            ))}
                        </div>
                    </td>
                    <td>{pipeline.updated_at ? new Date(pipeline.updated_at).toLocaleString() : '-'}</td>
                </tr>
            ))}
            </tbody>
        </table>
    </div>
);

export default PipelinePage;
