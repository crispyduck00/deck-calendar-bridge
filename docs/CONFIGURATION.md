# Rules, field ownership and invitations

Configure rules in **Administration → Deck Calendar Bridge**. The advanced JSON editor is optional. Import only changes the local editor; save to update the server. Rules are saved as one revision-checked snapshot, so concurrent administrators cannot silently overwrite each other.

## One rule per board/tag

Select a board, one trigger tag, and an original organizer calendar. Enable and save the rule. Different trigger tags on one card can create different events/calendars; they share the card's start/due dates. One active board/tag pair cannot have two target rules because deleting an event must identify exactly one trigger to remove.

Changing a target cleans the old bound original before creating the new one. Removing a rule cleans its existing originals, including archived cards, while leaving Deck tags. Disabling a rule pauses it and keeps events. Adding or changing rules queues existing-card backfill.

## Field ownership

| Field | Authority and behavior |
|---|---|
| Start/due and event start/end | Bidirectional for normal timed rules; Deck wins conflicting changes |
| Title | Deck title with no prefix, custom text or the current list/column name; calendar title edits are restored |
| Categories | Fixed rule categories and optional current card tags; unrelated calendar categories retained |
| Description | Exact card link plus stable notice; calendar description edits replaced |
| Card description/checklists | Stay in Deck; never imported from or exported to the event |
| Attendees | Optional initial list on creation; current calendar attendees thereafter |
| RSVP, location, alarms | Calendar-managed and preserved |
| Trigger tag / original event | Removing either removes its exact counterpart; other triggers remain |
| Archive | Keeps events |
| Done | Keeps events by default; per-rule delete option |

The managed description for a normal timed rule is:

```text
Linked Deck card: https://cloud.example.com/apps/deck/board/12/card/34
Title and categories are managed in Deck. Please edit them on the card.
Time changes made here are synchronized back to Deck.
```

For forward-only day modes, the last line instead says that times are managed in Deck. Equal descriptions cause no write or mail. There is no whole-calendar read-only restriction: users with normal write permission can still move or delete original events. The notice guides editors; it does not prevent them from sending a native update before the bridge restores a managed field.

On the first reconcile after upgrading from a version with editable calendar descriptions, existing notes in that field are replaced. Move needed notes to Deck before upgrading. This genuine description change can generate one normal attendee update.

## Title prefix and separator

Each rule has three **Event title prefix** choices. For a card titled `Collect item` in the `Ready` list:

| Choice | Prefix text | Separator | Event title |
|---|---|---|---|
| No prefix | Ignored | Ignored | `Collect item` |
| Custom text | `Collection` | ` - ` | `Collection - Collect item` |
| Deck list / column name | Current list: `Ready` | `: ` | `Ready: Collect item` |

The separator is literal, including spaces. An empty separator joins directly. Empty custom text or an empty list name leaves only the card title, without a dangling separator. Custom text is retained while another mode is selected, but is used only in Custom text mode.

Moving a card to another list updates a list-based prefix through the card event. A list rename is recovered by the periodic scan, including for existing links whose dates are now in the past. Changing the mode, text or separator also updates existing linked originals without creating a new UID. Equal rendered titles cause no calendar write. Actual title changes can generate normal Nextcloud attendee updates.

The JSON fields are `presentation.titlePrefixMode` (`none`, `custom`, `list`), `titlePrefix` (custom text) and `titleSeparator`. New explicitly selected modes default to ` - ` if no separator is provided. New UI rules start with No prefix and that separator ready to use.

Existing rules without `titlePrefixMode` keep their exact previous title: nonempty `titlePrefix` becomes Custom text with an empty separator; an empty prefix becomes No prefix. Existing punctuation and spaces are not stripped or duplicated. No database migration or manual rule conversion is required.

Custom prefix text is limited to 120 UTF-8 bytes, the separator to 32 bytes, and the complete title to 1024 bytes. Control characters and invalid UTF-8 are refused. List names come from the current source column rather than a separate configured copy. Titles remain Deck-managed; calendar title edits do not rename the card or its list.

## Time mapping and existing cards

Start plus due defines the interval. A single start date uses the configured duration after the start. A single due date uses duration and the selected anchor: due at event end (`before`), center (`center`), or start (`after`). A calendar resize that needs two endpoints stores both start and due on the card. Each other projection is then planned from those shared endpoints.

New events require a qualifying source date now or in the future. Fully historical first-time creates are skipped; moving the source date forward qualifies later. Existing relations are retained as time passes. For day modes, today's date in the configured time zone qualifies.

Deck 1.19 does not expose a reliable date-only flag. `timing.dateOnly` therefore selects the interpretation for the whole rule:

- `skip`: use actual Deck clock times; normal bidirectional mode.
- `all_day`: discard clock times and create local all-day dates with an exclusive next-day end; forward-only.
- `day_edges`: discard clock times; start-only begins at local midnight and due-only ends at the following midnight; forward-only.

Use a valid IANA `dateTimeZone` for day boundaries. The UI initially uses the browser's time zone. Reminder `0` or `null` creates no bridge alarm. Changes to reminder settings apply when creating new events; existing calendar alarms remain owned by the calendar.

## Invitations and category updates

The bridge writes through native Nextcloud DAV scheduling. Nextcloud handles requests, replies and cancellations using its own mail configuration and policies. Initial recipients apply only to newly created events. Editing the list in a rule does not replace current event attendees.

`invitations.notifyOnCategoryChanges` is independent of initial invitations and defaults to **false**. It applies to the current original event:

- Off: categories still update. Native local delivery can update participants on the same instance, but category-only changes are not normally email-worthy.
- On: when the reconciler actually changes category values, it requests a native invitation update for the current attendees. It does not restore removed recipients, reset RSVP, override `SCHEDULE-AGENT=CLIENT/NONE`, or change title/description just to force mail.
- Enabling the option alone, reordering identical categories, and unchanged/recovered replay do not resend an update.
- No organizer or attendees means there is nobody to notify. The option does not add them.

The update contains current categories; external clients decide their presentation and retention. Normal title, time, description and cancellation notifications follow Nextcloud scheduling independently of this option. Global invitation settings and past-event mail suppression still apply.

## Example JSON

Replace the IDs, organizer and calendar URI with values from the installation. These are generic examples, not discovered resources. Optional fields are normalized when saved:

```json
[
  {
    "id": "collection",
    "enabled": true,
    "source": {"type": "deck", "boardIds": [12], "labelIds": [7]},
    "target": {"mode": "fixed", "principal": "principals/users/calendar-owner", "calendarUri": "work"},
    "timing": {"durationMinutes": 20, "maxBlockMinutes": 240, "dueAnchor": "before", "dateOnly": "skip", "dateTimeZone": "UTC"},
    "reminderMinutes": 0,
    "presentation": {"titlePrefixMode": "custom", "titlePrefix": "Collection", "titleSeparator": ": ", "categories": [], "syncDeckTags": true},
    "invitations": {"enabled": false, "attendees": [], "notifyOnCategoryChanges": false},
    "sync": {"direction": "bidirectional_times", "onCalendarDelete": "remove_tag", "onDone": "keep"}
  }
]
```

When enabling initial invitations, provide at least one valid recipient and ensure the organizer has a valid email address. Dynamic assignee invitations, full card-description export, additional availability modes and recurring event synchronization are not implemented.

## Input limits and unsupported edits

A rule uses one source board/tag and one original target. There can be up to 100 rules and 50 initial recipients per rule. The combined exported category list is limited to 20 names; each rule/Deck category name is at most 80 UTF-8 bytes. A card with more exported categories is reported as blocked, rather than silently truncating its categories. Disable full tag export or reduce the selection if needed.

Calendar times need UTC or an explicit `TZID`. Floating times, recurring events, duplicate managed properties and invalid scheduling sequences are refused. Fix that original event to a supported format and retry; the bridge does not infer a time zone or drop recurrence. Sequence exhaustion blocks the affected changed projection instead of overflowing its version.

Deleting or moving a parent column is discovered through the recovery scan when no card event is emitted. The bridge reads the current parent state and removes obsolete projections. New rule saves and global enablement enqueue backfill; see [worker timing](WORKER_SYSTEMD.md) for page sizes and scheduling.
