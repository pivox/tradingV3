# Hyperliquid Candle Frontier Order Design

## Goal

Allow a complete public Hyperliquid capture to certify when its candle
frontiers have the same streams and timestamps as the durable checkpoint but
were encountered in a different insertion order.

## Decision

`HyperliquidPaperLiveCheckpoint` already validates and sorts its frontier map.
The dataset verifier will sort a local copy of the frontiers reconstructed from
the event tape before retaining the existing strict array comparison. This
makes map order irrelevant without weakening stream, type, timestamp, epoch,
identity, checksum, continuity, or healthy-stop checks.

Comparing canonical JSON would also be order-independent, but would obscure a
simple map comparison. Requiring the scanner to encounter candles in lexical
stream order would incorrectly couple certification to live event arrival.

## Verification

An end-to-end verifier test will record candle events in non-lexical timeframe
order while persisting the checkpoint's canonical frontier map. It must fail on
the current verifier and pass after the normalization. Existing invalid live
checkpoint tests must remain fail-closed. The immutable r11 dataset will then
be re-verified, including its external event-file SHA-256.
