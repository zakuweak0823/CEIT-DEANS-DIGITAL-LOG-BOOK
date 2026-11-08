<?php
/**
 * Message generator: builds an SMS from the real document data instead of a fixed text.
 *
 * It picks a wording for the status, fills in the document, the missing requirements and the tracking link,
 * then fits the result into one SMS (160 characters) by dropping the least important parts first:
 * greeting, then a shorter subject, then extra requirements ("and 2 more"), then the link.
 */

function composer_office_name(): string
{
    return "CEIT Dean's Office";
}

function composer_phrases(string $event, string $status): array
{
    if ($event === 'requirements_complete') {
        return [
            'we have received all the requirements for {doc}. Processing will continue.',
            'thank you, your requirements for {doc} are complete. We will continue processing it.',
            'all requirements for {doc} are now complete and processing has resumed.',
        ];
    }
    $pools = [
        'Received' => [
            'we have received {doc}.',
            '{doc} was received by our office.',
            'your document {doc} is now recorded in our office.',
        ],
        'In Review' => [
            '{doc} is now under review.',
            'we are now reviewing {doc}.',
            'your document {doc} is being reviewed by our office.',
        ],
        'Forwarded' => [
            '{doc} was forwarded to the concerned office for action.',
            'we forwarded {doc} to the office that will act on it.',
        ],
        'Incomplete' => [
            '{doc} is incomplete. Please submit: {items}.',
            'we still need {items} for {doc}. Please submit them to the office.',
            '{doc} cannot be processed yet. Missing: {items}.',
            'please bring {items} so we can continue processing {doc}.',
        ],
        'On Hold' => [
            '{doc} is temporarily on hold. Please contact the office for details.',
            'processing of {doc} is on hold for now.',
        ],
        'Approved' => [
            '{doc} was approved.',
            'good news, {doc} has been approved.',
        ],
        'For Release' => [
            '{doc} is ready for release. Please claim it at the Dean\'s Office.',
            'you may now claim {doc} at the Dean\'s Office.',
            'good news, {doc} is ready for pick-up.',
        ],
        'Released' => [
            '{doc} has been released.',
            'your document {doc} was released. Contact the office if you need help.',
        ],
        'Returned' => [
            '{doc} was returned. Please contact the office for details.',
            'your document {doc} has been returned to you.',
        ],
        'Cancelled' => [
            '{doc} was cancelled.',
            'the request for {doc} has been cancelled.',
        ],
    ];
    return $pools[$status] ?? ['there is an update on {doc}. Status: ' . $status . '.'];
}

function composer_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function composer_cut(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }
    if (composer_text_length($value) <= $max) {
        return $value;
    }
    $cut = function_exists('mb_substr') ? mb_substr($value, 0, max(1, $max - 3), 'UTF-8') : substr($value, 0, max(1, $max - 3));
    return rtrim($cut) . '...';
}

function composer_join_items(array $items, int $maxItems): string
{
    $items = array_values($items);
    $extra = 0;
    if ($maxItems > 0 && count($items) > $maxItems) {
        $extra = count($items) - $maxItems;
        $items = array_slice($items, 0, $maxItems);
    }
    $parts = array_map('strval', $items);
    if ($extra > 0) {
        $parts[] = $extra . ' more';
    }
    if (count($parts) <= 1) {
        return $parts[0] ?? '';
    }
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
}

/**
 * $context keys: status, event ('status' or 'requirements_complete'), reference, subject, name,
 * items (missing requirements), url, variant (changes the wording), limit (characters, default 160).
 */
function compose_sms_message(array $context): string
{
    $status = (string)($context['status'] ?? '');
    $event = (string)($context['event'] ?? 'status');
    $reference = trim((string)($context['reference'] ?? ''));
    $subject = trim((string)preg_replace('/\s+/', ' ', (string)($context['subject'] ?? '')));
    $url = trim((string)($context['url'] ?? ''));
    $limit = (int)($context['limit'] ?? 160);
    $items = array_values(array_filter(array_map(static function ($item) {
        return trim((string)$item);
    }, (array)($context['items'] ?? [])), static function ($item) {
        return $item !== '';
    }));
    $firstName = trim((string)strtok(trim((string)($context['name'] ?? '')), ' '));
    if (composer_text_length($firstName) > 18) {
        $firstName = '';
    }

    $phrases = composer_phrases($event, $status);
    if (strpos(implode(' ', $phrases), '{items}') !== false && !$items && $status === 'Incomplete') {
        // Without a requirement list the "missing" wording would read badly.
        $phrases = ['{doc} is incomplete. Please contact the office for the missing requirements.'];
    }
    $variant = (int)($context['variant'] ?? 0) + (int)sprintf('%u', crc32($reference . '|' . $status . '|' . $event));
    $phrase = $phrases[$variant % count($phrases)];

    $build = static function (bool $withUrl, int $maxItems, int $subjectLength, bool $greeting) use ($phrase, $subject, $reference, $items, $url, $firstName): string {
        $shortSubject = composer_cut($subject, $subjectLength);
        $doc = $shortSubject !== '' ? '"' . $shortSubject . '"' : ($reference !== '' ? $reference : 'your document');
        if ($shortSubject !== '' && $reference !== '' && $subjectLength >= 40) {
            $doc .= ' (' . $reference . ')';
        }
        $sentence = str_replace(['{doc}', '{items}'], [$doc, composer_join_items($items, $maxItems)], $phrase);
        $sentence = $greeting && $firstName !== '' ? 'Hi ' . $firstName . ', ' . $sentence : ucfirst($sentence);
        $text = composer_office_name() . ': ' . $sentence;
        if ($withUrl && $url !== '') {
            $text .= ' Track: ' . $url;
        }
        return $text;
    };

    $itemCount = max(1, count($items));
    foreach ([$limit, 306] as $allowed) {
        foreach ([true, false] as $withUrl) {
            foreach (array_unique([0, 3, 2, 1]) as $maxItems) {
                if ($maxItems !== 0 && $maxItems >= $itemCount) {
                    continue;
                }
                foreach ([48, 28, 0] as $subjectLength) {
                    foreach ([true, false] as $greeting) {
                        $candidate = $build($withUrl, $maxItems, $subjectLength, $greeting);
                        if (composer_text_length($candidate) <= $allowed) {
                            return $candidate;
                        }
                    }
                }
            }
        }
    }
    return composer_cut($build(false, 1, 0, false), 306);
}

/** Loads what the generator needs for one document (subject, name, phone, tracking link, missing items). */
function composer_document_context($conn, int $documentId, array $trackingSettings): ?array
{
    $stmt = safe_query($conn, 'SELECT doc_id, subject, submitter_name, client_phone, status, tracking_token FROM documents WHERE doc_id = ?', 'i', [$documentId]);
    if ($stmt === false) {
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $document = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$document) {
        return null;
    }
    require_once __DIR__ . '/requirements_support.php';
    $url = '';
    if (!empty($trackingSettings['enabled']) && !empty($document['tracking_token']) && !empty($trackingSettings['baseUrl'])) {
        $url = $trackingSettings['baseUrl'] . '?token=' . rawurlencode($document['tracking_token']);
    }
    return [
        'status' => (string)$document['status'],
        'event' => 'status',
        'reference' => document_tracking_reference($document['doc_id']),
        'subject' => (string)$document['subject'],
        'name' => (string)$document['submitter_name'],
        'phone' => trim((string)($document['client_phone'] ?? '')),
        'items' => requirements_pending_labels($conn, $documentId),
        'url' => $url,
        'variant' => 0,
    ];
}
