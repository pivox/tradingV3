// src/pages/OrchestrationCockpitPage.js
//
// Cockpit minimal d'orchestration (UI-001).
//
// Pilote l'API Python Orchestrator : sélection d'un dashboard, liste des sets
// configurés, preview du plan d'exécution, déclenchement d'un run et lecture du
// dernier JSON (résumé global + détail / erreurs par set). Conforme à
// docs/handbook/functional/orchestration-dashboard.md.
import React, { useCallback, useEffect, useRef, useState } from 'react';
import api from '../services/api';

// Exchanges verrouillés en dry-run uniquement (cf. garde-fous fonctionnels).
const LIVE_FORBIDDEN_EXCHANGES = ['okx', 'hyperliquid'];

const STATUS_BADGE_CLASS = {
    success: 'badge-success',
    partial_failure: 'badge-warning',
    failed: 'badge-danger',
    no_sets: 'badge-secondary'
};

const formatDate = (value) => (value ? new Date(value).toLocaleString('fr-FR') : '-');

const formatDuration = (ms) => {
    if (ms === null || ms === undefined) return '-';
    if (ms < 1000) return `${ms} ms`;
    return `${(ms / 1000).toFixed(1)} s`;
};

const OrchestrationCockpitPage = () => {
    const [dashboards, setDashboards] = useState([]);
    const [selectedDashboardId, setSelectedDashboardId] = useState('');
    const [sets, setSets] = useState([]);
    const [latestRun, setLatestRun] = useState(null);

    const [loadingDashboards, setLoadingDashboards] = useState(true);
    const [loadingSets, setLoadingSets] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [running, setRunning] = useState(false);

    const [forceDryRun, setForceDryRun] = useState(false);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);

    // Jeton monotone de requête : un chargement de détail (sets + dernier run)
    // n'applique son résultat que s'il est toujours le plus récent. Évite qu'une
    // réponse lente d'un dashboard précédent écrase celle du dashboard courant.
    const detailRequestRef = useRef(0);

    // 1) Chargement initial de la liste des dashboards.
    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoadingDashboards(true);
            setError(null);
            try {
                const data = await api.getOrchestrationDashboards();
                if (cancelled) return;
                const list = Array.isArray(data) ? data : [];
                setDashboards(list);
                if (list.length > 0) {
                    setSelectedDashboardId(String(list[0].id));
                }
            } catch (err) {
                if (!cancelled) setError(`Impossible de charger les dashboards : ${err.message}`);
            } finally {
                if (!cancelled) setLoadingDashboards(false);
            }
        })();
        return () => { cancelled = true; };
    }, []);

    // 2) Chargement des sets + dernier run du dashboard sélectionné.
    const loadDashboardDetail = useCallback(async (dashboardId) => {
        if (!dashboardId) {
            setSets([]);
            setLatestRun(null);
            return;
        }
        const token = ++detailRequestRef.current;
        const isStale = () => token !== detailRequestRef.current;
        // Vide le détail dès le début du chargement : sinon la preview et
        // `runnableSets` refléteraient l'ancien dashboard pendant la requête (ou
        // indéfiniment si elle échoue), au risque de lancer un run sur des données
        // périmées. Combiné au gating sur `loadingSets`, les actions sont neutres.
        setSets([]);
        setLatestRun(null);
        setLoadingSets(true);
        setError(null);
        try {
            const [setsData, runData] = await Promise.all([
                api.getOrchestrationSets(dashboardId),
                api.getOrchestrationLatestRun(dashboardId)
            ]);
            // Une sélection plus récente a été lancée entre-temps : on ignore.
            if (isStale()) return;
            setSets(Array.isArray(setsData) ? setsData : []);
            setLatestRun(runData);
        } catch (err) {
            if (isStale()) return;
            setError(`Impossible de charger le dashboard : ${err.message}`);
        } finally {
            if (!isStale()) setLoadingSets(false);
        }
    }, []);

    useEffect(() => {
        loadDashboardDetail(selectedDashboardId);
    }, [selectedDashboardId, loadDashboardDetail]);

    const selectedDashboard = dashboards.find((d) => String(d.id) === String(selectedDashboardId));
    // Le flag `enabled` du dashboard est un interrupteur de pause global côté
    // orchestrateur : un dashboard désactivé renvoie toujours `no_sets` (0 appel).
    // On le reflète dans la preview / le bouton pour ne pas proposer un run vide.
    const dashboardEnabled = selectedDashboard ? selectedDashboard.enabled : false;

    // Preview : seuls les sets actifs `mtf_run` d'un dashboard actif sont exécutés.
    const runnableSets = dashboardEnabled
        ? sets.filter((s) => s.enabled && s.action === 'mtf_run')
        : [];
    const exchanges = [...new Set(runnableSets.map((s) => s.exchange))];
    const liveSets = runnableSets.filter((s) => !s.dry_run && !forceDryRun);
    const bitmartLiveSets = liveSets.filter((s) => s.exchange === 'bitmart');
    const forbiddenLiveSets = liveSets.filter((s) => LIVE_FORBIDDEN_EXCHANGES.includes(s.exchange));

    const handleRefreshContracts = async () => {
        if (!selectedDashboardId) return;
        setRefreshing(true);
        setError(null);
        setNotice(null);
        try {
            const result = await api.refreshOrchestrationContracts(selectedDashboardId);
            setNotice(`Contrats rafraîchis : ${result.count} set(s) mis à jour.`);
            await loadDashboardDetail(selectedDashboardId);
        } catch (err) {
            setError(`Refresh des contrats échoué : ${err.message}`);
        } finally {
            setRefreshing(false);
        }
    };

    const handleRun = async () => {
        if (!selectedDashboardId) return;
        setRunning(true);
        setError(null);
        setNotice(null);
        try {
            const result = await api.triggerOrchestrationRun(selectedDashboardId, { forceDryRun });
            const { ok, run_id: runId, status, summary } = result;
            setNotice(
                `Run ${runId} terminé (${status}) — ${ok ? 'OK' : 'NON OK'} : ` +
                `${summary.success}/${summary.total_calls} réussis, ${summary.failed} échec(s).`
            );
            await loadDashboardDetail(selectedDashboardId);
        } catch (err) {
            setError(`Déclenchement du run échoué : ${err.message}`);
        } finally {
            setRunning(false);
        }
    };

    const busy = refreshing || running;

    return (
        <div className="orchestration-cockpit-page">
            <div className="page-header">
                <h1>Cockpit d'orchestration</h1>
                <p className="page-subtitle">
                    Configurer les sets, déclencher un run et visualiser le dernier résultat JSON
                    de l'API Python Orchestrator.
                </p>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {notice && <div className="alert alert-success">{notice}</div>}

            {/* Sélection du dashboard */}
            <div className="card cockpit-toolbar">
                <div className="filter-group">
                    <label htmlFor="dashboard-select">Dashboard</label>
                    <select
                        id="dashboard-select"
                        className="form-control"
                        value={selectedDashboardId}
                        onChange={(e) => setSelectedDashboardId(e.target.value)}
                        // Verrouillé pendant un refresh/run : empêche de changer de
                        // dashboard en cours d'action, ce qui ferait recharger le
                        // détail de l'ancien dashboard après coup (réponse périmée).
                        disabled={loadingDashboards || dashboards.length === 0 || busy}
                    >
                        {dashboards.length === 0 && <option value="">Aucun dashboard</option>}
                        {dashboards.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name} {d.enabled ? '' : '(inactif)'}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="cockpit-actions">
                    <label className="cockpit-checkbox">
                        <input
                            type="checkbox"
                            checked={forceDryRun}
                            onChange={(e) => setForceDryRun(e.target.checked)}
                        />
                        Forcer dry-run
                    </label>
                    <button
                        className="btn btn-secondary"
                        onClick={handleRefreshContracts}
                        disabled={!selectedDashboardId || busy || loadingSets}
                    >
                        {refreshing ? 'Refresh…' : 'Rafraîchir les contrats'}
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={handleRun}
                        disabled={!selectedDashboardId || busy || loadingSets || runnableSets.length === 0}
                    >
                        {running ? 'Run en cours…' : 'Lancer un run'}
                    </button>
                </div>
            </div>

            {loadingDashboards ? (
                <div className="card"><div className="loading">Chargement des dashboards…</div></div>
            ) : dashboards.length === 0 ? (
                <div className="card"><div className="no-data">Aucun dashboard d'orchestration configuré.</div></div>
            ) : (
                <>
                    {/* Preview du plan d'exécution */}
                    <div className="card">
                        <h3>Preview du plan d'exécution</h3>
                        {!dashboardEnabled && (
                            <div className="alert alert-warning">
                                Ce dashboard est <strong>inactif</strong> : un run renverrait
                                <code> no_sets</code> (0 appel). Activez-le pour l'exécuter.
                            </div>
                        )}
                        <ul className="cockpit-preview">
                            <li>{runnableSets.length} set(s) actif(s) à exécuter (action <code>mtf_run</code>)</li>
                            <li>{runnableSets.length} appel(s) Symfony prévu(s)</li>
                            <li>Exchanges : {exchanges.length > 0 ? exchanges.join(', ') : '—'}</li>
                            <li>Mode : {forceDryRun ? 'dry-run forcé (run-level)' : 'tel que configuré par set'}</li>
                            <li>Concurrence : bornée côté serveur (MAX_CONCURRENCY)</li>
                        </ul>
                        {bitmartLiveSets.length > 0 && (
                            <div className="alert alert-warning">
                                ⚠️ {bitmartLiveSets.length} set(s) Bitmart en <strong>live</strong> : à confirmer.
                            </div>
                        )}
                        {forbiddenLiveSets.length > 0 && (
                            <div className="alert alert-danger">
                                {forbiddenLiveSets.length} set(s) OKX/Hyperliquid en live :
                                seront <strong>refusés</strong> (live interdit, fail-closed).
                            </div>
                        )}
                    </div>

                    {/* Liste des sets */}
                    <div className="card">
                        <h3>Sets configurés{selectedDashboard ? ` — ${selectedDashboard.name}` : ''}</h3>
                        {loadingSets ? (
                            <div className="loading">Chargement des sets…</div>
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Enabled</th>
                                        <th>Set ID</th>
                                        <th>Action</th>
                                        <th>Exchange</th>
                                        <th>Marché</th>
                                        <th>Profil</th>
                                        <th>Env.</th>
                                        <th>Dry-run</th>
                                        <th>Workers</th>
                                        <th>Contrats</th>
                                        <th>Priorité</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sets.length === 0 ? (
                                        <tr><td colSpan="11" className="text-center">Aucun set configuré.</td></tr>
                                    ) : (
                                        sets.map((s) => (
                                            <tr key={s.id}>
                                                <td>
                                                    <span className={`badge ${s.enabled ? 'badge-success' : 'badge-secondary'}`}>
                                                        {s.enabled ? 'on' : 'off'}
                                                    </span>
                                                </td>
                                                <td>{s.set_id}</td>
                                                <td>{s.action}</td>
                                                <td>{s.exchange}</td>
                                                <td>{s.market_type}</td>
                                                <td>{s.mtf_profile}</td>
                                                <td>{s.environment}</td>
                                                <td>
                                                    <span className={`badge ${s.dry_run ? 'badge-info' : 'badge-danger'}`}>
                                                        {s.dry_run ? 'dry' : 'live'}
                                                    </span>
                                                </td>
                                                <td>{s.workers}</td>
                                                <td>
                                                    {s.symbols && s.symbols.length > 0
                                                        ? `${s.symbols.length} sym.`
                                                        : (s.contracts_limit ? `≤ ${s.contracts_limit}` : '—')}
                                                </td>
                                                <td>{s.priority}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {/* Dernier run */}
                    <div className="card">
                        <h3>Dernier run</h3>
                        {!latestRun ? (
                            <div className="no-data">Aucun run persisté pour ce dashboard.</div>
                        ) : (
                            <>
                                <div className="contract-info">
                                    <div><strong>Run ID</strong><br />{latestRun.run_id}</div>
                                    <div>
                                        <strong>Statut</strong><br />
                                        <span className={`badge ${STATUS_BADGE_CLASS[latestRun.status] || 'badge-secondary'}`}>
                                            {latestRun.status}
                                        </span>
                                    </div>
                                    <div><strong>OK</strong><br />{latestRun.ok ? 'oui' : 'non'}</div>
                                    <div><strong>Début</strong><br />{formatDate(latestRun.started_at)}</div>
                                    <div><strong>Fin</strong><br />{formatDate(latestRun.finished_at)}</div>
                                    <div><strong>Appels</strong><br />{latestRun.total_calls}</div>
                                    <div><strong>Réussis</strong><br />{latestRun.success_count}</div>
                                    <div><strong>Échoués</strong><br />{latestRun.failed_count}</div>
                                </div>

                                <h4>Détail par set</h4>
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Set ID</th>
                                            <th>Statut</th>
                                            <th>Durée</th>
                                            <th>Erreur</th>
                                            <th>JSON</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(latestRun.sets || []).length === 0 ? (
                                            <tr><td colSpan="5" className="text-center">Aucun set dans ce run.</td></tr>
                                        ) : (
                                            (latestRun.sets || []).map((rs) => (
                                                <tr key={rs.id}>
                                                    <td>{rs.set_id}</td>
                                                    <td>
                                                        <span className={`badge ${rs.ok ? 'badge-success' : 'badge-danger'}`}>
                                                            {rs.ok ? 'OK' : 'KO'}
                                                        </span>
                                                    </td>
                                                    <td>{formatDuration(rs.duration_ms)}</td>
                                                    <td className="cockpit-error">{rs.error || '—'}</td>
                                                    <td>
                                                        <details>
                                                            <summary>payload / réponse</summary>
                                                            <div className="cockpit-json-pair">
                                                                <div>
                                                                    <strong>Payload envoyé</strong>
                                                                    <pre className="cockpit-json">
                                                                        {JSON.stringify(rs.payload_sent, null, 2)}
                                                                    </pre>
                                                                </div>
                                                                <div>
                                                                    <strong>Réponse Symfony</strong>
                                                                    <pre className="cockpit-json">
                                                                        {JSON.stringify(rs.response_json, null, 2)}
                                                                    </pre>
                                                                </div>
                                                            </div>
                                                        </details>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>

                                <details className="cockpit-last-json">
                                    <summary>Dernier JSON global</summary>
                                    <pre className="cockpit-json">
                                        {JSON.stringify(latestRun.last_json, null, 2)}
                                    </pre>
                                </details>
                            </>
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default OrchestrationCockpitPage;
