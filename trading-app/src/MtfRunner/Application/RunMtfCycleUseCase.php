<?php

declare(strict_types=1);

namespace App\MtfRunner\Application;

use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;

final class RunMtfCycleUseCase
{
    public function __construct(
        private readonly MtfRunnerService $runner,
    ) {
    }

    /**
     * @return array{
     *     summary: array,
     *     results: array,
     *     errors: array,
     *     summary_by_tf: array,
     *     rejected_by: array,
     *     last_validated: array,
     *     orders_placed: array,
     *     performance: array
     * }
     */
    public function run(MtfRunnerRequestDto $request): array
    {
        return $this->runner->run($request);
    }

    /**
     * @return array{
     *     summary: array,
     *     results: array,
     *     errors: array,
     *     summary_by_tf: array,
     *     rejected_by: array,
     *     last_validated: array,
     *     orders_placed: array,
     *     performance: array
     * }
     */
    public function __invoke(MtfRunnerRequestDto $request): array
    {
        return $this->run($request);
    }
}
