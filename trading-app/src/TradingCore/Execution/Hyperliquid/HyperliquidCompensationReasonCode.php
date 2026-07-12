<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

enum HyperliquidCompensationReasonCode: string
{
    case ENTRY_REJECTED = 'entry_rejected';
    case ENTRY_CANCELED = 'entry_canceled';
    case EXPOSURE_CLOSED = 'exposure_closed';
    case KILL_SWITCH_ALREADY_TRIPPED = 'kill_switch_already_tripped';
    case KILL_SWITCH_STATE_UNAVAILABLE = 'kill_switch_state_unavailable';
    case ENTRY_RECONCILIATION_UNCONFIRMED = 'entry_reconciliation_unconfirmed';
    case ENTRY_LIFECYCLE_CONTRADICTORY = 'entry_lifecycle_contradictory';
    case IDENTIFIER_CONTRADICTION = 'identifier_contradiction';
    case CANCEL_SUBMISSION_UNCONFIRMED = 'cancel_submission_unconfirmed';
    case CANCEL_CONFIRMATION_UNCONFIRMED = 'cancel_confirmation_unconfirmed';
    case CLOSE_PREEXISTING_UNCONFIRMED = 'close_preexisting_unconfirmed';
    case CLOSE_SUBMISSION_UNCONFIRMED = 'close_submission_unconfirmed';
    case CLOSE_CONFIRMATION_UNCONFIRMED = 'close_confirmation_unconfirmed';
    case PROVIDER_RUNTIME_FAILURE = 'provider_runtime_failure';
    case NONCE_FAILURE = 'nonce_failure';
    case SLEEPER_FAILURE = 'sleeper_failure';
    case PROGRAMMER_INVARIANT_FAILURE = 'programmer_invariant_failure';
}
