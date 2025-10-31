"""Format MTF API responses for concise Temporal output."""
from __future__ import annotations

import json
from typing import Any, Dict, List


def format_mtf_response(raw_response: Dict[str, Any]) -> Dict[str, Any]:
    """
    Parse and format MTF API response to show concise summary + SUCCESS contracts for 5m/1m.
    
    Args:
        raw_response: Raw response dict from mtf_api_call activity
        
    Returns:
        Formatted dict with summary, success_contracts, and full_response
    """
    if not raw_response.get("ok"):
        return {
            "summary": f"❌ Request failed: {raw_response.get('status', 'N/A')}",
            "error": raw_response.get("body", "Unknown error"),
            "full_response": raw_response,
        }
    
    try:
        body = json.loads(raw_response["body"])
    except (json.JSONDecodeError, KeyError):
        return {
            "summary": "⚠️  Invalid JSON response",
            "full_response": raw_response,
        }
    
    data = body.get("data", {})
    summary = data.get("summary", {})
    results = data.get("results", {})
    
    # Extract global metrics
    execution_time = summary.get("execution_time_seconds", 0)
    symbols_processed = summary.get("symbols_processed", 0)
    success_rate = summary.get("success_rate", 0)
    dry_run = summary.get("dry_run", False)
    
    # Extract SUCCESS contracts by timeframe
    success_by_tf = _extract_success_contracts(results)
    
    # Build summary text
    summary_lines = [
        f"✅ MTF Run Completed ({execution_time:.1f}s)",
        f"📊 Symbols: {symbols_processed} processed | Success Rate: {success_rate}%",
        f"🔄 Workers: {raw_response['payload'].get('workers', '?')} | Dry-run: {dry_run}",
        "",
    ]
    
    # Add SUCCESS contracts (prioritize 5m, 1m, then others)
    priority_tfs = ["5m", "1m", "15m", "1h", "4h"]
    success_displayed = False
    
    for tf in priority_tfs:
        contracts = success_by_tf.get(tf, [])
        if contracts:
            summary_lines.append(f"🎯 SUCCESS ({tf}): {', '.join(sorted(contracts))}")
            success_displayed = True
    
    # Show other timeframes if any
    for tf in sorted(success_by_tf.keys()):
        if tf not in priority_tfs:
            contracts = success_by_tf[tf]
            summary_lines.append(f"🎯 SUCCESS ({tf}): {', '.join(sorted(contracts))}")
            success_displayed = True
    
    if not success_displayed:
        summary_lines.append("🎯 SUCCESS: None")
    
    # Add INVALID breakdown by timeframe (condensed)
    tf_counts = _count_by_timeframe(results)
    if tf_counts:
        summary_lines.append("")
        summary_lines.append("📉 INVALID by timeframe:")
        for tf, count in sorted(tf_counts.items()):
            summary_lines.append(f"  • {tf}: {count} symbols")
    
    return {
        "summary": "\n".join(summary_lines),
        "success_contracts": success_by_tf,
        "metrics": {
            "execution_time_seconds": execution_time,
            "symbols_processed": symbols_processed,
            "success_rate": success_rate,
            "dry_run": dry_run,
        },
        "full_response": raw_response,
    }


def _extract_success_contracts(results: Dict[str, Any]) -> Dict[str, List[str]]:
    """
    Extract SUCCESS contracts grouped by execution timeframe.
    
    Args:
        results: Results dict from MTF API response
        
    Returns:
        Dict mapping timeframe -> list of symbol names
    """
    success_by_tf: Dict[str, List[str]] = {}
    
    for symbol, result in results.items():
        if symbol == "FINAL":  # Skip summary entry
            continue
            
        status = result.get("status", "").upper()
        if status == "SUCCESS" or status == "VALID":
            execution_tf = result.get("execution_tf") or result.get("timeframe")
            if execution_tf:
                if execution_tf not in success_by_tf:
                    success_by_tf[execution_tf] = []
                success_by_tf[execution_tf].append(symbol)
    
    return success_by_tf


def _count_by_timeframe(results: Dict[str, Any]) -> Dict[str, int]:
    """
    Count symbols by failed_timeframe from INVALID results.
    
    Args:
        results: Results dict from MTF API response
        
    Returns:
        Dict mapping timeframe -> count of symbols
    """
    tf_counts: Dict[str, int] = {}
    
    for symbol, result in results.items():
        if symbol == "FINAL":
            continue
            
        status = result.get("status", "").upper()
        if status == "INVALID":
            failed_tf = result.get("failed_timeframe")
            if failed_tf:
                tf_counts[failed_tf] = tf_counts.get(failed_tf, 0) + 1
    
    return tf_counts

