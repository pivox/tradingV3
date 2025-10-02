import React, { useEffect, useState, useCallback } from 'react';
import api from '../services/api';

const normalizeAccounts = (payload) => {
    if (Array.isArray(payload)) {
        return payload;
    }
    if (payload && Array.isArray(payload.data)) {
        return payload.data;
    }
    return [];
};

const formatDateTime = (value) => {
    if (!value) {
        return '—';
    }
    try {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    } catch (error) {
        return value;
    }
};

const initialForm = {
    userId: '',
    exchange: '',
    availableBalance: '',
    balance: '',
    lastBalanceSyncAt: '',
    lastOrderSyncAt: ''
};

const toNullableNumber = (value) => {
    if (value === '' || value === null || value === undefined) {
        return null;
    }
    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

const ExchangeAccountsPage = () => {
    const [accounts, setAccounts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [formData, setFormData] = useState(initialForm);
    const [editingId, setEditingId] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const loadAccounts = useCallback(async () => {
        setLoading(true);
        setError(null);
        setSuccess(null);
        try {
            const response = await api.getExchangeAccounts();
            setAccounts(normalizeAccounts(response));
        } catch (err) {
            setError(err.message ?? "Impossible de charger les comptes d'échange");
            setAccounts([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadAccounts();
    }, [loadAccounts]);

    const resetForm = () => {
        setFormData(initialForm);
        setEditingId(null);
        setSubmitting(false);
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setFormData((prev) => ({ ...prev, [name]: value }));
    };

    const handleEdit = (account) => {
        setEditingId(account.id);
        setFormData({
            userId: account.user_id ?? account.userId ?? '',
            exchange: account.exchange ?? '',
            availableBalance: account.available_balance ?? account.availableBalance ?? '',
            balance: account.balance ?? '',
            lastBalanceSyncAt: account.last_balance_sync_at ?? account.lastBalanceSyncAt ?? '',
            lastOrderSyncAt: account.last_order_sync_at ?? account.lastOrderSyncAt ?? ''
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        setSuccess(null);

        const payload = {
            user_id: formData.userId.trim(),
            exchange: formData.exchange.trim(),
            available_balance: toNullableNumber(formData.availableBalance),
            balance: toNullableNumber(formData.balance),
            last_balance_sync_at: formData.lastBalanceSyncAt.trim() !== '' ? formData.lastBalanceSyncAt.trim() : null,
            last_order_sync_at: formData.lastOrderSyncAt.trim() !== '' ? formData.lastOrderSyncAt.trim() : null
        };

        if (!payload.user_id || !payload.exchange) {
            setError("L'utilisateur et l'exchange sont obligatoires");
            setSubmitting(false);
            return;
        }

        try {
            if (editingId !== null) {
                await api.updateExchangeAccount(editingId, payload);
                setSuccess('Compte mis à jour avec succès.');
            } else {
                await api.createExchangeAccount(payload);
                setSuccess('Compte créé avec succès.');
            }
            resetForm();
            await loadAccounts();
        } catch (err) {
            setError(err.message ?? "Échec de l'enregistrement du compte");
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div>
            <div className="card" style={{ marginBottom: '20px' }}>
                <h2>{editingId ? 'Modifier un compte exchange' : 'Ajouter un compte exchange'}</h2>
                <p>Persisté côté backend pour suivre les soldes synchronisés.</p>

                <form onSubmit={handleSubmit} className="accounts-form" style={{ display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
                    <label>
                        Utilisateur (user_id)
                        <input
                            name="userId"
                            type="text"
                            placeholder="ex: system"
                            value={formData.userId}
                            onChange={handleChange}
                            required
                        />
                    </label>

                    <label>
                        Exchange
                        <input
                            name="exchange"
                            type="text"
                            placeholder="ex: bitmart"
                            value={formData.exchange}
                            onChange={handleChange}
                            required
                        />
                    </label>

                    <label>
                        Balance disponible
                        <input
                            name="availableBalance"
                            type="number"
                            step="0.00000001"
                            value={formData.availableBalance}
                            onChange={handleChange}
                        />
                    </label>

                    <label>
                        Balance totale
                        <input
                            name="balance"
                            type="number"
                            step="0.00000001"
                            value={formData.balance}
                            onChange={handleChange}
                        />
                    </label>

                    <label>
                        Dernière sync balance (ISO8601)
                        <input
                            name="lastBalanceSyncAt"
                            type="text"
                            placeholder="2024-10-01T12:34:56Z"
                            value={formData.lastBalanceSyncAt}
                            onChange={handleChange}
                        />
                    </label>

                    <label>
                        Dernière sync ordres (ISO8601)
                        <input
                            name="lastOrderSyncAt"
                            type="text"
                            placeholder="2024-10-01T12:34:56Z"
                            value={formData.lastOrderSyncAt}
                            onChange={handleChange}
                        />
                    </label>

                    <div style={{ display: 'flex', gap: '10px', gridColumn: '1 / -1' }}>
                        <button className="btn btn-primary" type="submit" disabled={submitting}>
                            {submitting ? 'Enregistrement...' : editingId ? 'Mettre à jour' : 'Créer'}
                        </button>
                        {editingId && (
                            <button className="btn" type="button" onClick={resetForm} disabled={submitting}>
                                Annuler
                            </button>
                        )}
                        <button className="btn" type="button" onClick={loadAccounts} disabled={loading || submitting}>
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

            {!loading && accounts.length === 0 && !error && (
                <div className="card">
                    <p>Aucun compte enregistré pour le moment.</p>
                </div>
            )}

            {accounts.length > 0 && (
                <div className="card">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Exchange</th>
                                <th>Balance disponible</th>
                                <th>Balance totale</th>
                                <th>Sync balance</th>
                                <th>Sync ordres</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr key={`${account.id ?? `${account.userId}-${account.exchange}`}`}>
                                    <td>{account.userId ?? account.user_id ?? '—'}</td>
                                    <td>{account.exchange ?? '—'}</td>
                                    <td>{account.availableBalance ?? account.available_balance ?? '—'}</td>
                                    <td>{account.balance ?? '—'}</td>
                                    <td>{formatDateTime(account.lastBalanceSyncAt ?? account.last_balance_sync_at)}</td>
                                    <td>{formatDateTime(account.lastOrderSyncAt ?? account.last_order_sync_at)}</td>
                                    <td>
                                        <button className="btn" type="button" onClick={() => handleEdit(account)}>
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

export default ExchangeAccountsPage;
