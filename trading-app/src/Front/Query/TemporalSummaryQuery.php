<?php

declare(strict_types=1);

namespace App\Front\Query;

use Symfony\Component\Yaml\Yaml;

final class TemporalSummaryQuery
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $mtfConfig = $this->readYaml($this->projectDir . '/config/mtf.yaml');
        $compose = $this->readYaml($this->composePath());
        $services = $this->temporalServices($compose);
        $cluster = $this->cluster($mtfConfig, $services);
        $ui = $this->ui($services['temporal-ui'] ?? []);
        $workers = $this->workers($services);
        $taskQueues = $this->taskQueues($cluster, $workers);

        return [
            'cluster' => $cluster,
            'ui' => $ui,
            'services' => $services,
            'workers' => $workers,
            'task_queues' => $taskQueues,
            'checks' => $this->checks($mtfConfig, $compose, $services),
            'admin_commands' => $this->adminCommands($cluster),
            'config_files' => $this->configFiles(),
        ];
    }

    /**
     * @param array<string, mixed> $mtfConfig
     * @param array<string, array<string, mixed>> $services
     * @return array<string, mixed>
     */
    private function cluster(array $mtfConfig, array $services): array
    {
        $temporal = $mtfConfig['mtf']['temporal'] ?? [];
        $workerEnv = $this->firstWorkerEnvironment($services);
        $server = $services['temporal'] ?? [];

        return [
            'address' => (string) ($temporal['address'] ?? $workerEnv['TEMPORAL_ADDRESS'] ?? 'temporal:7233'),
            'namespace' => (string) ($temporal['namespace'] ?? 'default'),
            'task_queue' => (string) ($temporal['task_queue'] ?? $workerEnv['TASK_QUEUE_NAME'] ?? ''),
            'workflow_id' => (string) ($temporal['workflow_id'] ?? ''),
            'grpc_port' => $this->firstHostPort($server['ports'] ?? [], '7233') ?? '7233',
            'http_api_enabled' => $this->envTruthy($server['environment']['ENABLE_HTTP_API'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function ui(array $service): array
    {
        $hostPort = $this->firstHostPort($service['ports'] ?? [], '8080') ?? '8233';

        return [
            'url' => 'http://localhost:' . $hostPort,
            'container_name' => $service['container_name'] ?? 'temporal_ui',
            'auth_enabled' => $this->envTruthy($service['environment']['TEMPORAL_UI_AUTH_ENABLED'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $compose
     * @return array<string, array<string, mixed>>
     */
    private function temporalServices(array $compose): array
    {
        $services = $compose['services'] ?? [];
        if (!is_array($services)) {
            return [];
        }

        $selected = [];
        foreach ($services as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $environment = $this->normalizeEnvironment($definition['environment'] ?? []);
            $isTemporal = str_contains($name, 'temporal')
                || isset($environment['TEMPORAL_ADDRESS'])
                || isset($environment['TASK_QUEUE_NAME']);

            if (!$isTemporal) {
                continue;
            }

            $selected[$name] = [
                'name' => $name,
                'image' => $definition['image'] ?? null,
                'container_name' => $definition['container_name'] ?? $name,
                'ports' => $this->normalizePorts($definition['ports'] ?? []),
                'expose' => $this->normalizeList($definition['expose'] ?? []),
                'environment' => $environment,
                'mem_limit' => $definition['mem_limit'] ?? null,
                'restart' => $definition['restart'] ?? null,
                'has_healthcheck' => isset($definition['healthcheck']),
                'depends_on' => $definition['depends_on'] ?? [],
            ];
        }

        return $selected;
    }

    /**
     * @param array<string, array<string, mixed>> $services
     * @return list<array<string, mixed>>
     */
    private function workers(array $services): array
    {
        $workers = [];
        foreach ($services as $name => $service) {
            $environment = $service['environment'] ?? [];
            if (!is_array($environment) || !isset($environment['TASK_QUEUE_NAME'])) {
                continue;
            }

            $workers[] = [
                'service' => $name,
                'container_name' => $service['container_name'] ?? $name,
                'task_queue' => (string) $environment['TASK_QUEUE_NAME'],
                'temporal_address' => (string) ($environment['TEMPORAL_ADDRESS'] ?? ''),
                'workers_count' => (string) ($environment['MTF_WORKERS_COUNT'] ?? ''),
                'scalper_micro_workers_count' => (string) ($environment['SCALPER_MICRO_WORKERS_COUNT'] ?? ''),
                'target_url' => (string) ($environment['MTF_WORKERS_URL'] ?? ''),
                'dry_run' => (string) ($environment['MTF_WORKERS_DRY_RUN'] ?? ''),
            ];
        }

        return $workers;
    }

    /**
     * @param array<string, mixed> $cluster
     * @param list<array<string, mixed>> $workers
     * @return list<string>
     */
    private function taskQueues(array $cluster, array $workers): array
    {
        $queues = [];
        if (($cluster['task_queue'] ?? '') !== '') {
            $queues[] = (string) $cluster['task_queue'];
        }

        foreach ($workers as $worker) {
            if (($worker['task_queue'] ?? '') !== '') {
                $queues[] = (string) $worker['task_queue'];
            }
        }

        return array_values(array_unique($queues));
    }

    /**
     * @param array<string, mixed> $mtfConfig
     * @param array<string, mixed> $compose
     * @param array<string, array<string, mixed>> $services
     * @return list<array<string, string>>
     */
    private function checks(array $mtfConfig, array $compose, array $services): array
    {
        return [
            [
                'name' => 'config/mtf.yaml',
                'status' => isset($mtfConfig['mtf']['temporal']) ? 'ok' : 'warning',
                'detail' => isset($mtfConfig['mtf']['temporal']) ? 'Configuration Temporal MTF trouvee' : 'Configuration Temporal MTF absente',
            ],
            [
                'name' => 'docker-compose.yml',
                'status' => isset($compose['services']) ? 'ok' : 'warning',
                'detail' => isset($compose['services']) ? 'Stack Docker lisible' : 'Stack Docker non trouvee',
            ],
            [
                'name' => 'temporal',
                'status' => isset($services['temporal']) ? 'ok' : 'critical',
                'detail' => isset($services['temporal']) ? 'Service serveur declare' : 'Service serveur absent',
            ],
            [
                'name' => 'temporal-ui',
                'status' => isset($services['temporal-ui']) ? 'ok' : 'warning',
                'detail' => isset($services['temporal-ui']) ? 'UI declaree' : 'UI absente',
            ],
            [
                'name' => 'worker Temporal',
                'status' => $this->workers($services) !== [] ? 'ok' : 'warning',
                'detail' => $this->workers($services) !== [] ? 'Au moins un worker declare' : 'Aucun worker declare',
            ],
            [
                'name' => 'Temporal PHP SDK',
                'status' => class_exists('Temporal\Client\WorkflowClient') ? 'ok' : 'warning',
                'detail' => class_exists('Temporal\Client\WorkflowClient') ? 'Classes SDK disponibles' : 'SDK PHP non charge dans ce runtime',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $cluster
     * @return list<array<string, string>>
     */
    private function adminCommands(array $cluster): array
    {
        $address = $this->commandAddress((string) ($cluster['address'] ?? 'temporal:7233'));
        $namespace = $this->commandNamespace((string) ($cluster['namespace'] ?? 'default'));
        $workflowId = (string) ($cluster['workflow_id'] ?? '<workflow-id>');

        return [
            [
                'label' => 'Health cluster',
                'command' => sprintf('docker compose exec temporal temporal operator cluster health --address %s', $address),
            ],
            [
                'label' => 'Lister namespaces',
                'command' => sprintf('docker compose exec temporal temporal operator namespace list --address %s', $address),
            ],
            [
                'label' => 'Lister workflows ouverts',
                'command' => sprintf('docker compose exec temporal temporal workflow list --address %s --namespace %s', $address, $namespace),
            ],
            [
                'label' => 'Decrire workflow MTF',
                'command' => sprintf('docker compose exec temporal temporal workflow describe --address %s --namespace %s --workflow-id %s', $address, $namespace, $workflowId),
            ],
            [
                'label' => 'Logs serveur',
                'command' => 'docker compose logs -f temporal temporal-ui cron-symfony-mtf-workers',
            ],
            [
                'label' => 'Etat containers',
                'command' => 'docker compose ps temporal temporal-ui cron-symfony-mtf-workers postgresql',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configFiles(): array
    {
        $paths = [
            $this->projectDir . '/config/mtf.yaml',
            $this->projectDir . '/config/services_mtf.yaml',
            $this->composePath(),
        ];

        $files = [];
        foreach ($paths as $path) {
            $files[] = [
                'path' => $this->relativePath($path),
                'exists' => is_file($path),
                'updated_at' => is_file($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : null,
                'size_kb' => is_file($path) ? round(filesize($path) / 1024, 1) : null,
            ];
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function readYaml(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    private function composePath(): string
    {
        $local = $this->projectDir . '/docker-compose.yml';
        if (is_file($local)) {
            return $local;
        }

        return dirname($this->projectDir) . '/docker-compose.yml';
    }

    /**
     * @return array<string, string>
     */
    private function normalizeEnvironment(mixed $environment): array
    {
        if (!is_array($environment)) {
            return [];
        }

        $normalized = [];
        foreach ($environment as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = is_scalar($value) ? (string) $value : '';
                continue;
            }

            if (!is_string($value) || !str_contains($value, '=')) {
                continue;
            }

            [$envKey, $envValue] = explode('=', $value, 2);
            $normalized[$envKey] = $envValue;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @return list<string>
     */
    private function normalizePorts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ports = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $target = (string) ($item['target'] ?? '');
                $published = (string) ($item['published'] ?? '');
                $hostIp = (string) ($item['host_ip'] ?? '');
                if ($target === '') {
                    continue;
                }

                $ports[] = ($hostIp !== '' ? $hostIp . ':' : '') . ($published !== '' ? $published . ':' : '') . $target;
                continue;
            }

            $ports[] = (string) $item;
        }

        return $ports;
    }

    /**
     * @param list<string>|mixed $ports
     */
    private function firstHostPort(mixed $ports, string $containerPort): ?string
    {
        if (!is_array($ports)) {
            return null;
        }

        foreach ($ports as $port) {
            $port = trim((string) $port, '"\'');
            $portWithoutProtocol = explode('/', $port, 2)[0];
            $parts = explode(':', $portWithoutProtocol);
            if ((string) end($parts) !== $containerPort) {
                continue;
            }

            return $parts[count($parts) - 2] ?? $containerPort;
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $services
     * @return array<string, string>
     */
    private function firstWorkerEnvironment(array $services): array
    {
        foreach ($services as $service) {
            $environment = $service['environment'] ?? [];
            if (is_array($environment) && isset($environment['TASK_QUEUE_NAME'])) {
                /** @var array<string, string> $environment */
                return $environment;
            }
        }

        return [];
    }

    private function envTruthy(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function commandAddress(string $address): string
    {
        return $this->resolveEnvPlaceholder($address, 'temporal:7233');
    }

    private function commandNamespace(string $namespace): string
    {
        return $this->resolveEnvPlaceholder($namespace, 'default');
    }

    private function resolveEnvPlaceholder(string $value, string $default): string
    {
        if (!preg_match('/^%env\(([^)]+)\)%$/', $value, $matches)) {
            return $value;
        }

        $envValue = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);

        return is_string($envValue) && trim($envValue) !== '' ? $envValue : $default;
    }

    private function relativePath(string $path): string
    {
        $root = is_file($this->projectDir . '/docker-compose.yml') ? $this->projectDir : dirname($this->projectDir);

        return str_replace($root . '/', '', $path);
    }
}
