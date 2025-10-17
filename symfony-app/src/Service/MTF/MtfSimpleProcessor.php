<?php
namespace App\Service\MTF;

use App\Repository\MtfPlanRepository;
use App\Service\Pipeline\KlinesCallbackRunner;
use App\Service\Pipeline\PipelineMeta;

final class MtfSimpleProcessor
{
    public function __construct(
        private MtfContractSelector $selector,
        private KlinesCallbackRunner $runner,
        private MtfPlanRepository $planRepo,
    ) {}

    public function runForTimeframe(string $tf, int $limit = 270): void
    {
        $symbols = $this->selector->symbolsFor($tf);
        foreach ($symbols as $symbol) {
            // 1) (optionnel) rafraîchir les parents si activé pour ce contrat
            //    -> ici, on lit le flag depuis le repo pour le symbole
            if ($this->planRepo->shouldCascade($symbol)) {
                foreach ($this->selector->standardParents($tf) as $ptf) {
                    $this->runner->run(
                        symbol:    $symbol,
                        timeframe: $ptf,
                        limit:     $limit,
                        meta:      ['pipeline' => PipelineMeta::DONT_INC_DEC_DEL, 'source' => 'cli']
                    );
                }
            }

            // 2) TF courant (évaluation complète + éventuelle ouverture)
            $this->runner->run(
                symbol:    $symbol,
                timeframe: $tf,
                limit:     $limit,
                meta:      ['source' => 'cli']
            );
        }
    }
}
