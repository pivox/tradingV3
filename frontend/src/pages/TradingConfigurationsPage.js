import React, { useEffect, useState, useCallback } from 'react';
import api from '../services/api';

const normalizeConfigurations = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return {
            account: { id: null, budget_cap_usdt: null, risk_abs_usdt: null, tp_abs_usdt: null },
            items: [],
        };
    }

    const account = payload.account ?? {};
    const items = Array.isArray(payload.items) ? payload.items : [];

    return {
        account: {
            id: account.id ?? null,
            budget_cap_usdt: account.budget_cap_usdt ?? account.budgetCapUsdt ?? null,
            risk_abs_usdt: account.risk_abs_usdt ?? account.riskAbsUsdt ?? null,
            tp_abs_usdt: account.tp_abs_usdt ?? account.tpAbsUsdt ?? null,
        },
        items,
    };
};

const contexts = [
    { value: 'strategy', label: 'Stratégie' },
    { value: 'execution', label: 'Exécution' },
    { value: 'security', label: 'Sécurité' }
];

const initialAccountForm = {
    budgetCapUsdt: '',
    riskAbsUsdt: '',
    tpAbsUsdt: ''
};

const initialContextForm = {
    context: contexts[0].value,
    scope: '',
    bannedContracts: ''
};

const toNullableNumber = (value) => {
    if (value === '' || value === null || value === undefined) {
        return null;
    }
    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

const toArrayFromString = (value) => {
    if (!value) {
        return [];
    }
    return value
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item.length > 0);
};

const formatBannedForInput = (value) => {
    if (!value) {
        return '';
    }
    if (Array.isArray(value)) {
        return value.join(', ');
    }
    return value;
};

const toInputValue = (value) => {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value);
};

const TradingConfigurationsPage = () => {
    const [contextsData, setContextsData] = useState([]);
    const [account, setAccount] = useState({ id: null, budget_cap_usdt: null, risk_abs_usdt: null, tp_abs_usdt: null });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [accountForm, setAccountForm] = useState(initialAccountForm);
    const [accountSubmitting, setAccountSubmitting] = useState(false);
    const [contextForm, setContextForm] = useState(initialContextForm);
    const [contextEditingId, setContextEditingId] = useState(null);
    const [contextSubmitting, setContextSubmitting] = useState(false);

    const loadConfigurations = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await api.getTradingConfigurations();
            const normalized = normalizeConfigurations(response);
            setAccount(normalized.account);
            setAccountForm({
                budgetCapUsdt: toInputValue(normalized.account.budget_cap_usdt),
                riskAbsUsdt: toInputValue(normalized.account.risk_abs_usdt),
                tpAbsUsdt: toInputValue(normalized.account.tp_abs_usdt),
            });
            setContextsData(normalized.items);
        } catch (err) {
            setError(err.message ?? 'Impossible de charger les configurations');
            setAccount({ id: null, budget_cap_usdt: null, risk_abs_usdt: null, tp_abs_usdt: null });
            setAccountForm(initialAccountForm);
            setContextsData([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadConfigurations();
    }, [loadConfigurations]);

    const resetContextForm = () => {
        setContextForm(initialContextForm);
        setContextEditingId(null);
        setContextSubmitting(false);
    };

    const handleContextChange = (event) => {
        const { name, value } = event.target;
        setContextForm((prev) => ({ ...prev, [name]: value }));
    };

    const handleContextEdit = (config) => {
        setContextEditingId(config.id);
        setContextForm({
            context: config.context ?? initialContextForm.context,
            scope: config.scope ?? '',
            bannedContracts: formatBannedForInput(config.banned_contracts ?? config.bannedContracts)
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleAccountChange = (event) => {
        const { name, value } = event.target;
        setAccountForm((prev) => ({ ...prev, [name]: value }));
    };

    const submitAccount = async (event) => {
        event.preventDefault();
        setAccountSubmitting(true);
        setError(null);
        setSuccess(null);

        const payload = {
            context: 'global',
            scope: null,
            budget_cap_usdt: toNullableNumber(accountForm.budgetCapUsdt),
            risk_abs_usdt: toNullableNumber(accountForm.riskAbsUsdt),
            tp_abs_usdt: toNullableNumber(accountForm.tpAbsUsdt)
        };

        try {
            if (account.id !== null) {
                await api.updateTradingConfiguration(account.id, payload);
                setSuccess('Paramètres globaux mis à jour.');
            } else {
                const created = await api.createTradingConfiguration(payload);
                setSuccess('Paramètres globaux créés avec succès.');
                setAccount({
                    id: created.id ?? null,
                    budget_cap_usdt: created.budget_cap_usdt ?? null,
                    risk_abs_usdt: created.risk_abs_usdt ?? null,
                    tp_abs_usdt: created.tp_abs_usdt ?? null,
                });
            }
            await loadConfigurations();
        } catch (err) {
            setError(err.message ?? "Échec de l'enregistrement");
        } finally {
            setAccountSubmitting(false);
        }
    };

    const submitContext = async (event) => {
        event.preventDefault();
        setContextSubmitting(true);
        setError(null);
        setSuccess(null);

        const payload = {
            context: contextForm.context,
            scope: contextForm.scope.trim() !== '' ? contextForm.scope.trim() : null,
            banned_contracts: toArrayFromString(contextForm.bannedContracts)
        };

        try {
            if (contextEditingId !== null) {
                await api.updateTradingConfiguration(contextEditingId, payload);
                setSuccess('Configuration mise à jour avec succès.');
            } else {
                await api.createTradingConfiguration(payload);
                setSuccess('Configuration créée avec succès.');
            }
            resetContextForm();
            await loadConfigurations();
        } catch (err) {
            setError(err.message ?? "Échec de l'enregistrement de la configuration");
        } finally {
            setContextSubmitting(false);
        }
    };

    return (
        <div>
            <div className="card" style={{ marginBottom: '20px' }}>
                <h2>Paramètres globaux du compte</h2>
                <p>Budget et risk management appliqués à l'ensemble du compte.</p>

                <form onSubmit={submitAccount} className="config-form" style={{ display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
                    <label>
                        Budget Cap (USDT)
                        <input
                            name="budgetCapUsdt"
                            type="number"
                            step="0.01"
                            value={accountForm.budgetCapUsdt}
                            onChange={handleAccountChange}
                        />
                    </label>

                    <label>
                        Risk Abs (USDT)
                        <input
                            name="riskAbsUsdt"
                            type="number"
                            step="0.01"
                            value={accountForm.riskAbsUsdt}
                            onChange={handleAccountChange}
                        />
                    </label>

                    <label>
                        TP Abs (USDT)
                        <input
                            name="tpAbsUsdt"
                            type="number"
                            step="0.01"
                            value={accountForm.tpAbsUsdt}
                            onChange={handleAccountChange}
                        />
                    </label>

                    <div style={{ display: 'flex', gap: '10px', gridColumn: '1 / -1' }}>
                        <button className="btn btn-primary" type="submit" disabled={accountSubmitting}>
                            {accountSubmitting ? 'Enregistrement...' : 'Enregistrer'}
                        </button>
                        <button
                            className="btn"
                            type="button"
                            onClick={() => {
                                setSuccess(null);
                                void loadConfigurations();
                            }}
                            disabled={loading || accountSubmitting}
                        >
                            {loading ? 'Chargement...' : 'Rafraîchir'}
                        </button>
                    </div>
                </form>
            </div>

            <div className="card" style={{ marginBottom: '20px' }}>
                <h2>{contextEditingId ? 'Modifier un groupe' : 'Ajouter un groupe'}</h2>
                <p>Regrouper l'affichage et définir les contrats bannis par contexte.</p>

                <form onSubmit={submitContext} className="config-form" style={{ display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
                    <label>
                        Contexte
                        <select name="context" value={contextForm.context} onChange={handleContextChange} required>
                            {contexts.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                    </label>

                    <label>
                        Scope
                        <input
                            name="scope"
                            type="text"
                            placeholder="ex: BTCUSDT, 15m..."
                            value={contextForm.scope}
                            onChange={handleContextChange}
                        />
                    </label>

                    <label style={{ gridColumn: '1 / -1' }}>
                        Contrats bannis (séparés par des virgules)
                        <textarea
                            name="bannedContracts"
                            rows={2}
                            placeholder="ex: BTCUSDT, ETHUSDT"
                            value={contextForm.bannedContracts}
                            onChange={handleContextChange}
                        />
                    </label>

                    <div style={{ display: 'flex', gap: '10px', gridColumn: '1 / -1' }}>
                        <button className="btn btn-primary" type="submit" disabled={contextSubmitting}>
                            {contextSubmitting ? 'Enregistrement...' : contextEditingId ? 'Mettre à jour' : 'Créer'}
                        </button>
                        {contextEditingId && (
                            <button className="btn" type="button" onClick={resetContextForm} disabled={contextSubmitting}>
                                Annuler
                            </button>
                        )}
                        <button
                            className="btn"
                            type="button"
                            onClick={() => {
                                setSuccess(null);
                                void loadConfigurations();
                            }}
                            disabled={loading || contextSubmitting}
                        >
                            {loading ? 'Chargement...' : 'Rafraîchir'}
                        </button>
                    </div>
                </form>
            </div>

            {error && (
                <div className="card" style={{ borderLeft: '4px solid var(--danger-color)' }}>
                    <strong>Erreur :</strong> {error}
                </div>
            )}

            {success && (
                <div className="card" style={{ borderLeft: '4px solid var(--success-color)' }}>
                    {success}
                </div>
            )}

            {!loading && contextsData.length === 0 && !error && (
                <div className="card">
                    <p>Aucune configuration disponible pour le moment.</p>
                </div>
            )}

            {contextsData.length > 0 && (
                <div className="card">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Contexte</th>
                                <th>Scope</th>
                                <th>Contrats bannis</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contextsData.map((cfg) => (
                                <tr key={`${cfg.id ?? `${cfg.context}-${cfg.scope ?? 'global'}`}`}>
                                    <td>{cfg.context ?? '—'}</td>
                                    <td>{cfg.scope ?? 'Global'}</td>
                                    <td>{formatBannedForInput(cfg.banned_contracts ?? cfg.bannedContracts)}</td>
                                    <td>
                                        <button className="btn" type="button" onClick={() => handleContextEdit(cfg)}>
                                            Modifier
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
};

export default TradingConfigurationsPage;
