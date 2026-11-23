import { show as order } from '@/routes/orders';
import { show as plan } from '@/routes/plans';
import { show as requisition } from '@/routes/requisitions';
import { show as sourcing } from '@/routes/sourcing';

/** Where a document of an approval type is opened. */
export function documentUrl(type: string, id: string): string {
    switch (type) {
        case 'plan':
            return plan(id).url;
        case 'requisition':
            return requisition(id).url;
        case 'order':
            return order(id).url;
        default:
            // Sourcing events and their awards open on the event.
            return sourcing(id).url;
    }
}
