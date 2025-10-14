<?php
$maxLevs = [10, 20, 50, 100, 125, 150, 200];
foreach ($maxLevs as $lev) {
$targetLev = (int)($lev * 0.2);
echo $targetLev."\n";
}
