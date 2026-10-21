<?php

// FICTIONAL fixture. The numbers are deliberately NOT YAP's real ids, to prove the
// service never relies on hard-coded event numbers.

namespace App\Constants;

class EventId
{
    const VOLUNTEER_SEARCH = 101;
    const MEETING_SEARCH = 102;
    const JFT_LOOKUP = 103;
    const VOICEMAIL = 104;
    const VOLUNTEER_DIALED = 105;
    const VOLUNTEER_ANSWERED = 106;
    const VOLUNTEER_REJECTED = 107;
    const VOLUNTEER_NOANSWER = 108;
    const VOLUNTEER_ANSWERED_BUT_CALLER_HUP = 109;
    const CALLER_IN_CONFERENCE = 110;
    const VOLUNTEER_HUP = 111;
    const VOLUNTEER_IN_CONFERENCE = 112;
    const CALLER_HUP = 113;
    const HELPLINE_ROUTE = 115;
    const MEETING_SEARCH_LOCATION_GATHERED = 114;
    const DIALBACK = 117;
    const MEETING_SEARCH_SMS = 119;
    const VOLUNTEER_SEARCH_SMS = 120;
    const JFT_LOOKUP_SMS = 121;
    const SPAD_LOOKUP = 123;
    const VOLUNTEER_SEARCH_FORCE_DIALED = 125;
    const VOLUNTEER_NUMBER_BAD = 126;
    const VOLUNTEER_NUMBER_BUSY = 127;
    const STIR_VERSTAT_RECEIVED = 128;
}
