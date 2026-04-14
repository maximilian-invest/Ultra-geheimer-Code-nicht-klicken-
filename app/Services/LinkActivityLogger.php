<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\PropertyLinkEvent;
use App\Models\PropertyLinkSession;

class LinkActivityLogger
{
    public function recordLinkOpened(PropertyLinkSession $session): Activity
    {
        $session->loadMissing('link', 'events');

        return Activity::updateOrCreate(
            ['link_session_id' => $session->id],
            [
                'property_id'   => $session->link->property_id,
                'activity_date' => now()->toDateString(),
                'stakeholder'   => $session->email,
                'category'      => 'link_opened',
                'activity'      => $this->buildSummaryText($session),
            ]
        );
    }

    public function recordEvent(
        PropertyLinkSession $session,
        string $type,
        ?int $propertyFileId = null,
        ?int $durationS = null,
    ): PropertyLinkEvent {
        $event = PropertyLinkEvent::create([
            'session_id'       => $session->id,
            'property_file_id' => $propertyFileId,
            'event_type'       => $type,
            'duration_s'       => $durationS,
        ]);

        $session->last_seen_at = now();
        $session->save();

        $this->refreshActivitySummary($session);

        return $event;
    }

    public function refreshActivitySummary(PropertyLinkSession $session): void
    {
        $session->loadMissing('link', 'events');

        Activity::where('link_session_id', $session->id)->update([
            'activity' => $this->buildSummaryText($session),
        ]);
    }

    protected function buildSummaryText(PropertyLinkSession $session): string
    {
        $events = $session->events;
        $viewed = $events->where('event_type', PropertyLinkEvent::TYPE_DOC_VIEWED)->count();
        $downloaded = $events->where('event_type', PropertyLinkEvent::TYPE_DOC_DOWNLOADED)->count();
        $durationMin = max(1, (int) ceil($events->sum('duration_s') / 60));

        return sprintf(
            "hat Link '%s' geoeffnet · %d Dokumente angesehen, %d heruntergeladen · ~%d Min",
            $session->link->name,
            $viewed,
            $downloaded,
            $durationMin,
        );
    }
}
