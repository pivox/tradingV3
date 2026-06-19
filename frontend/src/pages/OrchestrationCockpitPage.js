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

// Borne workers côté Symfony, alignée sur `MAX_WORKERS_PER_SET` du runner Python
// (python-orchestrator/app/schemas.py). Aucune contrainte CHECK en base : le
// runtime clampe à l'exécution, donc la preview clampe pareil pour ne pas afficher
// un payload divergent de l'envoi réel.
const MAX_WORKERS_PER_SET = 1;

// Nettoie les symbols comme le runner / Symfony : trim puis écarte les vides et
// blancs. Une sélection qui se réduit à du vide vaut « tout l'univers actif » côté
// Symfony, ce que la matérialisation interdit ; on la traite donc comme non
// matérialisée (cf. generate_set_payload, app/services/symfony_client.py).
const cleanSymbols = (symbols) =>
    (Array.isArray(symbols) ? symbols : [])
        .filter((s) => typeof s === 'string')
        .map((s) => s.trim())
        .filter((s) => s.length > 0);

// Reconstruit le payload /api/mtf/run depuis les COLONNES du set (allow-list),
// exactement comme `generate_set_payload` + le clamp workers de `run_persisted_set`
// côté Python — plutôt que de faire confiance au JSON `payload` persisté, qui peut
// être périmé (symbols vidés depuis) ou porter des flags de contrôle runner. On ne
// montre ainsi dans la preview que ce qui partira réellement à Symfony.
// Renvoie `null` si la sélection n'a aucun symbole concret (non matérialisé :
// fail-closed, aucun appel). `open_state_snapshot` est joint au runtime, pas ici.
const buildSetPayload = (s, { dryRun } = {}) => {
    const symbols = cleanSymbols(s.symbols);
    if (symbols.length === 0) return null;
    return {
        dry_run: dryRun === undefined ? s.dry_run : dryRun,
        workers: Math.max(1, Math.min(s.workers || 1, MAX_WORKERS_PER_SET)),
        exchange: s.exchange,
        market_type: s.market_type,
        mtf_profile: s.mtf_profile,
        sync_tables: false,
        process_tp_sl: false,
        symbols
    };
};

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
    const enabledMtfSets = dashboardEnabled
        ? sets.filter((s) => s.enabled && s.action === 'mtf_run')
        : [];
    // Dry-run effectif d'un set : « Forcer dry-run » est un override run-level qui
    // écrase le `dry_run` configuré de chaque set (cf. RunRequest.dry_run côté
    // Python). On le reflète dans la preview pour montrer ce qui partira vraiment.
    const effectiveDryRun = (s) => forceDryRun || s.dry_run;

    // Un set effectivement live (`dry_run=false` ET « Forcer dry-run » décoché) est
    // REFUSÉ par le runner avant tout appel : tant que la readiness live n'est pas
    // livrée, `orchestrator.py` skip TOUT set live (tous exchanges, Bitmart inclus)
    // en `ok=false` / `payload_sent=null` — aucun /api/mtf/run n'est envoyé. Ce
    // garde précède la vérif de matérialisation côté runner, donc on l'applique en
    // premier ici. Cocher « Forcer dry-run » les rend dry → exécutables.
    const liveRefusedSets = enabledMtfSets.filter((s) => !effectiveDryRun(s));
    // OKX/Hyperliquid : live interdit même après readiness (politique permanente) ;
    // les autres (Bitmart, fake) ne sont refusés que dans la phase actuelle.
    const forbiddenLiveSets = liveRefusedSets.filter((s) => LIVE_FORBIDDEN_EXCHANGES.includes(s.exchange));

    // Parmi les sets effectivement dry, on distingue matérialisé / non matérialisé.
    // Un set non matérialisé n'a aucun symbole concret (sélection capée pas encore
    // rafraîchie, ou symbols tous blancs) : le runner renvoie `payload=null` et
    // n'appelle pas Symfony. On juge la matérialisation sur les COLONNES (comme le
    // runner), pas sur le JSON `payload` persisté qui pourrait être périmé.
    const effectiveDrySets = enabledMtfSets.filter((s) => effectiveDryRun(s));
    const runnableSets = effectiveDrySets.filter((s) => cleanSymbols(s.symbols).length > 0);
    const pendingMaterializationSets = effectiveDrySets.filter((s) => cleanSymbols(s.symbols).length === 0);
    // Les deux autres états de set, à distinguer dans la preview : les sets
    // désactivés (jamais exécutés) et les sets actifs dont l'action n'est pas
    // `mtf_run` (l'orchestrateur ne dispatche aujourd'hui que des `mtf_run`).
    // Calculés sur `sets` brut (indépendants de `dashboardEnabled`), à titre
    // informatif : un dashboard inactif n'exécute de toute façon aucun set.
    const disabledSets = sets.filter((s) => !s.enabled);
    const nonMtfRunSets = sets.filter((s) => s.enabled && s.action !== 'mtf_run');
    const exchanges = [...new Set(runnableSets.map((s) => s.exchange))];
    // Payload /api/mtf/run effectivement envoyé : reconstruit depuis les colonnes
    // (comme le runner), avec `dry_run` recalé sur la valeur effective.
    const effectivePayload = (s) => buildSetPayload(s, { dryRun: effectiveDryRun(s) });

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
                        disabled={
                            !selectedDashboardId
                            || busy
                            || loadingSets
                            || runnableSets.length === 0
                            // `/orchestrator/run` itère TOUS les sets actifs du
                            // dashboard (pas de sélection par set côté backend) et compte
                            // chaque set dans `total_calls`/`failed`. Un set non matérialisé
                            // OU effectivement live finit en échec (`ok=false`), donc le run
                            // entier finit NON OK. On bloque tant qu'un de ces cas subsiste :
                            // l'opérateur doit rafraîchir (matérialisation) ou cocher
                            // « Forcer dry-run » / retirer le set live.
                            || pendingMaterializationSets.length > 0
                            || liveRefusedSets.length > 0
                        }
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
                        {dashboardEnabled && pendingMaterializationSets.length > 0 && (
                            <div className="alert alert-warning">
                                {pendingMaterializationSets.length} set(s) actif(s) <strong>non
                                matérialisé(s)</strong> (contrats pas encore résolus). Le run est
                                <strong> bloqué</strong> : <code>/orchestrator/run</code> exécute
                                tous les sets actifs du dashboard, donc ces sets partiraient et
                                seraient comptés en échec <code>not materialized</code>. Lancez
                                « Rafraîchir les contrats » avant de relancer.
                            </div>
                        )}
                        <ul className="cockpit-preview">
                            <li>{runnableSets.length} set(s) runnable (action <code>mtf_run</code>, payload matérialisé)</li>
                            <li>{runnableSets.length} appel(s) Symfony prévu(s)</li>
                            <li>{pendingMaterializationSets.length} non matérialisé(s) (exclu(s) du compte)</li>
                            <li>{liveRefusedSets.length} live refusé(s) (exclu(s) du compte)</li>
                            <li>{disabledSets.length} désactivé(s)</li>
                            <li>{nonMtfRunSets.length} non-<code>mtf_run</code> (ignoré(s))</li>
                            <li>Exchanges : {exchanges.length > 0 ? exchanges.join(', ') : '—'}</li>
                            <li>Mode : {forceDryRun ? 'dry-run forcé (run-level)' : 'tel que configuré par set'}</li>
                            <li>Concurrence : bornée côté serveur (MAX_CONCURRENCY)</li>
                        </ul>
                        {liveRefusedSets.length > 0 && (
                            <div className="alert alert-warning">
                                {liveRefusedSets.length} set(s) en <strong>live effectif</strong>. Le
                                run est <strong>bloqué</strong> : <code>/orchestrator/run</code> itère
                                tous les sets actifs et compte ces sets live en échec
                                (<code>ok=false</code>, aucun <code>/api/mtf/run</code> envoyé,
                                fail-closed), donc le run finirait NON OK.
                                {forbiddenLiveSets.length > 0 && (
                                    <> Dont {forbiddenLiveSets.length} OKX/Hyperliquid (live interdit
                                    même après readiness).</>
                                )}{' '}
                                Cochez « Forcer dry-run » pour les exécuter en dry, ou désactivez /
                                retirez ces sets.
                            </div>
                        )}

                        {/* Détail par set runnable : ce qui partira réellement à Symfony.
                            Le payload affiché est reconstruit depuis les colonnes du set
                            (comme le runner), avec `dry_run` recalé sur la valeur effective
                            (override « Forcer dry-run »). `open_state_snapshot` est joint au
                            runtime, pas ici. */}
                        <h4>Détail des sets runnable</h4>
                        {loadingSets ? (
                            <div className="loading">Chargement…</div>
                        ) : runnableSets.length === 0 ? (
                            <div className="no-data">Aucun set runnable : rien ne partira à Symfony.</div>
                        ) : (
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Set ID</th>
                                        <th>Exchange</th>
                                        <th>Marché</th>
                                        <th>Profil</th>
                                        <th>Dry-run effectif</th>
                                        <th>Workers</th>
                                        <th>Symbols</th>
                                        <th>Payload /api/mtf/run</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runnableSets.map((s) => (
                                        <tr key={s.id}>
                                            <td>{s.set_id}</td>
                                            <td>{s.exchange}</td>
                                            <td>{s.market_type}</td>
                                            <td>{s.mtf_profile}</td>
                                            <td>
                                                {/* runnableSets ⊆ sets effectivement dry : un set live
                                                    n'est jamais runnable (refusé par le runner). */}
                                                <span className="badge badge-info">dry</span>
                                                {forceDryRun && !s.dry_run && (
                                                    <span className="cockpit-hint"> (forcé)</span>
                                                )}
                                            </td>
                                            <td>{Math.max(1, Math.min(s.workers || 1, MAX_WORKERS_PER_SET))}</td>
                                            <td>{cleanSymbols(s.symbols).length}</td>
                                            <td>
                                                <details>
                                                    <summary>payload</summary>
                                                    <pre className="cockpit-json">
                                                        {JSON.stringify(effectivePayload(s), null, 2)}
                                                    </pre>
                                                </details>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        {/* Sets non exécutés, regroupés par raison d'exclusion. */}
                        {(pendingMaterializationSets.length > 0
                            || liveRefusedSets.length > 0
                            || disabledSets.length > 0
                            || nonMtfRunSets.length > 0) && (
                            <ul className="cockpit-excluded">
                                {pendingMaterializationSets.length > 0 && (
                                    <li>
                                        <strong>Non matérialisé(s)</strong> (payload <code>null</code>, refresh requis) :{' '}
                                        {pendingMaterializationSets.map((s) => s.set_id).join(', ')}
                                    </li>
                                )}
                                {liveRefusedSets.length > 0 && (
                                    <li>
                                        <strong>Live refusé(s)</strong> (fail-closed, aucun appel ;
                                        « Forcer dry-run » pour exécuter en dry) :{' '}
                                        {liveRefusedSets.map((s) => s.set_id).join(', ')}
                                    </li>
                                )}
                                {disabledSets.length > 0 && (
                                    <li>
                                        <strong>Désactivé(s)</strong> :{' '}
                                        {disabledSets.map((s) => s.set_id).join(', ')}
                                    </li>
                                )}
                                {nonMtfRunSets.length > 0 && (
                                    <li>
                                        <strong>Non-<code>mtf_run</code></strong> (action non dispatchée) :{' '}
                                        {nonMtfRunSets.map((s) => `${s.set_id} (${s.action})`).join(', ')}
                                    </li>
                                )}
                            </ul>
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
