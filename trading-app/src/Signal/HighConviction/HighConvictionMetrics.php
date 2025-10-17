<?php

namespace App\Signal\HighConviction;

use App\Service\Indicator\AtrCalculator;
use App\Service\Indicator\Trend\Adx;
use App\Service\Indicator\Volatility\Bollinger;
use App\Service\Indicator\Volatility\Choppiness;
use App\Service\Indicator\Volume\Obv;
use App\Service\Indicator\Volume\Vwap;
use App\Util\SrRiskHelper;
use Psr\Log\LoggerInterface;

final class HighConvictionMetrics
{
    private const ADX_PERIOD = 14;
    private const DONCHIAN_LOOKBACK = 50;
    private const VOL_PCTILE = 0.80;
    private const CHOP_TRENDISH_MAX = 38.0;
    private const MIN_SERIE = 30;

    public function __construct(
        private readonly Adx $adx,
        private readonly AtrCalculator $atr,
        private readonly SrRiskHelper $sr,
        private readonly Vwap $vwap,
        private readonly Obv $obv,
        private readonly Bollinger $bollinger,
        private readonly Choppiness $choppiness,
        private readonly LoggerInterface $highconviction,
    ) {}

    public function build(
        array $signals,
        array $ohlc1h,
        array $ohlc15m,
        array $ohlc5m,
        array $ohlc1m,
        string $sideUpper,
        float $entry,
        float $sl,
        float $tp,
        float $leverage
    ): array {
        $side = strtoupper($sideUpper);
        $adx1h = $this->adx->calculate(array_column($ohlc1h,'high'), array_column($ohlc1h,'low'), array_column($ohlc1h,'close'), self::ADX_PERIOD);
        $adx15m= $this->adx->calculate(array_column($ohlc15m,'high'),array_column($ohlc15m,'low'),array_column($ohlc15m,'close'),self::ADX_PERIOD);
        $rr = $this->computeRr($entry,$sl,$tp);
        $liqRatio = $this->estimateLiqToStopRatio($entry,$sl,$side,$leverage);
        $breakoutConfirmed = $this->detectBreakoutConfirmed($ohlc15m,$entry,$side);
        $validRetest = $this->isValidRetest($signals,$ohlc5m,$ohlc1m);
        $expansionAfterContraction = $this->detectExpansionAfterContraction($ohlc15m);
        $metrics = [
            'adx_1h'=>$adx1h,'adx_15m'=>$adx15m,'rr'=>$rr,'liq_ratio'=>$liqRatio,
            'breakout_confirmed'=>$breakoutConfirmed,'valid_retest'=>$validRetest,
            'expansion_after_contraction'=>$expansionAfterContraction,
        ];
        $this->highconviction->info('[HC] Metrics calculés', ['side'=>$side,'entry'=>$entry,'sl'=>$sl,'tp'=>$tp,'lev'=>$leverage,'metrics'=>$metrics]);
        return $metrics;
    }

    private function computeRr(float $entry,float $sl,float $tp): float { if($entry<=0) return \NAN; $stopPct=abs($entry-$sl)/$entry; $tpPct=abs($tp-$entry)/$entry; return $stopPct>0? $tpPct/$stopPct: INF; }
    private function estimateLiqToStopRatio(float $entry,float $sl,string $sideUpper,float $lev): float { if($entry<=0||$lev<=0) return \NAN; $liq= $sideUpper==='LONG'? $entry*(1-1/$lev): $entry*(1+1/$lev); $distStop=abs($entry-$sl); $distLiq=abs($entry-$liq); return $distStop>0? $distLiq/$distStop: INF; }
    private function detectBreakoutConfirmed(array $ohlc15m,float $entry,string $sideUpper): bool { $n=count($ohlc15m); if($n< self::DONCHIAN_LOOKBACK+2) return false; $window=array_slice($ohlc15m,0,$n-1); $donHigh=max(array_column(array_slice($window,-self::DONCHIAN_LOOKBACK),'high')); $donLow=min(array_column(array_slice($window,-self::DONCHIAN_LOOKBACK),'low')); $lastVwap=$this->vwap->calculate(array_column($ohlc15m,'high'),array_column($ohlc15m,'low'),array_column($ohlc15m,'close'),array_column($ohlc15m,'volume')); $last=$ohlc15m[$n-1]; $lastVol=(float)($last['volume']??0); $vols=array_map(fn($k)=>(float)($k['volume']??0),$ohlc15m); sort($vols); $idx=(int)floor((count($vols)-1)*self::VOL_PCTILE); $volThr=$vols[$idx]??0.0; return $sideUpper==='LONG'? ($entry>$donHigh && $entry>$lastVwap && $lastVol>$volThr): ($entry<$donLow && $entry<$lastVwap && $lastVol>$volThr); }
    private function isValidRetest(array $signals,array $ohlc5m,array $ohlc1m): bool { $side15m=strtoupper((string)($signals['15m']['signal']??'NONE')); if(!in_array($side15m,['LONG','SHORT'],true)) return false; $side5m=strtoupper((string)($signals['5m']['signal']??'NONE')); $side1m=strtoupper((string)($signals['1m']['signal']??'NONE')); if($side5m!==$side15m||$side1m!==$side15m) return false; $vwap5=$this->vwap->calculate(array_column($ohlc5m,'high'),array_column($ohlc5m,'low'),array_column($ohlc5m,'close'),array_column($ohlc5m,'volume')); $vwap1=$this->vwap->calculate(array_column($ohlc1m,'high'),array_column($ohlc1m,'low'),array_column($ohlc1m,'close'),array_column($ohlc1m,'volume')); $c5=(float)($ohlc5m[array_key_last($ohlc5m)]['close']??\NAN); $c1=(float)($ohlc1m[array_key_last($ohlc1m)]['close']??\NAN); return $side15m==='LONG'? ($c5>=$vwap5 && $c1>=$vwap1): ($c5<=$vwap5 && $c1<=$vwap1); }
    private function detectExpansionAfterContraction(array $ohlc15m): bool { if(count($ohlc15m)< self::MIN_SERIE) return false; $lastChop=$this->choppiness->calculate(array_column($ohlc15m,'high'),array_column($ohlc15m,'low'),array_column($ohlc15m,'close'),14); return $lastChop< self::CHOP_TRENDISH_MAX; }
}

