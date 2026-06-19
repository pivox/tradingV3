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

// Rendu partagé du détail d'un run (`RunDetailRead`, PY-006) : résumé global,
// table « Détail par set » (payload / réponse / erreur) et « Dernier JSON global ».
// Utilisé tel quel pour le dernier run ET pour un run de l'historique, afin de ne
// pas dupliquer le chemin de rendu. Les erreurs par set restent toujours visibles.
const RunDetail = ({ run }) => (
    <>
        <div className="contract-info">
            <div><strong>Run ID</strong><br />{run.run_id}</div>
            <div>
                <strong>Statut</strong><br />
                <span className={`badge ${STATUS_BADGE_CLASS[run.status] || 'badge-secondary'}`}>
                    {run.status}
                </span>
            </div>
            <div><strong>OK</strong><br />{run.ok ? 'oui' : 'non'}</div>
            <div><strong>Début</strong><br />{formatDate(run.started_at)}</div>
            <div><strong>Fin</strong><br />{formatDate(run.finished_at)}</div>
            <div><strong>Appels</strong><br />{run.total_calls}</div>
            <div><strong>Réussis</strong><br />{run.success_count}</div>
            <div><strong>Échoués</strong><br />{run.failed_count}</div>
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
                {(run.sets || []).length === 0 ? (
                    <tr><td colSpan="5" className="text-center">Aucun set dans ce run.</td></tr>
                ) : (
                    (run.sets || []).map((rs) => (
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
                {JSON.stringify(run.last_json, null, 2)}
            </pre>
        </details>
    </>
);

// Taille de page de l'historique. La borne API (`_MAX_RUNS_PAGE_SIZE=100`) plafonne
// le `limit` par appel, pas le nombre total de runs : l'endpoint reste paginé par
// `offset`, donc « Charger plus » peut remonter au-delà de 100 runs (≤ 100 par page).
const RUNS_PAGE_SIZE = 20;

const OrchestrationCockpitPage = () => {
    const [dashboards, setDashboards] = useState([]);
    const [selectedDashboardId, setSelectedDashboardId] = useState('');
    const [sets, setSets] = useState([]);
    const [latestRun, setLatestRun] = useState(null);

    // Historique des runs (vue allégée `RunSummaryRead`) + détail d'un run
    // sélectionné dans l'historique (`RunDetailRead`). Par défaut, aucun run
    // sélectionné : le détail affiché reste le dernier run.
    const [runs, setRuns] = useState([]);
    const [runsHasMore, setRunsHasMore] = useState(false);
    const [loadingRuns, setLoadingRuns] = useState(false);
    const [loadingMoreRuns, setLoadingMoreRuns] = useState(false);
    const [selectedRunId, setSelectedRunId] = useState(null);
    const [runDetail, setRunDetail] = useState(null);
    const [loadingRunDetail, setLoadingRunDetail] = useState(false);

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

    // Jeton monotone dédié au chargement du détail d'un run d'historique : seule la
    // réponse de la dernière sélection est appliquée (clics rapides / changement de
    // dashboard). Incrémenté aussi par `loadDashboardDetail` pour invalider toute
    // sélection d'historique en cours quand on change de dashboard.
    const runDetailRequestRef = useRef(0);

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
        // Tout changement de dashboard invalide la sélection d'historique en cours
        // (jeton de détail de run) et revient à l'affichage par défaut (dernier run).
        // On nettoie aussi les loaders « détail de run » et « charger plus » : leurs
        // requêtes en vol seront ignorées (jeton périmé) et leur `finally` gardé ne les
        // remettrait pas à false, ce qui figerait l'UI sur « Chargement… ».
        runDetailRequestRef.current += 1;
        setSelectedRunId(null);
        setRunDetail(null);
        setLoadingRunDetail(false);
        setLoadingMoreRuns(false);
        if (!dashboardId) {
            setSets([]);
            setLatestRun(null);
            setRuns([]);
            setRunsHasMore(false);
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
        setRuns([]);
        setRunsHasMore(false);
        setLoadingSets(true);
        setLoadingRuns(true);
        setError(null);
        try {
            const [setsData, runData, runsData] = await Promise.all([
                api.getOrchestrationSets(dashboardId),
                api.getOrchestrationLatestRun(dashboardId),
                api.getOrchestrationRuns(dashboardId, { limit: RUNS_PAGE_SIZE, offset: 0 })
            ]);
            // Une sélection plus récente a été lancée entre-temps : on ignore.
            if (isStale()) return;
            setSets(Array.isArray(setsData) ? setsData : []);
            setLatestRun(runData);
            const runsList = Array.isArray(runsData) ? runsData : [];
            setRuns(runsList);
            // Une page pleine ⇒ il reste probablement des runs plus anciens à charger.
            setRunsHasMore(runsList.length === RUNS_PAGE_SIZE);
        } catch (err) {
            if (isStale()) return;
            setError(`Impossible de charger le dashboard : ${err.message}`);
        } finally {
            if (!isStale()) {
                setLoadingSets(false);
                setLoadingRuns(false);
            }
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
    // n'appelle pas Symfony. On juge la matérialisation sur `effective_payload`,
    // LE payload effectif calculé par l'API Python (SetRead.effective_payload,
    // PY-007) : `null` ⇔ non matérialisé, exactement comme côté runner.
    const effectiveDrySets = enabledMtfSets.filter((s) => effectiveDryRun(s));
    const runnableSets = effectiveDrySets.filter((s) => s.effective_payload != null);
    const pendingMaterializationSets = effectiveDrySets.filter((s) => s.effective_payload == null);
    // Les deux autres états de set, à distinguer dans la preview : les sets
    // désactivés (jamais exécutés) et les sets actifs dont l'action n'est pas
    // `mtf_run` (l'orchestrateur ne dispatche aujourd'hui que des `mtf_run`).
    // Calculés sur `sets` brut (indépendants de `dashboardEnabled`), à titre
    // informatif : un dashboard inactif n'exécute de toute façon aucun set.
    const disabledSets = sets.filter((s) => !s.enabled);
    const nonMtfRunSets = sets.filter((s) => s.enabled && s.action !== 'mtf_run');
    const exchanges = [...new Set(runnableSets.map((s) => s.exchange))];
    // Payload /api/mtf/run effectivement envoyé : LE payload calculé par l'API
    // (SetRead.effective_payload, PY-007), seul `dry_run` étant recalé localement
    // sur la valeur effective car « Forcer dry-run » est une décision run-level
    // côté front (transformation triviale, le reste vient de l'API).
    const effectivePayload = (s) =>
        s.effective_payload && { ...s.effective_payload, dry_run: effectiveDryRun(s) };

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

    // Sélection d'un run dans l'historique : charge son détail (`RunDetailRead`) et
    // l'affiche dans le même composant que le dernier run. Cliquer le dernier run
    // revient à l'affichage par défaut (détail déjà chargé, aucun refetch).
    const handleSelectRun = async (runId) => {
        if (!runId) return;
        if (latestRun && String(runId) === String(latestRun.run_id)) {
            // Retour au dernier run : on invalide tout chargement de détail en cours.
            // Sans `setLoadingRunDetail(false)`, le `finally` de la requête périmée est
            // gardé par le jeton et ne s'exécute pas → la carte resterait sur « Chargement… ».
            runDetailRequestRef.current += 1;
            setSelectedRunId(null);
            setRunDetail(null);
            setLoadingRunDetail(false);
            return;
        }
        const token = ++runDetailRequestRef.current;
        setSelectedRunId(runId);
        setRunDetail(null);
        setLoadingRunDetail(true);
        setError(null);
        try {
            const detail = await api.getOrchestrationRun(runId);
            if (token !== runDetailRequestRef.current) return;
            setRunDetail(detail);
        } catch (err) {
            if (token !== runDetailRequestRef.current) return;
            setError(`Impossible de charger le run ${runId} : ${err.message}`);
        } finally {
            if (token === runDetailRequestRef.current) setLoadingRunDetail(false);
        }
    };

    // Retour à l'affichage par défaut (dernier run). Comme pour la sélection du
    // dernier run, on remet `loadingRunDetail` à false : un détail encore en vol est
    // invalidé par le jeton et son `finally` gardé ne nettoierait pas le loader.
    const handleShowLatestRun = () => {
        runDetailRequestRef.current += 1;
        setSelectedRunId(null);
        setRunDetail(null);
        setLoadingRunDetail(false);
    };

    // Pagination « charger plus » : appoint d'une page (`offset = runs.length`), gardé
    // par le jeton de détail du dashboard pour ignorer un appoint périmé après changement.
    const handleLoadMoreRuns = async () => {
        if (!selectedDashboardId) return;
        const token = detailRequestRef.current;
        setLoadingMoreRuns(true);
        setError(null);
        try {
            const more = await api.getOrchestrationRuns(selectedDashboardId, {
                limit: RUNS_PAGE_SIZE,
                offset: runs.length
            });
            if (token !== detailRequestRef.current) return;
            const list = Array.isArray(more) ? more : [];
            setRuns((prev) => [...prev, ...list]);
            setRunsHasMore(list.length === RUNS_PAGE_SIZE);
        } catch (err) {
            if (token === detailRequestRef.current) {
                setError(`Impossible de charger plus de runs : ${err.message}`);
            }
        } finally {
            if (token === detailRequestRef.current) setLoadingMoreRuns(false);
        }
    };

    // Run affiché dans la carte détail : le run d'historique sélectionné, sinon le
    // dernier run par défaut. `activeRunId` sert à surligner la ligne correspondante.
    const viewingHistory = selectedRunId != null;
    const displayedRun = viewingHistory ? runDetail : latestRun;
    const activeRunId = viewingHistory ? selectedRunId : (latestRun ? latestRun.run_id : null);

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
                                            <td>{s.effective_payload.workers}</td>
                                            <td>{s.effective_payload.symbols.length}</td>
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

                    {/* Historique des runs (vue allégée `RunSummaryRead`, du plus
                        récent au plus ancien). Cliquer une ligne charge le détail du
                        run dans la carte « Détail du run » ci-dessous. */}
                    <div className="card">
                        <h3>Historique des runs</h3>
                        {loadingRuns ? (
                            <div className="loading">Chargement de l'historique…</div>
                        ) : runs.length === 0 ? (
                            <div className="no-data">Aucun run persisté pour ce dashboard.</div>
                        ) : (
                            <>
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Run ID</th>
                                            <th>Statut</th>
                                            <th>OK</th>
                                            <th>Début</th>
                                            <th>Fin</th>
                                            <th>Appels</th>
                                            <th>Réussis</th>
                                            <th>Échoués</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {runs.map((r) => {
                                            const isActive = String(r.run_id) === String(activeRunId);
                                            return (
                                                <tr
                                                    key={r.run_id}
                                                    className={`cockpit-run-row${isActive ? ' is-active' : ''}`}
                                                    onClick={() => handleSelectRun(r.run_id)}
                                                    style={{ cursor: 'pointer' }}
                                                >
                                                    <td>{r.run_id}</td>
                                                    <td>
                                                        <span className={`badge ${STATUS_BADGE_CLASS[r.status] || 'badge-secondary'}`}>
                                                            {r.status}
                                                        </span>
                                                    </td>
                                                    <td>{r.ok ? 'oui' : 'non'}</td>
                                                    <td>{formatDate(r.started_at)}</td>
                                                    <td>{formatDate(r.finished_at)}</td>
                                                    <td>{r.total_calls}</td>
                                                    <td>{r.success_count}</td>
                                                    <td>{r.failed_count}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                                {runsHasMore && (
                                    <div className="cockpit-actions">
                                        <button
                                            className="btn btn-secondary"
                                            onClick={handleLoadMoreRuns}
                                            disabled={loadingMoreRuns}
                                        >
                                            {loadingMoreRuns ? 'Chargement…' : 'Charger plus'}
                                        </button>
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    {/* Détail du run : dernier run par défaut, ou run sélectionné
                        dans l'historique. Rendu partagé via <RunDetail>. */}
                    <div className="card">
                        <h3>
                            {viewingHistory ? 'Détail du run sélectionné' : 'Dernier run'}
                            {viewingHistory && (
                                <button
                                    className="btn btn-secondary btn-sm"
                                    style={{ marginLeft: '1rem' }}
                                    onClick={handleShowLatestRun}
                                >
                                    Revenir au dernier run
                                </button>
                            )}
                        </h3>
                        {loadingRunDetail ? (
                            <div className="loading">Chargement du détail du run…</div>
                        ) : !displayedRun ? (
                            <div className="no-data">Aucun run persisté pour ce dashboard.</div>
                        ) : (
                            <RunDetail run={displayedRun} />
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default OrchestrationCockpitPage;
