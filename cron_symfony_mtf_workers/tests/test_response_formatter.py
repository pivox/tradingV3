"""Tests for response_formatter module."""
import json
from utils.response_formatter import format_mtf_response


def test_format_mtf_response_with_success():
    """Test formatting a response with SUCCESS contracts."""
    raw_response = {
        "ok": True,
        "status": 200,
        "body": json.dumps({
            "status": "success",
            "message": "MTF run completed",
            "data": {
                "summary": {
                    "execution_time_seconds": 31.4,
                    "symbols_processed": 10,
                    "success_rate": 20,
                    "dry_run": False,
                },
                "results": {
                    "BTCUSDT": {
                        "symbol": "BTCUSDT",
                        "status": "SUCCESS",
                        "execution_tf": "5m",
                    },
                    "ETHUSDT": {
                        "symbol": "ETHUSDT",
                        "status": "SUCCESS",
                        "execution_tf": "1m",
                    },
                    "SOLUSDT": {
                        "symbol": "SOLUSDT",
                        "status": "INVALID",
                        "failed_timeframe": "15m",
                    },
                    "FINAL": {
                        "run_id": "test-123",
                    }
                }
            }
        }),
        "url": "http://test",
        "payload": {"workers": 5}
    }
    
    result = format_mtf_response(raw_response)
    
    assert "summary" in result
    assert "success_contracts" in result
    assert "metrics" in result
    
    # Check SUCCESS contracts
    assert result["success_contracts"]["5m"] == ["BTCUSDT"]
    assert result["success_contracts"]["1m"] == ["ETHUSDT"]
    
    # Check metrics
    assert result["metrics"]["execution_time_seconds"] == 31.4
    assert result["metrics"]["symbols_processed"] == 10
    assert result["metrics"]["success_rate"] == 20
    
    # Check summary contains expected info
    summary = result["summary"]
    assert "31.4s" in summary
    assert "10 processed" in summary
    assert "BTCUSDT" in summary
    assert "ETHUSDT" in summary
    assert "5m" in summary
    assert "1m" in summary
    
    print("✅ Test passed!")
    print("\nFormatted summary:")
    print(result["summary"])


def test_format_mtf_response_no_success():
    """Test formatting a response with no SUCCESS contracts."""
    raw_response = {
        "ok": True,
        "status": 200,
        "body": json.dumps({
            "status": "success",
            "data": {
                "summary": {
                    "execution_time_seconds": 20.0,
                    "symbols_processed": 5,
                    "success_rate": 0,
                    "dry_run": True,
                },
                "results": {
                    "BTCUSDT": {
                        "status": "INVALID",
                        "failed_timeframe": "1h",
                    },
                    "ETHUSDT": {
                        "status": "INVALID",
                        "failed_timeframe": "15m",
                    }
                }
            }
        }),
        "url": "http://test",
        "payload": {"workers": 3}
    }
    
    result = format_mtf_response(raw_response)
    
    assert result["success_contracts"] == {}
    assert "SUCCESS: None" in result["summary"]
    
    print("✅ Test passed (no success)!")
    print("\nFormatted summary:")
    print(result["summary"])


def test_format_mtf_response_error():
    """Test formatting an error response."""
    raw_response = {
        "ok": False,
        "status": 500,
        "body": "Internal server error",
        "url": "http://test",
        "payload": {}
    }
    
    result = format_mtf_response(raw_response)
    
    assert "error" in result
    assert "Request failed" in result["summary"]
    
    print("✅ Test passed (error case)!")
    print("\nFormatted summary:")
    print(result["summary"])


if __name__ == "__main__":
    print("Running response_formatter tests...\n")
    test_format_mtf_response_with_success()
    print("\n" + "="*50 + "\n")
    test_format_mtf_response_no_success()
    print("\n" + "="*50 + "\n")
    test_format_mtf_response_error()
    print("\n✅ All tests passed!")

