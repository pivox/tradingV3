// src/pages/PipelinePage.js
import React, { useState, useEffect } from 'react';
import api from '../services/api';

const PipelinePage = () => {
    const [pipelines, setPipelines] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [activeFilter, setActiveFilter] = useState('all');

    useEffect(() => {
        setLoading(true);
        api.getPipeline(activeFilter)
            .then(data => {
                setPipelines(data);
                setError(null);
            })
            .catch(err => {
                setError(`Erreur de chargement des pipelines: ${err.message}`);
                console.error(err);
            })
            .finally(() => setLoading(false));
    }, [activeFilter]);

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
            </div>

            {error && <div className="error">{error}</div>}

            {loading ? (
                <div className="loading">Chargement des pipelines...</div>
            ) : pipelines.length > 0 ? (
                <div className="pipeline-list">
                    {pipelines.map(pipeline => (
                        <div
                            key={pipeline.id}
                            className={`pipeline-card status-${pipeline.status}`}
                        >
                            <h3>{pipeline.contract.symbol}</h3>

                            <div className="pipeline-stages">
                                {pipeline.stages.map((stage, index) => (
                                    <div
                                        key={index}
                                        className={`pipeline-stage ${stage.status}`}
                                    >
                                        {stage.name}
                                    </div>
                                ))}
                            </div>

                            <div className="pipeline-details">
                                <div className="detail-group">
                                    <span>Date de début:</span>
                                    <span>{new Date(pipeline.start_time).toLocaleString()}</span>
                                </div>
                                <div className="detail-group">
                                    <span>Date de fin:</span>
                                    <span>
                    {pipeline.end_time ? new Date(pipeline.end_time).toLocaleString() : '-'}
                  </span>
                                </div>
                                <div className="detail-group">
                                    <span>Durée:</span>
                                    <span>{pipeline.duration || '-'}</span>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="no-data">Aucun pipeline trouvé</div>
            )}
        </div>
    );
};

export default PipelinePage;