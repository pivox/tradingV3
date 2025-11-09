<?php

declare(strict_types=1);

namespace App\MtfValidator\Execution;

use App\Config\MtfValidationConfig;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecutionSelector
{
    public function __construct(
        private readonly MtfValidationConfig $mtfConfig,
        private readonly ConditionRegistry $registry,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed> $context Context keys consumed by conditions (expected_r_multiple, atr_pct_15m_bps, ...)
     */
    public function decide(array $context): ExecutionDecision
    {
        $cfg = $this->mtfConfig->getConfig();
        $selector = (array)($cfg['execution_selector'] ?? []);

        $stayOn15m = $this->namesFromSpec((array)($selector['stay_on_15m_if'] ?? []));
        $dropTo5mAny = $this->namesFromSpec((array)($selector['drop_to_5m_if_any'] ?? []));
        $forbidDropAny = $this->namesFromSpec((array)($selector['forbid_drop_to_5m_if_any'] ?? []));
        $allow1mOnlyFor = $this->namesFromSpec((array)($selector['allow_1m_only_for'] ?? []));

        $filtersMandatory = $this->namesFromSpec((array)($cfg['filters_mandatory'] ?? []));

        // Mandatory filters gate
        $filtersRes = $filtersMandatory ? $this->registry->evaluate($context, $filtersMandatory) : [];
        $filtersPassed = true;
        foreach ($filtersRes as $r) { if (!(bool)($r['passed'] ?? false)) { $filtersPassed = false; break; } }
        if (!$filtersPassed) {
            $this->logger->info('[ExecSelector] filters_mandatory failed', [ 'filters' => $filtersRes ]);
            return new ExecutionDecision('NONE', meta: [ 'filters' => $filtersRes ]);
        }

        $stayRes = $stayOn15m ? $this->registry->evaluate($context, $stayOn15m) : [];
        $stayAll = $this->allPassed($stayRes);
        if ($stayAll) {
            return $this->decision('15m', $context, [
                'stay_on_15m_if' => $stayRes,
                'filters' => $filtersRes,
            ]);
        }

        $forbidRes = $forbidDropAny ? $this->registry->evaluate($context, $forbidDropAny) : [];
        $forbidAny = $this->anyPassed($forbidRes);

        $dropRes = $dropTo5mAny ? $this->registry->evaluate($context, $dropTo5mAny) : [];
        $dropAny = $this->anyPassed($dropRes);

        if ($dropAny && !$forbidAny) {
            return $this->decision('5m', $context, [
                'stay_on_15m_if' => $stayRes,
                'drop_to_5m_if_any' => $dropRes,
                'forbid_drop_to_5m_if_any' => $forbidRes,
                'filters' => $filtersRes,
            ]);
        }

        $allow1mRes = $allow1mOnlyFor ? $this->registry->evaluate($context, $allow1mOnlyFor) : [];
        $allow1mAny = $this->anyPassed($allow1mRes);
        if ($allow1mAny) {
            return $this->decision('1m', $context, [
                'stay_on_15m_if' => $stayRes,
                'drop_to_5m_if_any' => $dropRes,
                'forbid_drop_to_5m_if_any' => $forbidRes,
                'allow_1m_only_for' => $allow1mRes,
                'filters' => $filtersRes,
            ]);
        }

        // Fallback pragmatique: rester 15m
        return $this->decision('15m', $context, [
            'stay_on_15m_if' => $stayRes,
            'drop_to_5m_if_any' => $dropRes,
            'forbid_drop_to_5m_if_any' => $forbidRes,
            'allow_1m_only_for' => $allow1mRes,
            'filters' => $filtersRes,
        ]);
    }

    /** @param array<string,mixed> $meta */
    private function decision(string $tf, array $context, array $meta): ExecutionDecision
    {
        $erm = isset($context['expected_r_multiple']) && \is_numeric($context['expected_r_multiple'])
            ? (float)$context['expected_r_multiple'] : null;
        $w = isset($context['entry_zone_width_pct']) && \is_numeric($context['entry_zone_width_pct'])
            ? (float)$context['entry_zone_width_pct'] : null;
        return new ExecutionDecision($tf, $erm, $w, $meta);
    }

    /** @param array<int,mixed> $spec */
    private function namesFromSpec(array $spec): array
    {
        $out = [];
        foreach ($spec as $item) {
            if (\is_string($item) && $item !== '') { $out[] = $item; continue; }
            if (\is_array($item) && $item !== []) {
                $k = array_key_first($item);
                if (\is_string($k) && $k !== '') { $out[] = $k; }
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<string,array> $results */
    private function allPassed(array $results): bool
    {
        if ($results === []) return false;
        foreach ($results as $r) { if (!(bool)($r['passed'] ?? false)) return false; }
        return true;
    }

    /** @param array<string,array> $results */
    private function anyPassed(array $results): bool
    {
        foreach ($results as $r) { if ((bool)($r['passed'] ?? false)) return true; }
        return false;
    }
}

