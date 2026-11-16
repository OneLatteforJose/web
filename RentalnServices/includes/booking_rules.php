<?php
// Vehicle pick-up lead time. A booking can never start sooner than the manager needs to approve it,
// plus the time the provider needs to get the vehicle ready. Change the two numbers below to change the rule everywhere.
const BOOKING_APPROVAL_HOURS = 1;   // the manager (or an admin) decides within this time
const BOOKING_PREP_HOURS     = 2;   // preparing the vehicle, counted after it is approved
const BOOKING_LEAD_HOURS     = BOOKING_APPROVAL_HOURS + BOOKING_PREP_HOURS;

// The earliest pick-up for a booking made at $from (default: right now), rounded up to the next whole minute.
function earliest_pickup(?DateTimeInterface $from = null): DateTime {
    $t = $from ? DateTime::createFromInterface($from) : new DateTime();
    $t->modify('+' . BOOKING_LEAD_HOURS . ' hours');
    if ((int)$t->format('s') > 0) {
        $t->modify('+1 minute');
    }
    return $t->setTime((int)$t->format('H'), (int)$t->format('i'), 0);
}

// "1 hour for the manager to approve it and 2 hours to prepare the vehicle"
function lead_text(): string {
    $h = fn(int $n): string => $n . ($n === 1 ? ' hour' : ' hours');
    return $h(BOOKING_APPROVAL_HOURS) . ' for the manager to approve it and ' . $h(BOOKING_PREP_HOURS) . ' to prepare the vehicle';
}
