import type { Tone } from '@miles/ui/components/status-badge';
import type { ItemType } from '@/collect/definition';

/*
 * What the data collection pages share: how statuses, purposes and
 * question types read.
 */

export type FormStatus = 'draft' | 'published' | 'archived';

export const FORM_STATUS: Record<FormStatus, { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    published: { label: 'Published', tone: 'success' },
    archived: { label: 'Archived', tone: 'neutral' },
};

export const PURPOSES: Record<string, string> = {
    survey: 'Survey',
    assessment: 'Assessment',
    monitoring_visit: 'Monitoring visit',
    inspection: 'Inspection',
    research: 'Research',
    other: 'Other',
};

export type CollectedStatus =
    | 'receiving'
    | 'submitted'
    | 'under_review'
    | 'correction_requested'
    | 'accepted'
    | 'rejected';

export const COLLECTED: Record<CollectedStatus, { label: string; tone: Tone }> =
    {
        receiving: { label: 'Photos still arriving', tone: 'warning' },
        submitted: { label: 'Submitted', tone: 'info' },
        under_review: { label: 'Under review', tone: 'info' },
        correction_requested: {
            label: 'Correction requested',
            tone: 'warning',
        },
        accepted: { label: 'Accepted', tone: 'success' },
        rejected: { label: 'Rejected', tone: 'danger' },
    };

export type AssignmentStatus = 'active' | 'closing' | 'revoked';

export const ASSIGNMENT: Record<AssignmentStatus, { label: string; tone: Tone }> =
    {
        active: { label: 'Active', tone: 'success' },
        closing: { label: 'Closing', tone: 'warning' },
        revoked: { label: 'Revoked', tone: 'neutral' },
    };

export const QUESTION_TYPES: Record<ItemType, { label: string; hint: string }> =
    {
        text: { label: 'Short text', hint: 'A name, a word, a line' },
        textarea: { label: 'Long text', hint: 'Several sentences' },
        integer: { label: 'Whole number', hint: '0, 1, 2…' },
        decimal: { label: 'Decimal number', hint: '2.5, 10.75' },
        select_one: { label: 'Single choice', hint: 'Pick one from a list' },
        select_multiple: {
            label: 'Multiple choice',
            hint: 'Pick any from a list',
        },
        yes_no: { label: 'Yes / No', hint: 'Yes or no' },
        date: { label: 'Date', hint: 'A day' },
        time: { label: 'Time', hint: 'A time of day' },
        datetime: { label: 'Date and time', hint: 'A day and a time' },
        geopoint: { label: 'GPS location', hint: 'Where it was collected' },
        photo: { label: 'Photo', hint: 'Taken with the camera' },
        note: { label: 'Note', hint: 'Instructions, no answer' },
        group: { label: 'Section', hint: 'Groups questions together' },
    };
